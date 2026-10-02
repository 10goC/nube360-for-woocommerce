<?php
/**
 * Base class of the integration tests.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Products;
use Nube360\WooCommerce\Webhooks;
use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	const API_KEY = 'test-api-key';
	const ERP_URL = 'https://erp.test/nube360/acme';

	/**
	 * Requests the plugin made to the ERP (outgoing webhooks and handshakes).
	 *
	 * @var array[] Each one: url, method, headers, body (decoded).
	 */
	protected $erp_requests = array();

	/**
	 * What the fake ERP answers: HTTP status and body.
	 *
	 * @var array
	 */
	protected $erp_response = array(
		'code' => 200,
		'body' => '{"success":true}',
	);

	public function set_up() {
		parent::set_up();

		update_option( 'nube360_url', self::ERP_URL );
		update_option( 'nube360_api_key', self::API_KEY );

		$this->erp_requests = array();
		$this->erp_response = array( 'code' => 200, 'body' => '{"success":true}' );
		add_filter( 'pre_http_request', array( $this, 'intercept_erp_requests' ), 10, 3 );

		$this->reset_plugin_static_state();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept_erp_requests' ), 10 );
		$this->reset_plugin_static_state();
		$this->unregister_attribute_taxonomies();

		parent::tear_down();
	}

	/**
	 * Answers the calls to the (fake) ERP and records them. Calls to any other
	 * host are left to other filters.
	 */
	public function intercept_erp_requests( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, self::ERP_URL ) ) {
			return $preempt;
		}

		$this->erp_requests[] = array(
			'url'     => $url,
			'method'  => $args['method'],
			'headers' => $args['headers'],
			'body'    => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);

		return array(
			'headers'  => array(),
			'body'     => $this->erp_response['body'],
			'response' => array( 'code' => $this->erp_response['code'], 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Only the events the plugin sent to the ERP.
	 *
	 * @return array[] Decoded payloads ({event, id, variant_id?}).
	 */
	protected function sent_events() {
		$events = array();
		foreach ( $this->erp_requests as $request ) {
			if ( false !== strpos( $request['url'], '/ecommerce/notifications/central' ) ) {
				$events[] = $request['body'];
			}
		}
		return $events;
	}

	/**
	 * Static state the plugin keeps for the duration of a request; the
	 * database rolls back between tests, this does not.
	 */
	private function reset_plugin_static_state() {
		$guard = new ReflectionProperty( Webhooks::class, 'suppressed' );
		$guard->setAccessible( true );
		$guard->setValue( null, 0 );

		$queued = new ReflectionProperty( Webhooks::class, 'queued' );
		$queued->setAccessible( true );
		$queued->setValue( null, array() );
		remove_all_actions( 'shutdown', 20 );

		$cache = new ReflectionProperty( Products::class, 'families_by_ref' );
		$cache->setAccessible( true );
		$cache->setValue( null, array() );
	}

	/**
	 * Simulates the start of a new request as far as attributes go.
	 *
	 * WooCommerce registers the attribute taxonomies (and fills
	 * $wc_product_attributes, which wc_attribute_label() relies on) on `init`.
	 * The request that creates an attribute only registers the bare taxonomy,
	 * so a test that reads what it just created must call this first.
	 */
	protected function start_new_request() {
		global $wc_product_attributes;

		wp_cache_flush();
		delete_transient( 'wc_attribute_taxonomies' );

		$wc_product_attributes = array();
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$wc_product_attributes[ wc_attribute_taxonomy_name( $attribute->attribute_name ) ] = $attribute;
		}
	}

	/**
	 * The plugin registers `pa_*` taxonomies at runtime; their rows roll back
	 * with the transaction but the registration would leak into the next test.
	 */
	private function unregister_attribute_taxonomies() {
		foreach ( get_taxonomies() as $taxonomy ) {
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		$GLOBALS['wc_product_attributes'] = array();
	}

	/**
	 * Calls the plugin's REST API the way Nube360 does (authenticated).
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route under nube360/v1, e.g. "/products".
	 * @param array|null $json   JSON body.
	 * @param array      $query  Query params.
	 * @param string|null $key   API key to send (null: none; default: the right one).
	 *
	 * @return WP_REST_Response
	 */
	protected function api( $method, $route, $json = null, array $query = array(), $key = self::API_KEY ) {
		$request = new WP_REST_Request( $method, '/nube360/v1' . $route );
		if ( null !== $key ) {
			$request->set_header( 'Authorization', 'Bearer ' . $key );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( null !== $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $json ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Same as api() but asserts a 200 and returns the data.
	 */
	protected function api_ok( $method, $route, $json = null, array $query = array() ) {
		$response = $this->api( $method, $route, $json, $query );
		$this->assertSame( 200, $response->get_status(), 'Unexpected response: ' . wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}
}
