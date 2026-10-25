<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the sync state saved on each attachment.
 *
 *  _cdnhub_offloaded  1 when the files are on the remote storage
 *  _cdnhub_status     synced | failed | pending
 *  _cdnhub_dir        remote sub-folder (relative to the base folder)
 *  _cdnhub_map        array( filename => blob sha ) of files already stored
 *  _cdnhub_file       (multiple rows) local relative path of every stored file — used for content lookups
 *  _cdnhub_error      last error message
 *  _cdnhub_attempts   failed attempts since the last success
 *  _cdnhub_exclude    1 when the image is deliberately kept on this server (never uploaded, never rewritten)
 */
final class CDNHub_Store {

	public static function is_synced( $id ) {
		return (bool) get_post_meta( $id, '_cdnhub_offloaded', true );
	}

	/** Kept on this server by choice: not uploaded, not rewritten to the CDN. */
	public static function is_excluded( $id ) {
		return (bool) get_post_meta( $id, '_cdnhub_exclude', true );
	}

	/** Remote sub-folder. Version 1.x stored files in the same sub-folder as WordPress. */
	public static function dir( $id ) {
		if ( metadata_exists( 'post', $id, '_cdnhub_dir' ) ) {
			return (string) get_post_meta( $id, '_cdnhub_dir', true );
		}
		$d = dirname( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		return ( '.' === $d || '' === $d ) ? '' : $d;
	}

	public static function map( $id ) {
		$m = get_post_meta( $id, '_cdnhub_map', true );
		return is_array( $m ) ? $m : array();
	}

	public static function has_file( $id, $name ) {
		if ( ! metadata_exists( 'post', $id, '_cdnhub_map' ) ) {
			return true; // Synced by version 1.x: everything was uploaded.
		}
		return isset( self::map( $id )[ $name ] );
	}

	/** synced | failed | pending | excluded | none */
	public static function status( $id ) {
		if ( self::is_excluded( $id ) ) {
			return 'excluded';
		}
		$st = (string) get_post_meta( $id, '_cdnhub_status', true );
		if ( 'failed' === $st ) {
			return 'failed';
		}
		if ( self::is_synced( $id ) ) {
			return 'synced';
		}
		return 'pending' === $st ? 'pending' : 'none';
	}
}
