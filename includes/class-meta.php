<?php
/**
 * Property meta box: fields, save handling, and push triggering.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and persists eb_property meta.
 */
class EBS_Meta {

	const NONCE = 'ebs_meta_nonce';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post_' . EBS_Cpt::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'save_post_' . EBS_Houzez::POST_TYPE, array( $this, 'save_houzez' ), 20, 2 );
	}

	/**
	 * Add the meta boxes (full box on our CPT, compact box on Houzez properties).
	 */
	public function add_box() {
		add_meta_box(
			'ebs_property_details',
			__( 'EasyBroker Property Details', 'easybroker-sync' ),
			array( $this, 'render' ),
			EBS_Cpt::POST_TYPE,
			'normal',
			'high'
		);

		if ( EBS_Houzez::is_active() ) {
			add_meta_box(
				'ebs_houzez_sync',
				__( 'EasyBroker Sync', 'easybroker-sync' ),
				array( $this, 'render_houzez' ),
				EBS_Houzez::POST_TYPE,
				'side',
				'high'
			);
		}
	}

	/**
	 * Compact sync box on Houzez properties: status, Push now, and the few
	 * EasyBroker-specific overrides Houzez has no field for.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_houzez( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$status    = EBS_Field_Map::get( $post->ID, 'sync_status', '—' );
		$message   = EBS_Field_Map::get( $post->ID, 'sync_message', '' );
		$public_id = EBS_Field_Map::get( $post->ID, 'eb_public_id', '' );

		echo '<div class="ebs-meta">';
		echo '<p class="ebs-status ebs-status-' . esc_attr( $status ) . '"><strong>' . esc_html__( 'Status:', 'easybroker-sync' ) . '</strong> ' . esc_html( $status );
		if ( $public_id ) {
			echo ' &middot; <code>' . esc_html( $public_id ) . '</code>';
		}
		echo '</p>';
		if ( $message ) {
			echo '<p class="ebs-status-msg description">' . esc_html( $message ) . '</p>';
		}

		printf(
			'<p><button type="button" class="button" id="ebs-push-now" data-post-id="%1$d">%2$s</button> <span class="ebs-inline-result" id="ebs-push-now-result"></span></p>',
			(int) $post->ID,
			esc_html__( 'Push to EasyBroker now', 'easybroker-sync' )
		);

		$fields = array(
			'eb_public_id'  => array( __( 'EasyBroker ID', 'easybroker-sync' ), __( 'Link to an existing EasyBroker listing (e.g. EB-XX1234) so pushes update it instead of creating a new one.', 'easybroker-sync' ) ),
			'location_name' => array( __( 'EasyBroker Location', 'easybroker-sync' ), __( 'Auto-resolved from City/State; set it here if the push reports a location error.', 'easybroker-sync' ) ),
			'property_type' => array( __( 'EB Type override', 'easybroker-sync' ), __( 'e.g. House, Apartment, Villa, Lot…', 'easybroker-sync' ) ),
			'currency'      => array( __( 'Currency override', 'easybroker-sync' ), __( 'MXN or USD. Auto-detected from the price postfix.', 'easybroker-sync' ) ),
			'agent_email'   => array( __( 'Agent Email', 'easybroker-sync' ), '' ),
		);
		foreach ( $fields as $key => $def ) {
			$value = EBS_Field_Map::get( $post->ID, $key, '' );
			printf(
				'<p><label for="ebs_%1$s"><strong>%2$s</strong></label><br /><input type="text" id="ebs_%1$s" name="ebs_%1$s" value="%3$s" class="widefat" />%4$s</p>',
				esc_attr( $key ),
				esc_html( $def[0] ),
				esc_attr( $value ),
				$def[1] ? '<span class="description">' . esc_html( $def[1] ) . '</span>' : ''
			);
		}
		?>
		<div class="ebs-loc-lookup">
			<label for="ebs-loc-query"><strong><?php esc_html_e( 'Location lookup', 'easybroker-sync' ); ?></strong></label>
			<input type="text" id="ebs-loc-query" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Mérida, Yucatán', 'easybroker-sync' ); ?>" />
			<button type="button" class="button" id="ebs-loc-search"><?php esc_html_e( 'Search', 'easybroker-sync' ); ?></button>
			<span class="ebs-inline-result" id="ebs-loc-status"></span>
			<ul id="ebs-loc-results" class="ebs-loc-results"></ul>
		</div>
		<?php
		echo '</div>';
	}

	/**
	 * Save the EasyBroker overrides on a Houzez property and queue a push.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save_houzez( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$overrides = array(
			'eb_public_id'  => 'sanitize_text_field',
			'location_name' => 'sanitize_text_field',
			'property_type' => 'sanitize_text_field',
			'currency'      => 'sanitize_text_field',
			'agent_email'   => 'sanitize_email',
		);
		foreach ( $overrides as $key => $sanitizer ) {
			$input = 'ebs_' . $key;
			if ( ! isset( $_POST[ $input ] ) ) {
				continue;
			}
			$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $input ] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, '_ebs_' . $key );
			} else {
				EBS_Field_Map::set( $post_id, $key, $value );
			}
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}
		EBS_Field_Map::set( $post_id, 'sync_status', 'pending' );
		$this->enqueue_push( $post_id );
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$is_collab = (bool) EBS_Field_Map::get( $post->ID, 'is_collaboration', false );
		$status    = EBS_Field_Map::get( $post->ID, 'sync_status', 'pending' );
		$message   = EBS_Field_Map::get( $post->ID, 'sync_message', '' );
		$public_id = EBS_Field_Map::get( $post->ID, 'eb_public_id', '' );
		$synced_at = EBS_Field_Map::get( $post->ID, 'last_synced_at', '' );
		$disabled  = $is_collab ? 'disabled' : '';

		// Fields: key => [ label, hint ].
		$fields = array(
			'internal_id'       => array( __( 'Internal ID', 'easybroker-sync' ), '' ),
			'status'            => array( __( 'EasyBroker Status', 'easybroker-sync' ), __( 'published, not_published, sold, rented, reserved or suspended. Default: published.', 'easybroker-sync' ) ),
			'operation_type'    => array( __( 'Operation', 'easybroker-sync' ), __( 'sale or rental.', 'easybroker-sync' ) ),
			'price'             => array( __( 'Price', 'easybroker-sync' ), '' ),
			'currency'          => array( __( 'Currency', 'easybroker-sync' ), __( 'e.g. MXN or USD.', 'easybroker-sync' ) ),
			'property_type'     => array( __( 'Property Type', 'easybroker-sync' ), __( 'Must match an EasyBroker type (Apartment, House, Villa, Lot, …).', 'easybroker-sync' ) ),
			'bedrooms'          => array( __( 'Bedrooms', 'easybroker-sync' ), '' ),
			'bathrooms'         => array( __( 'Bathrooms', 'easybroker-sync' ), '' ),
			'half_bathrooms'    => array( __( 'Half Bathrooms', 'easybroker-sync' ), '' ),
			'parking_spaces'    => array( __( 'Parking Spaces', 'easybroker-sync' ), '' ),
			'construction_size' => array( __( 'Construction Size (m²)', 'easybroker-sync' ), '' ),
			'lot_size'          => array( __( 'Lot Size (m²)', 'easybroker-sync' ), '' ),
			'location_name'     => array( __( 'Location (required)', 'easybroker-sync' ), __( 'Must match a valid EasyBroker city/neighborhood, e.g. "Chablekal, Mérida, Yucatán". Use the lookup below.', 'easybroker-sync' ) ),
			'street'            => array( __( 'Street', 'easybroker-sync' ), __( 'Required to show the exact location (not for land).', 'easybroker-sync' ) ),
			'postal_code'       => array( __( 'Postal Code', 'easybroker-sync' ), '' ),
			'latitude'          => array( __( 'Latitude', 'easybroker-sync' ), '' ),
			'longitude'         => array( __( 'Longitude', 'easybroker-sync' ), '' ),
			'agent_email'       => array( __( 'Agent Email (optional)', 'easybroker-sync' ), __( 'EasyBroker account email of the listing agent. Falls back to the plugin default.', 'easybroker-sync' ) ),
		);

		echo '<div class="ebs-meta">';

		// Status banner.
		echo '<p class="ebs-status ebs-status-' . esc_attr( $status ) . '">';
		echo '<strong>' . esc_html__( 'Sync status:', 'easybroker-sync' ) . '</strong> ' . esc_html( $status );
		if ( $public_id ) {
			echo ' &middot; ' . esc_html__( 'EB ID:', 'easybroker-sync' ) . ' <code>' . esc_html( $public_id ) . '</code>';
		}
		if ( $synced_at ) {
			echo ' &middot; ' . esc_html( $synced_at );
		}
		echo '</p>';
		if ( $message ) {
			echo '<p class="ebs-status-msg description">' . esc_html( $message ) . '</p>';
		}

		if ( $is_collab ) {
			echo '<p class="description"><em>' . esc_html__( 'This is a collaboration listing imported from EasyBroker. It is read-only and will not be pushed back.', 'easybroker-sync' ) . '</em></p>';
		} else {
			printf(
				'<p><button type="button" class="button" id="ebs-push-now" data-post-id="%1$d">%2$s</button> <span class="ebs-inline-result" id="ebs-push-now-result"></span></p><p class="description">%3$s</p>',
				(int) $post->ID,
				esc_html__( 'Push to EasyBroker now', 'easybroker-sync' ),
				esc_html__( 'Save your changes first. Use this if automatic (cron) sync is delayed.', 'easybroker-sync' )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $def ) {
			$label = $def[0];
			$hint  = isset( $def[1] ) ? $def[1] : '';
			$value = EBS_Field_Map::get( $post->ID, $key, '' );
			printf(
				'<tr><th scope="row"><label for="ebs_%1$s">%2$s</label></th><td><input type="text" id="ebs_%1$s" name="ebs_%1$s" value="%3$s" class="regular-text" %4$s />%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $value ),
				esc_attr( $disabled ),
				$hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : ''
			);
		}
		echo '</tbody></table>';

		if ( ! $is_collab ) {
			?>
			<div class="ebs-loc-lookup">
				<label for="ebs-loc-query"><strong><?php esc_html_e( 'Location lookup', 'easybroker-sync' ); ?></strong></label><br />
				<input type="text" id="ebs-loc-query" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Mérida, Yucatán', 'easybroker-sync' ); ?>" />
				<button type="button" class="button" id="ebs-loc-search"><?php esc_html_e( 'Search EasyBroker locations', 'easybroker-sync' ); ?></button>
				<span class="ebs-inline-result" id="ebs-loc-status"></span>
				<ul id="ebs-loc-results" class="ebs-loc-results"></ul>
				<p class="description"><?php esc_html_e( 'Click a result to fill the Location field with a name EasyBroker will accept.', 'easybroker-sync' ); ?></p>
			</div>
			<p class="description"><?php esc_html_e( 'To push, these are required by EasyBroker: Title, content/description, Location, Property Type, Operation, Price, and Status.', 'easybroker-sync' ); ?></p>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Persist meta and queue a push.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		// Guard clauses.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Never overwrite collaboration listings from the editor.
		if ( EBS_Field_Map::get( $post_id, 'is_collaboration', false ) ) {
			return;
		}

		$fields = EBS_Field_Map::fields();
		// Editable subset (exclude system-managed keys).
		$system = array( 'eb_public_id', 'sync_status', 'sync_message', 'last_synced_at' );

		foreach ( $fields as $key => $sanitizer ) {
			if ( in_array( $key, $system, true ) ) {
				continue;
			}
			$input = 'ebs_' . $key;
			if ( ! isset( $_POST[ $input ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $input ] );
			if ( ! is_scalar( $raw ) ) {
				continue; // Reject array/object input (e.g. crafted ebs_price[]=x).
			}
			$value = is_callable( $sanitizer ) ? call_user_func( $sanitizer, $raw ) : sanitize_text_field( $raw );
			EBS_Field_Map::set( $post_id, $key, $value );
		}

		// Only push published properties.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Mark pending and enqueue a non-blocking push.
		EBS_Field_Map::set( $post_id, 'sync_status', 'pending' );
		$this->enqueue_push( $post_id );
	}

	/**
	 * Schedule a near-immediate single cron event to push this post,
	 * so saving stays fast and the editor is never blocked on an HTTP call.
	 *
	 * @param int $post_id Post id.
	 */
	private function enqueue_push( $post_id ) {
		$args = array( $post_id );
		if ( ! wp_next_scheduled( EBS_PUSH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, EBS_PUSH_HOOK, $args );
		}
	}
}
