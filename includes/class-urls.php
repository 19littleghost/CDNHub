<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds public CDN URLs from remote paths.
 */
final class CDNHub_URLs {

	private $settings;

	public function __construct( CDNHub_Settings $settings ) {
		$this->settings = $settings;
	}

	/** Full remote path: base folder / sub-folder / file name (raw, not URL-encoded). */
	public function remote_path( $dir, $name ) {
		$parts = array_filter( array( (string) $this->settings->get( 'folder' ), (string) $dir, (string) $name ), 'strlen' );
		return implode( '/', $parts );
	}

	public function for_file( $dir, $name ) {
		return $this->url_for_path( $this->remote_path( $dir, $name ) );
	}

	/**
	 * URLs to call so CDN caches drop the old copy of these remote paths. jsDelivr caches
	 * branch URLs for up to a week, so an overwritten or deleted file would otherwise linger.
	 * Filter `cdnhub_purge_urls` to purge other CDNs.
	 *
	 * @param string[] $paths Remote paths.
	 * @return string[]
	 */
	public function purge_urls( array $paths ) {
		$out = array();
		foreach ( $paths as $path ) {
			$url = $this->url_for_path( $path );
			if ( 0 === strpos( $url, 'https://cdn.jsdelivr.net/gh/' ) ) {
				$out[] = 'https://purge.jsdelivr.net/' . substr( $url, strlen( 'https://cdn.jsdelivr.net/' ) );
			}
		}
		return array_values( array_unique( (array) apply_filters( 'cdnhub_purge_urls', $out, $paths ) ) );
	}

	public function url_for_path( $path ) {
		$o   = $this->settings->all();
		$enc = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );

		switch ( $o['url_mode'] ) {
			case 'raw':
				return 'https://raw.githubusercontent.com/' . $o['owner'] . '/' . $o['repo'] . '/' . $o['branch'] . '/' . $enc;
			case 'pages':
				return 'https://' . $o['owner'] . '.github.io/' . $o['repo'] . '/' . $enc;
			case 'custom':
				if ( '' !== $o['custom_template'] ) {
					$tpl = $o['custom_template'];
					if ( false === strpos( $tpl, '{path}' ) ) {
						$tpl = rtrim( $tpl, '/' ) . '/{path}';
					}
					return strtr(
						$tpl,
						array(
							'{owner}'  => $o['owner'],
							'{repo}'   => $o['repo'],
							'{branch}' => $o['branch'],
							'{path}'   => $enc,
						)
					);
				}
				// No template yet: fall back to jsDelivr.
			default:
				return 'https://cdn.jsdelivr.net/gh/' . $o['owner'] . '/' . $o['repo'] . '@' . $o['branch'] . '/' . $enc;
		}
	}
}
