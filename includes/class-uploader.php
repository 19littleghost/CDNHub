<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends attachments to the storage provider: instantly or through a background queue,
 * with duplicate detection, conflict handling, retries and rate-limit awareness.
 */
final class CDNHub_Uploader {

	const CRON_HOOK     = 'cdnhub_sync_attachment';
	const RL_KEY        = 'cdnhub_rate_limited_until';
	const INDEX_KEY     = 'cdnhub_adopt_index';
	const OFFLOAD_NONCE = 'cdnhub_offload';

	/** Error codes a retry cannot fix (only a settings change can). */
	const PERMANENT_ERRORS = array( 'cdnhub_memory' );

	private $settings;
	private $logger;
	private $urls;
	private $busy = array();

	public function __construct( CDNHub_Settings $settings, CDNHub_Logger $logger, CDNHub_URLs $urls ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->urls     = $urls;

		// Runs for classic uploads, the block editor (REST) and image edits alike.
		add_filter( 'wp_update_attachment_metadata', array( $this, 'on_metadata' ), 20, 2 );
		add_action( 'delete_attachment', array( $this, 'on_delete' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'cron_sync' ), 10, 1 );
	}

	/* --------------------------------------------------------------- provider */

	public function provider() {
		$s = $this->settings;
		return $this->provider_from( new CDNHub_GitHub( $s->get( 'token' ), $s->get( 'owner' ), $s->get( 'repo' ), $s->get( 'branch' ) ) );
	}

	/**
	 * Let `cdnhub_provider` replace the default provider, but never hand callers an
	 * object that cannot do the job. A faulty filter must not cause a fatal error.
	 *
	 * @param CDNHub_Provider $default Default GitHub provider (or a test instance).
	 * @return CDNHub_Provider|null Null when the filter returned something invalid.
	 */
	public function provider_from( CDNHub_Provider $default ) {
		$provider = apply_filters( 'cdnhub_provider', $default, $this->settings );
		if ( ! $provider instanceof CDNHub_Provider ) {
			$this->logger->error( 'The cdnhub_provider filter did not return a CDNHub_Provider instance.' );
			return null;
		}
		return $provider;
	}

	/* ------------------------------------------------------------------ types */

	public function allowed_mimes() {
		$all   = CDNHub_Settings::all_types();
		$mimes = array();
		foreach ( (array) $this->settings->get( 'types' ) as $t ) {
			if ( isset( $all[ $t ] ) ) {
				$mimes[] = $all[ $t ]['mime'];
			}
		}
		return $mimes;
	}

	private function ext_of( $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpeg', 'jpe' ), true ) ? 'jpg' : $ext;
	}

