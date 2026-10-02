<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Webhooks;
use WC_Post_Data;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * The events the plugin sends to Nube360 and, above all, the ones it must NOT
 * send (changes that Nube360 itself asked for).
 *
 * @covers \Nube360\WooCommerce\Webhooks
 */
class WebhooksTest extends TestCase {

	private function simple_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->set_sku( 'MUG-1' );
		$product->set_regular_price( '10' );
		$product->save();
		return $product;
	}

	/**
	 * A variable product with one variation, created outside of the API.
	 */
	private function variable_product() {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_sku( 'SH-1' );
		$variation->set_regular_price( '10' );
		$variation->save();

		return array( $parent, $variation );
	}

	/**
	 * What WordPress does when the request ends: WooCommerce syncs the parent of
	 * a variable product (deferred to `shutdown`) and the plugin releases its
	 * guard. Running the whole `shutdown` action would flush PHPUnit's output
	 * buffers, so the two relevant callbacks are run by hand.
	 */
	private function end_request() {
		WC_Post_Data::do_deferred_product_sync();
		$this->assertSame( 999, has_action( 'shutdown', array( Webhooks::class, 'resume' ) ), 'The guard must be released at the very end of the request.' );
		Webhooks::resume();
		remove_action( 'shutdown', array( Webhooks::class, 'resume' ), 999 );
	}

	public function test_editing_a_product_by_hand_notifies_nube360() {
		$product = $this->simple_product();
		$this->erp_requests = array();

		$product->set_name( 'Big mug' );
		$product->save();

		$this->assertSame(
			array( array( 'event' => 'product.updated', 'id' => (string) $product->get_id(), 'variant_id' => (string) $product->get_id() ) ),
			$this->sent_events()
		);
	}

	public function test_the_notification_is_authenticated_and_goes_to_the_configured_instance() {
		$product = $this->simple_product();
		$this->erp_requests = array();

		$product->set_name( 'Big mug' );
		$product->save();

		$this->assertSame( self::ERP_URL . '/ecommerce/notifications/central', $this->erp_requests[0]['url'] );
		$this->assertSame( 'POST', $this->erp_requests[0]['method'] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $this->erp_requests[0]['headers']['Authorization'] );
	}

	public function test_a_variation_edited_by_hand_is_notified_under_its_family() {
		list( $parent, $variation ) = $this->variable_product();
		$this->erp_requests         = array();

		$variation->set_regular_price( '20' );
		$variation->save();

		$this->assertContains(
			array( 'event' => 'product.updated', 'id' => (string) $parent->get_id(), 'variant_id' => (string) $variation->get_id() ),
			$this->sent_events()
		);
	}

	public function test_changing_the_stock_by_hand_is_notified() {
		$product = $this->simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 3 );
		$product->save();
		$this->erp_requests = array();

		wc_update_product_stock( $product, 7 );

		$this->assertContains(
			array( 'event' => 'stock.updated', 'id' => (string) $product->get_id(), 'variant_id' => (string) $product->get_id() ),
			$this->sent_events()
		);
	}

	public function test_a_new_order_is_notified_with_only_its_id() {
		$order = wc_create_order();
		$this->erp_requests = array();

		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );

		$this->assertSame( array( array( 'event' => 'order.created', 'id' => (string) $order->get_id() ) ), $this->sent_events() );
	}

	public function test_nothing_is_sent_until_the_erp_url_is_configured() {
		delete_option( 'nube360_url' );
		$product = $this->simple_product();

		$product->set_name( 'Big mug' );
		$product->save();

		$this->assertSame( array(), $this->erp_requests );
	}

	public function test_a_failing_erp_does_not_break_the_woocommerce_save() {
		$product = $this->simple_product();
		$this->erp_response = array( 'code' => 500, 'body' => 'Internal Server Error' );
		$log                = tempnam( sys_get_temp_dir(), 'nube360-log' );
		$previous_log       = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet

		$product->set_name( 'Big mug' );
		$saved = $product->save();

		ini_set( 'error_log', $previous_log ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = file_get_contents( $log );
		unlink( $log );

		$this->assertGreaterThan( 0, $saved );
		$this->assertStringContainsString( 'failed to notify product.updated', $logged, 'The failure is logged (WP_DEBUG is on).' );
	}

	/* --------------------------------------------------------- no echo to Nube360 */

	public function test_changes_made_through_the_api_are_not_notified_back() {
		$created = $this->api_ok(
			'POST',
			'/products',
			array(
				'title'             => 'Shirt',
				'family_ref'        => '1',
				'family_attributes' => array( 'Color' ),
				'variant'           => array( 'sku' => 'SH-1', 'price' => 10, 'stock' => 3, 'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ) ),
			)
		);
		$this->api_ok( 'POST', '/products', array( 'title' => 'Shirt', 'family_ref' => '1', 'family_attributes' => array( 'Color' ), 'variant' => array( 'sku' => 'SH-2', 'price' => 10, 'stock' => 3, 'values' => array( array( 'attribute' => 'Color', 'value' => 'Blue' ) ) ) ) );
		$this->api_ok( 'PUT', '/products/' . $created['id'] . '/variants/' . $created['variant_id'], array( 'price' => 30, 'stock' => 8 ) );
		$this->api_ok( 'PUT', '/products/' . $created['id'], array( 'title' => 'Linen shirt', 'description' => 'text' ) );

		$this->end_request();

		$this->assertSame( array(), $this->sent_events(), 'Including the parent sync WooCommerce defers to `shutdown`.' );
	}

	public function test_deleting_through_the_api_is_not_notified_back() {
		$created = $this->api_ok( 'POST', '/products', array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG-1', 'price' => 10 ) ) );

		$this->api_ok( 'DELETE', '/products/' . $created['id'] . '/variants/' . $created['variant_id'] );
		$this->end_request();

		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_reading_through_the_api_does_not_silence_later_notifications() {
		$product = $this->simple_product();
		$this->api_ok( 'GET', '/products/' . $product->get_id() );
		$this->erp_requests = array();

		$product->set_name( 'Big mug' );
		$product->save();

		$this->assertCount( 1, $this->sent_events() );
	}

	public function test_manual_edits_are_notified_again_once_the_request_that_came_from_nube360_is_over() {
		$created = $this->api_ok( 'POST', '/products', array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG-1', 'price' => 10 ) ) );
		$this->end_request();

		$product = wc_get_product( $created['id'] );
		$product->set_name( 'Edited by hand' );
		$product->save();

		$this->assertCount( 1, $this->sent_events() );
	}

	/* ------------------------------------------------------ brands and categories */

	/**
	 * Runs the queued term events, as WordPress does at the end of the request.
	 */
	private function flush_term_events() {
		foreach ( $GLOBALS['wp_filter']['shutdown']->callbacks[20] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && 'flush_queued' === $callback['function'][1] ) {
				call_user_func( $callback['function'] );
			}
		}
	}

	private function brand_term() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
		return self::factory()->term->create( array( 'taxonomy' => 'product_brand', 'name' => 'Acme' ) );
	}

	public function test_creating_or_editing_a_brand_notifies_once_at_the_end_of_the_request() {
		$term_id = $this->brand_term();
		wp_update_term( $term_id, 'product_brand', array( 'name' => 'Acme Corp' ) );
		$this->assertSame( array(), $this->sent_events(), 'Nothing is sent before the request ends.' );

		$this->flush_term_events();

		$this->assertSame( array( array( 'event' => 'brand.updated', 'id' => (string) $term_id ) ), $this->sent_events() );
	}

	public function test_changing_the_image_of_a_brand_or_a_category_notifies() {
		$brand    = $this->brand_term();
		$category = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		$this->flush_term_events();
		$this->erp_requests = array();

		update_term_meta( $brand, 'thumbnail_id', 5 );
		update_term_meta( $category, 'thumbnail_id', 6 );
		update_term_meta( $category, 'something_else', 7 );
		$this->flush_term_events();

		$this->assertSame(
			array(
				array( 'event' => 'brand.updated', 'id' => (string) $brand ),
				array( 'event' => 'category.updated', 'id' => (string) $category ),
			),
			$this->sent_events()
		);
	}

	public function test_terms_changed_by_an_incoming_nube360_request_do_not_notify() {
		$this->api_ok( 'POST', '/categories', array( 'name' => 'Shoes', 'image' => 'https://images.test/a.png' ) );
		if ( taxonomy_exists( 'product_brand' ) ) {
			$this->api_ok( 'POST', '/brands', array( 'name' => 'Acme' ) );
		}
		$this->flush_term_events();

		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_deleting_a_brand_by_hand_notifies_its_deletion_and_nothing_else_about_it() {
		$brand = $this->brand_term();
		update_term_meta( $brand, 'thumbnail_id', 5 );

		wp_delete_term( $brand, 'product_brand' );
		$this->flush_term_events();

		$this->assertSame( array( array( 'event' => 'brand.deleted', 'id' => (string) $brand ) ), $this->sent_events() );
	}
}
