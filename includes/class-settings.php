<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage, defaults, sanitizing and token encryption.
 */
final class CDNHub_Settings {

	const OPT = 'cdnhub_options';

	private $cache = null;

	public function __construct() {
		add_action( 'update_option_' . self::OPT, array( $this, 'flush' ) );
		add_action( 'add_option_' . self::OPT, array( $this, 'flush' ) );
	}

	public function flush() {
		$this->cache = null;
		delete_transient( 'cdnhub_adopt_index' ); // Repo/branch/folder may have changed: drop the cached tree.
	}

	public static function defaults() {
		return array(
			'enabled'         => 1,
			'token'           => '',
			'owner'           => '',
			'repo'            => '',
			'branch'          => 'main',
			'folder'          => 'media',
			'structure'       => 'year_month', // year_month | year | post_id | flat | custom
			'custom_pattern'  => '{year}/{month}',
			'url_mode'        => 'jsdelivr',   // jsdelivr | raw | pages | custom
			'custom_template' => '',
			'conflict'        => 'unique',     // unique | overwrite | fail
			'types'           => array( 'jpg', 'png', 'gif', 'webp', 'avif' ),
			'max_size_mb'     => 10,
			'upload_mode'     => 'instant',    // instant | background
			'auto_retry'      => 1,
			'max_attempts'    => 5,
			'upload_original' => 0,
			'rewrite_content' => 1,
			'save_cdn_urls'   => 0,
			'delete_local'    => 0,
			'delete_remote'   => 0,
			'upload_to_cdn'   => 1,
		);
	}

	public static function all_types() {
		return array(
			'jpg'  => array( 'label' => 'JPG / JPEG', 'mime' => 'image/jpeg' ),
			'png'  => array( 'label' => 'PNG', 'mime' => 'image/png' ),
			'gif'  => array( 'label' => 'GIF', 'mime' => 'image/gif' ),
			'webp' => array( 'label' => 'WebP', 'mime' => 'image/webp' ),
			'avif' => array( 'label' => 'AVIF', 'mime' => 'image/avif' ),
			'svg'  => array( 'label' => 'SVG (scanned for unsafe code)', 'mime' => 'image/svg+xml' ),
		);
	}

