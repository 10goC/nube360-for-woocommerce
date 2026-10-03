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
	 * Scheduled action hook that replaces the images of a product that
	 * already had some (the ERP changed its gallery).
	 */
	const REPLACE_HOOK = 'nube360_wc_replace_images';

	/**
	 * Product meta with the images Nube360 wants the product to have (JSON
	 * {urls, names}). The replace action reads it when it runs, so a burst of
	 * changes always ends with the last one, whatever the order they ran in.
	 */
	const META_WANTED = '_nube360_wc_images_wanted';

	/**
	 * Attachment meta WordPress sets when it downloads an image from a URL.
	 * It is how the plugin recognizes (and reuses or cleans up) the images it
	 * downloaded itself, without keeping a mapping of its own.
	 */
	const META_SOURCE_URL = '_source_url';

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
		add_action( self::HOOK, array( $this, 'process' ), 10, 4 );
		add_action( self::REPLACE_HOOK, array( $this, 'process_replace' ), 10, 1 );
	}

	/**
	 * Schedules the download/assignment of a product's images. If Action
	 * Scheduler is not available (or the
	 * `nube360_wc_images_in_background` filter returns false) it is done
	 * right away, synchronously.
	 *
	 * @param int   $product_id Product id (the parent, for a family).
	 * @param array $images     List of {"src": "...", "name": "..."} (or of
	 *                          URLs). `name` is the file name to give the image
	 *                          in the media library (optional).
	 */
	public static function schedule( $product_id, $images ) {
		$urls = self::normalize( $images );
		if ( empty( $urls ) ) {
			return;
		}

		$names = self::names_for( $images );

		if ( self::in_background() ) {
			$args = empty( $names ) ? array( (int) $product_id, $urls, 1 ) : array( (int) $product_id, $urls, 1, $names );

			$action = as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			if ( $action ) {
				return;
			}
		}

		self::assign( (int) $product_id, $urls, $names );
	}

	/**
	 * Makes the images of a product the ones Nube360 sends: replaces the
	 * current ones with the new list, in the same order. The images already
	 * downloaded from the same URL are reused, the ones that are no longer
	 * in the list are deleted, and an empty list removes them all. Only the
	 * images this plugin downloaded are ever deleted.
	 *
	 * Runs in Action Scheduler when available (like `schedule()`), so the
	 * request that carries the change answers right away.
	 *
	 * @param int   $product_id Product id (the parent, for a family).
	 * @param array $images     List of {"src": "..."} (or of URLs).
	 */
	public static function replace( $product_id, $images ) {
		$product_id = (int) $product_id;

		update_post_meta(
			$product_id,
			self::META_WANTED,
			wp_json_encode(
				array(
					'urls'  => self::normalize( $images ),
					'names' => self::names_for( $images ),
				)
			)
		);

		if ( self::in_background() ) {
			$args = array( $product_id );

			if ( as_has_scheduled_action( self::REPLACE_HOOK, $args, self::GROUP ) ) {
				return;
			}

			if ( as_enqueue_async_action( self::REPLACE_HOOK, $args, self::GROUP ) ) {
				return;
			}
		}

		self::apply_wanted( $product_id );
	}

	/**
	 * Replace action callback.
	 *
	 * @param int $product_id Product id.
	 *
	 * @throws Exception If none of the new images could be downloaded.
	 */
	public function process_replace( $product_id ) {
		if ( 0 === self::apply_wanted( (int) $product_id ) ) {
			throw new Exception(
				sprintf(
					/* translators: %d: product id */
					esc_html__( 'No image could be downloaded for product %d.', 'nube360-for-woocommerce' ),
					(int) $product_id
				)
			);
		}
	}

	/**
	 * Replaces the images of a term (product category or brand) with the one
	 * at $url. Nothing is downloaded if the term already has that image; an
	 * empty URL removes the image, but only if the plugin had downloaded it.
	 *
	 * @param int    $term_id Term id.
	 * @param string $url     Image URL, or empty to remove it.
	 * @param string $name    File name for the downloaded image (optional).
	 *
	 * @return bool False if the download failed (the term is left as it was).
	 */
	public static function sync_term_image( $term_id, $url, $name = '' ) {
		$url     = esc_url_raw( (string) $url );
		$current = (int) get_term_meta( $term_id, 'thumbnail_id', true );
		$ours    = $current && self::is_ours( $current );

		if ( '' === $url ) {
			if ( $ours ) {
				delete_term_meta( $term_id, 'thumbnail_id' );
				wp_delete_attachment( $current, true );
			}

			return true;
		}

		if ( $current && self::is_image_at( $current, $url ) ) {
			return true;
		}

		$attachment_id = self::sideload( $url, 0, $name );

		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}

		update_term_meta( $term_id, 'thumbnail_id', (int) $attachment_id );

		if ( $ours ) {
			wp_delete_attachment( $current, true );
		}

		return true;
	}

	/**
	 * Name of the file Nube360 saves an image under when it downloads it from
	 * $url (a hash of the URL plus its extension). Nube360 sends back its own
	 * copy of an image it took from WordPress under that name, which is how
	 * an attachment uploaded to WordPress by hand is recognized as the same
	 * image instead of being downloaded again. It must match Nube360's
	 * naming exactly.
	 *
	 * @param string $url Image URL.
	 *
	 * @return string
	 */
	public static function local_name( $url ) {
		$extension = pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$extension = $extension ? '.' . preg_replace( '/[^a-zA-Z0-9]/', '', $extension ) : '';

		return substr( md5( $url ) . $extension, 0, 100 );
	}

	/**
	 * Whether an attachment is the image at $wanted_url: it was downloaded
	 * from that URL, or it is an attachment uploaded by hand that Nube360
	 * took over under its `local_name()`.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $wanted_url    URL Nube360 sent.
	 *
	 * @return bool
	 */
	private static function is_image_at( $attachment_id, $wanted_url ) {
		$source = (string) get_post_meta( $attachment_id, self::META_SOURCE_URL, true );

		if ( '' !== $source ) {
			return $source === $wanted_url;
		}

		$own_url = wp_get_attachment_url( $attachment_id );

		return $own_url && self::local_name( $own_url ) === rawurldecode( basename( (string) wp_parse_url( $wanted_url, PHP_URL_PATH ) ) );
	}

	/**
	 * URL Nube360 can use to identify an image: the one the plugin
	 * downloaded it from (Nube360's own file, so it recognizes it and does
	 * not import it again), or the attachment's URL if it was uploaded to
	 * WordPress by hand.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return string|null
	 */
	public static function origin_url( $attachment_id ) {
		$source = (string) get_post_meta( $attachment_id, self::META_SOURCE_URL, true );

		if ( '' !== $source ) {
			return $source;
		}

		$url = wp_get_attachment_url( $attachment_id );

		return $url ? $url : null;
	}

	/**
	 * Whether an attachment was downloaded by this plugin.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return bool
	 */
	private static function is_ours( $attachment_id ) {
		return '' !== (string) get_post_meta( $attachment_id, self::META_SOURCE_URL, true );
	}

	/**
	 * Downloads an image into the media library. With a $name the file is
	 * saved under it (WordPress makes it unique if it is taken); without
	 * one it keeps the name in the URL. Either way the attachment records
	 * the URL it came from, which is how it is recognized later.
	 *
	 * @param string $url       Image URL.
	 * @param int    $parent_id Post the attachment belongs to (0 for none).
	 * @param string $name      File name (optional).
	 *
	 * @return int|WP_Error Attachment id.
	 */
	private static function sideload( $url, $parent_id, $name = '' ) {
		self::load_media_functions();

		if ( '' === (string) $name ) {
			return media_sideload_image( $url, $parent_id, null, 'id' );
		}

		$name = sanitize_file_name( (string) $name );

		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$parent_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );

			return $attachment_id;
		}

		add_post_meta( $attachment_id, self::META_SOURCE_URL, esc_url_raw( $url ) );

		return $attachment_id;
	}

	/**
	 * File names Nube360 asked for, by URL (only the images that have one).
	 *
	 * @param array $images List of {"src": "...", "name": "..."} or of URLs.
	 *
	 * @return string[]
	 */
	private static function names_for( $images ) {
		$names = array();

		foreach ( (array) $images as $image ) {
			if ( ! is_array( $image ) || empty( $image['src'] ) || empty( $image['name'] ) ) {
				continue;
			}

			$url  = esc_url_raw( (string) $image['src'] );
			$name = sanitize_file_name( (string) $image['name'] );

			if ( '' !== $url && '' !== $name && ! isset( $names[ $url ] ) ) {
				$names[ $url ] = $name;
			}
		}

		return $names;
	}

	/**
	 * Loads the WordPress admin functions that download images.
	 */
	private static function load_media_functions() {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Applies the list stored in META_WANTED to the product.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int|null Number of images the product ends up with (0 if it
	 *                  should have some and none could be downloaded, in
	 *                  which case it is left as it was), or null if there
	 *                  is nothing to do (the product no longer exists).
	 */
	private static function apply_wanted( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$decoded = json_decode( (string) get_post_meta( $product_id, self::META_WANTED, true ), true );
		$wanted  = isset( $decoded['urls'] ) && is_array( $decoded['urls'] ) ? $decoded['urls'] : array();
		$names   = isset( $decoded['names'] ) && is_array( $decoded['names'] ) ? $decoded['names'] : array();

		$old_ids = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
		$new_ids = array();
		foreach ( $wanted as $url ) {
			$same = null;
			foreach ( $old_ids as $old_id ) {
				if ( self::is_image_at( $old_id, $url ) ) {
					$same = (int) $old_id;
					break;
				}
			}

			if ( $same ) {
				$new_ids[] = $same;
				continue;
			}

			$attachment_id = self::sideload( $url, $product_id, isset( $names[ $url ] ) ? $names[ $url ] : '' );
			if ( ! is_wp_error( $attachment_id ) ) {
				$new_ids[] = (int) $attachment_id;
			}
		}

		if ( ! empty( $wanted ) && empty( $new_ids ) ) {
			return 0;
		}

		if ( array_map( 'intval', $old_ids ) === $new_ids ) {
			return count( $new_ids );
		}

		$product->set_image_id( empty( $new_ids ) ? 0 : $new_ids[0] );
		$product->set_gallery_image_ids( array_slice( $new_ids, 1 ) );

		Webhooks::suppress_until_shutdown();
		$product->save();

		foreach ( array_diff( $old_ids, $new_ids ) as $old_id ) {
			if ( self::is_ours( $old_id ) ) {
				wp_delete_attachment( $old_id, true );
			}
		}

		return count( $new_ids );
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
	 * @param array $names      File names by URL (optional).
	 *
	 * @throws Exception If no image could be assigned on the last attempt.
	 */
	public function process( $product_id, $urls, $attempt = 1, $names = array() ) {
		$product_id = (int) $product_id;
		$attempt    = max( 1, (int) $attempt );
		$names      = (array) $names;

		if ( 0 !== self::assign( $product_id, (array) $urls, $names ) ) {
			return;
		}

		if ( $attempt < self::MAX_ATTEMPTS ) {
			as_schedule_single_action(
				time() + MINUTE_IN_SECONDS * $attempt,
				self::HOOK,
				empty( $names ) ? array( $product_id, (array) $urls, $attempt + 1 ) : array( $product_id, (array) $urls, $attempt + 1, $names ),
				self::GROUP
			);
			return;
		}

		throw new Exception(
			sprintf(
				/* translators: 1: product id, 2: number of attempts */
				esc_html__( 'No image could be downloaded for product %1$d after %2$d attempts.', 'nube360-for-woocommerce' ),
				(int) $product_id,
				(int) self::MAX_ATTEMPTS
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
	 * @param string[] $names      File names by URL (optional).
	 *
	 * @return int|null Number of images assigned (0 if none could be
	 *                  downloaded), or null if there is nothing to do (the
	 *                  product no longer exists or already has an image, for
	 *                  example a retry of something already done).
	 */
	private static function assign( $product_id, $urls, $names = array() ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_image_id() ) {
			return null;
		}

		$attachment_ids = array();

		foreach ( $urls as $url ) {
			$attachment_id = self::sideload( $url, $product_id, isset( $names[ $url ] ) ? $names[ $url ] : '' );
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
