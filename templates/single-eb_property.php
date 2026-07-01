<?php
/**
 * Single template for eb_property. Falls back here only when the active theme
 * does not provide its own single-eb_property.php.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$post_id   = get_the_ID();
	$price     = EBS_Field_Map::get( $post_id, 'price', '' );
	$currency  = EBS_Field_Map::get( $post_id, 'currency', 'MXN' );
	$operation = EBS_Field_Map::get( $post_id, 'operation_type', '' );
	$address   = EBS_Field_Map::get( $post_id, 'location_name', '' );
	$is_collab = EBS_Field_Map::get( $post_id, 'is_collaboration', false );
	$public_url = get_post_meta( $post_id, '_ebs_public_url', true );

	$attrs = array(
		__( 'Bedrooms', 'easybroker-sync' )          => EBS_Field_Map::get( $post_id, 'bedrooms', '' ),
		__( 'Bathrooms', 'easybroker-sync' )         => EBS_Field_Map::get( $post_id, 'bathrooms', '' ),
		__( 'Parking', 'easybroker-sync' )           => EBS_Field_Map::get( $post_id, 'parking_spaces', '' ),
		__( 'Construction (m²)', 'easybroker-sync' ) => EBS_Field_Map::get( $post_id, 'construction_size', '' ),
		__( 'Lot (m²)', 'easybroker-sync' )          => EBS_Field_Map::get( $post_id, 'lot_size', '' ),
	);
	?>
	<main class="ebs-single">
		<article <?php post_class( 'ebs-single-article' ); ?>>
			<header class="ebs-single-header">
				<h1><?php the_title(); ?></h1>
				<?php if ( '' !== $price ) : ?>
					<p class="ebs-single-price">
						<?php echo esc_html( EBS_Shortcode::format_price( $price, $currency ) ); ?>
						<?php if ( $operation ) : ?><span class="ebs-op"><?php echo esc_html( $operation ); ?></span><?php endif; ?>
					</p>
				<?php endif; ?>
				<?php if ( $address ) : ?><p class="ebs-single-address"><?php echo esc_html( $address ); ?></p><?php endif; ?>
			</header>

			<div class="ebs-single-media">
				<?php
				if ( has_post_thumbnail() ) {
					the_post_thumbnail( 'large' );
				} else {
					$remote = get_post_meta( $post_id, '_ebs_remote_images', true );
					if ( is_array( $remote ) ) {
						foreach ( $remote as $img ) {
							printf( '<img src="%s" alt="%s" loading="lazy" />', esc_url( $img ), esc_attr( get_the_title() ) );
						}
					}
				}
				?>
			</div>

			<table class="ebs-single-attrs">
				<tbody>
				<?php foreach ( $attrs as $label => $value ) : ?>
					<?php if ( '' !== $value ) : ?>
						<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="ebs-single-content">
				<?php the_content(); ?>
			</div>

			<?php if ( $is_collab && $public_url ) : ?>
				<p class="ebs-single-cta">
					<a class="button" href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener nofollow">
						<?php esc_html_e( 'View original listing', 'easybroker-sync' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
