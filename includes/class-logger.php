<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small activity log (last 200 entries). Credentials are always scrubbed.
 */
final class CDNHub_Logger {

	const OPT = 'cdnhub_log';
	const MAX = 200;

	private $settings;

	public function __construct( CDNHub_Settings $settings ) {
		$this->settings = $settings;
	}

	public function info( $msg, $id = 0 ) {
		$this->add( 'info', $msg, $id );
	}

	public function error( $msg, $id = 0 ) {
		$this->add( 'error', $msg, $id );
	}

	private function scrub( $msg ) {
		$msg   = (string) $msg;
		$token = (string) $this->settings->get( 'token' );
		if ( '' !== $token ) {
			$msg = str_replace( $token, '***', $msg );
		}
		return preg_replace( '/\b(github_pat_|gh[pousr]_)[A-Za-z0-9_]+/', '***', $msg );
	}

	private function add( $level, $msg, $id ) {
		$log = $this->all();
		array_unshift(
			$log,
			array(
				't'  => time(),
				'l'  => $level,
				'm'  => $this->scrub( $msg ),
				'id' => (int) $id,
			)
		);
		update_option( self::OPT, array_slice( $log, 0, self::MAX ), false );
	}

	public function all() {
		$log = get_option( self::OPT, array() );
		return is_array( $log ) ? $log : array();
	}

	public function clear() {
		delete_option( self::OPT );
	}
}
