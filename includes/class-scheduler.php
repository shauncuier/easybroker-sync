<?php
/**
 * WP-Cron scheduling for periodic sync.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers cron intervals and handlers.
 */
class EBS_Scheduler {

	/**
	 * Register runtime hooks (called on every request).
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_intervals' ) );
		add_action( EBS_CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( EBS_PUSH_HOOK, array( $this, 'run_async_push' ), 10, 1 );

		// Keep the recurring event aligned with the configured interval.
		add_action( 'init', array( __CLASS__, 'maybe_reschedule' ) );
	}

	/**
	 * Add custom intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_intervals( $schedules ) {
		if ( ! isset( $schedules['ebs_hourly'] ) ) {
			$schedules['ebs_hourly'] = array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => __( 'Every hour (EasyBroker Sync)', 'easybroker-sync' ),
			);
		}
		if ( ! isset( $schedules['ebs_six_hours'] ) ) {
			$schedules['ebs_six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 hours (EasyBroker Sync)', 'easybroker-sync' ),
			);
		}
		return $schedules;
	}

	/**
	 * The configured recurrence key.
	 *
	 * @return string
	 */
	public static function recurrence() {
		$interval = EBS_Plugin::get_setting( 'cron_interval', 'ebs_hourly' );
		$allowed  = array( 'ebs_hourly', 'ebs_six_hours', 'daily' );
		return in_array( $interval, $allowed, true ) ? $interval : 'ebs_hourly';
	}

	/**
	 * Schedule the recurring event if absent.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( EBS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::recurrence(), EBS_CRON_HOOK );
		}
	}

	/**
	 * Remove the recurring event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( EBS_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, EBS_CRON_HOOK );
		}
		wp_clear_scheduled_hook( EBS_CRON_HOOK );
	}

	/**
	 * If the interval setting changed, reschedule.
	 */
	public static function maybe_reschedule() {
		$current  = wp_get_schedule( EBS_CRON_HOOK );
		$desired  = self::recurrence();
		if ( $current && $current !== $desired ) {
			self::unschedule();
			self::schedule();
		} elseif ( ! $current ) {
			self::schedule();
		}
	}

	/**
	 * Scheduled run: pull, then retry pending pushes.
	 */
	public function run_scheduled() {
		EBS_Logger::log( 'system', 'info', __( 'Scheduled sync started.', 'easybroker-sync' ) );

		$pull    = new EBS_Pull();
		$pull->run();

		$push    = new EBS_Push();
		$push->push_pending();

		EBS_Logger::log( 'system', 'info', __( 'Scheduled sync finished.', 'easybroker-sync' ) );
	}

	/**
	 * Handle a single async push scheduled from the editor save.
	 *
	 * @param int $post_id Post id.
	 */
	public function run_async_push( $post_id ) {
		$push = new EBS_Push();
		$push->push_post( (int) $post_id );
	}
}
