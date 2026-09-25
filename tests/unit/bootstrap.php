<?php
/**
 * Bootstrap of the unit suite: no WordPress, no database. WordPress functions
 * are replaced per test with Brain Monkey and the few WordPress/WooCommerce
 * classes the plugin touches are declared in stubs.php.
 *
 * @package Nube360\WooCommerce
 */

$plugin_dir = dirname( __DIR__, 2 );

require_once $plugin_dir . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

// What WordPress defines and the plugin relies on.
define( 'ABSPATH', $plugin_dir . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WP_DEBUG', false );

// The constants the main plugin file defines (see nube360-for-woocommerce.php).
define( 'Nube360\WooCommerce\VERSION', '1.0.0' );
define( 'Nube360\WooCommerce\FILE', $plugin_dir . '/nube360-for-woocommerce.php' );
define( 'Nube360\WooCommerce\DIR', $plugin_dir . '/' );
define( 'Nube360\WooCommerce\URL', 'https://example.test/wp-content/plugins/nube360-for-woocommerce/' );
define( 'Nube360\WooCommerce\BASENAME', 'nube360-for-woocommerce/nube360-for-woocommerce.php' );
define( 'Nube360\WooCommerce\REST_NAMESPACE', 'nube360/v1' );
define( 'Nube360\WooCommerce\API_BASE', '/wp-json/nube360/v1' );

// Same PSR-4 autoloader as the main plugin file (which the unit suite does not
// load, because it boots WordPress hooks). The integration suite exercises the
// real one. Admin is not covered by the unit suite (it renders admin screens).
spl_autoload_register(
	function ( $class_name ) use ( $plugin_dir ) {
		$prefix = 'Nube360\\WooCommerce\\';

		if ( 0 === strpos( $class_name, $prefix ) ) {
			$file = $plugin_dir . '/includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

require_once __DIR__ . '/TestCase.php';