	public function is_supported( $id ) {
		$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $rel || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return false;
		}
		return in_array( $this->ext_of( $rel ), (array) $this->settings->get( 'types' ), true );
	}

	/* ------------------------------------------------------------ path helpers */

	/** Remote sub-folder for a new attachment, from the "folder structure" setting. */
	public function compute_dir( $id ) {
		$ts = (int) get_post_time( 'U', true, $id );
		if ( ! $ts ) {
			$ts = time();
		}
		$parent = (int) wp_get_post_parent_id( $id );
		$tokens = array(
			'{year}'    => gmdate( 'Y', $ts ),
			'{month}'   => gmdate( 'm', $ts ),
			'{day}'     => gmdate( 'd', $ts ),
			'{post_id}' => $parent ? (string) $parent : 'unattached',
		);
		switch ( $this->settings->get( 'structure' ) ) {
			case 'year':
				$pattern = '{year}';
				break;
			case 'post_id':
				$pattern = '{post_id}';
				break;
			case 'flat':
				$pattern = '';
				break;
			case 'custom':
				$pattern = (string) $this->settings->get( 'custom_pattern' );
				break;
			default:
				$pattern = '{year}/{month}';
		}
		return $this->settings->clean_path( strtr( $pattern, $tokens ) );
	}

	/** A relative path inside the uploads folder: no absolute path, no "." / ".." segments. */
	private function safe_rel( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		if ( '' === $rel || '/' === $rel[0] || false !== strpos( $rel, "\0" ) || preg_match( '~^[A-Za-z]:~', $rel ) ) {
			return false;
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '' === $seg || '.' === $seg || '..' === $seg ) {
				return false;
			}
		}
		return true;
	}

	/** Local relative paths of every file to store for this attachment. */
	private function collect_files( $id, $meta ) {
		$rel = ( is_array( $meta ) && ! empty( $meta['file'] ) ) ? (string) $meta['file'] : (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $rel || ! $this->safe_rel( $rel ) ) {
			return array(); // Missing or unsafe metadata: never read outside the uploads folder.
		}
		$d   = dirname( $rel );
		$dir = ( '.' === $d || '' === $d ) ? '' : $d . '/';

		$files = array( $rel );
		if ( is_array( $meta ) ) {
			if ( $this->settings->get( 'upload_original' ) && ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . $meta['original_image'];
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$files[] = $dir . $size['file'];
					}
					if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
						foreach ( $size['sources'] as $src ) {
							if ( ! empty( $src['file'] ) ) {
								$files[] = $dir . $src['file'];
							}
						}
					}
				}
			}
			if ( ! empty( $meta['sources'] ) && is_array( $meta['sources'] ) ) {
				foreach ( $meta['sources'] as $src ) {
					if ( ! empty( $src['file'] ) ) {
						$files[] = $dir . $src['file'];
					}
				}
			}
		}
		$files = array_filter(
			$files,
			function ( $file ) {
				return $this->safe_rel( $file );
			}
		);
		return array_values( array_unique( $files ) );
	}

	private function safe_name( $name ) {
		return '' !== $name && '.' !== $name[0] && false === strpos( $name, '..' ) && ! preg_match( '/[\x00-\x1f\\\\\/]/', $name );
	}

	/** Git blob SHA-1 (streamed, so large files don't use much memory). */
	private function git_sha( $abs, $size ) {
		$ctx = hash_init( 'sha1' );
		hash_update( $ctx, 'blob ' . $size . "\0" );
		hash_update_file( $ctx, $abs );
		return hash_final( $ctx );
	}

	/* ------------------------------------------------------------------ hooks */

	/**
	 * The "Upload to GitHub" choice sent with this upload: true or false, or null when the
	 * request carries none — or one without a valid nonce, which is then ignored.
	 */
	private function upload_choice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified right here.
		if ( ! isset( $_REQUEST['cdnhub_offload'] ) ) {
			return null;
		}
		$nonce = isset( $_REQUEST['cdnhub_offload_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cdnhub_offload_nonce'] ) ) : '';
		if ( ! current_user_can( 'upload_files' ) || ! wp_verify_nonce( $nonce, self::OFFLOAD_NONCE ) ) {
			return null;
		}
		$val = sanitize_text_field( wp_unslash( $_REQUEST['cdnhub_offload'] ) );
		// phpcs:enable
		return in_array( $val, array( '1', 'true' ), true );
	}

	public function on_metadata( $metadata, $id ) {
		$id = (int) $id;
		if ( ! $this->settings->is_active() || isset( $this->busy[ $id ] ) || ! $this->is_supported( $id ) ) {
			return $metadata;
		}

		// Per-upload choice from the "Upload to GitHub" toggle.
		$offload = $this->upload_choice();
		if ( null !== $offload ) {
			if ( ! $offload ) {
				update_post_meta( $id, '_cdnhub_exclude', 1 );
				$this->logger->info( 'Upload option unchecked: image kept local on server.', $id );
				return $metadata;
			}
			// User explicitly selected to upload to GitHub CDN.
			delete_post_meta( $id, '_cdnhub_exclude' );
		} elseif ( ! metadata_exists( 'post', $id, '_cdnhub_status' ) && ! metadata_exists( 'post', $id, '_cdnhub_offloaded' ) && ! metadata_exists( 'post', $id, '_cdnhub_exclude' ) ) {
			// Newly uploaded file without explicit param; check default setting.
			if ( ! $this->settings->get( 'upload_to_cdn' ) ) {
				update_post_meta( $id, '_cdnhub_exclude', 1 );
				return $metadata;
			}
		}

		if ( CDNHub_Store::is_excluded( $id ) ) {
			return $metadata;
		}
		if ( ! $this->needs_sync( $id, $metadata ) ) {
			return $metadata;
		}

		if ( 'background' === $this->settings->get( 'upload_mode' ) ) {
			update_post_meta( $id, '_cdnhub_status', 'pending' );
			$this->schedule( $id, 3 );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			return $metadata;
		}

		$this->sync( $id, $metadata ); // Never throws; on failure the local file is simply kept.
		return $metadata;
	}

	/**
	 * True when this attachment (or one of its sizes) is not on the remote yet, or
	 * when a file was edited/replaced after it was uploaded. The stored blob SHA is
	 * compared with the current content, because the file name alone cannot tell a
	 * re-uploaded image from the original one.
	 */
	private function needs_sync( $id, $metadata ) {
		if ( ! CDNHub_Store::is_synced( $id ) ) {
			return true;
		}
		$map     = CDNHub_Store::map( $id );
		$upload  = wp_get_upload_dir();
		$basedir = trailingslashit( $upload['basedir'] );
		foreach ( $this->collect_files( $id, $metadata ) as $rel ) {
			$name = wp_basename( $rel );
			if ( ! isset( $map[ $name ] ) ) {
				return true;
			}
			$abs = $basedir . $rel;
			// A missing local file is normal when "delete local copy" is on: it cannot have changed.
			if ( is_readable( $abs ) && $this->git_sha( $abs, (int) filesize( $abs ) ) !== $map[ $name ] ) {
				return true;
			}
		}
		return false;
	}

	public function cron_sync( $id ) {
		if ( $this->settings->is_active() && get_post( $id ) ) {
			$this->sync( (int) $id );
		}
	}

	public function schedule( $id, $delay ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK, array( (int) $id ) );
		}
	}

	public function on_delete( $id ) {
		if ( ! $this->settings->is_active() || ! $this->settings->get( 'delete_remote' ) || ! CDNHub_Store::is_synced( $id ) ) {
			return;
		}
		$dir   = CDNHub_Store::dir( $id );
		$names = array_keys( CDNHub_Store::map( $id ) );
		if ( ! $names ) {
			foreach ( (array) get_post_meta( $id, '_cdnhub_file', false ) as $rel ) {
				$names[] = wp_basename( $rel );
			}
		}
		$paths = array();
		foreach ( array_unique( $names ) as $name ) {
			$paths[] = $this->urls->remote_path( $dir, $name );
		}
		if ( ! $paths ) {
			return;
		}

		$provider = $this->provider();
		if ( ! $provider ) {
			$this->logger->error( 'Could not delete the attachment files from remote storage: the storage provider is unavailable.', $id );
			return;
		}
		$ok = true;
		if ( $provider instanceof CDNHub_Batch_Provider ) {
			$r = $provider->delete_many( $paths, sprintf( 'CDNHub: delete %s (%d file(s))', wp_basename( $paths[0] ), count( $paths ) ) );
			if ( is_wp_error( $r ) ) {
				$ok = false;
				$this->logger->error( 'Could not delete the files from remote storage: ' . $r->get_error_message(), $id );
			}
		} else {
			foreach ( $paths as $path ) {
				$r = $provider->delete( $path );
				if ( is_wp_error( $r ) ) {
					$ok = false;
					$this->logger->error( 'Could not delete ' . wp_basename( $path ) . ' from remote storage: ' . $r->get_error_message(), $id );
				}
			}
		}
		$this->purge( $paths, $id );
		if ( $ok ) {
			$this->logger->info( 'Deleted attachment files from remote storage.', $id );
		}
	}

	/**
	 * Ask the CDN to drop cached copies of changed or deleted files. Requests are
	 * blocking so failures can be reported: a stale CDN cache would otherwise hide
	 * an upload for days with no explanation in the log. A total time budget keeps
	 * a slow CDN from holding the request open for every single file size.
	 */
	private function purge( array $paths, $id ) {
		$urls = $this->urls->purge_urls( $paths );
		$ok   = 0;
		$end  = microtime( true ) + 10;
		foreach ( $urls as $url ) {
			$left = $end - microtime( true );
			if ( $left < 0.5 ) {
				$this->logger->error( 'Skipped the CDN purge request for ' . $url . ': the time budget was used up.', $id );
				continue;
			}
			$r = wp_remote_get(
				$url,
				array(
					'timeout'  => min( 5, $left ),
					'blocking' => true,
				)
			);
			if ( is_wp_error( $r ) ) {
				$this->logger->error( 'Could not ask the CDN to refresh ' . $url . ': ' . $r->get_error_message(), $id );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( $code < 200 || $code >= 400 ) {
				$this->logger->error( sprintf( 'The CDN purge request for %s returned HTTP %d.', $url, $code ), $id );
				continue;
			}
			$ok++;
		}
		if ( $urls ) {
			$this->logger->info(
				sprintf(
					'Asked the CDN to refresh %d file(s)%s.',
					count( $urls ),
					$ok === count( $urls ) ? '' : sprintf( ' (%d request(s) failed)', count( $urls ) - $ok )
				),
				$id
			);
		}
	}

	/* -------------------------------------------------------------- lifecycle */

	/** Plugin activation: queue the uploads that were waiting when the plugin was switched off. */
	public static function activate() {
		global $wpdb;
		$settings = new CDNHub_Settings();
		$retry    = (bool) $settings->get( 'auto_retry' );
		$max      = (int) $settings->get( 'max_attempts' );
		$rows     = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_cdnhub_status' AND meta_value IN ('pending', 'failed')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$delay    = 10;
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->post_id;
			if ( 'failed' === $row->meta_value && ( ! $retry || (int) get_post_meta( $id, '_cdnhub_attempts', true ) >= $max ) ) {
				continue;
			}
			if ( ! wp_next_scheduled( self::CRON_HOOK, array( $id ) ) ) {
				wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $id ) );
				$delay += 5; // Spread out, to stay clear of GitHub's rate limits.
			}
		}
	}

	/** Plugin deactivation: no CDNHub cron events may fire while the plugin is off. */
	public static function deactivate() {
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/* ------------------------------------------------------- keep it local */

	/**
	 * Keep one attachment on this server: no uploads, no CDN URLs. If the image was
	 * already offloaded and its local copy deleted, files are downloaded back from
	 * the remote storage first — the exclusion only takes effect when every file
	 * that is on the remote exists locally again, so nothing on the site breaks.
	 *
	 * @return array{ok:bool,message:string} Plus restored (number of files downloaded).
	 */
	public function keep_local( $id ) {
		$id = (int) $id;
		if ( ! $this->is_supported( $id ) && ! CDNHub_Store::is_synced( $id ) ) {
			return $this->result( false, 'CDNHub does not manage this file.' );
		}
		if ( CDNHub_Store::is_excluded( $id ) ) {
			return $this->result( true, 'Already kept on this server.' );
		}

		$restored = 0;
		if ( CDNHub_Store::is_synced( $id ) ) {
			$upload  = wp_get_upload_dir();
			$basedir = trailingslashit( $upload['basedir'] );
			$dir     = CDNHub_Store::dir( $id );
			$map     = CDNHub_Store::map( $id );
			$meta    = wp_get_attachment_metadata( $id );
			$files   = $this->collect_files( $id, is_array( $meta ) ? $meta : array() );
			if ( ! $files ) {
				$fallback = (string) get_post_meta( $id, '_wp_attached_file', true );
				if ( $this->safe_rel( $fallback ) ) {
					$files = array( $fallback );
				}
			}

			$provider    = null;
			$no_provider = false;
			$failed      = array();
			foreach ( $files as $rel ) {
				$abs = $basedir . $rel;
				if ( file_exists( $abs ) ) {
					continue;
				}
				$name = wp_basename( $rel );
				if ( $map && ! isset( $map[ $name ] ) ) {
					continue; // Was never uploaded: nothing to bring back.
				}
				if ( $no_provider ) {
					$failed[] = $name . ' (the storage provider is unavailable)';
					continue;
				}
				if ( null === $provider ) {
					$provider = $this->provider();
					if ( ! $provider ) {
						$no_provider = true;
						$failed[]    = $name . ' (the storage provider is unavailable)';
						continue;
					}
				}
				if ( ! method_exists( $provider, 'get' ) ) {
					$failed[] = $name . ' (the storage provider cannot download)';
					continue;
				}
				$bin = $provider->get( $this->urls->remote_path( $dir, $name ) );
				if ( is_wp_error( $bin ) || ! is_string( $bin ) ) {
					$failed[] = $name . ( is_wp_error( $bin ) ? ' (' . $bin->get_error_message() . ')' : '' );
					continue;
				}
				wp_mkdir_p( dirname( $abs ) );
				if ( false === file_put_contents( $abs, $bin ) ) { // phpcs:ignore
					$failed[] = $name . ' (could not write the local file)';
					continue;
				}
				$restored++;
			}

			if ( $failed ) {
				return $this->result(
					false,
					'Could not restore from GitHub: ' . implode( '; ', $failed ) . '. The image stays on the CDN — nothing was changed.'
				);
			}
		}

		update_post_meta( $id, '_cdnhub_exclude', 1 );
		delete_post_meta( $id, '_cdnhub_offloaded' );
		delete_post_meta( $id, '_cdnhub_status' );
		delete_post_meta( $id, '_cdnhub_error' );
		delete_post_meta( $id, '_cdnhub_attempts' );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) ); // In case a retry was queued.

		$name = wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$this->logger->info( sprintf( 'Kept on this server%s — CDNHub will not upload it or rewrite its URLs.', $restored ? ' (' . $restored . ' file(s) downloaded back from GitHub)' : '' ), $id );

		return $this->result( true, 'Now served from your own server.', array( 'restored' => $restored, 'name' => $name ) );
	}

	/** Remove the exclusion and upload the attachment to the remote storage again. */
	public function use_cdn( $id ) {
		$id = (int) $id;
		if ( ! CDNHub_Store::is_excluded( $id ) ) {
			return $this->result( false, 'This image is not excluded from the CDN.' );
		}
		delete_post_meta( $id, '_cdnhub_exclude' );
		if ( ! $this->settings->is_active() ) {
			return $this->result( true, 'CDNHub is paused or not configured — use “Send existing images” in Tools → CDNHub → Tools once it is active.' );
		}
		if ( ! $this->is_supported( $id ) ) {
			return $this->result( true, 'Exclusion removed, but this file type is not enabled for upload.' );
		}
		$this->logger->info( 'CDN re-enabled for this image.', $id );
		return $this->sync( $id, null, false );
	}

	/* --------------------------------------------------------------- adoption */

	/**
	 * Fetch (and cache for 15 minutes) the whole remote tree as two indexes:
	 * 'paths' => path => blob SHA, and 'by_sha' => blob SHA => paths with that content.
	 * Keeping every path (not just the first) matters: the same logo can exist in
	 * several folders, and only some of them are inside the media folder.
	 *
	 * @return array|WP_Error
	 */
	public function load_index() {
		$index = get_transient( self::INDEX_KEY );
		if ( is_array( $index ) && ! empty( $index['paths'] ) ) {
			return $index;
		}
		$provider = $this->provider();
		if ( ! $provider || ! method_exists( $provider, 'tree' ) ) {
			return new WP_Error( 'cdnhub_tree', 'The active storage provider cannot list remote files, so adopting is not available.' );
		}
		$tree = $provider->tree();
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		if ( ! empty( $tree['truncated'] ) ) {
			return new WP_Error( 'cdnhub_tree', 'The repository tree is too large for GitHub to return at once, so adopting is not available. Use the upload tool instead.' );
		}
		$by_sha = array();
		foreach ( $tree['paths'] as $path => $sha ) {
			$by_sha[ $sha ][] = $path;
		}
		$index = array(
			'paths'  => $tree['paths'],
			'by_sha' => $by_sha,
		);
		set_transient( self::INDEX_KEY, $index, 15 * MINUTE_IN_SECONDS );
		return $index;
	}

	public function drop_index() {
		delete_transient( self::INDEX_KEY );
	}

	/**
	 * Mark an attachment as synced when its files are already on the remote storage —
	 * matched by Git blob SHA, so nothing is re-uploaded. Sizes that are not found
	 * remotely are completed right away with a normal (differential) upload.
	 *
	 * @return array{ok:bool,message:string} Plus found|complete|url|rate_limited.
	 */
	public function adopt( $id, $index ) {
		$id = (int) $id;
		if ( isset( $this->busy[ $id ] ) ) {
			return $this->result( false, 'Already syncing.' );
		}
		$this->busy[ $id ] = true;
		try {
			return $this->do_adopt( $id, $index );
		} catch ( \Throwable $e ) {
			return $this->fail( $id, 'Unexpected error: ' . $e->getMessage() );
		} finally {
			unset( $this->busy[ $id ] );
		}
	}

	private function do_adopt( $id, $index ) {
		if ( ! $this->settings->is_ready() ) {
			return $this->result( false, 'CDNHub is not configured yet.', array( 'found' => false ) );
		}
		if ( CDNHub_Store::is_excluded( $id ) ) {
			return $this->result( false, 'This image is kept on this server on purpose (excluded from the CDN).', array( 'found' => false ) );
		}
		if ( ! $this->is_supported( $id ) ) {
			return $this->result( false, 'This file type is not enabled for upload.', array( 'found' => false ) );
		}
		$until = (int) get_transient( self::RL_KEY );
		if ( $until > time() ) {
			return $this->result( false, 'GitHub rate limit is active. Try again in a few minutes.', array( 'found' => false, 'rate_limited' => true ) );
		}

		$paths  = isset( $index['paths'] ) && is_array( $index['paths'] ) ? $index['paths'] : array();
		$by_sha = isset( $index['by_sha'] ) && is_array( $index['by_sha'] ) ? $index['by_sha'] : array();
		if ( ! $paths ) {
			return $this->result( false, 'The remote storage is empty.', array( 'found' => false ) );
		}

		$meta  = wp_get_attachment_metadata( $id );
		$meta  = is_array( $meta ) ? $meta : array();
		$files = $this->collect_files( $id, $meta );
		if ( ! $files ) {
			return $this->result( false, 'No file is recorded for this attachment.', array( 'found' => false ) );
		}

		$upload  = wp_get_upload_dir();
		$basedir = trailingslashit( $upload['basedir'] );
		$max     = (int) ( (float) $this->settings->get( 'max_size_mb' ) * MB_IN_BYTES );
		$folder  = (string) $this->settings->get( 'folder' );
		$prefix  = '' !== $folder ? $folder . '/' : '';
		$provider = null;

		// The main file decides: where its content lives remotely is where the attachment is adopted from.
		$main = wp_basename( $files[0] );
		$abs  = $basedir . $files[0];
		if ( ! $this->safe_name( $main ) ) {
			return $this->result( false, 'Unsafe file name: ' . $main, array( 'found' => false ) );
		}
		if ( ! is_readable( $abs ) ) {
			// Auto-recovery: check if the expected remote path exists in remote index
			$expected_dir  = dirname( $files[0] );
			$expected_path = $prefix . ( ( '.' === $expected_dir || '' === $expected_dir ) ? '' : $expected_dir . '/' ) . $main;
			if ( isset( $paths[ $expected_path ] ) ) {
				$provider = $this->provider();
				if ( $provider && method_exists( $provider, 'get' ) ) {
					$downloaded = $provider->get( $expected_path );
					if ( ! is_wp_error( $downloaded ) && is_string( $downloaded ) && '' !== $downloaded ) {
						wp_mkdir_p( dirname( $abs ) );
						file_put_contents( $abs, $downloaded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
					}
				}
			}
		}
		if ( ! is_readable( $abs ) ) {
			return $this->result( false, 'Local file not found: ' . $main, array( 'found' => false ) );
		}
		$size = (int) filesize( $abs );
		if ( $size > $max ) {
			return $this->result( false, $main . ' is larger than your ' . $this->settings->get( 'max_size_mb' ) . ' MB limit.', array( 'found' => false ) );
		}
		$sha  = $this->git_sha( $abs, $size );
		$hits = isset( $by_sha[ $sha ] ) ? (array) $by_sha[ $sha ] : array();
		$hit  = null;
		foreach ( $hits as $candidate ) {
			$candidate = (string) $candidate;
			// Prefer a copy inside the media folder: other copies may belong to unrelated projects.
			if ( 0 === strpos( $candidate, $prefix ) ) {
				$hit = $candidate;
				break;
			}
		}
		if ( null === $hit ) {
			return $this->result( false, 'Not on the remote storage yet (or outside the media folder).', array( 'found' => false ) );
		}
		$rest = '' === $prefix ? $hit : substr( $hit, strlen( $prefix ) );
		$d    = dirname( $rest );
		$dir  = ( '.' === $d || '' === $d ) ? '' : $d;

		// Accept every size that sits in the same remote folder with identical content.
		$map  = array( $main => $sha );
		$rels = array( $files[0] );
		foreach ( array_slice( $files, 1 ) as $rel ) {
			$name = wp_basename( $rel );
			$abs2 = $basedir . $rel;
			if ( ! $this->safe_name( $name ) ) {
				continue;
			}
			$p2 = $prefix . ( '' !== $dir ? $dir . '/' : '' ) . $name;
			if ( ! is_readable( $abs2 ) && isset( $paths[ $p2 ] ) ) {
				if ( null === $provider ) {
					$provider = $this->provider();
				}
				if ( $provider && method_exists( $provider, 'get' ) ) {
					$dl = $provider->get( $p2 );
					if ( ! is_wp_error( $dl ) && is_string( $dl ) && '' !== $dl ) {
						wp_mkdir_p( dirname( $abs2 ) );
						file_put_contents( $abs2, $dl ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
					}
				}
			}
			if ( ! is_readable( $abs2 ) ) {
				continue;
			}
			$s2 = (int) filesize( $abs2 );
			if ( $s2 > $max ) {
				continue;
			}
			$h2 = $this->git_sha( $abs2, $s2 );
			if ( isset( $paths[ $p2 ] ) && $paths[ $p2 ] === $h2 ) {
				$map[ $name ] = $h2;
				$rels[]       = $rel;
			}
		}

		$complete = count( $map ) === count( $files );

		update_post_meta( $id, '_cdnhub_dir', $dir );
		update_post_meta( $id, '_cdnhub_map', $map );
		delete_post_meta( $id, '_cdnhub_file' );
		foreach ( $rels as $rel ) {
			add_post_meta( $id, '_cdnhub_file', $rel );
		}
		update_post_meta( $id, '_cdnhub_offloaded', 1 );
		update_post_meta( $id, '_cdnhub_status', 'synced' );
		delete_post_meta( $id, '_cdnhub_error' );
		delete_post_meta( $id, '_cdnhub_attempts' );

		$url = $this->urls->for_file( $dir, $main );
		$this->logger->info( sprintf( 'Adopted %s — %d of %d file(s) were already on GitHub → %s', $main, count( $map ), count( $files ), $url ), $id );

		if ( $this->settings->get( 'delete_local' ) && $complete ) {
			foreach ( $rels as $rel ) {
				@unlink( $basedir . $rel ); // phpcs:ignore
			}
		}

		$r = $this->result( true, 'Adopted.', array( 'found' => true, 'complete' => $complete, 'url' => $url ) );

		if ( ! $complete ) {
			$r2 = $this->do_sync( $id, null, false ); // Uploads only the sizes the index did not have.
			$r['complete'] = ! empty( $r2['ok'] );
			if ( empty( $r2['ok'] ) ) {
				$r['message'] = 'Adopted, but completing the missing sizes failed: ' . $r2['message'];
				if ( ! empty( $r2['rate_limited'] ) ) {
					$r['rate_limited'] = true;
				}
			}
		}
		return $r;
	}

	/**
	 * Adopt un-synced attachments in small batches (cursor: attachment IDs greater than $last).
	 *
	 * @return array Summary for the admin tool; 'done' when nothing is left, 'stop' on rate limits.
	 */
	public function adopt_batch( $index, $last, $limit = 5 ) {
		global $wpdb;
		$q = $this->unsynced_sql( 'p.ID', $last );
		if ( ! $q ) {
			return array( 'done' => true );
		}
		$ids = $wpdb->get_col( $q . ' ORDER BY p.ID ASC LIMIT ' . max( 1, (int) $limit ) ); // phpcs:ignore
		if ( ! $ids ) {
			return array( 'done' => true );
		}
		$out = array(
			'done'     => false,
			'last'     => 0,
			'adopted'  => 0,
			'completed' => 0,
			'missing'  => 0,
			'failures' => 0,
			'messages' => array(),
			'stop'     => false,
		);
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$out['last'] = $id;
			if ( ! $this->is_supported( $id ) ) {
				$out['missing']++;
				continue;
			}
			$r = $this->adopt( $id, $index );
			if ( ! empty( $r['rate_limited'] ) ) {
				$out['stop']    = true;
				$out['message'] = $r['message'];
				break;
			}
			if ( ! empty( $r['found'] ) && $r['ok'] ) {
				$out['adopted']++;
				if ( ! empty( $r['complete'] ) ) {
					$out['completed']++;
				}
			} elseif ( empty( $r['found'] ) ) {
				$out['missing']++;
			} else {
				$out['failures']++;
				if ( count( $out['messages'] ) < 20 ) {
					$out['messages'][] = 'Image #' . $id . ': ' . $r['message'];
				}
			}
		}
		$out['remaining'] = (int) $wpdb->get_var( $this->unsynced_sql( 'COUNT(*)', $out['last'] ) ); // phpcs:ignore
		return $out;
	}

	/**
	 * Delete local copies of attachments that are safely on the remote storage.
	 * A file is only removed when the stored blob SHA still matches its content, so
	 * images edited after their sync are skipped and keep their local file.
	 *
	 * @return array Summary for the admin tool; 'done' when nothing is left.
	 */
	public function cleanup_batch( $last, $limit = 20 ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_cdnhub_offloaded')
				LEFT JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
				WHERE p.post_type = 'attachment' AND x.meta_id IS NULL AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				(int) $last,
				max( 1, (int) $limit )
			)
		);
		if ( ! $ids ) {
			return array( 'done' => true );
		}
		$upload  = wp_get_upload_dir();
		$basedir = trailingslashit( $upload['basedir'] );
		$out     = array(
			'done'     => false,
			'last'     => 0,
			'images'   => 0,
			'deleted'  => 0,
			'skipped'  => 0,
			'failures' => 0,
		);
		if ( ! $last ) {
			// Full count once, for the progress bar on the first request.
			$out['total'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_cdnhub_offloaded')
				LEFT JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
				WHERE p.post_type = 'attachment' AND x.meta_id IS NULL"
			);
		}
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$out['last'] = $id;
			$map         = CDNHub_Store::map( $id );
			if ( ! $map ) {
				continue; // Synced by version 1.x without a map: too little info to delete safely.
			}
			$meta  = wp_get_attachment_metadata( $id );
			$files = $this->collect_files( $id, is_array( $meta ) ? $meta : array() );
			$touched = false;
			foreach ( $files as $rel ) {
				$abs = $basedir . $rel;
				if ( ! file_exists( $abs ) ) {
					continue; // Already gone.
				}
				$name = wp_basename( $rel );
				if ( ! is_readable( $abs ) || ! isset( $map[ $name ] ) || $this->git_sha( $abs, (int) filesize( $abs ) ) !== $map[ $name ] ) {
					$out['skipped']++;
					continue; // Not on the remote (or edited since): keep it.
				}
				if ( @unlink( $abs ) ) { // phpcs:ignore
					$out['deleted']++;
					$touched = true;
				} elseif ( file_exists( $abs ) ) {
					$out['failures']++;
					$this->logger->error( 'Could not delete the local copy of ' . $name . ' (check file permissions).', $id );
				}
			}
			if ( $touched ) {
				$out['images']++;
			}
		}
		$out['remaining'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_cdnhub_offloaded')
				LEFT JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
				WHERE p.post_type = 'attachment' AND x.meta_id IS NULL AND p.ID > %d",
				$out['last']
			)
		);
		return $out;
	}

	/* ------------------------------------------------------------------- sync */

	private function result( $ok, $message, $extra = array() ) {
		return array_merge(
			array(
				'ok'      => $ok,
				'message' => $message,
			),
			$extra
		);
	}

	/**
	 * Upload one attachment (all sizes). Safe to call repeatedly: unchanged files are skipped.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function sync( $id, $metadata = null, $force = false ) {
		$id = (int) $id;
		if ( isset( $this->busy[ $id ] ) ) {
			return $this->result( false, 'Already syncing.' );
		}
		$this->busy[ $id ] = true;
		try {
			return $this->do_sync( $id, $metadata, (bool) $force );
		} catch ( \Throwable $e ) {
			return $this->fail( $id, 'Unexpected error: ' . $e->getMessage() );
		} finally {
			unset( $this->busy[ $id ] );
		}
	}

	private function do_sync( $id, $metadata, $force ) {
		$s = $this->settings;
		if ( ! $s->is_ready() ) {
			return $this->result( false, 'CDNHub is not configured yet.' );
		}
		if ( CDNHub_Store::is_excluded( $id ) ) {
			return $this->result( false, 'This image is kept on this server on purpose (excluded from the CDN). Use “Use CDN” to change that.' );
		}
		if ( ! $this->is_supported( $id ) ) {
			return $this->result( false, 'This file type is not enabled for upload.' );
		}
		$until = (int) get_transient( self::RL_KEY );
		if ( $until > time() ) {
			return $this->fail( $id, 'GitHub rate limit is active. Will retry automatically.', $until - time(), true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}

		$meta = ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) ? $metadata : wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$files = $this->collect_files( $id, $meta );
		if ( ! $files ) {
			return $this->fail( $id, 'No file is recorded for this attachment.' );
		}

		$upload    = wp_get_upload_dir();
		$basedir   = trailingslashit( $upload['basedir'] );
		$max_bytes = (int) ( (float) $s->get( 'max_size_mb' ) * MB_IN_BYTES );
		$conflict  = (string) $s->get( 'conflict' );
		$provider  = $this->provider();
		if ( ! $provider ) {
			return $this->fail( $id, 'The storage provider is unavailable. Check the cdnhub_provider filter.', null, false, false );
		}

		// Already-synced attachments keep their remote folder so existing URLs never change.
		// Re-included (previously excluded) ones keep theirs too — and their file map, so
		// unchanged files are verified locally without any API call.
		$dir = metadata_exists( 'post', $id, '_cdnhub_dir' ) ? CDNHub_Store::dir( $id ) : $this->compute_dir( $id );
		$map = CDNHub_Store::map( $id );

		$second = false;
		$main   = wp_basename( $files[0] );

		while ( true ) {
			$new_map = array();
			$pending = array(); // name => file to send.
			$same    = 0;

			foreach ( $files as $rel ) {
				$name = wp_basename( $rel );
				$abs  = $basedir . $rel;

				if ( ! $this->safe_name( $name ) ) {
					return $this->fail( $id, 'Unsafe file name skipped: ' . $name );
				}
				if ( ! is_readable( $abs ) ) {
					// Auto-recovery: If the file already exists on remote storage, restore it locally first.
					if ( method_exists( $provider, 'get' ) ) {
						$rel_dir     = dirname( $rel );
						$rel_dir     = ( '.' === $rel_dir || '' === $rel_dir ) ? '' : $rel_dir;
						$remote_dirs = array_unique( array_filter( array( $dir, $rel_dir ), 'strlen' ) );
						foreach ( $remote_dirs as $try_dir ) {
							$remote_file = $this->urls->remote_path( $try_dir, $name );
							$downloaded  = $provider->get( $remote_file );
							if ( ! is_wp_error( $downloaded ) && is_string( $downloaded ) && '' !== $downloaded ) {
								wp_mkdir_p( dirname( $abs ) );
								file_put_contents( $abs, $downloaded );
								$dir = $try_dir;
								$this->logger->info( sprintf( 'Restored missing local file %s from remote storage (%s).', $name, $remote_file ), $id );
								break;
							}
						}
					}
				}
				if ( ! is_readable( $abs ) ) {
					return $this->fail( $id, 'Local file not found: ' . $name );
				}
				$size = (int) filesize( $abs );
				if ( $size > $max_bytes ) {
					return $this->fail( $id, $name . ' is larger than your ' . $s->get( 'max_size_mb' ) . ' MB limit. The local file is used instead.' );
				}
				if ( 'svg' === $this->ext_of( $name ) && ! CDNHub_SVG::is_safe_file( $abs ) ) {
					return $this->fail( $id, $name . ' was not uploaded: the SVG contains potentially unsafe code (or is larger than 5 MB).', null, false, false );
				}

				$sha = $this->git_sha( $abs, $size );
				if ( ! $force && isset( $map[ $name ] ) && $map[ $name ] === $sha ) {
					$new_map[ $name ] = $sha;
					$same++;
					continue;
				}

				$pending[ $name ] = array(
					'abs'       => $abs,
					'sha'       => $sha,
					// Files this attachment already stored are ours to update; the conflict policy only protects foreign files.
					'overwrite' => isset( $map[ $name ] ) || 'overwrite' === $conflict,
				);
			}

			// Remote paths are built last: restoring a missing file above may have changed $dir.
			$batch = array();
			foreach ( $pending as $name => $f ) {
				$batch[ $this->urls->remote_path( $dir, $name ) ] = $f + array( 'name' => $name );
			}
			$message = 'CDNHub: add ' . $main . ( count( $batch ) > 1 ? ' (' . count( $batch ) . ' files)' : '' );
			$stored  = $this->store( $provider, $batch, $message );

			if ( is_wp_error( $stored ) && 'cdnhub_conflict' === $stored->get_error_code() && 'unique' === $conflict && ! $second ) {
				// A different file already uses this name: move the whole attachment to a unique sub-folder.
				$first  = $files[0];
				$suffix = substr( $this->git_sha( $basedir . $first, (int) filesize( $basedir . $first ) ), 0, 8 );
				$dir    = trim( $dir . '/' . $suffix, '/' );
				$map    = array();
				$second = true;
				continue;
			}
			if ( is_wp_error( $stored ) ) {
				return $this->fail_from_error( $id, $stored );
			}
			break;
		}

		$sent    = 0;
		$updated = array();
		foreach ( $batch as $path => $f ) {
			$new_map[ $f['name'] ] = $f['sha'];
			$status                = isset( $stored[ $path ] ) ? $stored[ $path ] : 'created';
			if ( 'unchanged' === $status ) {
				$same++;
			} else {
				$sent++;
			}
			if ( 'updated' === $status ) {
				$updated[] = $path;
			}
		}
		if ( $updated ) {
			$this->purge( $updated, $id );
		}

		update_post_meta( $id, '_cdnhub_dir', $dir );
		update_post_meta( $id, '_cdnhub_map', $new_map );
		delete_post_meta( $id, '_cdnhub_file' );
		foreach ( $files as $rel ) {
			add_post_meta( $id, '_cdnhub_file', $rel );
		}
		update_post_meta( $id, '_cdnhub_offloaded', 1 );
		update_post_meta( $id, '_cdnhub_status', 'synced' );
		delete_post_meta( $id, '_cdnhub_error' );
		delete_post_meta( $id, '_cdnhub_attempts' );

		$url = $this->urls->for_file( $dir, $main );
		$this->logger->info( sprintf( 'Synced %s (%d uploaded, %d already up to date) → %s', $main, $sent, $same, $url ), $id );

		if ( $s->get( 'delete_local' ) ) {
			foreach ( $files as $rel ) {
				@unlink( $basedir . $rel ); // phpcs:ignore
			}
		}

		return $this->result( true, 'Synced.', array( 'url' => $url ) );
	}

	/**
	 * Send files to the provider: as one change when it supports batches, otherwise file by file.
	 *
	 * @return array|WP_Error remote path => created|updated|unchanged
	 */
	private function store( $provider, array $batch, $message ) {
		if ( ! $batch ) {
			return array();
		}
		if ( $provider instanceof CDNHub_Batch_Provider ) {
			return $provider->put_many( $batch, $message );
		}
		$out = array();
		foreach ( $batch as $path => $f ) {
			$r = $provider->put( $f['abs'], $path, $f['sha'], $f['overwrite'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$out[ $path ] = $r['status'];
		}
		return $out;
	}

	/* --------------------------------------------------------------- failures */

	private function fail_from_error( $id, WP_Error $e ) {
		$retry = null;
		$rl    = false;
		if ( 'cdnhub_rate_limited' === $e->get_error_code() ) {
			$data  = $e->get_error_data();
			$retry = isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 60;
			set_transient( self::RL_KEY, time() + $retry, $retry );
			$rl = true;
		}
		return $this->fail( $id, $e->get_error_message(), $retry, $rl, ! in_array( $e->get_error_code(), self::PERMANENT_ERRORS, true ) );
	}

	/**
	 * Record a failure, schedule an automatic retry (unless $can_retry is false: retrying
	 * would fail the same way) and return a result array. Local files are never touched.
	 */
	private function fail( $id, $message, $retry_after = null, $rate_limited = false, $can_retry = true ) {
		update_post_meta( $id, '_cdnhub_status', 'failed' );
		update_post_meta( $id, '_cdnhub_error', $message );
		$attempts = (int) get_post_meta( $id, '_cdnhub_attempts', true ) + 1;
		update_post_meta( $id, '_cdnhub_attempts', $attempts );
		$this->logger->error( $message, $id );

		if ( $can_retry && $this->settings->get( 'auto_retry' ) && $attempts < (int) $this->settings->get( 'max_attempts' ) ) {
			$delay = $retry_after ? (int) $retry_after : (int) min( 21600, 300 * pow( 2, $attempts - 1 ) );
			$this->schedule( $id, $delay );
		}
		return $this->result( false, $message, array( 'rate_limited' => (bool) $rate_limited ) );
	}

	/* ------------------------------------------------------------- statistics */

	/**
	 * Re-include excluded attachments in batches and sync them to CDN.
	 *
	 * @param int $last  Attachment ID cursor (process IDs greater than $last).
	 * @param int $limit Batch size.
	 * @return array Summary for the admin tool; 'done' when nothing is left.
	 */
	public function reinclude_batch( $last, $limit = 10 ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
				WHERE p.post_type = 'attachment' AND p.ID > %d
				ORDER BY p.ID ASC LIMIT %d",
				(int) $last,
				max( 1, (int) $limit )
			)
		);
		if ( ! $ids ) {
			return array( 'done' => true );
		}
		$out = array(
			'done'       => false,
			'last'       => 0,
			'reincluded' => 0,
			'failures'   => 0,
			'messages'   => array(),
			'stop'       => false,
		);
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$out['last'] = $id;
			$r = $this->use_cdn( $id );
			if ( ! empty( $r['rate_limited'] ) ) {
				$out['stop']    = true;
				$out['message'] = $r['message'];
				break;
			}
			if ( ! empty( $r['ok'] ) ) {
				$out['reincluded']++;
			} else {
				$out['failures']++;
				if ( count( $out['messages'] ) < 20 ) {
					$out['messages'][] = 'Image #' . $id . ': ' . $r['message'];
				}
			}
		}
		$out['remaining'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
				WHERE p.post_type = 'attachment' AND p.ID > %d",
				$out['last']
			)
		);
		return $out;
	}

	public function counts() {
		global $wpdb;
		$pm = $wpdb->postmeta;
		// Images kept on this server are reported separately; they must not also count
		// as synced, queued or failed (their old status meta may still be present).
		$ex = "SELECT post_id FROM {$pm} WHERE meta_key = '_cdnhub_exclude'";
		return array(
			'synced'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm} WHERE meta_key = '_cdnhub_offloaded' AND post_id NOT IN ({$ex})" ),
			'failed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm} WHERE meta_key = '_cdnhub_status' AND meta_value = 'failed' AND post_id NOT IN ({$ex})" ),
			'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm} WHERE meta_key = '_cdnhub_status' AND meta_value = 'pending' AND post_id NOT IN ({$ex})" ),
			'local'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pm} WHERE meta_key = '_cdnhub_exclude'" ),
		);
	}

	/** Prepared SQL for allowed-type images that are not on the remote yet (ID greater than $last). Excluded images are skipped. */
	public function unsynced_sql( $select, $last = 0 ) {
		global $wpdb;
		$mimes = $this->allowed_mimes();
		if ( ! $mimes ) {
			return null;
		}
		$ph  = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$sql = "SELECT {$select} FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_cdnhub_offloaded')
			LEFT JOIN {$wpdb->postmeta} x ON (x.post_id = p.ID AND x.meta_key = '_cdnhub_exclude')
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$ph}) AND m.meta_id IS NULL AND x.meta_id IS NULL AND p.ID > %d";
		return $wpdb->prepare( $sql, array_merge( $mimes, array( (int) $last ) ) ); // phpcs:ignore
	}
}
