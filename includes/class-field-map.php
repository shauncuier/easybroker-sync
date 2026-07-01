<?php
/**
 * Single source of truth for translating between WordPress meta and the
 * EasyBroker property payload — in both directions.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field mapping helpers.
 */
class EBS_Field_Map {

	const META_PREFIX = '_ebs_';

	/**
	 * Meta keys managed by the plugin (without prefix), with sanitizers.
	 *
	 * @return array key => sanitizer callback.
	 */
	public static function fields() {
		return array(
			'internal_id'       => 'sanitize_text_field',
			'eb_public_id'      => 'sanitize_text_field',
			'operation_type'    => 'sanitize_text_field', // sale | rental.
			'price'             => array( __CLASS__, 'sanitize_amount' ),
			'currency'          => array( __CLASS__, 'sanitize_currency' ),
			'property_type'     => 'sanitize_text_field',
			'bedrooms'          => 'absint',
			'bathrooms'         => array( __CLASS__, 'sanitize_half' ),
			'half_bathrooms'    => 'absint',
			'parking_spaces'    => 'absint',
			'construction_size' => array( __CLASS__, 'sanitize_amount' ),
			'lot_size'          => array( __CLASS__, 'sanitize_amount' ),
			'latitude'          => array( __CLASS__, 'sanitize_coord' ),
			'longitude'         => array( __CLASS__, 'sanitize_coord' ),
			'location_name'     => 'sanitize_text_field', // Must match a valid EasyBroker location.
			'street'            => 'sanitize_text_field',
			'postal_code'       => 'sanitize_text_field',
			'status'            => 'sanitize_text_field', // published | not_published | sold | rented | reserved | suspended.
			'agent_email'       => 'sanitize_email',
			'sync_status'       => 'sanitize_text_field', // pending | synced | error.
			'sync_message'      => 'sanitize_text_field',
			'last_synced_at'    => 'sanitize_text_field',
		);
	}

	/**
	 * Prefix a bare key.
	 *
	 * @param string $key Bare key.
	 * @return string
	 */
	public static function key( $key ) {
		return self::META_PREFIX . $key;
	}

	/**
	 * Read a meta value.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Bare key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get( $post_id, $key, $default = '' ) {
		$value = get_post_meta( $post_id, self::key( $key ), true );
		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * Write a meta value.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Bare key.
	 * @param mixed  $value   Value.
	 */
	public static function set( $post_id, $key, $value ) {
		update_post_meta( $post_id, self::key( $key ), $value );
	}

	/**
	 * Build an EasyBroker property payload from a WP post.
	 *
	 * @param int         $post_id     Post id.
	 * @param array       $image_urls  Public image URLs.
	 * @param array       $valid_types Allowed property_type strings (empty = skip validation).
	 * @param bool        $is_create   Whether this is a create (POST). Images are only sent
	 *                                 on create by default to avoid duplicating them on update.
	 * @return array
	 */
	public static function to_easybroker( $post_id, $image_urls = array(), $valid_types = array(), $is_create = true ) {
		$post = get_post( $post_id );

		$operation = self::get( $post_id, 'operation_type', 'sale' );
		$price     = (float) self::get( $post_id, 'price', 0 );
		$currency  = self::get( $post_id, 'currency', 'MXN' );

		$property_type = self::get( $post_id, 'property_type', '' );
		if ( $property_type && ! empty( $valid_types ) && ! in_array( $property_type, $valid_types, true ) ) {
			// Leave as-is but caller may warn; EasyBroker will reject unknown types.
			$property_type = self::closest( $property_type, $valid_types );
		}

		$status = self::get( $post_id, 'status', 'published' );
		$allowed_status = array( 'published', 'not_published', 'sold', 'rented', 'reserved', 'suspended' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'published';
		}

		$payload = array(
			'title'         => $post ? $post->post_title : '',
			'description'   => self::description_for( $post ),
			'property_type' => $property_type,
			'status'        => $status,
			'operations'    => array(
				array(
					'type'     => ( 'rental' === $operation ) ? 'rental' : 'sale',
					'active'   => true, // Required by EasyBroker; without it the operation is ignored.
					'amount'   => $price,
					'currency' => $currency,
					'unit'     => 'total',
				),
			),
			'location'      => self::location_payload( $post_id ),
		);

		// Numeric attributes — only include when set and numeric (legacy meta may
		// predate the stricter sanitizers; arithmetic on garbage fatals in PHP 8).
		foreach ( array( 'bedrooms', 'bathrooms', 'half_bathrooms', 'parking_spaces', 'construction_size', 'lot_size' ) as $attr ) {
			$val = self::get( $post_id, $attr, '' );
			if ( '' !== $val && is_numeric( $val ) ) {
				$payload[ $attr ] = 0 + $val;
			}
		}

		$internal = self::get( $post_id, 'internal_id', '' );
		if ( $internal ) {
			$payload['internal_id'] = $internal;
		}

		// EasyBroker expects the agent's account email under `agent`.
		$agent = self::get( $post_id, 'agent_email', '' );
		if ( ! $agent ) {
			$agent = self::get( $post_id, 'agent_id', '' ); // Back-compat with pre-0.2 meta.
		}
		if ( ! $agent ) {
			$agent = EBS_Plugin::get_setting( 'agent_email', '' ); // Global default.
		}
		if ( $agent && is_email( $agent ) ) {
			$payload['agent'] = $agent;
		}

		/**
		 * Whether to (re)send images on update. Default false — sending images on
		 * every PATCH duplicates them in EasyBroker, which appends rather than replaces.
		 *
		 * @param bool $send    Send on update.
		 * @param int  $post_id Post id.
		 */
		$send_images = $is_create || apply_filters( 'ebs_send_images_on_update', false, $post_id );

		if ( $send_images && ! empty( $image_urls ) ) {
			$payload['property_images'] = array();
			foreach ( $image_urls as $i => $url ) {
				$payload['property_images'][] = array(
					'url'   => $url,
					'title' => $post ? $post->post_title . ' ' . ( $i + 1 ) : '',
				);
			}
		}

		/**
		 * Allow customising the outgoing payload (e.g. features, custom fields).
		 *
		 * @param array $payload The EasyBroker payload.
		 * @param int   $post_id The source post id.
		 */
		return apply_filters( 'ebs_property_payload', $payload, $post_id );
	}

