<?php
/**
 * Minimal declarations of the WordPress/WooCommerce classes the plugin refers
 * to, so the unit suite can run (and mock them) without loading either.
 *
 * @package Nube360\WooCommerce
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Request {
	private $method;
	private $route;
	private $headers;
	private $params;
	private $json;

	public function __construct( $method = 'GET', $route = '', array $headers = array(), array $params = array(), $json = null ) {
		$this->method  = $method;
		$this->route   = $route;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->params  = $params;
		$this->json    = $json;
	}

	public function get_method() {
		return $this->method;
	}

	public function get_route() {
		return $this->route;
	}

	public function get_header( $key ) {
		return $this->headers[ strtolower( $key ) ] ?? null;
	}

	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function get_json_params() {
		return $this->json;
	}
}

class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

class WP_Term {}
class WC_Product {}
class WC_Order {}
class WC_Order_Item {}
class WC_Order_Item_Product extends WC_Order_Item {}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variable extends WC_Product {}
class WC_Product_Variation extends WC_Product {}
class WC_Product_Attribute {}
