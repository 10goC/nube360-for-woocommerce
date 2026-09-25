<?php
/**
 * Bootstrap of the integration suite: a real WordPress + WooCommerce, loaded
 * through the WordPress core test library (wp-phpunit) against a dedicated
 * test database (see wp-tests-config.php).
 *
 * @package Nube360\WooCommerce
 */

$plugin_dir = dirname( __DIR__, 2 );

require_once $plugin_dir . '/vendor/autoload.php';

// The polyfills path the WordPress test library asks for.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills' );
}

$tests_config = __DIR__ . '/wp-tests-config.php';

if ( ! file_exists( $tests_config ) ) {
	fwrite( STDERR, "Missing {$tests_config}.\nCopy tests/integration/wp-tests-config.php.dist to tests/integration/wp-tests-config.php first (see AGENTS.md).\n" );
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $tests_config );

$tests_dir = $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

$woocommerce_file = getenv( 'WC_PLUGIN_FILE' ) ? getenv( 'WC_PLUGIN_FILE' ) : dirname( $plugin_dir ) . '/woocommerce/woocommerce.php';

if ( ! file_exists( $woocommerce_file ) ) {
	fwrite( STDERR, "WooCommerce not found at {$woocommerce_file}. Set WC_PLUGIN_FILE to its main file.\n" );
	exit( 1 );
}

// Load WooCommerce and this plugin as must-use plugins.
tests_add_filter(
	'muplugins_loaded',
	function () use ( $woocommerce_file, $plugin_dir ) {
		require_once $woocommerce_file;
		require_once $plugin_dir . '/nube360-for-woocommerce.php';
	}
);

// Create WooCommerce's tables and roles in the test database.
tests_add_filter(
	'setup_theme',
	function () {
		WC_Install::install();

		// Reload capabilities now that WooCommerce added its roles.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

require_once $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
