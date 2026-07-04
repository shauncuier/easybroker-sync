<?php
/**
 * Houzez theme adapter.
 *
 * Bridges Houzez's `property` post type (fave_* meta, property_* taxonomies)
 * with EasyBroker: builds push payloads from Houzez data and imports
 * EasyBroker listings as native Houzez properties so they use the site's UI.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Houzez integration.
 */
class EBS_Houzez {

	const POST_TYPE = 'property';

	/**
	 * Whether Houzez (or a compatible setup registering `property`) is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return post_type_exists( self::POST_TYPE );
	}

	/**
	 * Post types the sync engines operate on.
	 *
	 * @return string[]
	 */
	public static function supported_post_types() {
		$types = array( EBS_Cpt::POST_TYPE );
		if ( self::is_active() ) {
			$types[] = self::POST_TYPE;
		}
		return $types;
	}

	/**
	 * Default Houzez property-type → EasyBroker property-type aliases.
	 * Keys are lowercase Houzez names; values must match EasyBroker types.
	 *
	 * @return array
	 */
	public static function type_map() {
		$map = array(
			'house'         => 'House',
			'single family' => 'House',
			'villa'         => 'Villa',
			'apartment'     => 'Apartment',
			'condo'         => 'Apartment',
			'condominium'   => 'Apartment',
			'studio'        => 'Apartment',
			'land'          => 'Lot',
			'lot'           => 'Lot',
			'commercial'    => 'Retail Space',
			'retail'        => 'Retail Space',
			'office'        => 'Office',
			'industrial'    => 'Industrial Warehouse',
			'warehouse'     => 'Industrial Warehouse',
			'ranch'         => 'Ranch',
			'farm'          => 'Ranch',
			'building'      => 'Building',
			'waterfront'    => 'House',
			'historic'      => 'House',
			'equestrian'    => 'Ranch',
			// Spanish term names, common on Mexican Houzez sites.
			'casa'            => 'House',
			'casa sola'       => 'House',
			'departamento'    => 'Apartment',
			'depto'           => 'Apartment',
			'penthouse'       => 'Apartment',
			'terreno'         => 'Lot',
			'lote'            => 'Lot',
			'rancho'          => 'Ranch',
			'hacienda'        => 'Ranch',
			'oficina'         => 'Office',
			'local'           => 'Retail Space',
			'local comercial' => 'Retail Space',
			'bodega'          => 'Industrial Warehouse',
			'edificio'        => 'Building',
		);

		/**
		 * Filter the Houzez → EasyBroker property-type alias map.
		 *
		 * @param array $map lowercase houzez name => EasyBroker type.
		 */
		return apply_filters( 'ebs_houzez_type_map', $map );
	}

	/**
	 * Resolve the EasyBroker property type for a Houzez post.
	 *
	 * @param int   $post_id     Post id.
	 * @param array $valid_types EasyBroker types (for exact-name passthrough).
	 * @return string Empty when unmappable.
	 */
	public static function eb_property_type( $post_id, $valid_types = array() ) {
		$map = self::type_map();

		// Explicit override wins — but run it through the alias map too, so
		// typing "casa" or "Departamento" in the box works like a term would.
		$override = trim( (string) get_post_meta( $post_id, '_ebs_property_type', true ) );
		if ( '' !== $override ) {
			$key = strtolower( $override );
			if ( isset( $map[ $key ] ) ) {
				return self::align_type( $map[ $key ], $valid_types );
			}
			return self::align_type( $override, $valid_types );
		}

		$terms = get_the_terms( $post_id, 'property_type' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			$name = strtolower( $term->name );
			if ( isset( $map[ $name ] ) ) {
				// Align to the account's canonical spelling when we know it.
				return self::align_type( $map[ $name ], $valid_types );
			}
			// Exact EasyBroker name used directly as a Houzez term.
			foreach ( $valid_types as $vt ) {
				if ( 0 === strcasecmp( $vt, $term->name ) ) {
					return $vt;
				}
			}
		}
		return '';
	}

	/**
	 * Case-insensitively align a type string to the account's valid list.
	 * Returns the input unchanged when the list is empty or has no match.
	 *
	 * @param string $type  Candidate type.
	 * @param array  $valid Valid EasyBroker types.
	 * @return string
	 */
	private static function align_type( $type, $valid ) {
		foreach ( (array) $valid as $vt ) {
			if ( 0 === strcasecmp( $vt, $type ) ) {
				return $vt;
			}
		}
		return $type;
	}

