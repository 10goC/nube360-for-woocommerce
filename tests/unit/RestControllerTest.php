<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Nube360\WooCommerce\Auth;
use Nube360\WooCommerce\Categories;
use Nube360\WooCommerce\Orders;
use Nube360\WooCommerce\Products;
use Nube360\WooCommerce\RestController;
use WP_Error;
use WP_REST_Request;

/**
 * @covers \Nube360\WooCommerce\RestController
 */
class RestControllerTest extends TestCase {

	/**
	 * @var RestController
	 */
	private $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new RestController();
	}

	private function with_products( $mock ) {
		$this->set_private( $this->controller, 'products', $mock );
		return $mock;
	}

	public function test_it_hooks_into_rest_api_init_and_the_before_callbacks_filter() {
		Actions\expectAdded( 'rest_api_init' )->once();
		Filters\expectAdded( 'rest_request_before_callbacks' )->once();

		new RestController();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The contract Nube360 depends on: every route and the methods it accepts.
	 */
	public function test_it_registers_exactly_the_routes_of_the_contract() {
		$registered = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$registered ) {
				$definitions = isset( $args['methods'] ) ? array( $args ) : $args;
				foreach ( $definitions as $definition ) {
					$this->assertSame( array( Auth::class, 'check_permission' ), $definition['permission_callback'], "$route must be authenticated" );
					$registered[ $namespace . $route ][] = $definition['methods'];
				}
			}
		);

		$this->controller->register_routes();

		$this->assertEquals(
			array(
				'nube360/v1/store'                                          => array( 'GET' ),
				'nube360/v1/categories'                                     => array( 'GET', 'POST' ),
				'nube360/v1/products'                                       => array( 'GET', 'POST' ),
				'nube360/v1/products/batch'                                 => array( 'POST' ),
				'nube360/v1/products/sku/(?P<sku>.+)'                       => array( 'GET' ),
				'nube360/v1/products/(?P<id>\d+)'                           => array( 'GET', 'POST, PUT, PATCH' ),
				'nube360/v1/products/(?P<id>\d+)/variants/(?P<variant_id>\d+)' => array( 'POST, PUT, PATCH', 'DELETE' ),
				'nube360/v1/orders/(?P<id>\d+)'                             => array( 'GET' ),
			),
			$registered
		);
	}

	/**
	 * @dataProvider modifying_methods
	 */
	public function test_requests_that_modify_data_suppress_the_outgoing_webhooks_until_shutdown( $method ) {
		Actions\expectAdded( 'shutdown' )->once();

		$request = new WP_REST_Request( $method, '/nube360/v1/products/5/variants/6' );
		$this->assertSame( 'passthrough', $this->controller->suppress_webhooks_if_modifying( 'passthrough', array(), $request ) );
	}

	public function modifying_methods() {
		return array(
			'POST'   => array( 'POST' ),
			'PUT'    => array( 'PUT' ),
			'PATCH'  => array( 'PATCH' ),
			'DELETE' => array( 'DELETE' ),
		);
	}

	public function test_reads_do_not_suppress_the_webhooks() {
		Actions\expectAdded( 'shutdown' )->never();

		$this->controller->suppress_webhooks_if_modifying( null, array(), new WP_REST_Request( 'GET', '/nube360/v1/products' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_routes_of_other_plugins_are_left_alone() {
		Actions\expectAdded( 'shutdown' )->never();

		$this->controller->suppress_webhooks_if_modifying( null, array(), new WP_REST_Request( 'POST', '/wc/v3/products' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_the_sku_reaches_the_lookup_percent_decoded() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'get_by_sku' )->once()->with( '5084 T85 NOUGAT' )->andReturn( array( 'id' => '135' ) );

		$response = $this->controller->get_product_by_sku( new WP_REST_Request( 'GET', '', array(), array( 'sku' => '5084%20T85%20NOUGAT' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => '135' ), $response->get_data() );
	}

	public function test_an_error_becomes_a_response_with_its_status_and_the_message() {
		$orders = Mockery::mock( Orders::class );
		$orders->shouldReceive( 'get' )->with( 5 )->andReturn( new WP_Error( 'nope', 'Not here', array( 'status' => 404 ) ) );
		$this->set_private( $this->controller, 'orders', $orders );

		$response = $this->controller->get_order( new WP_REST_Request( 'GET', '', array(), array( 'id' => '5' ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array( 'success' => false, 'message' => 'Not here' ), $response->get_data() );
	}

	public function test_an_error_without_a_status_defaults_to_400() {
		$orders = Mockery::mock( Orders::class );
		$orders->shouldReceive( 'get' )->andReturn( new WP_Error( 'nope', 'Bad' ) );
		$this->set_private( $this->controller, 'orders', $orders );

		$response = $this->controller->get_order( new WP_REST_Request( 'GET', '', array(), array( 'id' => '5' ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_listing_products_defaults_to_page_one_of_one_hundred() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'list_all' )->once()->with( 1, 100 )->andReturn( array( 'items' => array(), 'total_pages' => 1 ) );

		$response = $this->controller->get_products( new WP_REST_Request() );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_listing_products_passes_the_requested_page() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'list_all' )->once()->with( 3, 25 )->andReturn( array() );

		$this->controller->get_products( new WP_REST_Request( 'GET', '', array(), array( 'page' => '3', 'per_page' => '25' ) ) );
	}

	public function test_the_batch_endpoint_hands_over_the_items() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'create_batch' )->once()->with( array( array( 'title' => 'A' ) ) )->andReturn( array( 'results' => array() ) );

		$request = new WP_REST_Request( 'POST', '', array(), array(), array( 'items' => array( array( 'title' => 'A' ) ) ) );
		$this->assertSame( 200, $this->controller->post_products_batch( $request )->get_status() );
	}

	public function test_a_batch_without_a_json_body_is_handed_over_as_empty() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'create_batch' )->once()->with( array() )->andReturn( new WP_Error( 'nube360_wc_empty_batch', 'empty', array( 'status' => 400 ) ) );

		$this->assertSame( 400, $this->controller->post_products_batch( new WP_REST_Request() )->get_status() );
	}

	public function test_updating_a_variant_passes_ids_as_integers_and_the_json_body() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'update' )->once()->with( 10, 11, array( 'price' => 5 ) )->andReturn( array( 'success' => true ) );

		$request = new WP_REST_Request( 'PUT', '', array(), array( 'id' => '10', 'variant_id' => '11' ), array( 'price' => 5 ) );
		$this->assertSame( array( 'success' => true ), $this->controller->put_variant( $request )->get_data() );
	}

	public function test_deleting_a_variant_passes_ids_as_integers() {
		$products = $this->with_products( Mockery::mock( Products::class ) );
		$products->shouldReceive( 'delete' )->once()->with( 10, 11 )->andReturn( array( 'success' => true ) );

		$request = new WP_REST_Request( 'DELETE', '', array(), array( 'id' => '10', 'variant_id' => '11' ) );
		$this->assertSame( 200, $this->controller->delete_variant( $request )->get_status() );
	}

	public function test_creating_a_category_uses_the_name_and_parent() {
		$categories = Mockery::mock( Categories::class );
		$categories->shouldReceive( 'find_or_create' )->once()->with( 'Shoes', '7' )->andReturn( array( 'id' => '9' ) );
		$this->set_private( $this->controller, 'categories', $categories );

		$request = new WP_REST_Request( 'POST', '', array(), array( 'name' => 'Shoes', 'parent_id' => '7' ) );
		$this->assertSame( array( 'id' => '9' ), $this->controller->post_categories( $request )->get_data() );
	}

	public function test_the_store_endpoint_describes_the_site() {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Shop' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.test' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'ARS' );

		$this->assertSame(
			array( 'name' => 'My Shop', 'url' => 'https://shop.test', 'currency' => 'ARS' ),
			$this->controller->get_store()->get_data()
		);
	}
}
