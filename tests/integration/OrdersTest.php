<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Products;

/**
 * @covers \Nube360\WooCommerce\Orders
 */
class OrdersTest extends TestCase {

	/**
	 * A WooCommerce order with the given lines: [ product id, quantity ].
	 */
	private function order( array $lines ) {
		$order = wc_create_order();
		foreach ( $lines as list( $product_id, $quantity ) ) {
			$order->add_product( wc_get_product( $product_id ), $quantity );
		}
		$order->set_address(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'Perez',
				'email'      => 'ana@example.com',
				'address_1'  => 'Calle 1',
				'address_2'  => '4B',
				'city'       => 'CABA',
				'state'      => 'C',
				'postcode'   => '1000',
				'country'    => 'AR',
			),
			'billing'
		);
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Bank transfer' );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	private function product( $sku, $price ) {
		$result = ( new Products() )->create( array( 'title' => "Product $sku", 'variant' => array( 'sku' => $sku, 'price' => $price ) ) );
		return (int) $result['id'];
	}

	public function test_it_returns_the_order_in_the_rest_contract_format() {
		$order = $this->order( array( array( $this->product( 'MUG-1', 444 ), 2 ) ) );

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame(
			array(
				'id'             => (string) $order->get_id(),
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
						'sku'      => 'MUG-1',
						'quantity' => 2,
						'price'    => 444.0,
						'name'     => 'Product MUG-1',
					),
				),
				'total'          => (float) $order->get_total(),
				'payment_method' => 'Bank transfer',
			),
			$data
		);
	}

	public function test_the_price_is_the_unit_price_paid() {
		$order = $this->order( array( array( $this->product( 'A', 30 ), 3 ) ) );

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame( 30.0, $data['items'][0]['price'] );
		$this->assertSame( 3, $data['items'][0]['quantity'] );
	}

	public function test_every_line_is_reported() {
		$order = $this->order( array( array( $this->product( 'A', 10 ), 1 ), array( $this->product( 'B', 20 ), 2 ) ) );

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame( array( 'A', 'B' ), wp_list_pluck( $data['items'], 'sku' ) );
	}

	public function test_a_variation_line_reports_the_variation_sku() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'family_attributes' => array( 'Color' ),
				'variant'           => array( 'sku' => 'SH-RED', 'price' => 10, 'stock' => 5, 'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ) ),
			)
		);
		$order = wc_create_order();
		$order->add_product( wc_get_product( $result['variant_id'] ), 1 );
		$order->calculate_totals();
		$order->save();

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame( 'SH-RED', $data['items'][0]['sku'] );
	}

	public function test_the_tax_id_can_be_filled_in_through_a_filter() {
		$order = $this->order( array( array( $this->product( 'A', 10 ), 1 ) ) );
		add_filter(
			'nube360_wc_order_tax_id',
			function ( $tax_id, $filtered_order ) use ( $order ) {
				$this->assertSame( $order->get_id(), $filtered_order->get_id() );
				return '20-12345678-3';
			},
			10,
			2
		);

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertSame( '20-12345678-3', $data['customer']['tax_id'] );
	}

	public function test_a_line_whose_product_was_deleted_has_no_sku() {
		$product_id = $this->product( 'GONE', 10 );
		$order      = $this->order( array( array( $product_id, 1 ) ) );
		wp_delete_post( $product_id, true );
		wp_cache_flush();

		$data = $this->api_ok( 'GET', '/orders/' . $order->get_id() );

		$this->assertNull( $data['items'][0]['sku'] );
	}
}
