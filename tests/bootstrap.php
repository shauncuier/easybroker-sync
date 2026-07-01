<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * Set WP_TESTS_DIR to your wordpress-tests-lib path, e.g.:
 *   export WP_TESTS_DIR=/tmp/wordpress-tests-lib
 * or install with: bin/install-wp-tests.sh wordpress_test root '' localhost latest
 *
 * @package EasyBroker_Sync
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test.
 */
function _ebs_manually_load_plugin() {
	require dirname( __DIR__ ) . '/easybroker-sync.php';
}
tests_add_filter( 'muplugins_loaded', '_ebs_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
