<?php
/**
 * Uninstall handler: remove plugin options and scheduled events.
 *
 * Note: property posts (CPT `eb_property`) and their meta are intentionally
 * preserved so uninstalling does not destroy the catalog. Delete them manually
 * if a full purge is required.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ebs_settings' );
delete_option( 'ebs_sync_log' );
delete_option( 'ebs_collaboration_agencies' );
delete_transient( 'ebs_property_types' );
delete_transient( 'ebs_listing_statuses' );

// Clear any scheduled events belonging to the plugin.
wp_clear_scheduled_hook( 'ebs_scheduled_sync' );

// Remove per-post async push single events (best effort).
$crons = _get_cron_array();
if ( is_array( $crons ) ) {
	foreach ( $crons as $timestamp => $hooks ) {
		if ( isset( $hooks['ebs_async_push'] ) ) {
			foreach ( $hooks['ebs_async_push'] as $event ) {
				wp_unschedule_event( $timestamp, 'ebs_async_push', $event['args'] );
			}
		}
	}
}
