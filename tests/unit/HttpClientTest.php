<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use Nube360\WooCommerce\HttpClient;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\HttpClient
 */
class HttpClientTest extends TestCase {

	private $request;

	protected function setUp(): void {
		parent::setUp();

		$this->request = null;

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array(
					'nube360_url'     => 'https://erp.test/nube360/acme/',
					'nube360_api_key' => 'k3y',
				)[ $name ] ?? $default;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
			}
		);
	}

	private function respond_with( $status, $body ) {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				$this->request = array( 'url' => $url, 'args' => $args );
				return array();
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	public function test_a_relative_endpoint_is_appended_to_the_configured_url_without_double_slashes() {
		$this->respond_with( 200, '{}' );

		( new HttpClient() )->post( '/ecommerce/notifications/central', array( 'a' => 1 ) );

		$this->assertSame( 'https://erp.test/nube360/acme/ecommerce/notifications/central', $this->request['url'] );
	}

	public function test_an_absolute_url_is_used_as_is() {
		$this->respond_with( 200, '{}' );

		( new HttpClient() )->get( 'https://other.test/ping' );

		$this->assertSame( 'https://other.test/ping', $this->request['url'] );
	}

	public function test_get_adds_query_args() {
		$this->respond_with( 200, '{}' );

		( new HttpClient() )->get( 'ping', array( 'page' => 2 ) );

		$this->assertSame( 'https://erp.test/nube360/acme/ping?page=2', $this->request['url'] );
		$this->assertSame( 'GET', $this->request['args']['method'] );
		$this->assertArrayNotHasKey( 'body', $this->request['args'] );
	}

	public function test_post_sends_the_bearer_key_and_a_json_body() {
		$this->respond_with( 200, '{}' );

		( new HttpClient() )->post( 'x', array( 'event' => 'order.created' ) );

		$args = $this->request['args'];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 'Bearer k3y', $args['headers']['Authorization'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( '{"event":"order.created"}', $args['body'] );
		$this->assertSame( HttpClient::TIMEOUT, $args['timeout'] );
	}

	public function test_a_2xx_response_is_a_success_with_its_decoded_body() {
		$this->respond_with( 201, '{"success":true,"n":3}' );

		$result = ( new HttpClient() )->post( 'x' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 201, $result['status'] );
		$this->assertSame( array( 'success' => true, 'n' => 3 ), $result['data'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_an_error_response_reports_the_message_the_erp_sent() {
		$this->respond_with( 401, '{"success":false,"message":"Clave inválida"}' );

		$result = ( new HttpClient() )->post( 'x' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( 'Clave inválida', $result['error'] );
	}

	public function test_an_error_response_without_a_message_reports_the_http_status() {
		$this->respond_with( 500, 'Internal Server Error' );

		$result = ( new HttpClient() )->post( 'x' );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['data'], 'A body that is not JSON is not decoded.' );
		$this->assertStringContainsString( '500', $result['error'] );
	}

	public function test_a_transport_error_is_normalized() {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' ) );

		$result = ( new HttpClient() )->post( 'x' );

		$this->assertSame(
			array(
				'success' => false,
				'status'  => 0,
				'data'    => null,
				'error'   => 'cURL error 7: connection refused',
			),
			$result
		);
	}
}
