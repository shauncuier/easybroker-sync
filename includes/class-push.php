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
		if ( ! $post || EBS_Cpt::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'ebs_bad_post', __( 'Not a property post.', 'easybroker-sync' ) );
		}

		// Never push collaboration listings.
		if ( EBS_Field_Map::get( $post_id, 'is_collaboration', false ) ) {
			return new WP_Error( 'ebs_collab', __( 'Collaboration listings are read-only.', 'easybroker-sync' ) );
		}

		$client = new EBS_Api_Client();
		if ( ! $client->has_key() ) {
			return $this->fail( $post_id, __( 'No EasyBroker API key configured.', 'easybroker-sync' ) );
		}

		// Pre-flight validation — fail locally without wasting an API round-trip.
		$errors = EBS_Field_Map::validate( $post_id );
		if ( ! empty( $errors ) ) {
			return $this->fail( $post_id, implode( ' ', $errors ) );
		}

		$public_id = EBS_Field_Map::get( $post_id, 'eb_public_id', '' );
		$is_create = '' === $public_id;

		$hotlink     = 'hotlink' === EBS_Plugin::get_setting( 'image_mode', 'import' );
		$image_urls  = $hotlink ? array() : EBS_Images::outgoing_urls( $post_id );
		$valid_types = $client->property_types();
		$payload     = EBS_Field_Map::to_easybroker( $post_id, $image_urls, $valid_types, $is_create );

		if ( $is_create ) {
			$result = $client->create_property( $payload );
			$action = 'create';
		} else {
			$result = $client->update_property( $public_id, $payload );
			$action = 'update';
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
	 * @param int $limit Max posts to process this run.
	 * @return array Summary counts.
	 */
	public function push_pending( $limit = 25 ) {
		$query = new WP_Query(
			array(
				'post_type'      => EBS_Cpt::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_ebs_sync_status',
						'value'   => array( 'pending', 'error' ),
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

		$ok    = 0;
		$fail  = 0;
		foreach ( $query->posts as $post_id ) {
			$result = $this->push_post( $post_id );
			if ( is_wp_error( $result ) ) {
				$fail++;
			} else {
				$ok++;
			}
			usleep( 60000 ); // Throttle (~16/sec worst case).
		}

		return array(
			'ok'   => $ok,
			'fail' => $fail,
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
