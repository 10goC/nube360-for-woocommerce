<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Nube360\WooCommerce\Plugin;

/**
 * Loading, hooks, WooCommerce compatibility and clean-up on uninstall.
 */
class PluginTest extends TestCase {

	public function test_the_plugin_is_a_singleton() {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_it_boots_when_woocommerce_is_active() {
		$this->assertTrue( Plugin::instance()->is_woocommerce_active() );
		$this->assertNotFalse( has_action( 'woocommerce_update_product' ), 'Webhooks are hooked.' );
		$this->assertNotFalse( has_action( 'rest_api_init' ), 'The REST controller is hooked.' );
		$this->assertNotFalse( has_action( 'nube360_wc_assign_images' ), 'The image queue callback is hooked.' );
	}

	/**
	 * Composer's autoloader is loaded by the test bootstrap but has no mapping for this
	 * namespace, so this exercises the plugin's own autoloader.
	 */
	public function test_the_plugins_own_autoloader_finds_every_class_in_includes() {
		foreach ( glob( \Nube360\WooCommerce\DIR . 'includes/*.php' ) as $file ) {
			$class = 'Nube360\\WooCommerce\\' . basename( $file, '.php' );
			$this->assertTrue( class_exists( $class ), "$class is autoloaded from " . basename( $file ) );
		}
	}

	public function test_a_class_outside_the_namespace_or_the_plugin_is_left_to_other_autoloaders() {
		$this->assertFalse( class_exists( 'Nube360\\WooCommerce\\DoesNotExist' ) );
		$this->assertFalse( class_exists( 'Some\\Other\\Products' ) );
	}

	public function test_the_routes_are_registered_under_the_nube360_namespace() {
		$routes = array_keys( rest_get_server()->get_routes( 'nube360/v1' ) );

		foreach ( array( '/nube360/v1/store', '/nube360/v1/products', '/nube360/v1/products/batch', '/nube360/v1/categories' ) as $route ) {
			$this->assertContains( $route, $routes );
		}
	}

	public function test_it_declares_compatibility_with_high_performance_order_storage() {
		do_action( 'before_woocommerce_init' );

		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables', true );

		$this->assertContains( 'nube360-for-woocommerce/nube360-for-woocommerce.php', $plugins['compatible'] );
		$this->assertNotContains( 'nube360-for-woocommerce/nube360-for-woocommerce.php', $plugins['incompatible'] );
	}

	public function test_the_admin_notice_for_a_missing_woocommerce_is_only_for_users_who_can_activate_plugins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Plugin::instance()->render_woocommerce_notice();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::instance()->render_woocommerce_notice();
		$this->assertStringContainsString( 'requires WooCommerce', ob_get_clean() );
	}

	public function test_the_translations_shipped_with_the_plugin_load() {
		$text = 'Save changes';

		$this->assertSame( $text, __( $text, 'nube360-for-woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n

		load_textdomain( 'nube360-for-woocommerce', \Nube360\WooCommerce\DIR . 'languages/nube360-for-woocommerce-es_ES.mo' );
		$this->assertSame( 'Guardar cambios', __( $text, 'nube360-for-woocommerce' ) ); // phpcs:ignore WordPress.WP.I18n

		unload_textdomain( 'nube360-for-woocommerce' );
	}

	public function test_activation_and_deactivation_flush_the_rewrite_rules_without_errors() {
		\Nube360\WooCommerce\activate();
		\Nube360\WooCommerce\deactivate();

		$this->assertNotFalse( has_action( 'activate_' . \Nube360\WooCommerce\BASENAME ) );
		$this->assertNotFalse( has_action( 'deactivate_' . \Nube360\WooCommerce\BASENAME ) );
	}

	public function test_uninstalling_removes_every_option_the_plugin_saved() {
		update_option( 'nube360_wc_connected', true );
		update_option( 'nube360_wc_last_handshake', '2026-09-25 12:00:00' );
		update_option( 'unrelated_option', 'keep me' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'nube360-for-woocommerce/nube360-for-woocommerce.php' );
		}
		require \Nube360\WooCommerce\DIR . 'uninstall.php';

		foreach ( array( 'nube360_url', 'nube360_api_key', 'nube360_wc_connected', 'nube360_wc_last_handshake' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertSame( 'keep me', get_option( 'unrelated_option' ) );
	}

	public function test_there_is_no_option_left_behind_by_the_old_names() {
		// Everything the plugin stores is under one of these prefixes.
		$this->assertFalse( get_option( 'nube360_sync_url' ) );

		update_option( 'nube360_wc_connected', true );
		global $wpdb;
		$options = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nube360%'" );

		foreach ( $options as $option ) {
			$this->assertContains( $option, array( 'nube360_url', 'nube360_api_key', 'nube360_wc_connected', 'nube360_wc_last_handshake' ), $option );
		}
	}
}
