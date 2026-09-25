<?php
namespace Nube360\WooCommerce\Tests\Integration;

/**
 * Sanity checks of the test environment itself.
 */
class BootstrapTest extends TestCase {

	public function test_woocommerce_and_the_plugin_are_loaded() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( class_exists( 'Nube360\WooCommerce\Plugin' ) );
		$this->assertSame( '1.0.0', \Nube360\WooCommerce\VERSION );
	}

	public function test_the_tests_run_against_the_test_database() {
		global $wpdb;
		$this->assertStringContainsString( 'test', $wpdb->dbname );
		$this->assertSame( 'wptests_', $wpdb->prefix );
	}
}
