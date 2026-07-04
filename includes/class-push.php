<?php
/**
 * Push engine: WordPress -> EasyBroker.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes own (non-collaboration) listings to EasyBroker.
 */
class EBS_Push {

	/**
	 * Stop auto-retrying a listing after this many consecutive failures.
	 * A manual "Push now" resets the counter.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Push a single post to EasyBroker.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	public function push_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, EBS_Houzez::supported_post_types(), true ) ) {
			return new WP_Error( 'ebs_bad_post', __( 'Not a property post.', 'easybroker-sync' ) );
		}
		$is_houzez = EBS_Houzez::POST_TYPE === $post->post_type;

		// Never push collaboration listings.
		if ( EBS_Field_Map::get( $post_id, 'is_collaboration', false ) ) {
			return new WP_Error( 'ebs_collab', __( 'Collaboration listings are read-only.', 'easybroker-sync' ) );
		}

		$client = new EBS_Api_Client();
		if ( ! $client->has_key() ) {
			return $this->fail( $post_id, __( 'No EasyBroker API key configured.', 'easybroker-sync' ) );
		}

		$valid_types = $client->property_types();

		// Pre-flight validation — fail locally without wasting an API round-trip.
		$errors = $is_houzez
			? EBS_Houzez::validate( $post_id, $valid_types )
			: EBS_Field_Map::validate( $post_id );
		if ( ! empty( $errors ) ) {
			return $this->fail( $post_id, implode( ' ', $errors ) );
		}

		$public_id = EBS_Field_Map::get( $post_id, 'eb_public_id', '' );
		$is_create = '' === $public_id;

		$hotlink    = 'hotlink' === EBS_Plugin::get_setting( 'image_mode', 'import' );
		$image_urls = array();
		if ( ! $hotlink ) {
			$image_urls = $is_houzez
				? EBS_Houzez::outgoing_image_urls( $post_id )
				: EBS_Images::outgoing_urls( $post_id );
		}

		$payload = $is_houzez
			? EBS_Houzez::to_easybroker( $post_id, $image_urls, $valid_types, $is_create, $client )
			: EBS_Field_Map::to_easybroker( $post_id, $image_urls, $valid_types, $is_create );

		// Houzez location resolves lazily against /locations — catch failures here.
		if ( $is_houzez && empty( $payload['location']['name'] ) ) {
			return $this->fail(
				$post_id,
				sprintf(
					/* translators: %s: the city/state query that failed to resolve. */
					__( 'Location "%s" could not be matched to an EasyBroker location. Set it in the EasyBroker box using the location lookup.', 'easybroker-sync' ),
					EBS_Houzez::location_query( $post_id )
				)
			);
		}

		if ( $is_create ) {
			$result = $client->create_property( $payload );
			$action = 'create';
		} else {
			$result = $client->update_property( $public_id, $payload );
			$action = 'update';

			// The linked listing no longer exists on EasyBroker (deleted remotely,
			// or a bad manual link). Clear the stale id and create a fresh listing
			// instead of failing on every future push.
			if ( is_wp_error( $result ) && 'ebs_http_404' === $result->get_error_code() ) {
				EBS_Logger::log(
					'push',
					'info',
					sprintf(
						/* translators: %s: EasyBroker property id. */
						__( 'EasyBroker listing %s no longer exists — re-creating it.', 'easybroker-sync' ),
						$public_id
					),
					array(
						'post_id'   => $post_id,
						'public_id' => $public_id,
					)
				);
				delete_post_meta( $post_id, EBS_Field_Map::key( 'eb_public_id' ) );
				$public_id = '';

				// Rebuild as a create so images are included again.
				$payload = $is_houzez
					? EBS_Houzez::to_easybroker( $post_id, $image_urls, $valid_types, true, $client )
					: EBS_Field_Map::to_easybroker( $post_id, $image_urls, $valid_types, true );

				$result = $client->create_property( $payload );
				$action = 'create';
			}
		}

		if ( is_wp_error( $result ) ) {
			return $this->fail( $post_id, $result->get_error_message(), $public_id );
		}

		// Store returned id on create.
		$returned_id = '';
		foreach ( array( 'public_id', 'id' ) as $k ) {
			if ( ! empty( $result[ $k ] ) ) {
				$returned_id = (string) $result[ $k ];
				break;
			}
		}
		if ( $returned_id ) {
			EBS_Field_Map::set( $post_id, 'eb_public_id', $returned_id );
			$public_id = $returned_id;
		}

		EBS_Field_Map::set( $post_id, 'sync_status', 'synced' );
		EBS_Field_Map::set( $post_id, 'sync_message', '' );
		EBS_Field_Map::set( $post_id, 'last_synced_at', current_time( 'mysql' ) );
		delete_post_meta( $post_id, '_ebs_sync_attempts' ); // Reset retry counter on success.

		EBS_Logger::log(
			'push',
			'success',
			sprintf( '%s: "%s"', $action, $post->post_title ),
			array(
				'post_id'   => $post_id,
				'public_id' => $public_id,
			)
		);

		return true;
	}

	/**
	 * Push all posts currently in pending/error status.
	 *
	 * @param int      $limit       Max posts to process this run.
	 * @param int      $time_budget Seconds to spend before stopping early, so an
	 *                              admin-ajax request never hits max_execution_time.
	 *                              Unpushed posts stay 'pending' for the next run.
	 * @param string[] $statuses    Sync statuses to process. Cron uses the default
	 *                              (pending + error) so failures retry up to
	 *                              MAX_ATTEMPTS; the bulk-sync browser loop passes
	 *                              only 'pending' so each post is attempted once
	 *                              per run instead of re-burning its retry budget
	 *                              on every batch.
	 * @return array Summary counts, plus per-post error lines under 'errors'.
	 */
	public function push_pending( $limit = 25, $time_budget = 20, $statuses = array( 'pending', 'error' ) ) {
		$statuses = array_values( array_intersect( (array) $statuses, array( 'pending', 'error' ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'pending', 'error' );
		}
		if ( ! EBS_Plugin::acquire_lock( 'push' ) ) {
			return array(
				'ok'     => 0,
				'fail'   => 0,
				'errors' => array(),
				'locked' => true,
			);
		}
		$query = new WP_Query(
			array(
				'post_type'      => EBS_Houzez::supported_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_ebs_sync_status',
						'value'   => $statuses,
						'compare' => 'IN',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_ebs_is_collaboration',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ebs_is_collaboration',
							'value'   => '1',
							'compare' => '!=',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => '_ebs_sync_attempts',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_ebs_sync_attempts',
							'value'   => self::MAX_ATTEMPTS,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		$ok       = 0;
		$fail     = 0;
		$errors   = array();
		$deadline = time() + max( 5, (int) $time_budget );

		try {
			foreach ( $query->posts as $post_id ) {
				if ( time() >= $deadline ) {
					break; // Out of time — remaining posts stay pending for the next run.
				}
				$result = $this->push_post( $post_id );
				if ( is_wp_error( $result ) ) {
					$fail++;
					if ( count( $errors ) < 10 ) {
						$errors[] = sprintf(
							/* translators: 1: post title, 2: post id, 3: error message. */
							__( '%1$s (post #%2$d): %3$s', 'easybroker-sync' ),
							get_the_title( $post_id ),
							$post_id,
							$result->get_error_message()
						);
					}
				} else {
					$ok++;
				}
				usleep( 60000 ); // Throttle (~16/sec worst case).
			}
		} finally {
			EBS_Plugin::release_lock( 'push' );
		}

		return array(
			'ok'     => $ok,
			'fail'   => $fail,
			'errors' => $errors,
		);
	}

	/**
	 * Record a failure on the post and in the log.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $message   Error message.
	 * @param string $public_id Optional EB id.
	 * @return WP_Error
	 */
	private function fail( $post_id, $message, $public_id = '' ) {
		$attempts = (int) get_post_meta( $post_id, '_ebs_sync_attempts', true ) + 1;
		update_post_meta( $post_id, '_ebs_sync_attempts', $attempts );

		EBS_Field_Map::set( $post_id, 'sync_status', 'error' );
		EBS_Field_Map::set( $post_id, 'sync_message', $message );
		EBS_Logger::log(
			'push',
			'error',
			$message,
			array(
				'post_id'   => $post_id,
				'public_id' => $public_id,
			)
		);
		return new WP_Error( 'ebs_push_failed', $message );
	}
}
