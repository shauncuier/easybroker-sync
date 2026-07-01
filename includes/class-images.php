<?php
/**
 * Image helpers: gather outgoing public URLs, and import EasyBroker image URLs.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles image collection (push) and ingestion (pull).
 */
class EBS_Images {

	/**
	 * Collect public image URLs for a post: featured image first, then any
	 * attached images, deduplicated.
	 *
	 * @param int $post_id Post id.
	 * @return array List of URLs.
	 */
	public static function outgoing_urls( $post_id ) {
		$urls = array();

		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		$attachments = get_attached_media( 'image', $post_id );
		foreach ( $attachments as $attachment ) {
			$url = wp_get_attachment_image_url( $attachment->ID, 'full' );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );

		/**
		 * Filter the outgoing image URL list.
		 *
		 * @param array $urls    Image URLs.
		 * @param int   $post_id Source post.
		 */
		return apply_filters( 'ebs_outgoing_image_urls', $urls, $post_id );
	}

	/**
	 * Import a list of EasyBroker image URLs into the media library and attach
	 * them to a post. The first becomes the featured image. Idempotent via a
	 * per-post record of already-imported source URLs.
	 *
	 * @param int   $post_id Target post.
	 * @param array $images  EasyBroker property_images (array of {url,...}) or URL strings.
	 * @param bool  $hotlink If true, do not sideload; store URLs in meta for templates.
	 */
	public static function ingest( $post_id, $images, $hotlink = false ) {
		$urls = array();
		foreach ( (array) $images as $img ) {
			if ( is_string( $img ) ) {
				$urls[] = $img;
			} elseif ( is_array( $img ) && ! empty( $img['url'] ) ) {
				$urls[] = $img['url'];
			}
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		// SSRF hardening: only keep safe public http(s) URLs.
		$urls = array_values( array_filter( $urls, array( __CLASS__, 'is_safe_remote_url' ) ) );

		/**
		 * Cap the number of images ingested per property to bound cron work.
		 *
		 * @param int $max Maximum images.
		 */
		$max  = (int) apply_filters( 'ebs_max_images_per_property', 30 );
		if ( $max > 0 && count( $urls ) > $max ) {
			$urls = array_slice( $urls, 0, $max );
		}

		if ( empty( $urls ) ) {
			return;
		}

		if ( $hotlink ) {
			// Store raw URLs for the template to render; no media library entries.
			update_post_meta( $post_id, '_ebs_remote_images', $urls );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$imported = get_post_meta( $post_id, '_ebs_imported_sources', true );
		$imported = is_array( $imported ) ? $imported : array();
		$first    = ! has_post_thumbnail( $post_id );

		foreach ( $urls as $url ) {
			if ( in_array( $url, $imported, true ) ) {
				continue; // Already imported.
			}
			$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				EBS_Logger::log( 'pull', 'error', 'Image import failed: ' . $attachment_id->get_error_message(), array( 'post_id' => $post_id ) );
				continue;
			}
			$imported[] = $url;
			if ( $first ) {
				set_post_thumbnail( $post_id, $attachment_id );
				$first = false;
			}
		}

		update_post_meta( $post_id, '_ebs_imported_sources', $imported );
	}

	/**
	 * Whether a remote image URL is safe to fetch/store — blocks non-http(s)
	 * schemes and private/loopback/link-local hosts (SSRF defense).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_safe_remote_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		// WordPress' own SSRF-aware validator (scheme, host, port checks).
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		/**
		 * Optional allowlist of hosts permitted for image ingestion. Empty = allow
		 * any public host that passes the SSRF checks.
		 *
		 * @param string[] $hosts Allowlisted hostnames (e.g. 'assets.easybroker.com').
		 */
		$allowed = (array) apply_filters( 'ebs_allowed_image_hosts', array() );
		if ( ! empty( $allowed ) && ! in_array( $host, array_map( 'strtolower', $allowed ), true ) ) {
			return false;
		}

		// Reject literal private / loopback / link-local IP hosts. IPv6 literals
		// arrive bracketed from wp_parse_url ("[::1]") — unwrap before validating.
		$literal = trim( $host, '[]' );
		if ( filter_var( $literal, FILTER_VALIDATE_IP ) ) {
			$ip = $literal;
		} elseif ( $literal !== $host ) {
			return false; // Bracketed host that is not a valid IP literal.
		} else {
			// Note: resolving here and again at fetch time leaves a DNS-rebinding
			// window; the actual download goes through wp_safe_remote_get(), which
			// re-validates, so this check is defense-in-depth only.
			$ip = gethostbyname( $host );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		return true;
	}
}
