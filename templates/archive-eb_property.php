<?php
/**
 * Archive template for eb_property. Falls back here only when the active theme
 * does not provide its own archive-eb_property.php.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main class="ebs-archive">
	<header class="ebs-archive-header">
		<h1><?php post_type_archive_title(); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="ebs-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				EBS_Shortcode::card( get_the_ID() );
			endwhile;
			?>
		</div>

		<div class="ebs-pagination">
			<?php
			the_posts_pagination(
				array(
					'prev_text' => __( 'Previous', 'easybroker-sync' ),
					'next_text' => __( 'Next', 'easybroker-sync' ),
				)
			);
			?>
		</div>
	<?php else : ?>
		<p class="ebs-empty"><?php esc_html_e( 'No properties found.', 'easybroker-sync' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
