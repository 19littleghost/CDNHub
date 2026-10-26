<?php
/**
 * Plugin Name: CDNHub
 * Description: Automatically uploads your blog images to GitHub and serves them from a CDN (jsDelivr, GitHub raw, GitHub Pages or your own domain). Upload images the normal way — CDNHub does the rest.
 * Version:     26.2.2
 * Author:      bdSpark
 * Author URI:  https://bdspark.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * Text Domain: cdnhub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CDNHUB_VERSION', '26.2.2' );
define( 'CDNHUB_FILE', __FILE__ );
define( 'CDNHUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDNHUB_URL', plugin_dir_url( __FILE__ ) );

if ( is_readable( CDNHUB_DIR . 'vendor/autoload.php' ) ) {
	require_once CDNHUB_DIR . 'vendor/autoload.php';
} else {
	// Installed without running Composer: load the classes directly.
	require_once CDNHUB_DIR . 'includes/interface-provider.php';
	require_once CDNHUB_DIR . 'includes/interface-batch-provider.php';
	require_once CDNHUB_DIR . 'includes/class-settings.php';
	require_once CDNHUB_DIR . 'includes/class-logger.php';
	require_once CDNHUB_DIR . 'includes/class-store.php';
	require_once CDNHUB_DIR . 'includes/class-svg.php';
	require_once CDNHUB_DIR . 'includes/class-github-provider.php';
	require_once CDNHUB_DIR . 'includes/class-urls.php';
	require_once CDNHUB_DIR . 'includes/class-uploader.php';
	require_once CDNHUB_DIR . 'includes/class-rewriter.php';
	require_once CDNHUB_DIR . 'includes/class-admin.php';
}

/**
 * Plugin container. Everything is wired here so each piece stays small and replaceable.
 */
final class CDNHub {

	private static $instance = null;

	public $settings;
	public $logger;
	public $urls;
	public $uploader;
	public $rewriter;
	public $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new CDNHub_Settings();
		$this->logger   = new CDNHub_Logger( $this->settings );
		$this->urls     = new CDNHub_URLs( $this->settings );
		$this->uploader = new CDNHub_Uploader( $this->settings, $this->logger, $this->urls );
		$this->rewriter = new CDNHub_Rewriter( $this->settings, $this->urls );

		if ( is_admin() ) {
			$this->admin = new CDNHub_Admin( $this->settings, $this->logger, $this->uploader, $this->urls );
		}
	}
}

add_action( 'plugins_loaded', array( 'CDNHub', 'instance' ) );
register_activation_hook( __FILE__, array( 'CDNHub_Uploader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CDNHub_Uploader', 'deactivate' ) );
