<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Nube360\WooCommerce\Webhooks;
use ReflectionProperty;

/**
 * @covers \Nube360\WooCommerce\Webhooks
 */
class WebhooksTest extends TestCase {

	/**
	 * Every outgoing HTTP call the plugin makes, as decoded arrays.
	 *
	 * @var array
	 */
	private $sent;

	protected function setUp(): void {
		parent::setUp();

		$this->sent = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return 'nube360_url' === $name ? 'https://erp.test/nube360/acme/' : ( 'nube360_api_key' === $name ? 'k3y' : $default );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				$this->sent[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
					'body'    => json_decode( $args['body'], true ),
				);
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );
	}

	private function product( $type, $id, $parent_id = 0 ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_type' )->andReturn( $type );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( $parent_id );
		return $product;
	}

	public function test_it_hooks_into_the_woocommerce_events() {
		Actions\expectAdded( 'woocommerce_checkout_order_processed' )->once();
		Actions\expectAdded( 'woocommerce_update_product' )->once();
		Actions\expectAdded( 'woocommerce_update_product_variation' )->once();
		Actions\expectAdded( 'woocommerce_product_set_stock' )->once();
		Actions\expectAdded( 'woocommerce_variation_set_stock' )->once();

		new Webhooks();

		$this->addToAssertionCount( 1 );
	}

	public function test_the_guard_is_a_counter_so_nested_suppressions_work() {
		$webhooks = new Webhooks();
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 5 ) );

		Webhooks::suppress();
		Webhooks::suppress();
		Webhooks::resume();
		$webhooks->notify_product_updated( 5 );
		$this->assertSame( array(), $this->sent, 'Still suppressed after one resume().' );

		Webhooks::resume();
		$webhooks->notify_product_updated( 5 );
		$this->assertCount( 1, $this->sent, 'Notifies again once the counter is back to zero.' );
	}

	public function test_resume_never_goes_below_zero() {
		Webhooks::resume();
		Webhooks::resume();

		$property = new ReflectionProperty( Webhooks::class, 'suppressed' );
		$property->setAccessible( true );
		$this->assertSame( 0, $property->getValue() );
	}

	public function test_suppress_until_shutdown_releases_the_guard_on_the_shutdown_hook() {
		Actions\expectAdded( 'shutdown' )->once()->with( array( Webhooks::class, 'resume' ), 999 );

		Webhooks::suppress_until_shutdown();

		$webhooks = new Webhooks();
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 5 ) );
		$webhooks->notify_product_updated( 5 );
		$this->assertSame( array(), $this->sent );

		// What WordPress does at shutdown.
		Webhooks::resume();
		$webhooks->notify_product_updated( 5 );
		$this->assertCount( 1, $this->sent );
	}

	public function test_a_new_order_is_notified_with_only_its_id() {
		( new Webhooks() )->notify_new_order( 77 );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'https://erp.test/nube360/acme/ecommerce/notifications/central', $this->sent[0]['url'] );
		$this->assertSame( array( 'event' => 'order.created', 'id' => '77' ), $this->sent[0]['body'] );
		$this->assertSame( 'Bearer k3y', $this->sent[0]['headers']['Authorization'] );
	}

	public function test_a_zero_order_id_is_ignored() {
		( new Webhooks() )->notify_new_order( 0 );

		$this->assertSame( array(), $this->sent );
	}

	public function test_a_simple_product_is_its_own_variant() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 12 ) );

		( new Webhooks() )->notify_product_updated( 12 );

		$this->assertSame(
			array(
				'event'      => 'product.updated',
				'id'         => '12',
				'variant_id' => '12',
			),
			$this->sent[0]['body']
		);
	}

	public function test_a_variation_is_notified_under_its_parent_family() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variation', 31, 30 ) );

		( new Webhooks() )->notify_product_updated( 31 );

		$this->assertSame(
			array(
				'event'      => 'product.updated',
				'id'         => '30',
				'variant_id' => '31',
			),
			$this->sent[0]['body']
		);
	}

	public function test_the_parent_of_a_variable_product_is_notified_with_variant_id_equal_to_id() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'variable', 30 ) );

		( new Webhooks() )->notify_product_updated( 30 );

		$this->assertSame( '30', $this->sent[0]['body']['id'] );
		$this->assertSame( '30', $this->sent[0]['body']['variant_id'] );
	}

	public function test_a_product_that_does_not_exist_is_not_notified() {
		Functions\when( 'wc_get_product' )->justReturn( false );

		( new Webhooks() )->notify_product_updated( 99 );

		$this->assertSame( array(), $this->sent );
	}

	public function test_stock_changes_are_notified_from_the_product_object() {
		$product = $this->product( 'variation', 31, 30 );
		Functions\when( 'wc_get_product' )->justReturn( $product );

		( new Webhooks() )->notify_stock_updated( $product );

		$this->assertSame(
			array(
				'event'      => 'stock.updated',
				'id'         => '30',
				'variant_id' => '31',
			),
			$this->sent[0]['body']
		);
	}

	public function test_stock_hooks_ignore_anything_that_is_not_a_product() {
		( new Webhooks() )->notify_stock_updated( 'not a product' );

		$this->assertSame( array(), $this->sent );
	}

	public function test_nothing_is_sent_while_suppressed() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( 'simple', 5 ) );
		$webhooks = new Webhooks();

		Webhooks::suppress();
		$webhooks->notify_product_updated( 5 );
		$webhooks->notify_stock_updated( $this->product( 'simple', 5 ) );

		$this->assertSame( array(), $this->sent );
	}

	public function test_nothing_is_sent_when_the_erp_url_is_not_configured() {
		Functions\when( 'get_option' )->justReturn( '' );

		( new Webhooks() )->notify_new_order( 5 );

		$this->assertSame( array(), $this->sent );
	}
}
