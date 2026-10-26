<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove CDNHub settings and temporary data for the current site.
 * Image sync data on attachments is intentionally kept, so URLs keep working if the
 * plugin is reinstalled.
 */
function cdnhub_uninstall_site() {
	delete_option( 'cdnhub_options' );
	delete_option( 'cdnhub_log' );
	delete_option( 'cdnhub_last_test' );
	delete_option( 'cdnhub_adopt_notice' );
	delete_transient( 'cdnhub_rate_limited_until' );
	delete_transient( 'cdnhub_adopt_index' );
	delete_transient( 'cdnhub_flash_' . get_current_user_id() );

	// Flash messages of other users: transients stored in the options table.
	global $wpdb;
	foreach ( array( '_transient_cdnhub_flash_', '_transient_timeout_cdnhub_flash_' ) as $prefix ) {
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( $name );
		}
	}

	wp_unschedule_hook( 'cdnhub_sync_attachment' );
}

if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
	$cdnhub_offset = 0;
	do {
		$cdnhub_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $cdnhub_offset,
			)
		);
		foreach ( (array) $cdnhub_sites as $cdnhub_site_id ) {
			switch_to_blog( (int) $cdnhub_site_id );
			cdnhub_uninstall_site();
			restore_current_blog();
		}
		$cdnhub_offset += 100;
	} while ( count( (array) $cdnhub_sites ) === 100 );
	unset( $cdnhub_offset, $cdnhub_sites, $cdnhub_site_id );
} else {
	cdnhub_uninstall_site();
}
