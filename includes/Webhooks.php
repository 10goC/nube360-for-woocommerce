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

		// Brands and category images, edited by hand in WordPress. The term
		// itself and its image are saved one after the other, so the events
		// are queued and sent once, at the end of the request, when both are
		// already stored (Nube360 reads them back right away).
		add_action( 'created_' . Brands::TAXONOMY, array( $this, 'queue_brand_updated' ), 10, 1 );
		add_action( 'edited_' . Brands::TAXONOMY, array( $this, 'queue_brand_updated' ), 10, 1 );
		add_action( 'edited_' . Categories::TAXONOMY, array( $this, 'queue_category_updated' ), 10, 1 );
		add_action( 'delete_term', array( $this, 'queue_term_deleted' ), 10, 3 );
		add_action( 'added_term_meta', array( $this, 'queue_term_image_updated' ), 10, 3 );
		add_action( 'updated_term_meta', array( $this, 'queue_term_image_updated' ), 10, 3 );
		add_action( 'deleted_term_meta', array( $this, 'queue_term_image_updated' ), 10, 3 );
	}

	/**
	 * Events waiting to be sent at the end of the request, keyed by
	 * "event:id" so that a term edited several times is notified once.
	 *
	 * @var array[]
	 */
	private static $queued = array();

	/**
	 * created_/edited_product_brand hooks.
	 *
	 * @param int $term_id Brand term id.
	 */
	public function queue_brand_updated( $term_id ) {
		$this->queue( 'brand.updated', $term_id );
	}

	/**
	 * edited_product_cat hook.
	 *
	 * @param int $term_id Category term id.
	 */
	public function queue_category_updated( $term_id ) {
		$this->queue( 'category.updated', $term_id );
	}

	/**
	 * delete_term hook: a brand was deleted. WordPress has already taken it
	 * off its products, and Nube360 does the same on its side. Whatever was
	 * queued for the brand before (its image meta going away, for example)
	 * is dropped: there is nothing left to read.
	 *
	 * @param int    $term_id  Deleted term id.
	 * @param int    $tt_id    Term taxonomy id, unused.
	 * @param string $taxonomy Taxonomy.
	 */
	public function queue_term_deleted( $term_id, $tt_id, $taxonomy ) {
		if ( Brands::TAXONOMY !== $taxonomy ) {
			return;
		}

		unset( self::$queued[ 'brand.updated:' . $term_id ] );
		$this->queue( 'brand.deleted', $term_id );
	}

	/**
	 * added_/updated_/deleted_term_meta hooks: the image of a brand or of a
	 * category changed.
	 *
	 * @param int|int[] $meta_id Meta id(s), unused.
	 * @param int       $term_id Term id.
	 * @param string    $key     Meta key.
	 */
	public function queue_term_image_updated( $meta_id, $term_id, $key ) {
		if ( Brands::THUMBNAIL_META !== $key ) {
			return;
		}

		$term = get_term( (int) $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		if ( Brands::TAXONOMY === $term->taxonomy ) {
			$this->queue( 'brand.updated', $term_id );
		} elseif ( Categories::TAXONOMY === $term->taxonomy ) {
			$this->queue( 'category.updated', $term_id );
		}
	}

	/**
	 * Queues an event to be sent when the request ends.
	 *
	 * @param string $event   Event name.
	 * @param int    $term_id Term id.
	 */
	private function queue( $event, $term_id ) {
		if ( self::is_suppressed() || ! $term_id ) {
			return;
		}

		if ( empty( self::$queued ) ) {
			add_action( 'shutdown', array( $this, 'flush_queued' ), 20 );
		}

		self::$queued[ $event . ':' . $term_id ] = array(
			'event' => $event,
			'id'    => (string) $term_id,
		);
	}

	/**
	 * shutdown hook: sends the queued events.
	 */
	public function flush_queued() {
		$queued       = self::$queued;
		self::$queued = array();

		foreach ( $queued as $payload ) {
			$this->notify( $payload );
		}
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
