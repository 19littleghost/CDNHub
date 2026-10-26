<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
// Settings and log only. Image sync data on attachments is kept so URLs keep working if you reinstall.
delete_option( 'cdnhub_options' );
delete_option( 'cdnhub_log' );
delete_option( 'cdnhub_last_test' );
delete_option( 'cdnhub_adopt_notice' );
delete_transient( 'cdnhub_rate_limited_until' );
delete_transient( 'cdnhub_adopt_index' );
wp_unschedule_hook( 'cdnhub_sync_attachment' );
