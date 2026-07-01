<?php
/**
 * [eb_properties] shortcode — renders a responsive grid of property listings.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Property grid shortcode.
 */
class EBS_Shortcode {

	/**
	 * Render the shortcode.
	 *
	 * Attributes:
	 *  - limit:        posts to show (default 12)
	 *  - operation:    sale | rental (optional, filters by eb_operation term)
	 *  - type:         property type slug (optional)
	 *  - collaboration: yes | no | all (default all)
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'         => 12,
				'operation'     => '',
				'type'          => '',
				'collaboration' => 'all',
			),
			$atts,
			'eb_properties'
		);

		$args = array(
			'post_type'      => EBS_Cpt::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['limit'],
			'tax_query'      => array(),
			'meta_query'     => array(),
		);

		if ( $atts['operation'] ) {
			$args['tax_query'][] = array(
				'taxonomy' => EBS_Cpt::TAX_OP,
				'field'    => 'slug',
				'terms'    => sanitize_title( $atts['operation'] ),
			);
		}
		if ( $atts['type'] ) {
			$args['tax_query'][] = array(
				'taxonomy' => EBS_Cpt::TAX_TYPE,
				'field'    => 'slug',
				'terms'    => sanitize_title( $atts['type'] ),
			);
		}
		if ( 'yes' === $atts['collaboration'] ) {
			$args['meta_query'][] = array(
				'key'   => '_ebs_is_collaboration',
				'value' => '1',
			);
		} elseif ( 'no' === $atts['collaboration'] ) {
			$args['meta_query'][] = array(
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
			);
		}

		$query = new WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return '<p class="ebs-empty">' . esc_html__( 'No properties found.', 'easybroker-sync' ) . '</p>';
		}

		ob_start();
		echo '<div class="ebs-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			self::card( get_the_ID() );
		}
		echo '</div>';
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Render a single card. Also reused by the archive template.
	 *
	 * @param int $post_id Post id.
	 */
	public static function card( $post_id ) {
		$price     = EBS_Field_Map::get( $post_id, 'price', '' );
		$currency  = EBS_Field_Map::get( $post_id, 'currency', 'MXN' );
		$operation = EBS_Field_Map::get( $post_id, 'operation_type', '' );
		$beds      = EBS_Field_Map::get( $post_id, 'bedrooms', '' );
		$baths     = EBS_Field_Map::get( $post_id, 'bathrooms', '' );
		$is_collab = EBS_Field_Map::get( $post_id, 'is_collaboration', false );
		?>
		<article class="ebs-card">
			<a class="ebs-card-link" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
				<div class="ebs-card-media">
					<?php
					if ( has_post_thumbnail( $post_id ) ) {
						echo get_the_post_thumbnail( $post_id, 'medium_large' );
					} else {
						$remote = get_post_meta( $post_id, '_ebs_remote_images', true );
						if ( is_array( $remote ) && ! empty( $remote[0] ) ) {
							printf( '<img src="%s" alt="%s" loading="lazy" />', esc_url( $remote[0] ), esc_attr( get_the_title( $post_id ) ) );
						}
					}
					if ( $is_collab ) {
						echo '<span class="ebs-tag ebs-tag-collab">' . esc_html__( 'Partner', 'easybroker-sync' ) . '</span>';
					}
					?>
				</div>
				<div class="ebs-card-body">
					<h3 class="ebs-card-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
					<?php if ( '' !== $price ) : ?>
						<p class="ebs-card-price">
							<?php echo esc_html( self::format_price( $price, $currency ) ); ?>
							<?php if ( $operation ) : ?><span class="ebs-op"><?php echo esc_html( $operation ); ?></span><?php endif; ?>
						</p>
					<?php endif; ?>
					<p class="ebs-card-attrs">
						<?php if ( '' !== $beds ) : ?><span><?php echo esc_html( $beds ); ?> <?php esc_html_e( 'bd', 'easybroker-sync' ); ?></span><?php endif; ?>
						<?php if ( '' !== $baths ) : ?><span><?php echo esc_html( $baths ); ?> <?php esc_html_e( 'ba', 'easybroker-sync' ); ?></span><?php endif; ?>
					</p>
				</div>
			</a>
		</article>
		<?php
	}

	/**
	 * Format a price with a thousands separator and currency.
	 *
	 * @param string $price    Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function format_price( $price, $currency ) {
		$num = number_format_i18n( (float) $price );
		return trim( $num . ' ' . $currency );
	}
}