	/**
	 * Produce a clean plain-text description from the post: expand blocks first
	 * so Gutenberg content is readable, fall back to the excerpt.
	 *
	 * @param WP_Post|null $post Post.
	 * @return string
	 */
	private static function description_for( $post ) {
		if ( ! $post ) {
			return '';
		}
		$text = trim( wp_strip_all_tags( do_blocks( $post->post_content ) ) );
		if ( '' === $text && $post->post_excerpt ) {
			$text = wp_strip_all_tags( $post->post_excerpt );
		}
		return $text;
	}

	/**
	 * Validate that a post has everything EasyBroker requires for a push.
	 * Returns an array of human-readable error messages (empty = valid).
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	public static function validate( $post_id ) {
		$post   = get_post( $post_id );
		$errors = array();

		if ( ! $post || '' === trim( (string) $post->post_title ) ) {
			$errors[] = __( 'Title is required.', 'easybroker-sync' );
		}
		if ( '' === self::description_for( $post ) ) {
			$errors[] = __( 'Description (post content) is required.', 'easybroker-sync' );
		}
		if ( '' === trim( (string) self::get( $post_id, 'location_name', '' ) ) ) {
			$errors[] = __( 'Location is required and must match a valid EasyBroker location.', 'easybroker-sync' );
		}
		if ( '' === trim( (string) self::get( $post_id, 'property_type', '' ) ) ) {
			$errors[] = __( 'Property type is required.', 'easybroker-sync' );
		}
		$op = self::get( $post_id, 'operation_type', '' );
		if ( ! in_array( $op, array( 'sale', 'rental' ), true ) ) {
			$errors[] = __( 'Operation must be "sale" or "rental".', 'easybroker-sync' );
		}
		if ( (float) self::get( $post_id, 'price', 0 ) <= 0 ) {
			$errors[] = __( 'Price must be greater than zero.', 'easybroker-sync' );
		}

		/**
		 * Filter the pre-flight validation errors.
		 *
		 * @param string[] $errors  Errors.
		 * @param int      $post_id Post id.
		 */
		return apply_filters( 'ebs_validation_errors', $errors, $post_id );
	}

	/**
	 * Build the location object.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private static function location_payload( $post_id ) {
		$street = self::get( $post_id, 'street', '' );
		// street is required by EasyBroker only when show_exact_location is true and
		// the property is not land — so only expose the exact location when we have one.
		$show_exact = '' !== $street;

		$loc = array(
			'name'                => self::get( $post_id, 'location_name', '' ),
			'show_exact_location' => $show_exact,
		);
		if ( $show_exact ) {
			$loc['street'] = $street;
		}
		$lat = self::get( $post_id, 'latitude', '' );
		$lng = self::get( $post_id, 'longitude', '' );
		if ( '' !== $lat && '' !== $lng ) {
			$loc['latitude']  = (float) $lat;
			$loc['longitude'] = (float) $lng;
		}
		$pc = self::get( $post_id, 'postal_code', '' );
		if ( $pc ) {
			$loc['postal_code'] = $pc;
		}
		return $loc;
	}

	/**
	 * Populate a WP post's meta from an EasyBroker property/collaboration item.
	 * Used by the pull engine.
	 *
	 * @param int   $post_id Target post.
	 * @param array $item    EasyBroker item.
	 */
	public static function from_easybroker( $post_id, $item ) {
		$map = array(
			'eb_public_id'      => self::first( $item, array( 'public_id', 'id' ) ),
			'bedrooms'          => self::first( $item, array( 'bedrooms' ) ),
			'bathrooms'         => self::first( $item, array( 'bathrooms' ) ),
			'half_bathrooms'    => self::first( $item, array( 'half_bathrooms' ) ),
			'parking_spaces'    => self::first( $item, array( 'parking_spaces' ) ),
			'construction_size' => self::first( $item, array( 'construction_size' ) ),
			'lot_size'          => self::first( $item, array( 'lot_size' ) ),
			'property_type'     => self::first( $item, array( 'property_type' ) ),
		);

		// Location.
		if ( isset( $item['location'] ) && is_array( $item['location'] ) ) {
			$map['location_name'] = self::first( $item['location'], array( 'name' ) );
			$map['street']        = self::first( $item['location'], array( 'street' ) );
			$map['latitude']      = self::first( $item['location'], array( 'latitude' ) );
			$map['longitude']     = self::first( $item['location'], array( 'longitude' ) );
			$map['postal_code']   = self::first( $item['location'], array( 'postal_code' ) );
		}

		// First operation -> price/currency/operation_type.
		if ( ! empty( $item['operations'][0] ) && is_array( $item['operations'][0] ) ) {
			$op                    = $item['operations'][0];
			$map['operation_type'] = self::first( $op, array( 'type' ) );
			$map['price']          = self::first( $op, array( 'amount' ) );
			$map['currency']       = self::first( $op, array( 'currency' ) );
		}

		$sanitizers = self::fields();
		foreach ( $map as $key => $value ) {
			if ( null === $value || '' === $value || ! is_scalar( $value ) ) {
				continue;
			}
			// Defense-in-depth: sanitize API-sourced data on the way in.
			if ( isset( $sanitizers[ $key ] ) && is_callable( $sanitizers[ $key ] ) ) {
				$value = call_user_func( $sanitizers[ $key ], $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			self::set( $post_id, $key, $value );
		}
	}

	/**
	 * Return the first present value among candidate keys.
	 *
	 * @param array $item Source.
	 * @param array $keys Candidate keys.
	 * @return mixed|null
	 */
	private static function first( $item, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $item[ $k ] ) && '' !== $item[ $k ] ) {
				return $item[ $k ];
			}
		}
		return null;
	}

	/**
	 * Best-effort case-insensitive match against a valid list.
	 *
	 * @param string $value Candidate.
	 * @param array  $valid Valid values.
	 * @return string
	 */
	private static function closest( $value, $valid ) {
		foreach ( $valid as $v ) {
			if ( 0 === strcasecmp( $v, $value ) ) {
				return $v;
			}
		}
		return $value;
	}

	/* ----- sanitizers ----- */

	/**
	 * Sanitize a monetary/size amount to a float-ish string.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_amount( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[^0-9.]/', '', (string) $value );
		// is_numeric guard: stripped input like "." or "1.2.3" is not a valid
		// number and would fatal on arithmetic in PHP 8.
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		return (string) ( 0 + $value );
	}

	/**
	 * Bathrooms may be halves (e.g. 2.5).
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_half( $value ) {
		return self::sanitize_amount( $value );
	}

	/**
	 * Sanitize a currency code to ISO-4217 shape (3 uppercase letters) or ''.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_currency( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = strtoupper( trim( (string) $value ) );
		return preg_match( '/^[A-Z]{3}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize a lat/lng coordinate.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_coord( $value ) {
		$value = trim( (string) $value );
		return is_numeric( $value ) ? (string) ( 0 + $value ) : '';
	}
}
