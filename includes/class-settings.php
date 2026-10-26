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
		if ( isset( $saved['cdn_mode'] ) && ! isset( $saved['url_mode'] ) ) {
			$saved['url_mode'] = $saved['cdn_mode'];
			if ( 'custom' === $saved['cdn_mode'] && ! empty( $saved['custom_url'] ) ) {
				$saved['custom_template'] = rtrim( $saved['custom_url'], '/' ) . '/{path}';
			}
		}

		$o = array_merge( self::defaults(), $saved );

		if ( defined( 'CDNHUB_GITHUB_TOKEN' ) && CDNHUB_GITHUB_TOKEN ) {
			$o['token'] = (string) CDNHUB_GITHUB_TOKEN;
		} else {
			$o['token'] = $this->decrypt( (string) $o['token'] );
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
		$token = is_array( $saved ) && isset( $saved['token'] ) ? (string) $saved['token'] : '';
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

		// Token: blank = keep the saved one. Stored encrypted.
		$token = isset( $in['token'] ) ? preg_replace( '/\s+/', '', (string) $in['token'] ) : '';
		if ( '' !== $token ) {
			$out['token'] = $this->encrypt( $token );
		} else {
			$out['token'] = isset( $raw['token'] ) ? (string) $raw['token'] : '';
		}

		$out['owner']  = preg_replace( '/[^A-Za-z0-9._-]/', '', isset( $in['owner'] ) ? (string) $in['owner'] : '' );
		$out['repo']   = preg_replace( '/[^A-Za-z0-9._-]/', '', isset( $in['repo'] ) ? (string) $in['repo'] : '' );
		$branch        = preg_replace( '/[^A-Za-z0-9._\/-]/', '', isset( $in['branch'] ) ? (string) $in['branch'] : '' );
		$out['branch'] = '' !== $branch ? $branch : 'main';

		$out['folder'] = $this->clean_path( isset( $in['folder'] ) ? (string) $in['folder'] : '' );

		$out['structure'] = $this->choice( $in, 'structure', array( 'year_month', 'year', 'post_id', 'flat', 'custom' ), $def['structure'] );
		$pattern          = preg_replace( '/[^A-Za-z0-9._\-\/{}]/', '', isset( $in['custom_pattern'] ) ? (string) $in['custom_pattern'] : '' );
		$out['custom_pattern'] = '' !== $pattern ? $pattern : $def['custom_pattern'];

		$out['url_mode'] = $this->choice( $in, 'url_mode', array( 'jsdelivr', 'raw', 'pages', 'custom' ), $def['url_mode'] );
		$tpl             = trim( preg_replace( '/[\s"\'<>]/', '', isset( $in['custom_template'] ) ? (string) $in['custom_template'] : '' ) );
		$out['custom_template'] = preg_match( '#^https?://#i', $tpl ) ? $tpl : '';

		$out['conflict']    = $this->choice( $in, 'conflict', array( 'unique', 'overwrite', 'fail' ), $def['conflict'] );
		$out['upload_mode'] = $this->choice( $in, 'upload_mode', array( 'instant', 'background' ), $def['upload_mode'] );

		$types        = isset( $in['types'] ) && is_array( $in['types'] ) ? $in['types'] : array();
		$out['types'] = array_values( array_intersect( array_keys( self::all_types() ), $types ) );

		$out['max_size_mb']  = min( 90, max( 1, (float) ( isset( $in['max_size_mb'] ) ? $in['max_size_mb'] : $def['max_size_mb'] ) ) );
		$out['max_attempts'] = min( 20, max( 1, (int) ( isset( $in['max_attempts'] ) ? $in['max_attempts'] : $def['max_attempts'] ) ) );

		return $out;
	}

	private function choice( $in, $key, $allowed, $default ) {
		$v = isset( $in[ $key ] ) ? (string) $in[ $key ] : '';
		return in_array( $v, $allowed, true ) ? $v : $default;
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

	public function encrypt( $plain ) {
		if ( '' === $plain || ! $this->can_encrypt() ) {
			return $plain;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$c   = openssl_encrypt( $plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $c ? $plain : 'enc1:' . base64_encode( $iv . $tag . $c );
	}

	public function decrypt( $stored ) {
		if ( 0 !== strpos( $stored, 'enc1:' ) ) {
			return $stored; // Plain text (older versions or no OpenSSL).
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
