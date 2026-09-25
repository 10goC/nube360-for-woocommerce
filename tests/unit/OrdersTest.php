<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Nube360\WooCommerce\Orders;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\Orders
 */
class OrdersTest extends TestCase {

	private function line( $sku, $quantity, $total, $name, $has_product = true ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );

		$item = Mockery::mock( 'WC_Order_Item_Product' );
		$item->shouldReceive( 'get_product' )->andReturn( $has_product ? $product : false );
		$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
		$item->shouldReceive( 'get_total' )->andReturn( $total );
		$item->shouldReceive( 'get_name' )->andReturn( $name );
		return $item;
	}

	private function order( array $items, array $overrides = array() ) {
		$data  = $overrides + array(
			'full_name'      => 'Ana Perez',
			'payment_title'  => 'Bank transfer',
			'payment_method' => 'bacs',
		);
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 244 );
		$order->shouldReceive( 'get_total' )->andReturn( '1776.00' );
		$order->shouldReceive( 'get_payment_method_title' )->andReturn( $data['payment_title'] );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $data['payment_method'] );
		$order->shouldReceive( 'get_formatted_billing_full_name' )->andReturn( $data['full_name'] );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( 'Ana' );
		$order->shouldReceive( 'get_billing_last_name' )->andReturn( 'Perez' );
		$order->shouldReceive( 'get_billing_email' )->andReturn( 'ana@example.com' );
		$order->shouldReceive( 'get_billing_address_1' )->andReturn( 'Calle 1' );
		$order->shouldReceive( 'get_billing_address_2' )->andReturn( '4B' );
		$order->shouldReceive( 'get_billing_city' )->andReturn( 'CABA' );
		$order->shouldReceive( 'get_billing_state' )->andReturn( 'C' );
		$order->shouldReceive( 'get_billing_postcode' )->andReturn( '1000' );
		$order->shouldReceive( 'get_billing_country' )->andReturn( 'AR' );
		$order->shouldReceive( 'get_items' )->andReturn( $items );
		return $order;
	}

	public function test_an_unknown_order_is_a_404() {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = ( new Orders() )->get( 999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nube360_wc_order_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_it_returns_the_order_in_the_rest_contract_format() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array( $this->line( 'ZZ-1', 2, '1776', 'Simple' ) ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertSame(
			array(
				'id'             => '244',
				'customer'       => array(
					'name'     => 'Ana Perez',
					'email'    => 'ana@example.com',
					'tax_id'   => null,
					'address'  => 'Calle 1 4B',
					'city'     => 'CABA',
					'state'    => 'C',
					'postcode' => '1000',
					'country'  => 'AR',
				),
				'items'          => array(
					array(
						'sku'      => 'ZZ-1',
						'quantity' => 2,
						'price'    => 888.0,
						'name'     => 'Simple',
					),
				),
				'total'          => 1776.0,
				'payment_method' => 'Bank transfer',
			),
			$result
		);
	}

	public function test_the_customer_name_falls_back_to_first_and_last_name() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array(), array( 'full_name' => '  ' ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertSame( 'Ana Perez', $result['customer']['name'] );
	}

	public function test_the_payment_method_id_is_used_when_there_is_no_title() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array(), array( 'payment_title' => '' ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertSame( 'bacs', $result['payment_method'] );
	}

	public function test_the_tax_id_can_be_provided_through_a_filter() {
		$order = $this->order( array() );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Filters\expectApplied( 'nube360_wc_order_tax_id' )->once()->with( null, $order )->andReturn( '20-12345678-3' );

		$result = ( new Orders() )->get( 244 );

		$this->assertSame( '20-12345678-3', $result['customer']['tax_id'] );
	}

	public function test_a_line_whose_product_was_deleted_has_a_null_sku() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array( $this->line( '', 1, '50', 'Gone', false ) ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertNull( $result['items'][0]['sku'] );
	}

	public function test_a_line_with_zero_quantity_does_not_divide_by_zero() {
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array( $this->line( 'ZZ-1', 0, '10', 'Free' ) ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertSame( 10.0, $result['items'][0]['price'] );
	}

	public function test_lines_that_are_not_products_are_skipped() {
		$shipping = Mockery::mock( 'WC_Order_Item' );
		Functions\when( 'wc_get_order' )->justReturn( $this->order( array( $shipping, $this->line( 'ZZ-1', 1, '5', 'Only' ) ) ) );

		$result = ( new Orders() )->get( 244 );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Only', $result['items'][0]['name'] );
	}
}
