<?php
namespace Nube360\WooCommerce\Tests\Integration;

/**
 * The REST contract Nube360 depends on, exercised end to end through the WordPress
 * REST server (routing, authentication, JSON in and out).
 *
 * @covers \Nube360\WooCommerce\RestController
 * @covers \Nube360\WooCommerce\Auth
 */
class RestApiTest extends TestCase {

	private function family_body( $sku, $color ) {
		return array(
			'title'             => 'Shirt',
			'description'       => 'Family text',
			'family_ref'        => '5',
			'family_attributes' => array( 'Color' ),
			'variant'           => array(
				'sku'    => $sku,
				'price'  => 10,
				'stock'  => 3,
				'values' => array( array( 'attribute' => 'Color', 'value' => $color ) ),
			),
		);
	}

	/* ------------------------------------------------------------ authentication */

	public function test_every_route_requires_the_key() {
		$product = $this->api_ok( 'POST', '/products', array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG-1' ) ) );

		$calls = array(
			array( 'GET', '/store' ),
			array( 'GET', '/categories' ),
			array( 'POST', '/categories', array( 'name' => 'Shirts' ) ),
			array( 'GET', '/products' ),
			array( 'POST', '/products' ),
			array( 'POST', '/products/batch' ),
			array( 'GET', '/products/sku/MUG-1' ),
			array( 'GET', '/products/' . $product['id'] ),
			array( 'PUT', '/products/' . $product['id'] ),
			array( 'PUT', '/products/' . $product['id'] . '/variants/' . $product['variant_id'] ),
			array( 'DELETE', '/products/' . $product['id'] . '/variants/' . $product['variant_id'] ),
			array( 'GET', '/orders/1' ),
		);

		// WordPress validates required parameters before the permission callback,
		// so the calls carry the body a legitimate one would.
		foreach ( $calls as $call ) {
			list( $method, $route ) = $call;
			$json                   = isset( $call[2] ) ? $call[2] : null;
			$this->assertSame( 401, $this->api( $method, $route, $json, array(), null )->get_status(), "$method $route without a key" );
			$this->assertSame( 403, $this->api( $method, $route, $json, array(), 'wrong-key' )->get_status(), "$method $route with a wrong key" );
		}
		$this->assertNotFalse( wc_get_product( $product['id'] ), 'Nothing was deleted by the rejected calls.' );
	}

	public function test_nothing_is_allowed_while_the_site_has_no_key_configured() {
		delete_option( 'nube360_api_key' );

		$response = $this->api( 'GET', '/store', null, array(), '' );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'nube360_wc_not_configured', $response->get_data()['code'] );
	}

	public function test_the_key_is_not_accepted_from_the_query_string() {
		$response = $this->api( 'GET', '/store', null, array( 'key' => self::API_KEY ), null );

		$this->assertSame( 401, $response->get_status() );
	}

	/* ------------------------------------------------------------------- store */

	public function test_store_describes_the_site() {
		$store = $this->api_ok( 'GET', '/store' );

		$this->assertSame( get_bloginfo( 'name' ), $store['name'] );
		$this->assertSame( home_url(), $store['url'] );
		$this->assertSame( get_woocommerce_currency(), $store['currency'] );
	}

	/* ---------------------------------------------------------------- products */

	public function test_creating_a_product_returns_its_ids() {
		$body = $this->api_ok( 'POST', '/products', $this->family_body( 'SH-RED', 'Red' ) );

		$this->assertTrue( $body['success'] );
		$this->assertIsString( $body['id'] );
		$this->assertIsString( $body['variant_id'] );
		$this->assertNotSame( $body['id'], $body['variant_id'] );
	}