	/** All settings, with the token decrypted. */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}
		$saved = get_option( self::OPT, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// Settings from version 1.x.
		if ( isset( $saved['keep_local'] ) && ! isset( $saved['delete_local'] ) ) {
			$saved['delete_local'] = $saved['keep_local'] ? 0 : 1;
		}
		if ( isset( $saved['cdn_mode'] ) && is_string( $saved['cdn_mode'] ) && ! isset( $saved['url_mode'] ) ) {
			$saved['url_mode'] = $saved['cdn_mode'];
			if ( 'custom' === $saved['cdn_mode'] && ! empty( $saved['custom_url'] ) && is_string( $saved['custom_url'] ) ) {
				$saved['custom_template'] = rtrim( $saved['custom_url'], '/' ) . '/{path}';
			}
		}

		$o = array_merge( self::defaults(), $saved );

		if ( defined( 'CDNHUB_GITHUB_TOKEN' ) && CDNHUB_GITHUB_TOKEN ) {
			$o['token'] = (string) CDNHUB_GITHUB_TOKEN;
		} else {
			$o['token'] = $this->decrypt( is_string( $o['token'] ) ? $o['token'] : '' );
		}
		$o['types']       = is_array( $o['types'] ) ? array_values( $o['types'] ) : array();
		$this->cache      = $o;
		return $o;
	}

	public function get( $key ) {
		$o = $this->all();
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public function token_from_constant() {
		return defined( 'CDNHUB_GITHUB_TOKEN' ) && CDNHUB_GITHUB_TOKEN;
	}

	/**
	 * A token is saved but can no longer be decrypted: the key is derived from the
	 * WordPress salts, so this happens when they change (or OpenSSL went away).
	 */
	public function token_unreadable() {
		if ( $this->token_from_constant() ) {
			return false;
		}
		$saved = get_option( self::OPT, array() );
		$token = is_array( $saved ) && isset( $saved['token'] ) && is_string( $saved['token'] ) ? $saved['token'] : '';
		return 0 === strpos( $token, 'enc1:' ) && '' === $this->decrypt( $token );
	}

	public function is_ready() {
		$o = $this->all();
		return '' !== $o['token'] && '' !== $o['owner'] && '' !== $o['repo'] && '' !== $o['branch'];
	}

	public function is_active() {
		return (bool) $this->get( 'enabled' ) && $this->is_ready();
	}

	/* ---------------------------------------------------------------- sanitize */

	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$def = self::defaults();
		$raw = get_option( self::OPT, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();

		foreach ( array( 'enabled', 'auto_retry', 'upload_original', 'rewrite_content', 'save_cdn_urls', 'delete_local', 'delete_remote', 'upload_to_cdn' ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}

		// Token: blank = keep the saved one. Stored encrypted, never in plain text.
		$token = preg_replace( '/\s+/', '', $this->text( $in, 'token' ) );
		if ( '' !== $token ) {
			$encrypted = $this->encrypt( $token );
			if ( false === $encrypted ) {
				// Fail closed: never fall back to storing a token in plain text.
				$out['token'] = $this->text( $raw, 'token' );
				$this->add_error(
					'cdnhub_token_encryption',
					'The GitHub token was not saved: this server cannot encrypt it (OpenSSL with AES-256-GCM is unavailable). Enable the PHP OpenSSL extension, or define CDNHUB_GITHUB_TOKEN in wp-config.php.'
				);
			} else {
				$out['token'] = $encrypted;
			}
		} else {
			$out['token'] = $this->text( $raw, 'token' );
		}

		$out['owner'] = $this->clean_owner( $this->text( $in, 'owner' ) );
		$out['repo']  = $this->clean_repo( $this->text( $in, 'repo' ) );

		$branch = preg_replace( '/[^A-Za-z0-9._\/-]/', '', trim( $this->text( $in, 'branch' ) ) );
		if ( '' === $branch ) {
			$out['branch'] = 'main';
		} elseif ( $this->valid_ref( $branch ) ) {
			$out['branch'] = $branch;
		} else {
			$out['branch'] = '';
			$this->add_error( 'cdnhub_branch', 'The branch name is not a valid Git branch. Use letters, numbers, dots, dashes and slashes only (no “..”, no leading or trailing slash).' );
		}

		$out['folder'] = $this->clean_path( $this->text( $in, 'folder' ) );

		$out['structure'] = $this->choice( $in, 'structure', array( 'year_month', 'year', 'post_id', 'flat', 'custom' ), $def['structure'] );
		$pattern          = preg_replace( '/[^A-Za-z0-9._\-\/{}]/', '', $this->text( $in, 'custom_pattern' ) );
		$out['custom_pattern'] = '' !== $pattern ? $pattern : $def['custom_pattern'];

		$out['url_mode'] = $this->choice( $in, 'url_mode', array( 'jsdelivr', 'raw', 'pages', 'custom' ), $def['url_mode'] );
		$tpl             = trim( preg_replace( '/[\s"\'<>]/', '', $this->text( $in, 'custom_template' ) ) );
		$out['custom_template'] = preg_match( '#^https?://#i', $tpl ) ? $tpl : '';

		$out['conflict']    = $this->choice( $in, 'conflict', array( 'unique', 'overwrite', 'fail' ), $def['conflict'] );
		$out['upload_mode'] = $this->choice( $in, 'upload_mode', array( 'instant', 'background' ), $def['upload_mode'] );

		$types        = isset( $in['types'] ) && is_array( $in['types'] ) ? array_filter( $in['types'], 'is_string' ) : array();
		$out['types'] = array_values( array_intersect( array_keys( self::all_types() ), $types ) );

		$max_size = isset( $in['max_size_mb'] ) && is_scalar( $in['max_size_mb'] ) ? (float) $in['max_size_mb'] : (float) $def['max_size_mb'];
		$attempts = isset( $in['max_attempts'] ) && is_scalar( $in['max_attempts'] ) ? (int) $in['max_attempts'] : (int) $def['max_attempts'];
		$out['max_size_mb']  = min( 90, max( 1, $max_size ) );
		$out['max_attempts'] = min( 20, max( 1, $attempts ) );

		return $out;
	}

	/** A submitted string, ignoring arrays and other non-scalar values. */
	private function text( $in, $key ) {
		return isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? $in[ $key ] : '';
	}

	private function choice( $in, $key, $allowed, $default ) {
		$v = $this->text( $in, $key );
		return in_array( $v, $allowed, true ) ? $v : $default;
	}

	/** GitHub user/organization names: letters, digits and single hyphens, up to 39 characters. */
	private function clean_owner( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$value = preg_replace( '/[^A-Za-z0-9-]/', '', $raw );
		$value = trim( preg_replace( '/-+/', '-', $value ), '-' );
		if ( '' === $value || strlen( $value ) > 39 ) {
			$this->add_error( 'cdnhub_owner', 'The GitHub username or organization is not valid. Use letters, numbers and single hyphens (e.g. “your-username”).' );
			return '';
		}
		return $value;
	}

	/** GitHub repository names: letters, digits, dots, underscores and dashes, up to 100 characters. */
	private function clean_repo( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '', $raw );
		if ( '' === $value || '.' === $value || '..' === $value || strlen( $value ) > 100 ) {
			$this->add_error( 'cdnhub_repo', 'The repository name is not valid. Use letters, numbers, dots, underscores and dashes.' );
			return '';
		}
		return $value;
	}

	/**
	 * Git's rules for branch names (check-ref-format): no control characters, spaces,
	 * "..", "~^:?*[", "@{", "//", backslashes, or path segments starting with a dot
	 * or ending in ".lock". Rejecting these avoids URLs and API endpoints that break.
	 */
	private function valid_ref( $ref ) {
		if ( '' === $ref || strlen( $ref ) > 255 || preg_match( '/[\x00-\x20\x7f~^:?*\[\\\\]/', $ref ) ) {
			return false;
		}
		if ( false !== strpos( $ref, '..' ) || false !== strpos( $ref, '@{' ) || false !== strpos( $ref, '//' ) ) {
			return false;
		}
		if ( '.' === $ref[0] || '/' === $ref[0] || '/' === substr( $ref, -1 ) || '.' === substr( $ref, -1 ) ) {
			return false;
		}
		foreach ( explode( '/', $ref ) as $seg ) {
			if ( '' === $seg || '.' === $seg[0] || '.lock' === strtolower( substr( $seg, -5 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Show a settings error; the function is missing only outside the WordPress admin. */
	private function add_error( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPT, $code, $message, 'error' );
		}
	}

	/** Safe relative path: letters, digits, . _ - and slashes; no traversal. */
	public function clean_path( $path ) {
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $path ) ) as $seg ) {
			$seg = trim( preg_replace( '/[^A-Za-z0-9._-]/', '-', $seg ), '.-' );
			if ( '' !== $seg ) {
				$parts[] = $seg;
			}
		}
		return implode( '/', $parts );
	}

	/* ------------------------------------------------------------- encryption */

	private function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'cdnhub', true );
	}

	private function can_encrypt() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/** Whether tokens can be stored encrypted (the admin UI warns when they cannot). */
	public function encryption_available() {
		return $this->can_encrypt();
	}

	/**
	 * Encrypt a token for storage.
	 *
	 * @return string|false 'enc1:...' on success, '' for an empty token, false when
	 *                      encryption is unavailable or failed (never plain text).
	 */
	public function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! $this->can_encrypt() ) {
			return false;
		}
		try {
			$iv = random_bytes( 12 );
		} catch ( \Throwable $e ) {
			return false;
		}
		$tag = '';
		$c   = openssl_encrypt( $plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $c || 16 !== strlen( $tag ) ) {
			return false;
		}
		return 'enc1:' . base64_encode( $iv . $tag . $c );
	}

	public function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'enc1:' ) ) {
			return $stored; // Plain text saved by an older version (before encryption).
		}
		if ( ! $this->can_encrypt() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 5 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$p = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $p ? '' : $p;
	}
}
