<?php
// phpcs:ignoreFile

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/** Base test case: Brain Monkey plus stubs for the WordPress helpers every class uses. */
abstract class CDNHub_Test_Case extends TestCase {

	/** @var array Value returned by get_option( 'cdnhub_options' ). */
	protected $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_basename' )->alias( 'basename' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'cdnhub_options' === $name ? $this->options : $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Settings whose saved options are $options (merged over the defaults by the class). */
	protected function settings( array $options = array() ) {
		$this->options = $options;
		return new CDNHub_Settings();
	}

	/** Git blob SHA-1, as GitHub computes it. */
	protected static function git_sha( $content ) {
		return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
	}
}
