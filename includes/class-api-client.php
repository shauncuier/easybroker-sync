<?php
/**
 * EasyBroker REST API client.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper over the EasyBroker v1 API using the WordPress HTTP API.
 */
class EBS_Api_Client {

	const BASE_URL = 'https://api.easybroker.com/v1';

	/**
	 * API key (X-Authorization header value).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string|null $api_key Optional explicit key; falls back to settings/constant.
	 */
	public function __construct( $api_key = null ) {
		$this->api_key = $api_key ? $api_key : EBS_Plugin::get_setting( 'api_key', '' );
	}

	/**
	 * Whether a key is configured.
	 *
	 * @return bool
	 */
	public function has_key() {
		return ! empty( $this->api_key );
	}

	/**
	 * Build request headers.
	 *
	 * @param bool $write Include content-type for writes.
	 * @return array
	 */
	private function headers( $write = false ) {
		$headers = array(
			'X-Authorization' => $this->api_key,
			'accept'          => 'application/json',
		);
		if ( $write ) {
			$headers['content-type'] = 'application/json';
		}
		return $headers;
	}

	/**
	 * Perform a request.
	 *
	 * @param string     $method HTTP verb.
	 * @param string     $path   Path relative to BASE_URL (leading slash optional).
	 * @param array|null $body   Optional body for write requests.
	 * @param array      $query  Optional query args.
	 * @return array|WP_Error  Decoded body on success (array), WP_Error on failure.
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'ebs_no_key', __( 'No EasyBroker API key is configured.', 'easybroker-sync' ) );
		}

		$url = self::BASE_URL . '/' . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => $this->headers( null !== $body ),
			'timeout' => 30,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
			if ( false === $args['body'] ) {
				// Invalid UTF-8 in a field (bad copy-paste, mojibake) breaks encoding.
				return new WP_Error(
					'ebs_bad_payload',
					__( 'The property contains characters that cannot be encoded — check the title/description for corrupted text.', 'easybroker-sync' )
				);
			}
		}

		// Up to 2 attempts: retry once on 429 / transient 5xx, honoring Retry-After.
		$max_attempts = 2;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( ( 429 === $code || $code >= 500 ) && $attempt < $max_attempts ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$wait        = max( 1, min( 5, $retry_after ) ); // Bounded 1–5s so we never hang a request.
				sleep( $wait );
				continue;
			}
			break;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->extract_error_message( $data, $code );
			// Always carry the HTTP status in the message — it is the single most
			// useful debugging datum and EB error bodies rarely include it.
			if ( false === strpos( $message, 'HTTP ' . $code ) ) {
				$message .= sprintf( ' (HTTP %d)', $code );
			}
			return new WP_Error(
				'ebs_http_' . $code,
				$message,
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		// A 2xx with a non-JSON body means something between us and EasyBroker
		// (proxy, WAF, maintenance page) answered instead — treat it as a failure
		// rather than returning an empty array a caller could mistake for success
		// (e.g. a create would "succeed" without ever storing the returned id).
		if ( null === $data && '' !== trim( (string) $raw ) ) {
			return new WP_Error(
				'ebs_bad_json',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'EasyBroker returned an unexpected non-JSON response (HTTP %d) — possibly a proxy or maintenance page.', 'easybroker-sync' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Turn an EasyBroker error body into a readable string.
	 *
	 * @param mixed $data Decoded body.
	 * @param int   $code HTTP status.
	 * @return string
	 */
	private function extract_error_message( $data, $code ) {
		if ( is_array( $data ) ) {
			if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( ! empty( $data['errors'] ) ) {
				if ( is_array( $data['errors'] ) ) {
					$parts = array();
					foreach ( $data['errors'] as $field => $msgs ) {
						$text    = $this->stringify_error( $msgs );
						$parts[] = is_string( $field ) ? $field . ': ' . $text : $text;
					}
					return implode( ' | ', array_filter( $parts ) );
				}
				return (string) $data['errors'];
			}
			if ( ! empty( $data['message'] ) ) {
				return (string) $data['message'];
			}
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'EasyBroker request failed (HTTP %d).', 'easybroker-sync' ), $code );
	}

	/**
	 * Flatten an error value (string, or arbitrarily nested arrays of strings)
	 * into readable text without ever fataling on implode(array).
	 *
	 * @param mixed $value Error value from the response body.
	 * @return string
	 */
	private function stringify_error( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $v ) {
				$flat[] = $this->stringify_error( $v );
			}
			return implode( ', ', array_filter( $flat ) );
		}
		return '';
	}

	/**
	 * GET a single page.
	 *
	 * @param string $path  Resource path.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	public function get( $path, $query = array() ) {
		return $this->request( 'GET', $path, null, $query );
	}

	/**
	 * GET every page of a paginated collection.
	 *
	 * @param string $path        Resource path (e.g. 'collaborations').
	 * @param string $collection  Key holding the array (e.g. 'content', 'collaborations').
	 * @param array  $query       Extra query args.
	 * @param int    $max_items   Safety cap (0 = no cap).
	 * @return array|WP_Error     Flat array of items, or WP_Error.
	 */
	public function get_all( $path, $collection, $query = array(), $max_items = 0 ) {
		$items = array();
		$page  = 1;
		$limit = isset( $query['limit'] ) ? (int) $query['limit'] : 50;
		$limit = min( 50, max( 1, $limit ) );

		// Safety cap on page count to avoid runaway loops.
		$max_pages = 200;

		while ( $page <= $max_pages ) {
			$query['page']  = $page;
			$query['limit'] = $limit;
			$result         = $this->get( $path, $query );

			if ( is_wp_error( $result ) ) {
				if ( empty( $items ) ) {
					return $result;
				}
				// Return partial results, but record that the list is incomplete so
				// callers/admins never mistake it for the full collection.
				EBS_Logger::log(
					'system',
					'error',
					sprintf(
						/* translators: 1: API path, 2: page number, 3: error message. */
						__( 'Pagination of "%1$s" aborted on page %2$d (%3$s) — results are partial.', 'easybroker-sync' ),
						$path,
						$page,
						$result->get_error_message()
					)
				);
				return $items;
			}

			$batch = $this->pluck_collection( $result, $collection );
			$items = array_merge( $items, $batch );

			if ( $max_items > 0 && count( $items ) >= $max_items ) {
				$items = array_slice( $items, 0, $max_items );
				break;
			}

			// EasyBroker returns pagination.next_page as a *full URL* (or null when done).
			// Use it only as a stop signal and advance the page counter ourselves.
			$has_next = ! empty( $result['pagination']['next_page'] );
			if ( ! $has_next ) {
				break;
			}

			++$page;
			usleep( 60000 ); // Gentle throttle (~16 req/sec) to stay under the 20/sec limit.
		}

		return $items;
	}

	/**
	 * Extract the collection array from a response, tolerating key differences.
	 *
	 * @param array  $result     Response body.
	 * @param string $collection Preferred key.
	 * @return array
	 */
	private function pluck_collection( $result, $collection ) {
		if ( isset( $result[ $collection ] ) && is_array( $result[ $collection ] ) ) {
			return $result[ $collection ];
		}
		// Common fallbacks used across EasyBroker endpoints.
		foreach ( array( 'content', 'collaborations', 'properties', 'data' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				return $result[ $key ];
			}
		}
		return array();
	}

	/**
	 * Retrieve a single property's full detail (list items are thin — the
	 * detail endpoint carries description, images, features, etc.).
	 *
	 * @param string $public_id EasyBroker property id.
	 * @return array|WP_Error
	 */
	public function get_property( $public_id ) {
		return $this->get( 'properties/' . rawurlencode( $public_id ) );
	}

	/**
	 * Resolve a location and its children via GET /locations?query=.
	 * Returns the location object ({ name, full_name, type, localities[] }).
	 *
	 * @param string $query Free text, e.g. "Mérida, Yucatán".
	 * @return array|WP_Error
	 */
	public function search_locations( $query ) {
		return $this->get( 'locations', array( 'query' => $query ) );
	}

	/**
	 * List the agencies you collaborate with. NOTE: EasyBroker's /collaborations
	 * endpoint returns partner *agencies* (agency_id, agency_name, group) — it
	 * does NOT return their property listings.
	 *
	 * @return array|WP_Error Flat array of agency rows.
	 */
	public function get_collaboration_agencies() {
		return $this->get_all( 'collaborations', 'content', array( 'limit' => 50 ) );
	}

	/**
	 * Create a property (Beta).
	 *
	 * @param array $payload Property payload.
	 * @return array|WP_Error
	 */
	public function create_property( $payload ) {
		return $this->request( 'POST', 'properties', $payload );
	}

	/**
	 * Update a property (Beta).
	 *
	 * @param string $public_id EasyBroker property id.
	 * @param array  $payload   Property payload.
	 * @return array|WP_Error
	 */
	public function update_property( $public_id, $payload ) {
		return $this->request( 'PATCH', 'properties/' . rawurlencode( $public_id ), $payload );
	}

	/**
	 * Test the connection (used by the admin "Test connection" button).
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		return $this->get( 'properties', array( 'limit' => 1 ) );
	}

	/**
	 * Fetch allowed property-type strings, cached in a transient for a day.
	 *
	 * @return array
	 */
	public function property_types() {
		return $this->cached_enum( 'ebs_property_types', 'property_types' );
	}

	/**
	 * Fetch allowed listing-status strings, cached in a transient for a day.
	 *
	 * @return array
	 */
	public function listing_statuses() {
		return $this->cached_enum( 'ebs_listing_statuses', 'listing_statuses' );
	}

	/**
	 * Shared cache helper for small enum lists.
	 *
	 * @param string $transient Transient key.
	 * @param string $path      API path.
	 * @return array
	 */
	private function cached_enum( $transient, $path ) {
		$cached = get_transient( $transient );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->get( $path );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		// Normalise to a flat list of names/ids.
		$list = array();
		$rows = $this->pluck_collection( $result, $path );
		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) {
				$list[] = $row;
			} elseif ( is_array( $row ) ) {
				$list[] = isset( $row['name'] ) ? $row['name'] : ( isset( $row['id'] ) ? $row['id'] : '' );
			}
		}
		$list = array_values( array_filter( $list ) );
		set_transient( $transient, $list, DAY_IN_SECONDS );
		return $list;
	}
}
