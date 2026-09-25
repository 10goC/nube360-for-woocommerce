<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Exception;
use Mockery;
use Nube360\WooCommerce\Products;
use ReflectionProperty;
use WP_Error;

/**
 * Only what can be checked without WooCommerce. Creating products, attributes
 * and variations is covered by the integration suite.
 *
 * @covers \Nube360\WooCommerce\Products
 */
class ProductsTest extends TestCase {

	/**
	 * @var Products
	 */
	private $products;

	protected function setUp(): void {
		parent::setUp();

		$this->products = new Products();

		// Static request cache: family reference => parent id.
		$cache = new ReflectionProperty( Products::class, 'families_by_ref' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );
	}

	private function product( $type, $id, $parent_id = 0 ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_type' )->andReturn( $type );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( $parent_id );
		return $product;
	}

	private function assert_error( $result, $code, $status ) {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/* ---------------------------------------------------------------- create() */

	public function test_creating_requires_a_title() {
		$this->assert_error( $this->products->create( array( 'variant' => array( 'sku' => 'A' ) ) ), 'nube360_wc_title_required', 400 );
		$this->assert_error( $this->products->create( array( 'title' => '', 'variant' => array( 'sku' => 'A' ) ) ), 'nube360_wc_title_required', 400 );
	}

	public function test_creating_requires_a_variant_sku() {
		$this->assert_error( $this->products->create( array( 'title' => 'Shirt' ) ), 'nube360_wc_sku_required', 400 );
		$this->assert_error( $this->products->create( array( 'title' => 'Shirt', 'variant' => array( 'price' => 5 ) ) ), 'nube360_wc_sku_required', 400 );
	}

	public function test_creating_an_existing_sku_returns_its_ids_instead_of_failing() {
		Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 31 );
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variation', 31, 30 ) );

		$result = $this->products->create( array( 'title' => 'Shirt', 'variant' => array( 'sku' => 'SH-1' ) ) );

		$this->assertSame(
			array(
				'success'    => true,
				'id'         => '30',
				'variant_id' => '31',
				'existing'   => true,
			),
			$result
		);
	}

