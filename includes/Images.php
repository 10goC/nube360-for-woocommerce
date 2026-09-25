<?php
/**
 * Downloads and assigns a product's images, in the background by default.
 *
 * Downloading a photo and generating all its thumbnail sizes is the slowest
 * part of creating a product (seconds per photo), and it is not needed for
 * the product to exist or for Nube360 to move on to the next one. That is
 * why creation responds right away and the photos are queued in Action
 * Scheduler (bundled with WooCommerce): they are processed in their own
 * queue, without holding up the export request.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use ActionScheduler;
use ActionScheduler_Store;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product images queue.
 */
class Images {

	/**
	 * Scheduled action hook.
	 */
	const HOOK = 'nube360_wc_assign_images';

	/**
	 * Action Scheduler group (to filter them in WooCommerce > Status >
	 * Scheduled Actions).
	 */
	const GROUP = 'nube360-for-woocommerce';

	/**
	 * Total attempts before giving the action up as failed.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Hooks the scheduled action processing. It has to be instantiated on
	 * every request (the ones run by Action Scheduler included), not only on
	 * REST API ones.
	 */
	public function __construct() {
		add_action( self::HOOK, array( $this, 'process' ), 10, 3 );
	}

	/**
	 * Schedules the download/assignment of a product's images. If Action
	 * Scheduler is not available (or the
	 * `nube360_wc_images_in_background` filter returns false) it is done
	 * right away, synchronously.
	 *
	 * @param int   $product_id Product id (the parent, for a family).
	 * @param array $images     List of {"src": "..."} (or of URLs).
	 */
	public static function schedule( $product_id, $images ) {
		$urls = self::normalize( $images );
		if ( empty( $urls ) ) {
			return;
		}

		if ( self::in_background() ) {
			$action = as_enqueue_async_action( self::HOOK, array( (int) $product_id, $urls, 1 ), self::GROUP );
			if ( $action ) {
				return;
			}
		}

		self::assign( (int) $product_id, $urls );
	}

	/**
	 * Scheduled action callback.
	 *
	 * If no image could be downloaded it is rescheduled with a growing wait
	 * (1, 2 minutes...) up to MAX_ATTEMPTS; once exhausted, it throws an
	 * exception so that Action Scheduler marks it as failed and it stays
	 * visible.
	 *
	 * @param int   $product_id Product id.
	 * @param array $urls       Image URLs.
	 * @param int   $attempt    Attempt number (starts at 1).
	 *
	 * @throws Exception If no image could be assigned on the last attempt.
	 */
	public function process( $product_id, $urls, $attempt = 1 ) {
		$product_id = (int) $product_id;
		$attempt    = max( 1, (int) $attempt );

		if ( 0 !== self::assign( $product_id, (array) $urls ) ) {
			return;
		}

		if ( $attempt < self::MAX_ATTEMPTS ) {
			as_schedule_single_action(
				time() + MINUTE_IN_SECONDS * $attempt,
				self::HOOK,
				array( $product_id, (array) $urls, $attempt + 1 ),
				self::GROUP
			);
			return;
		}

		throw new Exception(
			sprintf(
				/* translators: 1: product id, 2: number of attempts */
				__( 'No image could be downloaded for product %1$d after %2$d attempts.', 'nube360-for-woocommerce' ),
				$product_id,
				self::MAX_ATTEMPTS
			)
		);
	}

	/**
	 * Number of pending and failed image actions, to show on the settings
	 * screen. Null if Action Scheduler is not ready.
	 *
	 * @return array|null {pending: int, failed: int}
	 */
	public static function status() {
		if ( ! class_exists( 'ActionScheduler' ) || ! did_action( 'action_scheduler_init' ) ) {
			return null;
		}

		$store = ActionScheduler::store();

		return array(
			'pending' => (int) $store->query_actions(
				array(
					'hook'   => self::HOOK,
					'status' => ActionScheduler_Store::STATUS_PENDING,
				),
				'count'
			),
			'failed'  => (int) $store->query_actions(
				array(
					'hook'   => self::HOOK,
					'status' => ActionScheduler_Store::STATUS_FAILED,
				),
				'count'
			),
		);
	}

	/**
	 * Whether Action Scheduler can be used (only after it was initialized).
	 *
	 * @return bool
	 */
	private static function in_background() {
		return function_exists( 'as_enqueue_async_action' )
			&& did_action( 'action_scheduler_init' )
			&& apply_filters( 'nube360_wc_images_in_background', true );
	}

	/**
	 * Leaves a flat list of unique, valid URLs.
	 *
	 * @param array $images List of {"src": "..."} or of URLs.
	 *
	 * @return string[]
	 */
	private static function normalize( $images ) {
		$urls = array();

		foreach ( (array) $images as $image ) {
			$src = is_array( $image ) ? ( isset( $image['src'] ) ? $image['src'] : '' ) : $image;
			$src = esc_url_raw( (string) $src );
			if ( '' !== $src ) {
				$urls[] = $src;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Downloads the images, uploads them to the media library and sets the
	 * first one as the featured image and the rest as the gallery.
	 *
	 * The save runs with the anti-loop guard on until the end of the
	 * request: it is a change of our own and must not notify Nube360 with a
	 * `product.updated`.
	 *
	 * @param int      $product_id Product id.
	 * @param string[] $urls       URLs to download.
	 *
	 * @return int|null Number of images assigned (0 if none could be
	 *                  downloaded), or null if there is nothing to do (the
	 *                  product no longer exists or already has an image, for
	 *                  example a retry of something already done).
	 */
	private static function assign( $product_id, $urls ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_image_id() ) {
			return null;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();

		foreach ( $urls as $url ) {
			$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
			if ( ! is_wp_error( $attachment_id ) ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if ( empty( $attachment_ids ) ) {
			return 0;
		}

		$count = count( $attachment_ids );

		$product->set_image_id( array_shift( $attachment_ids ) );
		if ( ! empty( $attachment_ids ) ) {
			$product->set_gallery_image_ids( $attachment_ids );
		}

		// Until the end of the request and not only around save(): for a
		// family, WooCommerce syncs the parent on `shutdown`.
		Webhooks::suppress_until_shutdown();
		$product->save();

		return $count;
	}
}