	/**
	 * sale|rental from Houzez property_status terms.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function operation_type( $post_id ) {
		$terms = get_the_terms( $post_id, 'property_status' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( preg_match( '/rent|lease|renta/i', $term->name ) ) {
					return 'rental';
				}
			}
		}
		return 'sale';
	}

	/**
	 * Read a Houzez meta value with fallbacks.
	 *
	 * @param int      $post_id Post id.
	 * @param string[] $keys    Candidate meta keys.
	 * @return string
	 */
	private static function meta( $post_id, $keys ) {
		foreach ( (array) $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Currency for a Houzez listing: _ebs_currency override, USD hint in the
	 * price prefix/postfix, else the plugin default.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function currency( $post_id ) {
		$override = get_post_meta( $post_id, '_ebs_currency', true );
		if ( $override ) {
			return strtoupper( $override );
		}
		$hint = self::meta( $post_id, array( 'fave_property_price_postfix', 'fave_property_price_prefix' ) );
		if ( $hint ) {
			if ( preg_match( '/usd|dll?s|dollar|d[oó]lar/i', $hint ) ) {
				return 'USD';
			}
			if ( preg_match( '/mxn|\bmn\b|peso/i', $hint ) ) {
				return 'MXN';
			}
		}
		return strtoupper( EBS_Plugin::get_setting( 'currency', 'MXN' ) );
	}

	/**
	 * Lat/lng from Houzez's "lat,lng[,zoom]" meta.
	 *
	 * @param int $post_id Post id.
	 * @return array [lat, lng] as floats, or empty array.
	 */
	public static function coordinates( $post_id ) {
		$raw = self::meta( $post_id, array( 'fave_property_location' ) );
		if ( $raw ) {
			$parts = array_map( 'trim', explode( ',', $raw ) );
			if ( count( $parts ) >= 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
				return array( (float) $parts[0], (float) $parts[1] );
			}
		}
		return array();
	}

	/**
	 * Candidate EasyBroker location query for a Houzez post: the stored
	 * resolved name, else "city, state" from taxonomies.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function location_query( $post_id ) {
		$stored = get_post_meta( $post_id, '_ebs_location_name', true );
		if ( $stored ) {
			return $stored;
		}
		$parts = array();
		foreach ( array( 'property_city', 'property_state' ) as $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$parts[] = $terms[0]->name;
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Resolve + cache the EasyBroker location for a Houzez post.
	 *
	 * @param int            $post_id Post id.
	 * @param EBS_Api_Client $client  Client.
	 * @return string Resolved full_name, or '' when unresolvable.
	 */
	public static function resolve_location( $post_id, $client ) {
		$stored = get_post_meta( $post_id, '_ebs_location_name', true );
		if ( $stored ) {
			return $stored;
		}
		$query = self::location_query( $post_id );
		if ( '' === $query ) {
			return '';
		}

		// Negative cache: many posts share the same bad city (e.g. demo data),
		// and cron retries the same posts — don't hit /locations for a query
		// that just failed to match.
		$miss_key = 'ebs_loc_miss_' . md5( $query );
		if ( get_transient( $miss_key ) ) {
			return '';
		}

		$result = $client->search_locations( $query );
		if ( is_wp_error( $result ) ) {
			return ''; // Network/API failure — don't cache, next attempt may succeed.
		}
		if ( empty( $result['full_name'] ) ) {
			set_transient( $miss_key, 1, 15 * MINUTE_IN_SECONDS );
			return '';
		}
		update_post_meta( $post_id, '_ebs_location_name', sanitize_text_field( $result['full_name'] ) );
		// Logged once (result is cached in meta): a bad source city — e.g. Houzez
		// demo data — can silently resolve to an unrelated EasyBroker location.
		EBS_Logger::log(
			'push',
			'info',
			sprintf(
				/* translators: 1: Houzez city/state query, 2: resolved EasyBroker location. */
				__( 'Location "%1$s" resolved to EasyBroker location "%2$s". If that is wrong, correct it in the EasyBroker box.', 'easybroker-sync' ),
				$query,
				$result['full_name']
			),
			array( 'post_id' => $post_id )
		);
		return $result['full_name'];
	}

	/**
	 * Validate a Houzez post for pushing. Mirrors EBS_Field_Map::validate().
	 *
	 * @param int   $post_id     Post id.
	 * @param array $valid_types EasyBroker property types.
	 * @return string[] Errors (empty = valid).
	 */
	public static function validate( $post_id, $valid_types = array() ) {
		$post   = get_post( $post_id );
		$errors = array();

		if ( ! $post || '' === trim( (string) $post->post_title ) ) {
			$errors[] = __( 'Title is required.', 'easybroker-sync' );
		}
		if ( '' === trim( wp_strip_all_tags( do_blocks( (string) $post->post_content ) ) ) ) {
			$errors[] = __( 'Description (post content) is required.', 'easybroker-sync' );
		}
		if ( '' === self::eb_property_type( $post_id, $valid_types ) ) {
			$terms = get_the_terms( $post_id, 'property_type' );
			$names = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array();
			if ( empty( $names ) ) {
				$errors[] = __( 'No Houzez Property Type is assigned — assign one, or set the EB Type override in the EasyBroker box.', 'easybroker-sync' );
			} else {
				$errors[] = sprintf(
					/* translators: %s: Houzez property type term name(s). */
					__( 'Property type "%s" could not be mapped to an EasyBroker type — set the override in the EasyBroker box.', 'easybroker-sync' ),
					implode( ', ', $names )
				);
			}
		}
		$price = (float) preg_replace( '/[^0-9.]/', '', (string) self::meta( $post_id, array( 'fave_property_price' ) ) );
		if ( $price <= 0 ) {
			$errors[] = __( 'Price must be greater than zero — set the Sale or Rent Price in Houzez\'s Property Price section.', 'easybroker-sync' );
		}
		if ( '' === self::location_query( $post_id ) ) {
			$errors[] = __( 'No location: set the EasyBroker Location in the EasyBroker box (or assign a City in Houzez).', 'easybroker-sync' );
		}

		return apply_filters( 'ebs_validation_errors', $errors, $post_id );
	}

	/**
	 * Build the EasyBroker payload from a Houzez property.
	 *
	 * @param int            $post_id     Post id.
	 * @param array          $image_urls  Public image URLs.
	 * @param array          $valid_types EasyBroker property types.
	 * @param bool           $is_create   Create vs update (images only on create).
	 * @param EBS_Api_Client $client      Client (for location resolution).
	 * @return array
	 */
	public static function to_easybroker( $post_id, $image_urls, $valid_types, $is_create, $client ) {
		$post  = get_post( $post_id );
		$price = (float) preg_replace( '/[^0-9.]/', '', (string) self::meta( $post_id, array( 'fave_property_price' ) ) );

		$location = array(
			'name'                => self::resolve_location( $post_id, $client ),
			'show_exact_location' => false,
		);
		$street = self::meta( $post_id, array( 'fave_property_address', 'fave_property_map_address' ) );
		if ( $street ) {
			$location['street']              = sanitize_text_field( $street );
			$location['show_exact_location'] = true;
		}
		$coords = self::coordinates( $post_id );
		if ( $coords ) {
			$location['latitude']  = $coords[0];
			$location['longitude'] = $coords[1];
		}
		$zip = self::meta( $post_id, array( 'fave_property_zip' ) );
		if ( $zip ) {
			$location['postal_code'] = sanitize_text_field( $zip );
		}

		$payload = array(
			'title'         => $post ? $post->post_title : '',
			'description'   => $post ? trim( wp_strip_all_tags( do_blocks( $post->post_content ) ) ) : '',
			'property_type' => self::eb_property_type( $post_id, $valid_types ),
			'status'        => 'published',
			'operations'    => array(
				array(
					'type'     => self::operation_type( $post_id ),
					'active'   => true,
					'amount'   => $price,
					'currency' => self::currency( $post_id ),
					'unit'     => 'total',
				),
			),
			'location'      => $location,
		);

		$numeric = array(
			'bedrooms'          => array( 'fave_property_bedrooms' ),
			'bathrooms'         => array( 'fave_property_bathrooms' ),
			'parking_spaces'    => array( 'fave_property_garage' ),
			'construction_size' => array( 'fave_property_size' ),
			'lot_size'          => array( 'fave_property_land' ),
		);
		foreach ( $numeric as $eb_key => $keys ) {
			$val = preg_replace( '/[^0-9.]/', '', (string) self::meta( $post_id, $keys ) );
			if ( '' !== $val ) {
				$payload[ $eb_key ] = 0 + $val;
			}
		}

		$internal = self::meta( $post_id, array( 'fave_property_id' ) );
		if ( $internal ) {
			$payload['internal_id'] = sanitize_text_field( $internal );
		}

		$agent = get_post_meta( $post_id, '_ebs_agent_email', true );
		if ( ! $agent ) {
			$agent = EBS_Plugin::get_setting( 'agent_email', '' );
		}
		if ( $agent && is_email( $agent ) ) {
			$payload['agent'] = $agent;
		}

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

		return apply_filters( 'ebs_property_payload', $payload, $post_id );
	}

	/**
	 * Outgoing image URLs for a Houzez post: featured first, then the
	 * fave_property_images gallery (attachment ids in multiple meta rows).
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	public static function outgoing_image_urls( $post_id ) {
		$ids = array();

		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$ids[] = (int) $thumb;
		}
		$gallery = get_post_meta( $post_id, 'fave_property_images', false );
		foreach ( (array) $gallery as $gid ) {
			$ids[] = (int) $gid;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$urls = array();
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		return apply_filters( 'ebs_outgoing_image_urls', array_values( array_unique( $urls ) ), $post_id );
	}

	/**
	 * Normalize a title for duplicate matching: lowercase, accent-free, alnum only.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function normalize_title( $title ) {
		// remove_accents() FIRST: PHP's strtolower() is byte-wise and leaves
		// multibyte letters (Á, Ó, Ñ…) untouched, so lowercasing first makes
		// remove_accents() emit uppercase ASCII ("MANSIÓN" → "mansiOn") which
		// the [^a-z0-9] strip then eats — breaking matches for accented
		// all-caps titles.
		$title = strtolower( remove_accents( (string) $title ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $title ) );
	}

	/**
	 * Link existing Houzez properties to EasyBroker listings by title match so
	 * bulk pushes PATCH the existing EB record instead of creating duplicates.
	 *
	 * @param EBS_Api_Client $client Client.
	 * @return int Number of properties newly linked.
	 */
	public static function link_existing( $client ) {
		$eb_list = $client->get_all( 'properties', 'content', array( 'limit' => 50 ) );
		if ( is_wp_error( $eb_list ) || empty( $eb_list ) ) {
			return 0;
		}

		// normalized EB title → public_id.
		$eb_titles = array();
		foreach ( $eb_list as $item ) {
			if ( ! empty( $item['public_id'] ) && ! empty( $item['title'] ) ) {
				$eb_titles[ self::normalize_title( $item['title'] ) ] = $item['public_id'];
			}
		}
		if ( empty( $eb_titles ) ) {
			return 0;
		}

		$unlinked = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_ebs_eb_public_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$linked = 0;
		foreach ( $unlinked as $post_id ) {
			$norm = self::normalize_title( get_the_title( $post_id ) );
			if ( '' === $norm ) {
				continue;
			}
			$match = isset( $eb_titles[ $norm ] ) ? $eb_titles[ $norm ] : '';
			if ( '' === $match ) {
				// Containment fallback for prefixed/suffixed variants of the same listing.
				foreach ( $eb_titles as $eb_norm => $pid ) {
					if ( strlen( $norm ) >= 12 && ( false !== strpos( $eb_norm, $norm ) || false !== strpos( $norm, $eb_norm ) ) ) {
						$match = $pid;
						break;
					}
				}
			}
			if ( $match ) {
				update_post_meta( $post_id, '_ebs_eb_public_id', sanitize_text_field( $match ) );
				update_post_meta( $post_id, '_ebs_sync_status', 'pending' ); // Next push PATCHes.
				EBS_Logger::log(
					'push',
					'info',
					sprintf( 'Linked existing listing to %s by title match.', $match ),
					array(
						'post_id'   => $post_id,
						'public_id' => $match,
					)
				);
				$linked++;
			}
		}
		return $linked;
	}

	/**
	 * Find an unlinked Houzez property whose title matches (normalized).
	 *
	 * @param string $title EasyBroker listing title.
	 * @return int Post id, or 0.
	 */
	public static function find_unlinked_by_title( $title ) {
		$norm = self::normalize_title( $title );
		if ( '' === $norm ) {
			return 0;
		}
		$unlinked = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_ebs_eb_public_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $unlinked as $post_id ) {
			$candidate = self::normalize_title( get_the_title( $post_id ) );
			if ( $candidate === $norm ) {
				return (int) $post_id;
			}
			if ( strlen( $norm ) >= 12 && ( false !== strpos( $candidate, $norm ) || false !== strpos( $norm, $candidate ) ) ) {
				return (int) $post_id;
			}
		}
		return 0;
	}

	/**
	 * Create/update a native Houzez property from an EasyBroker listing so it
	 * renders with the site's existing Houzez UI.
	 *
	 * @param array  $property  EasyBroker property (detail).
	 * @param string $public_id EasyBroker id.
	 * @param int    $existing  Existing post id (0 = create).
	 * @return int|WP_Error Post id.
	 */
	public static function upsert_from_easybroker( $property, $public_id, $existing = 0 ) {
		$postarr = array(
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => wp_strip_all_tags( isset( $property['title'] ) ? $property['title'] : '(untitled)' ),
			'post_content' => wp_kses_post( isset( $property['description'] ) ? $property['description'] : '' ),
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Houzez meta.
		$op = isset( $property['operations'][0] ) ? $property['operations'][0] : array();
		if ( isset( $op['amount'] ) ) {
			update_post_meta( $post_id, 'fave_property_price', (string) $op['amount'] );
			update_post_meta( $post_id, 'fave_property_price_postfix', isset( $op['currency'] ) ? $op['currency'] : '' );
		}
		$meta_map = array(
			'fave_property_bedrooms'  => 'bedrooms',
			'fave_property_bathrooms' => 'bathrooms',
			'fave_property_garage'    => 'parking_spaces',
			'fave_property_size'      => 'construction_size',
			'fave_property_land'      => 'lot_size',
		);
		foreach ( $meta_map as $fave => $eb ) {
			if ( isset( $property[ $eb ] ) && '' !== $property[ $eb ] && null !== $property[ $eb ] ) {
				update_post_meta( $post_id, $fave, sanitize_text_field( (string) $property[ $eb ] ) );
			}
		}

		$loc = isset( $property['location'] ) && is_array( $property['location'] ) ? $property['location'] : array();
		if ( ! empty( $loc['name'] ) ) {
			update_post_meta( $post_id, 'fave_property_map_address', sanitize_text_field( $loc['name'] ) );
			update_post_meta( $post_id, '_ebs_location_name', sanitize_text_field( $loc['name'] ) );
			// First segment of "Neighborhood, City, State" → city term best-effort.
			$parts = array_map( 'trim', explode( ',', $loc['name'] ) );
			if ( count( $parts ) >= 2 ) {
				wp_set_object_terms( $post_id, sanitize_text_field( $parts[ count( $parts ) - 2 ] ), 'property_city' );
				wp_set_object_terms( $post_id, sanitize_text_field( end( $parts ) ), 'property_state' );
			}
		}
		if ( isset( $loc['latitude'], $loc['longitude'] ) ) {
			update_post_meta( $post_id, 'fave_property_location', $loc['latitude'] . ',' . $loc['longitude'] );
		}
		if ( ! empty( $loc['street'] ) ) {
			update_post_meta( $post_id, 'fave_property_address', sanitize_text_field( $loc['street'] ) );
		}
		if ( ! empty( $loc['postal_code'] ) ) {
			update_post_meta( $post_id, 'fave_property_zip', sanitize_text_field( $loc['postal_code'] ) );
		}
		update_post_meta( $post_id, 'fave_property_map', '1' );

		// Taxonomies: status + reverse type mapping.
		$op_type = isset( $op['type'] ) ? $op['type'] : 'sale';
		wp_set_object_terms( $post_id, ( 'rental' === $op_type ) ? 'For Rent' : 'For Sale', 'property_status' );
		if ( ! empty( $property['property_type'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( $property['property_type'] ), 'property_type' );
			update_post_meta( $post_id, '_ebs_property_type', sanitize_text_field( $property['property_type'] ) );
		}
		if ( ! empty( $property['internal_id'] ) ) {
			update_post_meta( $post_id, 'fave_property_id', sanitize_text_field( $property['internal_id'] ) );
		}

		// Sync bookkeeping (same _ebs_* keys the push engine reads).
		update_post_meta( $post_id, '_ebs_eb_public_id', sanitize_text_field( $public_id ) );
		update_post_meta( $post_id, '_ebs_is_collaboration', '0' );
		update_post_meta( $post_id, '_ebs_sync_status', 'synced' );
		update_post_meta( $post_id, '_ebs_last_synced_at', current_time( 'mysql' ) );
		if ( ! empty( $property['public_url'] ) ) {
			update_post_meta( $post_id, '_ebs_public_url', esc_url_raw( $property['public_url'] ) );
		}

		// Images → media library + Houzez gallery meta.
		$images = array();
		if ( ! empty( $property['property_images'] ) && is_array( $property['property_images'] ) ) {
			$images = $property['property_images'];
		} elseif ( ! empty( $property['title_image_full'] ) ) {
			$images = array( array( 'url' => $property['title_image_full'] ) );
		}
		if ( $images ) {
			EBS_Images::ingest( $post_id, $images, false );
			// Register every attached image in Houzez's gallery meta.
			delete_post_meta( $post_id, 'fave_property_images' );
			foreach ( get_attached_media( 'image', $post_id ) as $attachment ) {
				add_post_meta( $post_id, 'fave_property_images', (string) $attachment->ID );
			}
		}

		return $post_id;
	}
}
