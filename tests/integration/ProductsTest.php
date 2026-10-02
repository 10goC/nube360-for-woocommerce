<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Brands;
use Nube360\WooCommerce\Products;
use WP_Error;

/**
 * Creating, reading, updating and deleting products through the plugin's
 * Products class, against real WooCommerce.
 *
 * @covers \Nube360\WooCommerce\Products
 */
class ProductsTest extends TestCase {

	/**
	 * @var Products
	 */
	private $products;

	public function set_up() {
		parent::set_up();
		$this->products = new Products();
	}

	/**
	 * Body of a POST /products for one variant of a family.
	 */
	private function variant_body( $sku, $color, $size, array $extra = array() ) {
		return array_merge(
			array(
				'title'             => 'Cotton shirt',
				'family_ref'        => '77',
				'family_attributes' => array( 'Color', 'Size' ),
				'variant'           => array(
					'sku'    => $sku,
					'price'  => 100.5,
					'stock'  => 4,
					'values' => array(
						array( 'attribute' => 'Color', 'value' => $color ),
						array( 'attribute' => 'Size', 'value' => $size ),
					),
				),
			),
			$extra
		);
	}

	private function simple_body( $sku = 'MUG-1', array $extra = array() ) {
		return array_merge(
			array(
				'title'   => 'Coffee mug',
				'variant' => array(
					'sku'   => $sku,
					'price' => 25,
					'stock' => 10,
				),
			),
			$extra
		);
	}

	private function assert_success( $result ) {
		$this->assertNotInstanceOf( WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
	}

	/* ---------------------------------------------------------- simple products */

	public function test_it_creates_a_simple_product() {
		$category = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Kitchen' ) );

		$result = $this->products->create(
			$this->simple_body( 'MUG-1', array( 'description' => '<p>Ceramic</p>', 'categories' => array( (string) $category, '999999' ) ) )
		);

		$this->assert_success( $result );
		$this->assertSame( $result['id'], $result['variant_id'], 'A simple product is its own variant.' );

		$product = wc_get_product( $result['id'] );
		$this->assertSame( 'simple', $product->get_type() );
		$this->assertSame( 'Coffee mug', $product->get_name() );
		$this->assertSame( 'MUG-1', $product->get_sku() );
		$this->assertSame( '25', $product->get_regular_price() );
		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 10, $product->get_stock_quantity() );
		$this->assertSame( 'instock', $product->get_stock_status() );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( '<p>Ceramic</p>', $product->get_description() );
		$this->assertSame( array( $category ), $product->get_category_ids(), 'Ids that are not categories are dropped.' );
	}

	public function test_it_assigns_the_brands_when_creating_a_product() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
		$brand = ( new Brands() )->sync( 'Acme' );

		$result = $this->products->create( $this->simple_body( 'MUG-1', array( 'brands' => array( $brand['id'], '999999' ) ) ) );

