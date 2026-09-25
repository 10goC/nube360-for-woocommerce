<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Admin;

/**
 * The settings screen and the handshake that links the site to Nube360.
 *
 * @covers \Nube360\WooCommerce\Admin
 */
class AdminTest extends TestCase {

	/**
	 * @var Admin
	 */
	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = new Admin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function handshake() {
		$method = new \ReflectionMethod( $this->admin, 'do_handshake' );
		$method->setAccessible( true );
		return $method->invoke( $this->admin );
	}

	private function render() {
		ob_start();
		$this->admin->render_page();
		return ob_get_clean();
	}

	/* --------------------------------------------------------------- handshake */

	public function test_the_handshake_announces_the_site_and_its_api_base_to_the_erp() {
		$this->handshake();

		$this->assertCount( 1, $this->erp_requests );
		$request = $this->erp_requests[0];
		$this->assertSame( self::ERP_URL . '/ecommerce/notifications/vincular', $request['url'] );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $request['headers']['Authorization'] );
		$this->assertSame(
			array(
				'site_url'  => home_url(),
				'site_name' => get_bloginfo( 'name' ),
				'api_base'  => '/wp-json/nube360/v1',
			),
			$request['body']
		);
	}

	public function test_a_successful_handshake_marks_the_site_as_connected() {
		$result = $this->handshake();

		$this->assertTrue( $result['success'] );
		$this->assertTrue( (bool) get_option( 'nube360_wc_connected' ) );
		$this->assertNotEmpty( get_option( 'nube360_wc_last_handshake' ) );
	}

	public function test_a_rejected_handshake_leaves_the_site_disconnected() {
		update_option( 'nube360_wc_connected', true );
		$this->erp_response = array( 'code' => 401, 'body' => '{"success":false,"message":"Clave inválida"}' );

		$result = $this->handshake();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Clave inválida', $result['error'] );
		$this->assertFalse( (bool) get_option( 'nube360_wc_connected' ) );
	}

	public function test_an_answer_that_is_not_the_expected_json_is_not_a_connection() {
		$this->erp_response = array( 'code' => 200, 'body' => '<html>a login page</html>' );

		$this->assertFalse( $this->handshake()['success'] );
	}

	/* ------------------------------------------------------------------ screen */

	public function test_it_adds_the_settings_page_under_the_settings_menu() {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->admin, 'register_menu' ) ) );

		global $submenu;
		$submenu = array();
		$this->admin->register_menu();

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertContains( 'nube360-for-woocommerce', $slugs );
	}

	public function test_the_form_shows_the_saved_url_and_key() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="nube360_url"', $html );
		$this->assertStringContainsString( 'value="' . self::ERP_URL . '"', $html );
		$this->assertStringContainsString( 'name="nube360_api_key"', $html );
		$this->assertStringContainsString( 'value="' . self::API_KEY . '"', $html );
		$this->assertStringContainsString( 'name="nube360_wc_nonce"', $html );
		$this->assertStringContainsString( 'name="nube360_wc_save"', $html );
		$this->assertStringContainsString( 'name="nube360_wc_test"', $html );
	}

	public function test_the_connection_status_is_shown() {
		$this->assertStringContainsString( 'Not connected', $this->render() );

		update_option( 'nube360_wc_connected', true );
		update_option( 'nube360_wc_last_handshake', '2026-09-25 12:00:00' );
		$html = $this->render();

		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringContainsString( '2026-09-25 12:00:00', $html );
	}

	public function test_the_background_images_queue_is_shown() {
		$this->assertMatchesRegularExpression( '/\d+ pending, \d+ failed/', $this->render() );
	}

	public function test_the_values_are_escaped() {
		update_option( 'nube360_url', '"><script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->render() );
	}

	public function test_users_who_cannot_manage_the_shop_see_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_assets_are_only_loaded_on_the_settings_page() {
		wp_dequeue_style( 'nube360-for-woocommerce-admin' );
		wp_dequeue_script( 'nube360-for-woocommerce-admin' );

		$this->admin->enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_style_is( 'nube360-for-woocommerce-admin', 'enqueued' ) );

		$this->admin->enqueue_assets( 'settings_page_nube360-for-woocommerce' );
		$this->assertTrue( wp_style_is( 'nube360-for-woocommerce-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'nube360-for-woocommerce-admin', 'enqueued' ) );
		$this->assertStringContainsString( 'unsavedChanges', wp_scripts()->get_data( 'nube360-for-woocommerce-admin', 'data' ) );
	}
}