	public function test_an_existing_simple_product_is_its_own_variant() {
		Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 12 );
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 12 ) );

		$result = $this->products->create( array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG' ) ) );

		$this->assertSame( '12', $result['id'] );
		$this->assertSame( '12', $result['variant_id'] );
	}

	/* ----------------------------------------------------------- create_batch() */

	public function test_an_empty_batch_is_rejected() {
		$this->assert_error( $this->products->create_batch( array() ), 'nube360_wc_empty_batch', 400 );
		$this->assert_error( $this->products->create_batch( 'nope' ), 'nube360_wc_empty_batch', 400 );
	}

	public function test_a_batch_over_the_limit_is_rejected() {
		$result = $this->products->create_batch( array_fill( 0, Products::MAX_BATCH + 1, array( 'title' => 'A' ) ) );

		$this->assert_error( $result, 'nube360_wc_batch_too_large', 400 );
		$this->assertStringContainsString( (string) Products::MAX_BATCH, $result->get_error_message() );
	}

	public function test_an_item_that_fails_does_not_stop_the_rest_of_the_batch() {
		Functions\when( 'wc_set_time_limit' )->justReturn( true );
		Functions\expect( 'wp_defer_term_counting' )->once()->with( true );
		Functions\expect( 'wp_defer_term_counting' )->once()->with( false );
		Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 12 );
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 12 ) );

		$result = $this->products->create_batch(
			array(
				'not an item',
				array( 'variant' => array( 'sku' => 'NO-TITLE' ) ),
				array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG' ) ),
			)
		);

		$this->assertCount( 3, $result['results'], 'One result per item, in order.' );
		$this->assertFalse( $result['results'][0]['success'] );
		$this->assertFalse( $result['results'][1]['success'] );
		$this->assertSame( 'The "title" field is required.', $result['results'][1]['message'] );
		$this->assertTrue( $result['results'][2]['success'] );
	}

	public function test_term_counting_is_resumed_even_if_creating_an_item_blows_up() {
		Functions\when( 'wc_set_time_limit' )->justReturn( true );
		Functions\expect( 'wp_defer_term_counting' )->once()->with( true );
		Functions\expect( 'wp_defer_term_counting' )->once()->with( false );
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function () {
				throw new Exception( 'boom' );
			}
		);

		$this->expectException( Exception::class );

		$this->products->create_batch( array( array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG' ) ) ) );
	}

	/* ----------------------------------------------------------- extract_values() */

	public function test_it_maps_attribute_names_to_their_values() {
		$values = $this->call_private(
			$this->products,
			'extract_values',
			array(
				array(
					'values' => array(
						array( 'attribute' => 'Color', 'value' => 'Red' ),
						array( 'attribute' => 'Size', 'value' => 'M' ),
						array( 'attribute' => 'Broken' ),
						array( 'value' => 'orphan' ),
					),
				),
			)
		);

		$this->assertSame( array( 'Color' => 'Red', 'Size' => 'M' ), $values );
	}

	public function test_a_variant_without_values_has_no_attributes() {
		$this->assertSame( array(), $this->call_private( $this->products, 'extract_values', array( array() ) ) );
		$this->assertSame( array(), $this->call_private( $this->products, 'extract_values', array( array( 'values' => 'x' ) ) ) );
	}

	/* -------------------------------------------------------------- update_texts() */

	public function test_texts_cannot_be_updated_on_a_variation_or_a_missing_product() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variation', 31, 30 ) );
		$this->assert_error( $this->products->update_texts( 31, array( 'title' => 'X' ) ), 'nube360_wc_not_found', 404 );

		Functions\when( 'wc_get_product' )->justReturn( false );
		$this->assert_error( $this->products->update_texts( 99, array( 'title' => 'X' ) ), 'nube360_wc_not_found', 404 );
	}

	public function test_only_the_texts_present_in_the_body_are_updated() {
		$product = $this->product( 'variable', 30 );
		$product->shouldReceive( 'set_description' )->once()->with( 'New description' );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assertSame( array( 'success' => true ), $this->products->update_texts( 30, array( 'description' => 'New description' ) ) );
	}

	public function test_a_save_failure_while_updating_texts_is_a_500() {
		$product = $this->product( 'simple', 12 );
		$product->shouldReceive( 'set_name' )->once();
		$product->shouldReceive( 'save' )->andThrow( new Exception( 'db down' ) );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->products->update_texts( 12, array( 'title' => 'Mug' ) );

		$this->assert_error( $result, 'nube360_wc_save_error', 500 );
		$this->assertSame( 'db down', $result->get_error_message() );
	}

	/* ------------------------------------------------------------------- update() */

	public function test_a_variant_that_belongs_to_another_product_is_a_404() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variation', 31, 99 ) );

		$this->assert_error( $this->products->update( 30, 31, array( 'price' => 5 ) ), 'nube360_wc_not_found', 404 );
	}

	public function test_a_variant_id_that_is_not_a_variation_is_a_404() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 31 ) );

		$this->assert_error( $this->products->update( 30, 31, array( 'price' => 5 ) ), 'nube360_wc_not_found', 404 );
	}

	public function test_updating_a_missing_product_is_a_404() {
		Functions\when( 'wc_get_product' )->justReturn( false );

		$this->assert_error( $this->products->update( 5, 5, array() ), 'nube360_wc_not_found', 404 );
	}

	public function test_it_applies_every_field_of_the_body() {
		$product = $this->product( 'simple', 12 );
		$product->shouldReceive( 'set_regular_price' )->once()->with( '12.5' );
		$product->shouldReceive( 'set_manage_stock' )->once()->with( true );
		$product->shouldReceive( 'set_stock_quantity' )->once()->with( 3 );
		$product->shouldReceive( 'set_stock_status' )->once()->with( 'instock' );
		$product->shouldReceive( 'set_name' )->once()->with( 'Mug' );
		$product->shouldReceive( 'set_description' )->once()->with( 'A mug' );
		$product->shouldReceive( 'set_sku' )->once()->with( 'MUG-2' );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Actions\expectAdded( 'shutdown' )->zeroOrMoreTimes();

		$result = $this->products->update(
			12,
			12,
			array(
				'price'       => 12.5,
				'stock'       => 3,
				'title'       => 'Mug',
				'description' => 'A mug',
				'sku'         => 'MUG-2',
			)
		);

		$this->assertSame( array( 'success' => true ), $result );
	}

	public function test_it_touches_only_the_fields_that_came_in_the_body() {
		$product = $this->product( 'variation', 31, 30 );
		$product->shouldReceive( 'set_regular_price' )->once()->with( '9' );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->products->update( 30, 31, array( 'price' => 9 ) );

		// Any other setter would have raised BadMethodCallException on the mock.
		$this->addToAssertionCount( 1 );
	}

	public function test_zero_stock_marks_the_product_out_of_stock() {
		$product = $this->product( 'simple', 12 );
		$product->shouldReceive( 'set_manage_stock' )->once()->with( true );
		$product->shouldReceive( 'set_stock_quantity' )->once()->with( 0 );
		$product->shouldReceive( 'set_stock_status' )->once()->with( 'outofstock' );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->products->update( 12, 12, array( 'stock' => 0 ) );
	}

	public function test_the_webhook_guard_is_released_even_when_saving_fails() {
		$product = $this->product( 'simple', 12 );
		$product->shouldReceive( 'set_regular_price' );
		$product->shouldReceive( 'save' )->andThrow( new Exception( 'db down' ) );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$result = $this->products->update( 12, 12, array( 'price' => 5 ) );

		$this->assert_error( $result, 'nube360_wc_save_error', 500 );
		$guard = new ReflectionProperty( 'Nube360\WooCommerce\Webhooks', 'suppressed' );
		$guard->setAccessible( true );
		$this->assertSame( 0, $guard->getValue() );
	}

	/* ------------------------------------------------------------------- delete() */

	public function test_it_deletes_a_variation_for_good() {
		$product = $this->product( 'variation', 31, 30 );
		$product->shouldReceive( 'delete' )->once()->with( true )->andReturn( true );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assertSame( array( 'success' => true ), $this->products->delete( 30, 31 ) );
	}

	public function test_a_failed_deletion_is_a_500() {
		$product = $this->product( 'simple', 12 );
		$product->shouldReceive( 'delete' )->andReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assert_error( $this->products->delete( 12, 12 ), 'nube360_wc_delete_error', 500 );
	}

	public function test_deleting_something_that_is_not_there_is_a_404() {
		Functions\when( 'wc_get_product' )->justReturn( false );

		$this->assert_error( $this->products->delete( 12, 12 ), 'nube360_wc_not_found', 404 );
	}

	/* ------------------------------------------------------------ get_family() */

	public function test_a_variation_id_is_not_a_family() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variation', 31, 30 ) );
		// is_type() is what the plugin asks.
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'is_type' )->with( 'variation' )->andReturn( true );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		$this->assert_error( $this->products->get_family( 31 ), 'nube360_wc_not_found', 404 );
	}

	public function test_an_unknown_sku_is_a_404() {
		Functions\when( 'wc_get_product_id_by_sku' )->justReturn( 0 );

		$this->assert_error( $this->products->get_by_sku( 'NOPE' ), 'nube360_wc_not_found', 404 );
	}
}
