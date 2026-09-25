<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use Nube360\WooCommerce\Auth;
use WP_Error;
use WP_REST_Request;

/**
 * @covers \Nube360\WooCommerce\Auth
 */
class AuthTest extends TestCase {

	private function request( $authorization = null ) {
		$headers = null === $authorization ? array() : array( 'Authorization' => $authorization );
		return new WP_REST_Request( 'GET', '/nube360/v1/store', $headers );
	}

	private function stored_key( $key ) {
		Functions\expect( 'get_option' )->with( 'nube360_api_key', '' )->andReturn( $key );
	}

	public function test_it_rejects_every_call_while_no_key_is_configured() {
		$this->stored_key( '' );

		$result = Auth::check_permission( $this->request( 'Bearer anything' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nube360_wc_not_configured', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_it_requires_the_authorization_header() {
		$this->stored_key( 'secret' );

		$result = Auth::check_permission( $this->request() );

		$this->assertSame( 'nube360_wc_unauthenticated', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * @dataProvider malformed_headers
	 */
	public function test_it_rejects_headers_that_are_not_a_bearer_token( $header ) {
		$this->stored_key( 'secret' );

		$result = Auth::check_permission( $this->request( $header ) );

		$this->assertSame( 'nube360_wc_unauthenticated', $result->get_error_code() );
	}

	public function malformed_headers() {
		return array(
			'basic auth'       => array( 'Basic c2VjcmV0' ),
			'no scheme'        => array( 'secret' ),
			'scheme only'      => array( 'Bearer' ),
			'scheme and space' => array( 'Bearer   ' ),
		);
	}

	public function test_it_rejects_a_wrong_key_with_403() {
		$this->stored_key( 'secret' );

		$result = Auth::check_permission( $this->request( 'Bearer not-the-secret' ) );

		$this->assertSame( 'nube360_wc_invalid_key', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_it_does_not_accept_a_prefix_of_the_key() {
		$this->stored_key( 'secret-key' );

		$result = Auth::check_permission( $this->request( 'Bearer secret' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * @dataProvider valid_headers
	 */
	public function test_it_accepts_the_right_key( $header ) {
		$this->stored_key( 'secret' );

		$this->assertTrue( Auth::check_permission( $this->request( $header ) ) );
	}

	public function valid_headers() {
		return array(
			'plain'             => array( 'Bearer secret' ),
			'lowercase scheme'  => array( 'bearer secret' ),
			'extra whitespace'  => array( '  Bearer    secret  ' ),
		);
	}
}
