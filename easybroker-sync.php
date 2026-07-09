<?php
/**
 * Plugin Name:       EasyBroker Sync
 * Plugin URI:        https://domeluxmerida.com/
 * Description:        Cross-post WordPress property listings to EasyBroker and pull collaboration + own listings back into WordPress for display.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Domelux Merida
 * License:           GPL-2.0-or-later
 * Update URI:        false
 * Text Domain:       easybroker-sync
 * Domain Path:       /languages
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EBS_VERSION', '0.6.0' );
define( 'EBS_FILE', __FILE__ );
define( 'EBS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EBS_URL', plugin_dir_url( __FILE__ ) );
define( 'EBS_BASENAME', plugin_basename( __FILE__ ) );

// Option keys.
define( 'EBS_OPTION', 'ebs_settings' );        // Settings array.
define( 'EBS_LOG_OPTION', 'ebs_sync_log' );    // Ring-buffer log.
define( 'EBS_CRON_HOOK', 'ebs_scheduled_sync' );
define( 'EBS_PUSH_HOOK', 'ebs_async_push' );   // Single event for non-blocking push of one post.

/**
 * Very small PSR-ish autoloader for the plugin's EBS_ classes.
 *
 * Maps class "EBS_Api_Client" -> includes/class-api-client.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'EBS_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		$file = EBS_DIR . 'includes/class-' . $slug . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 */
function ebs_bootstrap() {
	return EBS_Plugin::instance();
}
add_action( 'plugins_loaded', 'ebs_bootstrap' );

/**
 * Activation: register CPT (so rewrite rules exist), schedule cron, flush rewrites.
 */
function ebs_activate() {
	// Ensure the CPT/taxonomies are registered before flushing.
	( new EBS_Cpt() )->register();
	EBS_Scheduler::schedule();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ebs_activate' );

/**
 * Deactivation: unschedule cron, flush rewrites. Data is preserved (see uninstall.php).
 */
function ebs_deactivate() {
	EBS_Scheduler::unschedule();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ebs_deactivate' );
