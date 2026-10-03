<?php
/**
 * Authentication of incoming calls (from Nube360 to this plugin).
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the incoming Bearer token against the key stored in wp_options.
 */
class Auth {

	/**
	 * The permission_callback for register_rest_route(): validates the
	 * Authorization: Bearer <key> header of the incoming request against the
	 * stored key, using hash_equals() to avoid timing attacks.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @return bool|WP_Error True if valid, WP_Error (401/403) otherwise.
	 */
	public static function check_permission( $request ) {
		$api_key = get_option( 'nube360_api_key', '' );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'nube360_wc_not_configured',
				__( 'Nube360 for WooCommerce has no API key configured.', 'nube360-for-woocommerce' ),
				array( 'status' => 401 )
			);
		}

		$header = $request->get_header( 'authorization' );

		if ( empty( $header ) || ! preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return new WP_Error(
				'nube360_wc_unauthenticated',
				__( 'The Authorization: Bearer header is missing.', 'nube360-for-woocommerce' ),
				array( 'status' => 401 )
			);
		}

		$token = trim( $matches[1] );

		if ( ! hash_equals( $api_key, $token ) ) {
			return new WP_Error(
				'nube360_wc_invalid_key',
				__( 'The API key is not valid.', 'nube360-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
