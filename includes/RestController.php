<?php
/**
 * Registers, under the nube360/v1 namespace, all the REST routes Nube360
 * consumes to sync the catalog and read orders. All of them require the
 * Bearer token described in Auth, validated via
 * permission_callback.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's REST controller.
 */
class RestController {

	/**
	 * Products helper.
	 *
	 * @var Products
	 */
	private $products;

	/**
	 * Categories helper.
	 *
	 * @var Categories
	 */
	private $categories;

	/**
	 * Orders helper.
	 *
	 * @var Orders
	 */
	private $orders;

	/**
	 * Constructor: instantiates the helpers and hooks rest_api_init.
	 */
	public function __construct() {
		$this->products   = new Products();
		$this->categories = new Categories();
		$this->orders     = new Orders();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'suppress_webhooks_if_modifying' ), 10, 3 );
	}

	/**
	 * Every request to this plugin's API that is not a read is originated by
	 * Nube360, so its changes must not be notified back (not even the ones
	 * WooCommerce fires deferred to `shutdown`).
	 *
	 * @param mixed           $response Previous response (unused).
	 * @param array           $handler  Route handler (unused).
	 * @param WP_REST_Request $request  Request.
	 *
	 * @return mixed
	 */
	public function suppress_webhooks_if_modifying( $response, $handler, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . REST_NAMESPACE . '/' ) && 'GET' !== $request->get_method() ) {
			Webhooks::suppress_until_shutdown();
		}

		return $response;
	}

	/**
	 * Registers all the routes of the nube360/v1 namespace.
	 */
	public function register_routes() {
		$ns = REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/store',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_store' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_categories' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
					'args'                => array(
						'name'      => array( 'required' => true ),
						'parent_id' => array( 'required' => false ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/categories/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'put_category' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/brands',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_brands' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_brands' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
					'args'                => array(
						'name' => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/brands/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_brand' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/attributes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_attributes' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'put_attributes' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
					'args'                => array(
						'attributes' => array( 'required' => true ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/attributes/values/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_attribute_value' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_products' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_products' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/products/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'post_products_batch' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/sku/(?P<sku>.+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_by_sku' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_product' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'put_product' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)/variants/(?P<variant_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'put_variant' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_variant' ),
					'permission_callback' => array( Auth::class, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( Auth::class, 'check_permission' ),
			)
		);
	}

	/**
	 * GET /store
	 *
	 * @return WP_REST_Response
	 */
	public function get_store() {
		return new WP_REST_Response(
			array(
				'name'     => get_bloginfo( 'name' ),
				'url'      => home_url(),
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			),
			200
		);
	}

	/**
	 * GET /categories
	 *
	 * @return WP_REST_Response
	 */
	public function get_categories() {
		return new WP_REST_Response( $this->categories->list_all(), 200 );
	}

	/**
	 * POST /categories
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function post_categories( $request ) {
		$name      = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$parent_id = $request->get_param( 'parent_id' );

		$result = $this->categories->find_or_create( $name, $parent_id );

		// Only when the key is present: a request that does not mention the
		// image must not remove the one the category has.
		if ( ! is_wp_error( $result ) && null !== $request->get_param( 'image' ) ) {
			$this->categories->update(
				$result['id'],
				array(
					'image'      => $request->get_param( 'image' ),
					'image_name' => $request->get_param( 'image_name' ),
				)
			);
		}

		return $this->respond( $result );
	}

	/**
	 * PUT /categories/{id}
	 *
	 * Body {"name": "...", "image": "<url>"}, any of them: renames the
	 * category and/or sets its image ("" or null removes it).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function put_category( $request ) {
		$body = $request->get_json_params();

		return $this->respond( $this->categories->update( (int) $request->get_param( 'id' ), is_array( $body ) ? $body : array() ) );
	}

	/**
	 * GET /brands
	 *
	 * @return WP_REST_Response
	 */
	public function get_brands() {
		return new WP_REST_Response( ( new Brands() )->list_all(), 200 );
	}

	/**
	 * POST /brands
	 *
	 * Body {"name": "...", "image": "<url>", "id": "<term id>"}: with an id
	 * of an existing brand, renames it; otherwise finds the brand by name or
	 * creates it. Sets its image ("" or null removes it). Returns {id}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function post_brands( $request ) {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$image = (string) $request->get_param( 'image' );

		return $this->respond( ( new Brands() )->sync( $name, $image, $request->get_param( 'id' ), (string) $request->get_param( 'image_name' ) ) );
	}

	/**
	 * DELETE /brands/{id}
	 *
	 * The products that had the brand are left without it.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_brand( $request ) {
		return $this->respond( ( new Brands() )->delete( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * GET /attributes
	 *
	 * Global attributes with their values and the group of each value, and
	 * the groups with their swatch (colour / image).
	 *
	 * @return WP_REST_Response
	 */
	public function get_attributes() {
		return new WP_REST_Response( array( 'attributes' => ( new Attributes() )->list_all() ), 200 );
	}

	/**
	 * GET /attributes/values/{id}
	 *
	 * One value ({id, attribute, value, group}) by its term id: what Nube360
	 * reads when the store tells it that the group of a value changed.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_attribute_value( $request ) {
		return $this->respond( ( new Attributes() )->get_value( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * PUT /attributes
	 *
	 * Body {"attributes": [{"name": "Size", "values": [{"value": "2", "group": "Babies"}]}]}:
	 * creates the attributes, values and groups that do not exist and puts
	 * each value in its group (an empty group takes it out of any group).
	 * Returns the attributes touched, as GET /attributes does.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function put_attributes( $request ) {
		return $this->respond( ( new Attributes() )->sync( $request->get_param( 'attributes' ) ) );
	}

	/**
	 * GET /products
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_products( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		$result = $this->products->list_all(
			$page ? $page : 1,
			$per_page ? $per_page : 100
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /products/{id}
	 *
	 * Returns {"items": [...]} with ALL the variants of the family (or a
	 * single item for a simple product) — the family id alone is not enough
	 * to identify a specific variant, so Nube360 is the one that filters the
	 * one it cares about, as it already does with the GET /products response.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_product( $request ) {
		$result = $this->products->get_family( (int) $request->get_param( 'id' ) );

		return $this->respond( $result );
	}

	/**
	 * GET /products/sku/{sku}
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_product_by_sku( $request ) {
		// The path segment reaches us still percent-encoded (Nube360 sends it
		// with rawurlencode(), and SKUs commonly contain spaces).
		$result = $this->products->get_by_sku( rawurldecode( (string) $request->get_param( 'sku' ) ) );

		return $this->respond( $result );
	}

	/**
	 * POST /products
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function post_products( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$result = $this->products->create( $body );

		return $this->respond( $result );
	}

	/**
	 * PUT /products/{id}
	 *
	 * Updates the title and/or the description of the product itself (a
	 * simple one, or the parent of a family): {"title": ..., "description": ...}.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function put_product( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		return $this->respond( $this->products->update_texts( (int) $request->get_param( 'id' ), $body ) );
	}

	/**
	 * POST /products/batch
	 *
	 * Body {"items": [<POST /products body>, ...]} — returns
	 * {"results": [...]} with one result per item, in the same order.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function post_products_batch( $request ) {
		$body  = $request->get_json_params();
		$items = ( is_array( $body ) && isset( $body['items'] ) ) ? $body['items'] : array();

		return $this->respond( $this->products->create_batch( $items ) );
	}

	/**
	 * PUT /products/{id}/variants/{variant_id}
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function put_variant( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$result = $this->products->update(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'variant_id' ),
			$body
		);

		return $this->respond( $result );
	}

	/**
	 * DELETE /products/{id}/variants/{variant_id}
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function delete_variant( $request ) {
		$result = $this->products->delete(
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'variant_id' )
		);

		return $this->respond( $result );
	}

	/**
	 * GET /orders/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_order( $request ) {
		$result = $this->orders->get( (int) $request->get_param( 'id' ) );

		return $this->respond( $result );
	}

	/**
	 * Converts a helper result (success array, or WP_Error) into a
	 * WP_REST_Response. Errors are returned with the WP_Error's status (or
	 * 400 if it does not specify one) and body {"success": false, "message": "..."}.
	 *
	 * @param array|WP_Error $result Helper result.
	 *
	 * @return WP_REST_Response
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = ( is_array( $data ) && ! empty( $data['status'] ) ) ? (int) $data['status'] : 400;

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response( $result, 200 );
	}
}
