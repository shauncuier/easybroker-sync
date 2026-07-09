<?php
/**
 * Admin-AJAX handlers for manual sync actions.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Test connection", "Push pending" and "Pull collaborations" buttons.
 */
class EBS_Ajax {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_ebs_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_ebs_push_pending', array( $this, 'push_pending' ) );
		add_action( 'wp_ajax_ebs_pull_now', array( $this, 'pull_now' ) );
		add_action( 'wp_ajax_ebs_location_search', array( $this, 'location_search' ) );
		add_action( 'wp_ajax_ebs_push_one', array( $this, 'push_one' ) );
		add_action( 'wp_ajax_ebs_houzez_bulk', array( $this, 'houzez_bulk' ) );
		add_action( 'wp_ajax_ebs_eb_listings', array( $this, 'eb_listings' ) );
	}

	/**
	 * List EasyBroker listings (public_id + title) for the manual-match picker
	 * in the property editor. Cached briefly so repeated filtering is cheap.
	 */
	public function eb_listings() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'easybroker-sync' ) ), 403 );
		}
		check_ajax_referer( 'ebs_admin_action', 'nonce' );

		$client = new EBS_Api_Client();
		if ( ! $client->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'No EasyBroker API key configured.', 'easybroker-sync' ) ) );
		}

		$index = get_transient( 'ebs_eb_listing_index' );
		if ( ! is_array( $index ) ) {
			$list = $client->get_all( 'properties', 'content', array( 'limit' => 50 ), 300 );
			if ( is_wp_error( $list ) ) {
				wp_send_json_error( array( 'message' => $list->get_error_message() ) );
			}
			$index = array();
			foreach ( $list as $item ) {
				if ( ! empty( $item['public_id'] ) ) {
					$index[] = array(
						'id'    => (string) $item['public_id'],
						'title' => isset( $item['title'] ) ? wp_strip_all_tags( (string) $item['title'] ) : '',
					);
				}
			}
			usort(
				$index,
				function ( $a, $b ) {
					return strcasecmp( $a['title'], $b['title'] );
				}
			);
			set_transient( 'ebs_eb_listing_index', $index, 5 * MINUTE_IN_SECONDS );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' !== $query ) {
			$index = array_values(
				array_filter(
					$index,
					function ( $row ) use ( $query ) {
						return false !== stripos( $row['title'], $query ) || false !== stripos( $row['id'], $query );
					}
				)
			);
		}

		wp_send_json_success( array( 'listings' => array_slice( $index, 0, 100 ) ) );
	}

	/**
	 * Bulk-sync Houzez properties to EasyBroker, batched. The first call links
	 * title-matching listings (update instead of duplicate) and queues the rest;
	 * every call then pushes a small batch and reports the remaining count so
	 * the browser can loop.
	 */
	public function houzez_bulk() {
		$this->guard();
		if ( ! EBS_Houzez::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Houzez is not active.', 'easybroker-sync' ) ) );
		}

		$client = new EBS_Api_Client();
		if ( ! $client->has_key() ) {
			wp_send_json_error( array( 'message' => __( 'No EasyBroker API key configured.', 'easybroker-sync' ) ) );
		}

		$first  = ! empty( $_POST['first'] );
		$linked = 0;

		if ( $first ) {
			$linked = EBS_Houzez::link_existing( $client );

			// Queue every published Houzez property that isn't synced yet.
			$to_queue = get_posts(
				array(
					'post_type'      => EBS_Houzez::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'     => '_ebs_sync_status',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ebs_sync_status',
							'value'   => 'synced',
							'compare' => '!=',
						),
					),
				)
			);
			foreach ( $to_queue as $qid ) {
				update_post_meta( $qid, '_ebs_sync_status', 'pending' );
				delete_post_meta( $qid, '_ebs_sync_attempts' ); // Fresh explicit run gets fresh retries.
			}
		}

		// Only 'pending' posts: a post that fails flips to 'error' and is not
		// re-selected by later batches of the same run (each post gets exactly
		// one attempt per bulk run, instead of MAX_ATTEMPTS retries per run).
		$push    = new EBS_Push();
		$summary = $push->push_pending( 5, 20, array( 'pending' ) );

		// Stop the browser loop instead of spinning while the lock is held
		// (e.g. a cron push is mid-run).
		if ( ! empty( $summary['locked'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A push is already running (possibly a scheduled sync) — try again in a moment.', 'easybroker-sync' ) ) );
		}

		// Remaining = still pending and under the attempts cap.
		$remaining_q = new WP_Query(
			array(
				'post_type'      => EBS_Houzez::supported_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_ebs_sync_status',
						'value' => 'pending',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_ebs_sync_attempts',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ebs_sync_attempts',
							'value'   => EBS_Push::MAX_ATTEMPTS,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		wp_send_json_success(
			array(
				'linked'    => (int) $linked,
				'ok'        => (int) $summary['ok'],
				'fail'      => (int) $summary['fail'],
				'errors'    => isset( $summary['errors'] ) ? $summary['errors'] : array(),
				'remaining' => (int) $remaining_q->found_posts,
			)
		);
	}

	/**
	 * Push a single property immediately (manual fallback for the editor).
	 * Resets the retry counter so a previously capped listing can be retried.
	 */
	public function push_one() {
		check_ajax_referer( 'ebs_admin_action', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'easybroker-sync' ) ), 403 );
		}

		delete_post_meta( $post_id, '_ebs_sync_attempts' );
		$push   = new EBS_Push();
		$result = $push->push_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Pushed to EasyBroker.', 'easybroker-sync' ) ) );
	}

	/**
	 * Resolve EasyBroker locations for the editor picker.
	 * Allowed for anyone who can edit properties (not just admins).
	 */
	public function location_search() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'easybroker-sync' ) ), 403 );
		}
		check_ajax_referer( 'ebs_admin_action', 'nonce' );

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( '' === $query ) {
			wp_send_json_error( array( 'message' => __( 'Type a location to search.', 'easybroker-sync' ) ) );
		}

		$client = new EBS_Api_Client();
		$result = $client->search_locations( $query );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$options = array();
		if ( ! empty( $result['full_name'] ) ) {
			$options[] = $result['full_name'];
		}
		if ( ! empty( $result['localities'] ) && is_array( $result['localities'] ) ) {
			foreach ( $result['localities'] as $child ) {
				if ( ! empty( $child['full_name'] ) ) {
					$options[] = $child['full_name'];
				}
			}
		}
		$options = array_slice( array_values( array_unique( $options ) ), 0, 60 );

		wp_send_json_success( array( 'locations' => $options ) );
	}

	/**
	 * Shared guard: verify nonce + capability.
	 */
	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'easybroker-sync' ) ), 403 );
		}
		check_ajax_referer( 'ebs_admin_action', 'nonce' );
	}

	/**
	 * Test the API connection.
	 */
	public function test_connection() {
		$this->guard();
		$client = new EBS_Api_Client();
		$result = $client->test_connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$total = isset( $result['pagination']['total'] ) ? (int) $result['pagination']['total'] : null;
		$msg   = null === $total
			? __( 'Connection OK.', 'easybroker-sync' )
			/* translators: %d: number of properties. */
			: sprintf( __( 'Connection OK — %d properties found.', 'easybroker-sync' ), $total );
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/**
	 * Push pending listings.
	 */
	public function push_pending() {
		$this->guard();
		$push    = new EBS_Push();
		$summary = $push->push_pending();

		if ( ! empty( $summary['locked'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A push is already running — try again in a moment.', 'easybroker-sync' ) ) );
		}

		wp_send_json_success(
			array(
				/* translators: 1: success count, 2: failure count. */
				'message' => sprintf( __( 'Pushed %1$d listing(s), %2$d error(s).', 'easybroker-sync' ), (int) $summary['ok'], (int) $summary['fail'] ),
				'errors'  => isset( $summary['errors'] ) ? $summary['errors'] : array(),
			)
		);
	}

	/**
	 * Pull collaborations (and own listings if enabled).
	 */
	public function pull_now() {
		$this->guard();
		$pull    = new EBS_Pull();
		$summary = $pull->run();

		if ( isset( $summary['error'] ) ) {
			$message = 'locked' === $summary['error']
				? __( 'A sync is already running — try again in a moment.', 'easybroker-sync' )
				: __( 'Import failed — check the API key and log.', 'easybroker-sync' );
			wp_send_json_error( array( 'message' => $message ) );
		}

		$agencies = isset( $summary['agencies'] ) ? (int) $summary['agencies'] : 0;
		$own      = isset( $summary['own']['ok'] ) ? (int) $summary['own']['ok'] : 0;

		wp_send_json_success(
			array(
				/* translators: 1: imported own listings, 2: collaboration agencies recorded. */
				'message' => sprintf(
					__( 'Imported %1$d listing(s); recorded %2$d collaboration agency(ies).', 'easybroker-sync' ),
					$own,
					$agencies
				),
			)
		);
	}
}