		$this->assert_success( $result );
		$this->assertSame( array( $brand['id'] ), $this->products->get_by_sku( 'MUG-1' )['brands'], 'Ids that are not brands are dropped.' );
	}

	public function test_the_brands_of_a_family_are_read_on_every_variation() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
		$brand = ( new Brands() )->sync( 'Acme' );

		$this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M', array( 'brands' => array( $brand['id'] ) ) ) );
		$this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L' ) );

		$this->assertSame( array( $brand['id'] ), $this->products->get_by_sku( 'SH-RED-M' )['brands'] );
		$this->assertSame( array( $brand['id'] ), $this->products->get_by_sku( 'SH-RED-L' )['brands'] );
	}

	public function test_a_product_created_without_brands_reads_an_empty_list() {
		$this->products->create( $this->simple_body( 'MUG-1' ) );

		$this->assertSame( array(), $this->products->get_by_sku( 'MUG-1' )['brands'] );
	}

	public function test_zero_stock_creates_an_out_of_stock_product() {
		$body                     = $this->simple_body();
		$body['variant']['stock'] = 0;

		$result  = $this->products->create( $body );
		$product = wc_get_product( $result['id'] );

		$this->assertSame( 'outofstock', $product->get_stock_status() );
	}

	public function test_creating_the_same_sku_twice_returns_the_existing_product() {
		$first  = $this->products->create( $this->simple_body() );
		$second = $this->products->create( $this->simple_body() );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertTrue( $second['existing'] );
		$this->assertCount( 1, wc_get_products( array( 'sku' => 'MUG-1', 'limit' => -1 ) ) );
	}

	public function test_the_texts_are_sanitized() {
		$result = $this->products->create( $this->simple_body( 'XSS-1', array( 'title' => 'Mug <script>alert(1)</script>', 'description' => '<p>ok</p><script>alert(1)</script>' ) ) );

		$product = wc_get_product( $result['id'] );
		$this->assertStringNotContainsString( '<script', $product->get_name() );
		$this->assertStringNotContainsString( '<script', $product->get_description() );
		$this->assertStringContainsString( '<p>ok</p>', $product->get_description() );
	}

	/* ------------------------------------------------------------------ families */

	public function test_it_creates_a_variable_product_with_its_first_variation() {
		$result = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M', array( 'description' => 'Family text' ) ) );

		$this->assert_success( $result );
		$this->assertNotSame( $result['id'], $result['variant_id'] );

		$parent = wc_get_product( $result['id'] );
		$this->assertSame( 'variable', $parent->get_type() );
		$this->assertSame( 'Cotton shirt', $parent->get_name() );
		$this->assertSame( 'Family text', $parent->get_description() );
		$this->assertSame( '77', $parent->get_meta( '_nube360_wc_family_ref' ) );
		$this->assertSame( array( 'pa_color', 'pa_size' ), array_keys( $parent->get_attributes() ) );

		$variation = wc_get_product( $result['variant_id'] );
		$this->assertSame( 'variation', $variation->get_type() );
		$this->assertSame( (int) $result['id'], $variation->get_parent_id() );
		$this->assertSame( 'SH-RED-M', $variation->get_sku() );
		$this->assertSame( '100.5', $variation->get_regular_price() );
		$this->assertSame( 4, $variation->get_stock_quantity() );
		$this->assertSame( array( 'pa_color' => 'red', 'pa_size' => 'm' ), $variation->get_attributes() );
	}

	public function test_the_global_attributes_and_their_terms_are_created() {
		$this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );

		$this->assertTrue( taxonomy_exists( 'pa_color' ) );
		$this->assertTrue( taxonomy_exists( 'pa_size' ) );
		$this->assertNotFalse( get_term_by( 'name', 'Red', 'pa_color' ) );
		$this->assertNotFalse( get_term_by( 'name', 'M', 'pa_size' ) );

		$this->start_new_request();
		$this->assertSame( 'Color', wc_attribute_label( 'pa_color' ) );
		$this->assertSame( 'Size', wc_attribute_label( 'pa_size' ) );
	}

	/**
	 * Regression: only the first variation kept its attribute values, because
	 * mutating the existing WC_Product_Attribute in place is not detected as a
	 * change by WooCommerce.
	 */
	public function test_every_variation_of_a_family_keeps_its_attribute_values() {
		$combinations = array(
			array( 'SH-RED-M', 'Red', 'M' ),
			array( 'SH-RED-L', 'Red', 'L' ),
			array( 'SH-BLUE-M', 'Blue', 'M' ),
			array( 'SH-GREEN-XL', 'Green', 'XL' ),
		);

		$parent_id = null;
		foreach ( $combinations as $combination ) {
			$result = $this->products->create( $this->variant_body( ...$combination ) );
			$this->assert_success( $result );
			$parent_id = $parent_id ? $parent_id : $result['id'];
			$this->assertSame( $parent_id, $result['id'], 'All the variants share the same parent (found by family_ref).' );
		}

		wp_cache_flush();
		$parent = wc_get_product( $parent_id );

		$options = array();
		foreach ( $parent->get_attributes() as $name => $attribute ) {
			$options[ $name ] = array_map(
				function ( $term_id ) use ( $name ) {
					return get_term( $term_id, $name )->name;
				},
				$attribute->get_options()
			);
			sort( $options[ $name ] );
			$this->assertTrue( $attribute->get_variation(), "$name is used for variations." );
		}
		$this->assertSame( array( 'Blue', 'Green', 'Red' ), $options['pa_color'] );
		$this->assertSame( array( 'L', 'M', 'XL' ), $options['pa_size'] );

		$this->assertCount( 4, $parent->get_children() );
		foreach ( $combinations as $combination ) {
			$variation_id = wc_get_product_id_by_sku( $combination[0] );
			$variation    = wc_get_product( $variation_id );
			$this->assertSame(
				array(
					'pa_color' => sanitize_title( $combination[1] ),
					'pa_size'  => sanitize_title( $combination[2] ),
				),
				$variation->get_attributes(),
				$combination[0]
			);
		}
	}

	public function test_the_description_is_only_taken_from_the_call_that_creates_the_family() {
		$first = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M', array( 'description' => 'First' ) ) );
		$this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L', array( 'description' => 'Second' ) ) );

		$this->assertSame( 'First', wc_get_product( $first['id'] )->get_description() );
	}

	public function test_a_variant_can_be_added_to_a_family_by_its_id() {
		$first = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M', array( 'family_ref' => '' ) ) );

		$second = $this->products->create(
			$this->variant_body( 'SH-RED-L', 'Red', 'L', array( 'id' => $first['id'], 'family_ref' => '' ) )
		);

		$this->assert_success( $second );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertCount( 2, wc_get_product( $first['id'] )->get_children() );
	}

	public function test_families_with_different_references_are_different_products() {
		$a = $this->products->create( $this->variant_body( 'A-1', 'Red', 'M', array( 'family_ref' => '1' ) ) );
		$b = $this->products->create( $this->variant_body( 'B-1', 'Red', 'M', array( 'family_ref' => '2' ) ) );

		$this->assertNotSame( $a['id'], $b['id'] );
	}

	public function test_adding_a_variant_to_something_that_is_not_a_family_fails() {
		$simple = $this->products->create( $this->simple_body() );

		$result = $this->products->create( $this->variant_body( 'SH-1', 'Red', 'M', array( 'id' => $simple['id'], 'family_ref' => '' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nube360_wc_invalid_family', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_a_product_without_family_attributes_is_simple() {
		$body = $this->variant_body( 'SH-1', 'Red', 'M' );
		unset( $body['family_attributes'] );

		$result = $this->products->create( $body );

		$this->assertSame( 'simple', wc_get_product( $result['id'] )->get_type() );
	}

	/* --------------------------------------------------------------------- batch */

	public function test_a_batch_creates_a_family_and_a_simple_product_in_order() {
		$result = $this->products->create_batch(
			array(
				$this->variant_body( 'SH-RED-M', 'Red', 'M' ),
				$this->variant_body( 'SH-RED-L', 'Red', 'L' ),
				$this->simple_body( 'MUG-1' ),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$results = $result['results'];
		$this->assertCount( 3, $results );
		$this->assertTrue( $results[0]['success'] && $results[1]['success'] && $results[2]['success'] );
		$this->assertSame( $results[0]['id'], $results[1]['id'], 'The second variant joins the family created by the first.' );
		$this->assertNotSame( $results[0]['id'], $results[2]['id'] );
		$this->assertCount( 2, wc_get_product( $results[0]['id'] )->get_children() );
	}

	public function test_a_failing_item_does_not_stop_the_batch_and_reports_its_own_error() {
		$result = $this->products->create_batch(
			array(
				$this->simple_body( 'MUG-1' ),
				array( 'title' => 'No SKU' ),
				$this->simple_body( 'MUG-2' ),
			)
		);

		$results = $result['results'];
		$this->assertTrue( $results[0]['success'] );
		$this->assertFalse( $results[1]['success'] );
		$this->assertStringContainsString( 'sku', $results[1]['message'] );
		$this->assertTrue( $results[2]['success'] );
		$this->assertNotSame( 0, wc_get_product_id_by_sku( 'MUG-2' ) );
	}

	public function test_retrying_a_batch_that_already_ran_is_harmless() {
		$items = array( $this->variant_body( 'SH-RED-M', 'Red', 'M' ), $this->simple_body( 'MUG-1' ) );

		$first  = $this->products->create_batch( $items );
		$second = $this->products->create_batch( $items );

		$this->assertSame( $first['results'][0]['id'], $second['results'][0]['id'] );
		$this->assertTrue( $second['results'][0]['existing'] );
		$this->assertCount( 2, wc_get_products( array( 'limit' => -1, 'type' => array( 'simple', 'variable' ) ) ) );
	}

	/* --------------------------------------------------------------------- read */

	public function test_it_formats_a_simple_item() {
		$result = $this->products->create( $this->simple_body( 'MUG-1', array( 'description' => 'Ceramic' ) ) );

		$item = $this->products->get_by_sku( 'MUG-1' );

		$this->assertSame(
			array(
				'id'           => $result['id'],
				'variant_id'   => $result['id'],
				'title'        => 'Coffee mug',
				'sku'          => 'MUG-1',
				'code'         => null,
				'price'        => 25.0,
				'sale_price'   => null,
				'stock'        => 10,
				// Whatever WooCommerce assigned (its default category, if it exists).
				'categories'   => array_map( 'strval', wc_get_product( $result['id'] )->get_category_ids() ),
				'brands'       => array(),
				'attributes'   => array(),
				'images'       => array(),
				'is_family'    => false,
				'family_title' => null,
				'description'  => 'Ceramic',
			),
			$item
		);
	}

	public function test_it_formats_a_variation_under_its_family() {
		$result = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M', array( 'description' => 'Family text' ) ) );

		$this->start_new_request();
		$item = $this->products->get_by_sku( 'SH-RED-M' );

		$this->assertSame( $result['id'], $item['id'] );
		$this->assertSame( $result['variant_id'], $item['variant_id'] );
		$this->assertSame( 'Cotton shirt - Red / M', $item['title'], 'Variant title is the family name plus its values.' );
		$this->assertTrue( $item['is_family'] );
		$this->assertSame( 'Cotton shirt', $item['family_title'] );
		$this->assertSame( 'Family text', $item['family_description'] );
		$this->assertNull( $item['description'], 'A variation has no text of its own.' );
		$this->assertSame(
			array(
				array( 'attribute' => 'Color', 'value' => 'Red' ),
				array( 'attribute' => 'Size', 'value' => 'M' ),
			),
			$item['attributes']
		);
	}

	public function test_the_sale_price_is_reported_separately() {
		$result  = $this->products->create( $this->simple_body() );
		$product = wc_get_product( $result['id'] );
		$product->set_sale_price( '19' );
		$product->save();

		$item = $this->products->get_by_sku( 'MUG-1' );

		$this->assertSame( 25.0, $item['price'] );
		$this->assertSame( 19.0, $item['sale_price'] );
	}

	public function test_get_family_returns_every_variation_and_rejects_variation_ids() {
		$first = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );
		$this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L' ) );

		$family = $this->products->get_family( $first['id'] );
		$this->assertCount( 2, $family['items'] );
		$this->assertSame( array( $first['id'] ), array_values( array_unique( wp_list_pluck( $family['items'], 'id' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $this->products->get_family( $first['variant_id'] ) );
		$this->assertInstanceOf( WP_Error::class, $this->products->get_family( 999999 ) );
	}

	public function test_a_sku_that_does_not_exist_is_a_404() {
		$result = $this->products->get_by_sku( 'NOPE' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_listing_expands_variations_and_paginates() {
		$this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );
		$this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L' ) );
		$this->products->create( $this->simple_body( 'MUG-1' ) );

		$page1 = $this->products->list_all( 1, 2 );
		$page2 = $this->products->list_all( 2, 2 );

		$this->assertSame( 2, $page1['total_pages'] );
		$this->assertCount( 2, $page1['items'] );
		$this->assertCount( 1, $page2['items'] );

		$skus = array_merge( wp_list_pluck( $page1['items'], 'sku' ), wp_list_pluck( $page2['items'], 'sku' ) );
		sort( $skus );
		$this->assertSame( array( 'MUG-1', 'SH-RED-L', 'SH-RED-M' ), $skus, 'The variable parent itself is not an item.' );
	}

	public function test_listing_an_empty_catalog_has_one_empty_page() {
		$this->assertSame( array( 'items' => array(), 'total_pages' => 1 ), $this->products->list_all() );
	}

	/* ------------------------------------------------------------------- update */

	public function test_it_updates_a_simple_product() {
		$result = $this->products->create( $this->simple_body() );

		$update = $this->products->update(
			$result['id'],
			$result['variant_id'],
			array(
				'price'       => 30.25,
				'stock'       => 0,
				'title'       => 'Big mug',
				'description' => 'Now bigger',
				'sku'         => 'MUG-BIG',
			)
		);

		$this->assertSame( array( 'success' => true ), $update );
		wp_cache_flush();
		$product = wc_get_product( $result['id'] );
		$this->assertSame( '30.25', $product->get_regular_price() );
		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );
		$this->assertSame( 'Big mug', $product->get_name() );
		$this->assertSame( 'Now bigger', $product->get_description() );
		$this->assertSame( 'MUG-BIG', $product->get_sku() );
	}

	public function test_it_updates_a_single_variation_and_leaves_its_siblings_alone() {
		$first  = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );
		$second = $this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L' ) );

		$this->products->update( $first['id'], $first['variant_id'], array( 'price' => 200, 'stock' => 9 ) );

		wp_cache_flush();
		$this->assertSame( '200', wc_get_product( $first['variant_id'] )->get_regular_price() );
		$this->assertSame( 9, wc_get_product( $first['variant_id'] )->get_stock_quantity() );
		$this->assertSame( '100.5', wc_get_product( $second['variant_id'] )->get_regular_price() );
		$this->assertSame( 4, wc_get_product( $second['variant_id'] )->get_stock_quantity() );
	}

	public function test_a_variation_cannot_be_updated_through_another_family() {
		$a = $this->products->create( $this->variant_body( 'A-1', 'Red', 'M', array( 'family_ref' => '1' ) ) );
		$b = $this->products->create( $this->variant_body( 'B-1', 'Red', 'M', array( 'family_ref' => '2' ) ) );

		$result = $this->products->update( $a['id'], $b['variant_id'], array( 'price' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( '100.5', wc_get_product( $b['variant_id'] )->get_regular_price() );
	}

	public function test_it_updates_the_texts_of_a_family_on_its_parent() {
		$result = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );

		$update = $this->products->update_texts( $result['id'], array( 'title' => 'Linen shirt', 'description' => 'New text' ) );

		$this->assertSame( array( 'success' => true ), $update );
		wp_cache_flush();
		$parent = wc_get_product( $result['id'] );
		$this->assertSame( 'Linen shirt', $parent->get_name() );
		$this->assertSame( 'New text', $parent->get_description() );
		$this->assertSame( 'SH-RED-M', wc_get_product( $result['variant_id'] )->get_sku(), 'Variations are untouched.' );
	}

	public function test_texts_cannot_be_set_on_a_variation() {
		$result = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );

		$this->assertInstanceOf( WP_Error::class, $this->products->update_texts( $result['variant_id'], array( 'title' => 'X' ) ) );
	}

	/* ------------------------------------------------------------------- delete */

	public function test_deleting_a_variation_keeps_its_family_and_siblings() {
		$first  = $this->products->create( $this->variant_body( 'SH-RED-M', 'Red', 'M' ) );
		$second = $this->products->create( $this->variant_body( 'SH-RED-L', 'Red', 'L' ) );

		$this->assertSame( array( 'success' => true ), $this->products->delete( $first['id'], $first['variant_id'] ) );

		wp_cache_flush();
		$this->assertFalse( wc_get_product( $first['variant_id'] ) );
		$this->assertNotFalse( wc_get_product( $second['variant_id'] ) );
		$this->assertNotFalse( wc_get_product( $first['id'] ) );
	}

	public function test_deleting_a_simple_product_removes_it() {
		$result = $this->products->create( $this->simple_body() );

		$this->assertSame( array( 'success' => true ), $this->products->delete( $result['id'], $result['id'] ) );

		$this->assertSame( 0, wc_get_product_id_by_sku( 'MUG-1' ) );
	}

	public function test_deleting_something_that_does_not_exist_is_a_404() {
		$result = $this->products->delete( 999999, 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
