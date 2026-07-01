<?php
/**
 * Lightweight sync logger backed by a bounded option (ring buffer).
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records recent sync events for display in the admin.
 */
class EBS_Logger {

	const MAX_ENTRIES = 100;

	/**
	 * Append a log entry.
	 *
	 * @param string $direction 'push' | 'pull' | 'system'.
	 * @param string $status    'success' | 'error' | 'info'.
	 * @param string $message   Human-readable message.
	 * @param array  $context   Optional extra data (post_id, public_id).
	 */
	public static function log( $direction, $status, $message, $context = array() ) {
		$log   = get_option( EBS_LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$entry = array(
			'time'      => current_time( 'mysql' ),
			'direction' => $direction,
			'status'    => $status,
			'message'   => (string) $message,
			'post_id'   => isset( $context['post_id'] ) ? (int) $context['post_id'] : 0,
			'public_id' => isset( $context['public_id'] ) ? (string) $context['public_id'] : '',
		);

		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		update_option( EBS_LOG_OPTION, $log, false );
	}

	/**
	 * Retrieve the log.
	 *
	 * @return array
	 */
	public static function get() {
		$log = get_option( EBS_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the log.
	 */
	public static function clear() {
		delete_option( EBS_LOG_OPTION );
	}
}
