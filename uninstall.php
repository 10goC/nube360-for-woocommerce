<?php
/**
 * Runs when the user deletes the plugin from the WordPress admin (not on a
 * simple deactivation). Cleans up the options the plugin saved in wp_options.
 *
 * @package Nube360\WooCommerce
 */

// Exit if WordPress did not invoke this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'nube360_url' );
delete_option( 'nube360_api_key' );
delete_option( 'nube360_wc_connected' );
delete_option( 'nube360_wc_last_handshake' );
