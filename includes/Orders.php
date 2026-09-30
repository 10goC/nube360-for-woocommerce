<?php
/**
 * Builds the GET /orders/{id} payload from a WooCommerce order.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WC_Order;
use WC_Order_Item_Product;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapping of WooCommerce orders to the format Nube360 expects.
 */
class Orders {

	/**
	 * Gets an order in the REST contract format.
	 *
	 * @param int $id Order id.
	 *
	 * @return array|WP_Error
	 */
	public function get( $id ) {
		$order = wc_get_order( absint( $id ) );

		if ( ! $order ) {
			return new WP_Error(
				'nube360_wc_order_not_found',
				__( 'The requested order does not exist.', 'nube360-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'id'             => (string) $order->get_id(),
			'customer'       => $this->format_customer( $order ),
			'items'          => $this->format_items( $order ),
			'total'          => (float) $order->get_total(),
			'payment_method' => $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method(),
		);
	}

	/**
	 * Shapes the customer/billing data of an order.
	 *
	 * "tax_id" (national ID / tax number) is not a native WooCommerce field:
	 * it comes from the one the customer entered when registering (see TaxId),
	 * and is null for guests. Sites that capture it elsewhere can override it
	 * with the thenube360_wc_order_tax_id filter.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array
	 */
	private function format_customer( $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		$address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );

		/**
		 * Lets the site fill in or override the customer's tax ID,
		 * e.g. if it captures it through a custom checkout field.
		 *
		 * @param string|null $tax_id Tax id the customer registered with, null if none.
		 * @param WC_Order    $order  Order.
		 */
		$customer_id = $order->get_customer_id();
		$registered  = $customer_id ? TaxId::get_for_user( $customer_id ) : '';
		$tax_id      = apply_filters( 'nube360_wc_order_tax_id', '' !== $registered ? $registered : null, $order );

		return array(
			'name'     => $name,
			'email'    => $order->get_billing_email(),
			'tax_id'   => $tax_id,
			'address'  => $address,
			'city'     => $order->get_billing_city(),
			'state'    => $order->get_billing_state(),
			'postcode' => $order->get_billing_postcode(),
			'country'  => $order->get_billing_country(),
		);
	}

	/**
	 * Shapes the items (lines) of an order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array[]
	 */
	private function format_items( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';

			$items[] = array(
				'sku'      => $sku ? $sku : null,
				'quantity' => (int) $item->get_quantity(),
				'price'    => $item->get_quantity() > 0 ? (float) ( $item->get_total() / $item->get_quantity() ) : (float) $item->get_total(),
				'name'     => $item->get_name(),
			);
		}

		return $items;
	}
}
