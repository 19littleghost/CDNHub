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
