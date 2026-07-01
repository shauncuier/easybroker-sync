<?php
/**
 * Registers the eb_property custom post type and its taxonomies.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Property post type + taxonomies.
 */
class EBS_Cpt {

	const POST_TYPE = 'eb_property';
	const TAX_OP    = 'eb_operation';
	const TAX_TYPE  = 'eb_property_type';
	const TAX_LOC   = 'eb_location';

	/**
	 * Register CPT + taxonomies.
	 */
	public function register() {
		$labels = array(
			'name'               => _x( 'Properties', 'post type general name', 'easybroker-sync' ),
			'singular_name'      => _x( 'Property', 'post type singular name', 'easybroker-sync' ),
			'menu_name'          => _x( 'Properties', 'admin menu', 'easybroker-sync' ),
			'add_new'            => __( 'Add New', 'easybroker-sync' ),
			'add_new_item'       => __( 'Add New Property', 'easybroker-sync' ),
			'edit_item'          => __( 'Edit Property', 'easybroker-sync' ),
			'new_item'           => __( 'New Property', 'easybroker-sync' ),
			'view_item'          => __( 'View Property', 'easybroker-sync' ),
			'search_items'       => __( 'Search Properties', 'easybroker-sync' ),
			'not_found'          => __( 'No properties found', 'easybroker-sync' ),
			'not_found_in_trash' => __( 'No properties found in Trash', 'easybroker-sync' ),
			'all_items'          => __( 'All Properties', 'easybroker-sync' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => $labels,
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-building',
				'menu_position' => 25,
				'rewrite'      => array( 'slug' => 'properties' ),
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'show_in_rest' => true,
			)
		);

		$this->register_taxonomy( self::TAX_OP, __( 'Operations', 'easybroker-sync' ), __( 'Operation', 'easybroker-sync' ), 'operation' );
		$this->register_taxonomy( self::TAX_TYPE, __( 'Property Types', 'easybroker-sync' ), __( 'Property Type', 'easybroker-sync' ), 'property-type' );
		$this->register_taxonomy( self::TAX_LOC, __( 'Locations', 'easybroker-sync' ), __( 'Location', 'easybroker-sync' ), 'property-location' );
	}

	/**
	 * Register one taxonomy.
	 *
	 * @param string $tax      Taxonomy key.
	 * @param string $plural   Plural label.
	 * @param string $singular Singular label.
	 * @param string $slug     URL slug.
	 */
	private function register_taxonomy( $tax, $plural, $singular, $slug ) {
		register_taxonomy(
			$tax,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => $plural,
					'singular_name' => $singular,
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => $slug ),
			)
		);
	}
}
