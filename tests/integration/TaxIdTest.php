<?php
/**
 * National ID / tax number captured at registration.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce\Tests\Integration;

use WP_Error;

class TaxIdTest extends TestCase {

	public function tear_down() {
		unset( $_POST['nube360_wc_tax_id'] );
		parent::tear_down();
	}

	public function test_the_registration_form_shows_the_field() {
		ob_start();
		do_action( 'woocommerce_register_form' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="nube360_wc_tax_id"', $html );
		$this->assertStringContainsString( 'required', $html );
	}

	public function test_registration_without_a_tax_id_is_rejected() {
		$errors = apply_filters( 'woocommerce_process_registration_errors', new WP_Error() );

		$this->assertSame( array( 'nube360_wc_tax_id_error' ), $errors->get_error_codes() );
	}

	public function test_the_requirement_can_be_relaxed_with_a_filter() {
		add_filter( 'nube360_wc_tax_id_required', '__return_false' );

		$errors = apply_filters( 'woocommerce_process_registration_errors', new WP_Error() );

		$this->assertEmpty( $errors->get_error_codes() );
	}

	public function test_an_invalid_tax_id_is_rejected() {
		$_POST['nube360_wc_tax_id'] = '20-12345678-1';

		$errors = apply_filters( 'woocommerce_process_registration_errors', new WP_Error() );

		$this->assertSame( array( 'nube360_wc_tax_id_error' ), $errors->get_error_codes() );
	}

	public function test_a_valid_tax_id_is_saved_as_digits_and_reaches_the_order() {
		$_POST['nube360_wc_tax_id'] = '20-12345678-6';
		$errors                     = apply_filters( 'woocommerce_process_registration_errors', new WP_Error() );
		$this->assertEmpty( $errors->get_error_codes() );

		$user_id = self::factory()->user->create();
		do_action( 'woocommerce_created_customer', $user_id, array(), '' );
		$this->assertSame( '20123456786', get_user_meta( $user_id, 'nube360_wc_tax_id', true ) );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->save();

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame( '20123456786', $data['customer']['tax_id'] );
	}
}
