<?php
/**
 * Hooks into the WooCommerce events Nube360 cares about (new order, product
 * edited by hand, stock edited by hand) and notifies it with a lightweight
 * POST ({"event": ..., "id": ...}) — never the full data, Nube360 asks again
 * with a GET if it needs it.
 *
 * It includes an anti-loop guard: while this plugin is applying a change that
 * came from an incoming Nube360 call (the PUT/DELETE of the REST contract),
 * the matching outgoing webhook is suppressed so that it does not create an
 * infinite Nube360 -> plugin -> Nube360 -> ... cycle.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outgoing hooks towards Nube360.
 */
class Webhooks {

	/**
	 * Static flag: greater than zero while a change that came from an
	 * incoming Nube360 request is being applied (see
	 * RestController). A counter is used instead of a simple
	 * boolean to tolerate nested calls (e.g. updating a variation triggers a
	 * save of the parent).
	 *
	 * @var int
	 */
	private static $suppressed = 0;

	/**
	 * Hooks into WooCommerce.
	 */
	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'notify_new_order' ), 10, 1 );

		add_action( 'woocommerce_update_product', array( $this, 'notify_product_updated' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'notify_product_updated' ), 10, 1 );

		add_action( 'woocommerce_product_set_stock', array( $this, 'notify_stock_updated' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'notify_stock_updated' ), 10, 1 );
	}

	/**
	 * Marks the start of a change originated by an incoming Nube360 call:
	 * suppresses the outgoing webhooks until resume() is called. Supports
	 * nesting (counter).
	 */
	public static function suppress() {
		self::$suppressed++;
	}

	/**
	 * Suppresses the outgoing webhooks until the end of the request,
	 * `shutdown` included: WooCommerce syncs the parent of a variable
	 * product deferred to that hook (WC_Post_Data::deferred_product_sync) and
	 * only then fires `woocommerce_update_product`, when a suppress()/resume()
	 * guard around the save has already ended. It is used for every change
	 * that comes from Nube360 (API requests that modify data, and the
	 * background image job).
	 */
	public static function suppress_until_shutdown() {
		self::suppress();
		add_action( 'shutdown', array( __CLASS__, 'resume' ), 999 );
	}

	/**
	 * Counterpart of suppress(): lowers the counter. The webhooks fire again
	 * when it reaches 0.
	 */
	public static function resume() {
		self::$suppressed = max( 0, self::$suppressed - 1 );
	}

	/**
	 * Whether the anti-loop guard is active.
	 *
	 * @return bool
	 */
	private static function is_suppressed() {
		return self::$suppressed > 0;
	}

	/**
	 * woocommerce_checkout_order_processed hook: notifies "order.created".
	 *
	 * @param int $order_id Id of the newly created order.
	 */
	public function notify_new_order( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$this->notify(
			array(
				'event' => 'order.created',
				'id'    => (string) $order_id,
			)
		);
	}

	/**
	 * woocommerce_update_product / woocommerce_update_product_variation
	 * hooks: notifies "product.updated".
	 *
	 * @param int $product_id Id of the edited product or variation.
	 */
	public function notify_product_updated( $product_id ) {
		if ( self::is_suppressed() || ! $product_id ) {
			return;
		}

		// It includes the parent of a variable product (variant_id = its own
		// id): Nube360 uses it to copy the family's name and description.
		// suppress_until_shutdown() is what prevents echoes of our own
		// changes, not a filter here.
		$this->notify_product( 'product.updated', $product_id );
	}

	/**
	 * woocommerce_product_set_stock / woocommerce_variation_set_stock hooks:
	 * notifies "stock.updated". Both hooks pass the full WC_Product object,
	 * not just the id.
	 *
	 * @param WC_Product $product Product or variation whose stock changed.
	 */
	public function notify_stock_updated( $product ) {
		if ( self::is_suppressed() || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$this->notify_product( 'stock.updated', $product->get_id() );
	}

	/**
	 * Builds and sends the {event, id, variant_id} payload for a product or
	 * variation, resolving the matching family id.
	 *
	 * @param string $event      "product.updated" or "stock.updated".
	 * @param int    $product_id Id of the product or variation.
	 */
	private function notify_product( $event, $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		if ( 'variation' === $product->get_type() ) {
			$id         = $product->get_parent_id();
			$variant_id = $product->get_id();
		} else {
			$id         = $product->get_id();
			$variant_id = $product->get_id();
		}

		$this->notify(
			array(
				'event'      => $event,
				'id'         => (string) $id,
				'variant_id' => (string) $variant_id,
			)
		);
	}

	/**
	 * Makes the POST to {nube360_url}/ecommerce/notifications/central.
	 * It does not fail the WordPress request if Nube360 does not respond: it
	 * only logs it to the debug log when WP_DEBUG is on.
	 *
	 * @param array $payload Body to send.
	 */
	private function notify( $payload ) {
		$url = trim( (string) get_option( 'nube360_url', '' ) );

		if ( '' === $url ) {
			return;
		}

		$client = new HttpClient();
		$result = $client->post( 'ecommerce/notifications/central', $payload );

		if ( ! $result['success'] && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Nube360 for WooCommerce: failed to notify %s (%s)', $payload['event'], $result['error'] ) );
		}
	}
}
