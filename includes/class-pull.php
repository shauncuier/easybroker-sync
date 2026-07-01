<?php
/**
 * Pull engine: EasyBroker -> WordPress.
 *
 * Imports your own EasyBroker property listings into the eb_property post type
 * (fetching full detail per property), and records the roster of collaboration
 * agencies for reference.
 *
 * Note: EasyBroker's public API does NOT expose other agencies' property
 * listings. `GET /collaborations` only returns the partner agencies you work
 * with (agency_id, agency_name), so this engine imports OWN listings and stores
 * the agency roster in an option rather than fabricating property posts.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports own listings and the collaboration-agency roster.
 */
class EBS_Pull {

	const AGENCIES_OPTION = 'ebs_collaboration_agencies';

	/**
	 * Run a full pull.
	 *
	 * @return array Summary counts.
	 */
	public function run() {
		$client = new EBS_Api_Client();
		if ( ! $client->has_key() ) {
			EBS_Logger::log( 'pull', 'error', __( 'No EasyBroker API key configured.', 'easybroker-sync' ) );
			return array( 'error' => 'no_key' );
		}

		$summary = array(
			'agencies' => $this->sync_agencies( $client ),
		);

		if ( 'no' !== EBS_Plugin::get_setting( 'pull_own', 'yes' ) ) {
			$summary['own'] = $this->pull_own( $client );
		}

		return $summary;
	}

	/**
	 * Fetch the collaboration-agency roster and store it in an option.
	 *
	 * @param EBS_Api_Client $client Client.
	 * @return int Number of agencies recorded.
	 */
	private function sync_agencies( $client ) {
		$agencies = $client->get_collaboration_agencies();
		if ( is_wp_error( $agencies ) ) {
			EBS_Logger::log( 'pull', 'error', 'collaborations: ' . $agencies->get_error_message() );
			return 0;
		}

		$clean = array();
		foreach ( (array) $agencies as $row ) {
			if ( empty( $row['agency_id'] ) ) {
				continue;
			}
			$clean[] = array(
				'agency_id'   => (int) $row['agency_id'],
				'agency_name' => isset( $row['agency_name'] ) ? sanitize_text_field( $row['agency_name'] ) : '',
				'group'       => ! empty( $row['group'] ),
			);
		}

		update_option( self::AGENCIES_OPTION, $clean, false );
		EBS_Logger::log(
			'pull',
			'info',
			/* translators: %d: agency count. */
			sprintf( _n( 'Recorded %d collaboration agency.', 'Recorded %d collaboration agencies.', count( $clean ), 'easybroker-sync' ), count( $clean ) )
		);
		return count( $clean );
	}

	/**
	 * Retrieve the stored collaboration-agency roster.
	 *
	 * @return array
	 */
	public static function get_agencies() {
		$agencies = get_option( self::AGENCIES_OPTION, array() );
		return is_array( $agencies ) ? $agencies : array();
	}

	/**
	 * Import own listings: list -> per-property detail -> upsert.
	 *
	 * @param EBS_Api_Client $client Client.
	 * @return array Summary.
	 */
	private function pull_own( $client ) {
		$list = $client->get_all( 'properties', 'content', array( 'limit' => 50 ) );
		if ( is_wp_error( $list ) ) {
			EBS_Logger::log( 'pull', 'error', 'properties: ' . $list->get_error_message() );
			return array(
				'ok'   => 0,
				'fail' => 1,
			);
		}

		$ok   = 0;
		$fail = 0;
		foreach ( $list as $stub ) {
			$public_id = isset( $stub['public_id'] ) ? (string) $stub['public_id'] : '';
			if ( '' === $public_id ) {
				$fail++;
				continue;
			}

			// If this listing already exists locally, WordPress is the source of
			// truth for own listings — skip to avoid clobbering local edits.
			if ( $this->find_by_public_id( $public_id ) ) {
				continue;
			}

			$detail = $client->get_property( $public_id );
			$property = is_wp_error( $detail ) ? $stub : $detail; // Fall back to the stub.

			if ( $this->upsert_one( $property, $public_id ) ) {
				$ok++;
			} else {
				$fail++;
			}
			usleep( 60000 ); // Throttle detail requests.
		}

		return array(
			'ok'   => $ok,
			'fail' => $fail,
		);
	}

	/**
	 * Create or update a WP post from an EasyBroker property.
	 *
	 * @param array  $property  Property data (detail preferred).
	 * @param string $public_id EasyBroker id.
	 * @return bool
	 */
	private function upsert_one( $property, $public_id ) {
		$existing = $this->find_by_public_id( $public_id );

		$title       = isset( $property['title'] ) ? $property['title'] : __( '(untitled property)', 'easybroker-sync' );
		$description = isset( $property['description'] ) ? $property['description'] : '';

		$postarr = array(
			'post_type'    => EBS_Cpt::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => wp_kses_post( $description ),
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			EBS_Logger::log( 'pull', 'error', $post_id->get_error_message(), array( 'public_id' => $public_id ) );
			return false;
		}

		EBS_Field_Map::from_easybroker( $post_id, $property );
		EBS_Field_Map::set( $post_id, 'eb_public_id', $public_id );
		// Imported own listings are editable (not collaboration); future edits PATCH them.
		EBS_Field_Map::set( $post_id, 'is_collaboration', '0' );
		EBS_Field_Map::set( $post_id, 'sync_status', 'synced' );
		EBS_Field_Map::set( $post_id, 'last_synced_at', current_time( 'mysql' ) );

		if ( ! empty( $property['public_url'] ) ) {
			update_post_meta( $post_id, '_ebs_public_url', esc_url_raw( $property['public_url'] ) );
		}

		$this->assign_terms( $post_id, $property );
		$this->ingest_images( $post_id, $property );

		return true;
	}

	/**
	 * Import images from a property detail object.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $property Property data.
	 */
	private function ingest_images( $post_id, $property ) {
		$images = array();

		if ( ! empty( $property['property_images'] ) && is_array( $property['property_images'] ) ) {
			$images = $property['property_images'];
		} elseif ( ! empty( $property['images'] ) && is_array( $property['images'] ) ) {
			$images = $property['images'];
		} elseif ( ! empty( $property['title_image_full'] ) ) {
			$images = array( array( 'url' => $property['title_image_full'] ) );
		}

		if ( empty( $images ) ) {
			return;
		}
		$hotlink = 'hotlink' === EBS_Plugin::get_setting( 'image_mode', 'import' );
		EBS_Images::ingest( $post_id, $images, $hotlink );
	}

	/**
	 * Assign operation / property-type / location terms.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $property Property data.
	 */
	private function assign_terms( $post_id, $property ) {
		if ( ! empty( $property['operations'][0]['type'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $property['operations'][0]['type'] ), EBS_Cpt::TAX_OP );
		}
		if ( ! empty( $property['property_type'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $property['property_type'] ), EBS_Cpt::TAX_TYPE );
		}
		if ( ! empty( $property['location']['name'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $property['location']['name'] ), EBS_Cpt::TAX_LOC );
		}
	}

	/**
	 * Find an existing post by EasyBroker public id.
	 *
	 * @param string $public_id EB id.
	 * @return int|false
	 */
	private function find_by_public_id( $public_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => EBS_Cpt::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_ebs_eb_public_id',
						'value' => $public_id,
					),
				),
			)
		);
		return ! empty( $query->posts ) ? (int) $query->posts[0] : false;
	}
}
