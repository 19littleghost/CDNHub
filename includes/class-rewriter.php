<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swaps local upload URLs for CDN URLs (only for files that are really on the remote storage).
 * Images marked "keep on this server" are never swapped — and CDN URLs already stored in
 * content are swapped back to local ones for those images.
 */
final class CDNHub_Rewriter {

	private $settings;
	private $urls;
	private $lookup_cache = array();
	private $override_cache;

	public function __construct( CDNHub_Settings $settings, CDNHub_URLs $urls ) {
		$this->settings = $settings;
		$this->urls     = $urls;

		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 20, 2 );
		add_filter( 'wp_get_original_image_url', array( $this, 'original_url' ), 20, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'srcset' ), 20, 5 );
		add_filter( 'the_content', array( $this, 'content' ), 99 );
		add_filter( 'widget_text_content', array( $this, 'content' ), 99 );
		add_filter( 'widget_block_content', array( $this, 'content' ), 99 );
		add_filter( 'content_save_pre', array( $this, 'content_on_save' ), 99 );
	}

	private function strip_scheme( $url ) {
		return preg_replace( '#^https?:#i', '', (string) $url );
	}

	/** Main file URL (thumbnails/sizes are derived from this by WordPress). */
	public function attachment_url( $url, $id ) {
		if ( ! $id || ! $this->settings->is_active() || CDNHub_Store::is_excluded( $id ) || ! CDNHub_Store::is_synced( $id ) ) {
			return $url;
		}
		$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' === $rel ) {
			return $url;
		}
		return $this->urls->for_file( CDNHub_Store::dir( $id ), wp_basename( $rel ) );
	}

	public function original_url( $url, $id ) {
		if ( ! $id || ! $this->settings->is_active() || CDNHub_Store::is_excluded( $id ) || ! CDNHub_Store::is_synced( $id ) ) {
			return $url;
		}
		$name = wp_basename( (string) $url );
		if ( CDNHub_Store::has_file( $id, $name ) ) {
			return $this->urls->for_file( CDNHub_Store::dir( $id ), $name );
		}
		// The unscaled original was not uploaded: use the local copy.
		$upload = wp_get_upload_dir();
		$d      = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		return trailingslashit( $upload['baseurl'] ) . ( ( '.' === $d || '' === $d ) ? '' : $d . '/' ) . $name;
	}

	public function srcset( $sources, $size_array, $image_src, $image_meta, $id ) {
		if ( ! $id || ! is_array( $sources ) || ! $this->settings->is_active() || CDNHub_Store::is_excluded( $id ) || ! CDNHub_Store::is_synced( $id ) ) {
			return $sources;
		}
		$dir = CDNHub_Store::dir( $id );
		foreach ( $sources as $k => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$name = wp_basename( strtok( $source['url'], '?' ) );
			if ( CDNHub_Store::has_file( $id, $name ) ) {
				$sources[ $k ]['url'] = $this->urls->for_file( $dir, $name );
			}
		}
		return $sources;
	}

	/** Existing posts: show CDN URLs for images that were synced after the post was written. */
	public function content( $content ) {
		if ( ! $this->settings->get( 'rewrite_content' ) || ! $this->settings->is_active() ) {
			return $content;
		}
		return $this->rewrite( $content );
	}

	/** Optional: store CDN URLs permanently when a post is saved. */
	public function content_on_save( $content ) {
		if ( ! $this->settings->get( 'save_cdn_urls' ) || ! $this->settings->is_active() ) {
			return $content;
		}
		return $this->rewrite( $content );
	}

	private function rewrite( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Images switched back to "keep on this server": turn their CDN URLs local again,
		// wherever they were saved (e.g. by "save CDN URLs permanently", or copied by hand).
		$pairs = $this->local_overrides();
		if ( $pairs ) {
			$search  = array();
			$replace = array();
			foreach ( $pairs as $cdn => $local ) {
				$search[]  = $cdn;
				$search[]  = str_replace( 'https://', 'http://', $cdn );
				$replace[] = $local;
				$replace[] = $local;
			}
			$content = str_replace( $search, $replace, $content );
		}

		$upload = wp_get_upload_dir();
		$base   = $this->strip_scheme( $upload['baseurl'] );
		if ( false === strpos( $content, $base ) ) {
			return $content;
		}
		$pattern = '~(?:https?:)?' . preg_quote( $base, '~' ) . '/([^\s"\'<>(),\\\\?]+?\.(?:jpe?g|png|gif|webp|avif|svg))(?=[\s"\'<>(),?\\\\#]|$)~i';

		return preg_replace_callback(
			$pattern,
			function ( $m ) {
				$hit = $this->lookup( rawurldecode( $m[1] ) );
				if ( null === $hit ) {
					return $m[0];
				}
				return $this->urls->for_file( $hit['dir'], wp_basename( rawurldecode( $m[1] ) ) );
			},
			$content
		);
	}

	/**
	 * CDN URL => local URL for every excluded image that was on the CDN before,
	 * built once per request. Empty array when nothing is excluded.
	 */
	private function local_overrides() {
		if ( null !== $this->override_cache ) {
			return $this->override_cache;
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON (m.post_id = p.ID AND m.meta_key = '_cdnhub_exclude')
			WHERE p.post_type = 'attachment'"
		);
		$pairs = array();
		if ( $ids ) {
			$upload = wp_get_upload_dir();
			$base   = trailingslashit( $upload['baseurl'] );
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$dir    = CDNHub_Store::dir( $id );
				$is_map = metadata_exists( 'post', $id, '_cdnhub_map' );
				foreach ( (array) get_post_meta( $id, '_cdnhub_file', false ) as $rel ) {
					$name = wp_basename( $rel );
					if ( $is_map && ! CDNHub_Store::has_file( $id, $name ) ) {
						continue; // Never uploaded: there is no CDN URL to undo.
					}
					$pairs[ $this->urls->for_file( $dir, $name ) ] = $base . $rel;
				}
			}
		}
		$this->override_cache = $pairs;
		return $pairs;
	}

	/** Which synced attachment owns this local relative path? */
	private function lookup( $rel ) {
		if ( array_key_exists( $rel, $this->lookup_cache ) ) {
			return $this->lookup_cache[ $rel ];
		}
		global $wpdb;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cdnhub_file' AND meta_value = %s LIMIT 1", $rel )
		);
		if ( $id && CDNHub_Store::is_excluded( $id ) ) {
			$id = 0; // Kept on this server: leave its URLs local.
		}
		$this->lookup_cache[ $rel ] = $id ? array(
			'id'  => $id,
			'dir' => CDNHub_Store::dir( $id ),
		) : null;
		return $this->lookup_cache[ $rel ];
	}
}