	public function test_a_creation_error_has_the_status_and_message_shape() {
		$response = $this->api( 'POST', '/products', array( 'title' => 'No sku' ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertIsString( $data['message'] );
		$this->assertSame( array( 'success', 'message' ), array_keys( $data ) );
	}

	public function test_a_request_without_a_json_body_is_a_400_not_a_crash() {
		$response = $this->api( 'POST', '/products' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_the_batch_endpoint_answers_one_result_per_item() {
		$body = $this->api_ok(
			'POST',
			'/products/batch',
			array(
				'items' => array(
					$this->family_body( 'SH-RED', 'Red' ),
					$this->family_body( 'SH-BLUE', 'Blue' ),
					array( 'title' => 'Broken' ),
				),
			)
		);

		$this->assertCount( 3, $body['results'] );
		$this->assertTrue( $body['results'][0]['success'] );
		$this->assertSame( $body['results'][0]['id'], $body['results'][1]['id'] );
		$this->assertFalse( $body['results'][2]['success'] );
		$this->assertArrayHasKey( 'message', $body['results'][2] );
	}

	public function test_an_empty_batch_and_an_oversized_one_are_a_400() {
		$this->assertSame( 400, $this->api( 'POST', '/products/batch', array( 'items' => array() ) )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', '/products/batch', array( 'items' => array_fill( 0, 101, array( 'title' => 'x' ) ) ) )->get_status() );
	}

	public function test_a_family_is_read_with_all_its_variants() {
		$first = $this->api_ok( 'POST', '/products', $this->family_body( 'SH-RED', 'Red' ) );
		$this->api_ok( 'POST', '/products', $this->family_body( 'SH-BLUE', 'Blue' ) );
		$this->start_new_request();

		$family = $this->api_ok( 'GET', '/products/' . $first['id'] );

		$this->assertCount( 2, $family['items'] );
		$this->assertSame( 'Shirt', $family['items'][0]['family_title'] );
		$this->assertSame( 'Family text', $family['items'][0]['family_description'] );
		$this->assertSame( array( array( 'attribute' => 'Color', 'value' => 'Red' ) ), $family['items'][0]['attributes'] );
	}

	public function test_an_unknown_product_is_a_404_with_the_error_shape() {
		$response = $this->api( 'GET', '/products/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	public function test_the_listing_is_paginated() {
		foreach ( array( 'A', 'B', 'C' ) as $sku ) {
			$this->api_ok( 'POST', '/products', array( 'title' => "Mug $sku", 'variant' => array( 'sku' => $sku, 'price' => 1 ) ) );
		}

		$page = $this->api_ok( 'GET', '/products', null, array( 'page' => 2, 'per_page' => 2 ) );

		$this->assertSame( 2, $page['total_pages'] );
		$this->assertCount( 1, $page['items'] );
	}

	/**
	 * Regression: the SKU reaches WordPress still percent-encoded in the URL
	 * path (Nube360 sends it with rawurlencode(), and SKUs often contain spaces).
	 */
	public function test_a_sku_with_spaces_is_found_by_its_percent_encoded_url() {
		$this->api_ok( 'POST', '/products', array( 'title' => 'Bra', 'variant' => array( 'sku' => '5084 T85 NOUGAT', 'price' => 1 ) ) );

		$item = $this->api_ok( 'GET', '/products/sku/' . rawurlencode( '5084 T85 NOUGAT' ) );

		$this->assertSame( '5084 T85 NOUGAT', $item['sku'] );
		$this->assertSame( 404, $this->api( 'GET', '/products/sku/' . rawurlencode( 'NOT THERE' ) )->get_status() );
	}

	public function test_updating_and_deleting_a_variant() {
		$created = $this->api_ok( 'POST', '/products', $this->family_body( 'SH-RED', 'Red' ) );
		$route   = '/products/' . $created['id'] . '/variants/' . $created['variant_id'];

		$this->assertSame( array( 'success' => true ), $this->api_ok( 'PUT', $route, array( 'price' => 42, 'stock' => 1 ) ) );
		wp_cache_flush();
		$this->assertSame( '42', wc_get_product( $created['variant_id'] )->get_regular_price() );

		$this->assertSame( array( 'success' => true ), $this->api_ok( 'DELETE', $route ) );
		wp_cache_flush();
		$this->assertFalse( wc_get_product( $created['variant_id'] ) );
		$this->assertSame( 404, $this->api( 'DELETE', $route )->get_status() );
	}

	public function test_the_texts_of_a_family_are_updated_on_the_parent() {
		$created = $this->api_ok( 'POST', '/products', $this->family_body( 'SH-RED', 'Red' ) );

		$this->assertSame( array( 'success' => true ), $this->api_ok( 'PUT', '/products/' . $created['id'], array( 'title' => 'Linen shirt', 'description' => 'New' ) ) );

		wp_cache_flush();
		$this->assertSame( 'Linen shirt', wc_get_product( $created['id'] )->get_name() );
		$this->assertSame( 404, $this->api( 'PUT', '/products/' . $created['variant_id'], array( 'title' => 'x' ) )->get_status(), 'A variation has no texts of its own.' );
	}

	public function test_a_post_with_method_override_semantics_is_not_needed_put_and_patch_both_work() {
		$created = $this->api_ok( 'POST', '/products', array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG-1', 'price' => 1 ) ) );

		$this->assertSame( 200, $this->api( 'PATCH', '/products/' . $created['id'] . '/variants/' . $created['variant_id'], array( 'price' => 2 ) )->get_status() );
	}

	/* -------------------------------------------------------------- categories */

	public function test_categories_are_created_idempotently_and_listed_with_their_parent() {
		$parent = $this->api_ok( 'POST', '/categories', array( 'name' => 'Clothes' ) );
		$child  = $this->api_ok( 'POST', '/categories', array( 'name' => 'Shirts', 'parent_id' => $parent['id'] ) );
		$again  = $this->api_ok( 'POST', '/categories', array( 'name' => 'Shirts', 'parent_id' => $parent['id'] ) );

		$this->assertSame( $child['id'], $again['id'] );

		$listed = array();
		foreach ( $this->api_ok( 'GET', '/categories' ) as $category ) {
			$listed[ $category['id'] ] = $category;
		}
		$this->assertSame( array( 'id' => $child['id'], 'name' => 'Shirts', 'parent_id' => $parent['id'] ), $listed[ $child['id'] ] );
		$this->assertNull( $listed[ $parent['id'] ]['parent_id'] );
	}

	public function test_a_category_needs_a_name() {
		$this->assertSame( 400, $this->api( 'POST', '/categories', array() )->get_status() );
		$this->assertSame( 400, $this->api( 'POST', '/categories', array( 'name' => '  ' ) )->get_status() );
	}

	/* ------------------------------------------------------------------ orders */

	public function test_an_unknown_order_is_a_404() {
		$response = $this->api( 'GET', '/orders/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/* ------------------------------------------------------------------ routes */

	public function test_the_old_spanish_routes_are_gone() {
		foreach ( array( '/productos', '/categorias', '/pedidos/1', '/productos/lote' ) as $route ) {
			$this->assertSame( 404, $this->api( 'GET', $route )->get_status(), $route );
		}
	}
}
