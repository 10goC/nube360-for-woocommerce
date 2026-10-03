<?php
/**
 * Thin wrapper over wp_remote_request() for this plugin's outgoing calls
 * to Nube360: adds the Bearer header, decodes the JSON response and
 * normalizes errors.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outgoing HTTP client towards the configured Nube360 instance.
 */
class HttpClient {

	/**
	 * Default timeout, in seconds, for outgoing calls.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Makes an authenticated POST with a JSON body to an absolute Nube360
	 * URL (or one relative to the configured base URL).
	 *
	 * @param string $endpoint Path (e.g. "ecommerce/notifications/central") or absolute URL.
	 * @param array  $body     Data to send, serialized to JSON.
	 *
	 * @return array {
	 *     @type bool  $success Whether the call arrived and returned 2xx.
	 *     @type int   $status  HTTP status code (0 if there was no response).
	 *     @type mixed $data    Decoded body (array) or null.
	 *     @type string $error  Error message, if any.
	 * }
	 */
	public function post( $endpoint, $body = array() ) {
		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Makes an authenticated GET to an absolute (or relative) Nube360 URL.
	 *
	 * @param string $endpoint Path or absolute URL.
	 * @param array  $args     Optional query args.
	 *
	 * @return array See post().
	 */
	public function get( $endpoint, $args = array() ) {
		$url = $this->resolve_url( $endpoint );

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $this->do_request( 'GET', $url, null );
	}

	/**
	 * Builds the final URL from a relative or absolute endpoint.
	 *
	 * @param string $endpoint Relative path or absolute URL.
	 *
	 * @return string
	 */
	private function resolve_url( $endpoint ) {
		if ( preg_match( '#^https?://#i', $endpoint ) ) {
			return $endpoint;
		}

		$base = trim( (string) get_option( 'nube360_url', '' ), '/' );

		return $base . '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * Runs the request (POST or another method with a body).
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Path or absolute URL.
	 * @param array|null $body Body to serialize to JSON, or null.
	 *
	 * @return array See post().
	 */
	private function request( $method, $endpoint, $body ) {
		$url = $this->resolve_url( $endpoint );

		return $this->do_request( $method, $url, $body );
	}

	/**
	 * Makes the actual request via wp_remote_request() and normalizes the
	 * result.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    Absolute target URL.
	 * @param array|null $body   Body to serialize to JSON, or null if there is none.
	 *
	 * @return array
	 */
	private function do_request( $method, $url, $body ) {
		$api_key = get_option( 'nube360_api_key', '' );

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'status'  => 0,
				'data'    => null,
				'error'   => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = null;

		if ( '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$data = $decoded;
			}
		}

		$success = $status >= 200 && $status < 300;

		$error = '';
		if ( ! $success ) {
			if ( is_array( $data ) && isset( $data['message'] ) ) {
				$error = $data['message'];
			} else {
				/* translators: %d: HTTP status code */
				$error = sprintf( __( 'Nube360: the instance responded with HTTP status %d.', 'nube360-for-woocommerce' ), $status );
			}
		}

		return array(
			'success' => $success,
			'status'  => $status,
			'data'    => $data,
			'error'   => $error,
		);
	}
}
