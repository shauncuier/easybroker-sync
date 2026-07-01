<?php
/**
 * Main plugin controller.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires together all plugin components.
 */
final class EBS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var EBS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var EBS_Cpt
	 */
	public $cpt;

	/**
	 * @var EBS_Meta
	 */
	public $meta;

	/**
	 * @var EBS_Admin
	 */
	public $admin;

	/**
	 * @var EBS_Ajax
	 */
	public $ajax;

	/**
	 * @var EBS_Scheduler
	 */
	public $scheduler;

	/**
	 * Get the singleton.
	 *
	 * @return EBS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (use instance()).
	 */
	private function __construct() {}

	/**
	 * Wire hooks and instantiate components.
	 */
	private function init() {
		load_plugin_textdomain( 'easybroker-sync', false, dirname( EBS_BASENAME ) . '/languages' );

		$this->cpt       = new EBS_Cpt();
		$this->meta      = new EBS_Meta();
		$this->scheduler = new EBS_Scheduler();

		add_action( 'init', array( $this->cpt, 'register' ) );
		$this->meta->hooks();
		$this->scheduler->hooks();

		// Front-end display: template loading + shortcode/block.
		add_filter( 'single_template', array( $this, 'single_template' ) );
		add_filter( 'archive_template', array( $this, 'archive_template' ) );
		add_shortcode( 'eb_properties', array( 'EBS_Shortcode', 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );

		if ( is_admin() ) {
			$this->admin = new EBS_Admin();
			$this->ajax  = new EBS_Ajax();
			$this->admin->hooks();
			$this->ajax->hooks();
		}
	}

	/**
	 * Convenience accessor for a settings value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = '' ) {
		$settings = get_option( EBS_OPTION, array() );
		if ( 'api_key' === $key && defined( 'EBS_API_KEY' ) && EBS_API_KEY ) {
			return EBS_API_KEY; // wp-config constant wins.
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Load the plugin single template for eb_property unless the theme provides one.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function single_template( $template ) {
		if ( is_singular( 'eb_property' ) && ! $this->theme_has_template( 'single-eb_property.php' ) ) {
			$fallback = EBS_DIR . 'templates/single-eb_property.php';
			if ( is_readable( $fallback ) ) {
				return $fallback;
			}
		}
		return $template;
	}

	/**
	 * Load the plugin archive template for eb_property unless the theme provides one.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function archive_template( $template ) {
		if ( is_post_type_archive( 'eb_property' ) && ! $this->theme_has_template( 'archive-eb_property.php' ) ) {
			$fallback = EBS_DIR . 'templates/archive-eb_property.php';
			if ( is_readable( $fallback ) ) {
				return $fallback;
			}
		}
		return $template;
	}

	/**
	 * Whether the active theme (or child) supplies a given template file.
	 *
	 * @param string $file Template filename.
	 * @return bool
	 */
	private function theme_has_template( $file ) {
		return (bool) locate_template( array( $file ) );
	}

	/**
	 * Enqueue front-end styles.
	 */
	public function front_assets() {
		wp_enqueue_style( 'ebs-public', EBS_URL . 'assets/public.css', array(), EBS_VERSION );
	}
}
