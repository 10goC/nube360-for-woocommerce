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

		ImagesGuard::init();
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

		// A new list is a new start: whatever crashed before is not held against it.
		ImagesGuard::clear( $product_id );

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
	 * Queues the continuation of a product's images: the replace action, which
	 * reads what is wanted from the product and reuses what was already
	 * downloaded, so it only does what is left. It does not use
	 * `as_has_scheduled_action()` because that also counts the action that is
	 * running now (the one being continued).
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int Id of the pending action (0 if none could be queued).
	 */
	public static function enqueue_continuation( $product_id ) {
		if ( ! self::in_background() ) {
			return 0;
		}

		$args    = array( (int) $product_id );
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::REPLACE_HOOK,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( ! empty( $pending ) ) {
			return (int) reset( $pending );
		}

		return (int) as_enqueue_async_action( self::REPLACE_HOOK, $args, self::GROUP );
	}

	/**
	 * Replace action callback.
	 *
	 * @param int $product_id Product id.
	 *
	 * @throws Exception If none of the new images could be downloaded.
	 */
	public function process_replace( $product_id ) {
		ImagesGuard::protect( (int) $product_id );

		try {
			$this->do_process_replace( (int) $product_id );
		} finally {
			ImagesGuard::release();
		}
	}

	/**
	 * Body of `process_replace()`.
	 *
	 * @param int $product_id Product id.
	 *
	 * @throws Exception If none of the new images could be downloaded.
	 */
	private function do_process_replace( $product_id ) {
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
				self::delete_if_unused( $current );
			}

			return true;
		}

		if ( $current && self::is_image_at( $current, $url ) ) {
			return true;
		}

		$attachment_id = self::sideload( $url, 0, $name );

		if ( is_wp_error( $attachment_id ) ) {
			$term = get_term( $term_id );

			self::log_failure(
				$term && ! is_wp_error( $term ) ? $term->taxonomy : 'term',
				$term_id,
				$term && ! is_wp_error( $term ) ? $term->name : '',
				$url,
				$attachment_id
			);

			return false;
		}

		update_term_meta( $term_id, 'thumbnail_id', (int) $attachment_id );

		if ( $ours ) {
			self::delete_if_unused( $current );
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
	 * Attachment the plugin already downloaded from $url, if any.
	 *
	 * @param string $url Image URL.
	 *
	 * @return int Attachment id, or 0.
	 */
	private static function find_by_source_url( $url ) {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_SOURCE_URL,
						'value' => esc_url_raw( $url ),
					),
				),
			)
		);

		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Whether anything else (another product, variation or term) still uses
	 * the attachment as its image or in its gallery.
	 *
	 * @param int $attachment_id Attachment id.
	 *
	 * @return bool
	 */
	private static function is_in_use( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		$posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE ( meta_key = '_thumbnail_id' AND meta_value = %d ) OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %d, meta_value ) )",
				$attachment_id,
				$attachment_id
			)
		);

		if ( $posts > 0 ) {
			return true;
		}

		$terms = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = 'thumbnail_id' AND meta_value = %d",
				$attachment_id
			)
		);

		return $terms > 0;
	}

	/**
	 * Deletes an attachment the plugin downloaded, unless something else
	 * still uses it (the owner must have stopped referencing it first).
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function delete_if_unused( $attachment_id ) {
		if ( ! self::is_in_use( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
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
	 * Gets an image into the media library. If the plugin already downloaded
	 * it from the same URL it is reused, so the variations (and the family)
	 * that share a photo share one attachment instead of one copy each.
	 * Otherwise it is downloaded: with a $name the file is
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
		$existing = self::find_by_source_url( $url );
		if ( $existing ) {
			return $existing;
		}

		// After a crash the image goes in alone, without its thumbnail sizes (the
		// heavy part); they can be regenerated later with `wp media regenerate`.
		$degraded = ImagesGuard::is_degraded( (int) $parent_id );
		if ( $degraded ) {
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
			add_filter( 'big_image_size_threshold', '__return_false' );
		}
		add_filter( 'http_request_args', array( __CLASS__, 'cap_download_timeout' ) );

		try {
			return self::download_and_attach( $url, $parent_id, $name );
		} finally {
			remove_filter( 'http_request_args', array( __CLASS__, 'cap_download_timeout' ) );
			if ( $degraded ) {
				remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
				remove_filter( 'big_image_size_threshold', '__return_false' );
			}
		}
	}

	/**
	 * `download_url()` waits up to 5 minutes by default, far longer than a
	 * queue run lasts: a slow image server would end in a timeout fatal error.
	 * Caps the wait to what the request can still afford.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array
	 */
	public static function cap_download_timeout( $args ) {
		$left            = ImagesGuard::seconds_left() - 5;
		$args['timeout'] = (int) max( 5, min( isset( $args['timeout'] ) ? (int) $args['timeout'] : 25, 25, $left ) );

		return $args;
	}

	/**
	 * Downloads $url and adds it to the media library.
	 *
	 * @param string $url       Image URL.
	 * @param int    $parent_id Post the attachment belongs to (0 for none).
	 * @param string $name      File name (optional).
	 *
	 * @return int|WP_Error Attachment id.
	 */
	private static function download_and_attach( $url, $parent_id, $name ) {
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
	 * Name and SKU of a product, to identify it in the log.
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return string
	 */
	private static function product_label( $product ) {
		$sku = $product->get_sku();

		return $product->get_name() . ( '' !== $sku ? ' (SKU ' . $sku . ')' : '' );
	}

	/**
	 * Logs an image that could not be downloaded, with what it belongs to, in
	 * WooCommerce > Status > Logs (source `nube360-for-woocommerce`). Each
	 * failed attempt is logged, so a retried image shows up once per attempt.
	 *
	 * @param string   $type  What the image belongs to: "product" or the
	 *                        taxonomy of the term ("product_cat", "product_brand").
	 * @param int      $id    Product or term id.
	 * @param string   $label Name of the product or term.
	 * @param string   $url   URL that failed.
	 * @param WP_Error $error Download error.
	 */
	private static function log_failure( $type, $id, $label, $url, $error ) {
		wc_get_logger()->error(
			sprintf(
				'Image could not be downloaded for %1$s #%2$d "%3$s": %4$s (%5$s)',
				$type,
				(int) $id,
				$label,
				$url,
				$error->get_error_message()
			),
			array( 'source' => 'nube360-for-woocommerce' )
		);
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

		// The 'edit' context is the product's own data: in 'view' a variation
		// without an image answers with its parent's, which would be taken for
		// its own (and its attachment deleted below).
		$old_ids   = array_values( array_filter( array_merge( array( $product->get_image_id( 'edit' ) ), $product->get_gallery_image_ids( 'edit' ) ) ) );
		$new_ids   = array();
		$downloads = 0;
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

			// Short of time or memory: what is downloaded stays in the media library
			// (the next run finds it by its URL) and the rest goes to a new action,
			// instead of letting this one run into a fatal error. The product is not
			// touched until it can get its whole list.
			if ( $downloads > 0 && self::in_background() && ! ImagesGuard::has_budget() && self::enqueue_continuation( $product_id ) ) {
				return null;
			}

			++$downloads;
			$attachment_id = self::sideload( $url, $product_id, isset( $names[ $url ] ) ? $names[ $url ] : '' );
			if ( is_wp_error( $attachment_id ) ) {
				self::log_failure( 'product', $product_id, self::product_label( $product ), $url, $attachment_id );
			} else {
				$new_ids[] = (int) $attachment_id;
			}
		}

		if ( ! empty( $wanted ) && empty( $new_ids ) ) {
			return 0;
		}

		ImagesGuard::clear( $product_id );

		if ( array_map( 'intval', $old_ids ) === $new_ids ) {
			return count( $new_ids );
		}

		$product->set_image_id( empty( $new_ids ) ? 0 : $new_ids[0] );
		$product->set_gallery_image_ids( array_slice( $new_ids, 1 ) );

		Webhooks::suppress_until_shutdown();
		$product->save();

		foreach ( array_diff( $old_ids, $new_ids ) as $old_id ) {
			if ( self::is_ours( $old_id ) ) {
				self::delete_if_unused( $old_id );
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
		ImagesGuard::protect(
			(int) $product_id,
			array(
				'urls'  => (array) $urls,
				'names' => (array) $names,
			)
		);

		try {
			$this->do_process( $product_id, $urls, $attempt, $names );
		} finally {
			ImagesGuard::release();
		}
	}

	/**
	 * Body of `process()`.
	 *
	 * @param int   $product_id Product id.
	 * @param array $urls       Image URLs.
	 * @param int   $attempt    Attempt number (starts at 1).
	 * @param array $names      File names by URL (optional).
	 *
	 * @throws Exception If no image could be assigned on the last attempt.
	 */
	private function do_process( $product_id, $urls, $attempt, $names ) {
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
		if ( ! $product || $product->get_image_id( 'edit' ) ) {
			return null;
		}

		$attachment_ids = array();
		$deferred       = false;

		foreach ( array_values( $urls ) as $position => $url ) {
			// Short of time or memory: save what there is and continue in a new action.
			if ( $position > 0 && self::in_background() && ! ImagesGuard::has_budget() ) {
				$deferred = true;
				break;
			}

			$attachment_id = self::sideload( $url, $product_id, isset( $names[ $url ] ) ? $names[ $url ] : '' );
			if ( is_wp_error( $attachment_id ) ) {
				self::log_failure( 'product', $product_id, self::product_label( $product ), $url, $attachment_id );
			} else {
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

		if ( $deferred ) {
			// The rest: the replace action takes it from what is wanted and reuses
			// the images already downloaded.
			update_post_meta(
				$product_id,
				self::META_WANTED,
				wp_json_encode(
					array(
						'urls'  => array_values( $urls ),
						'names' => $names,
					)
				)
			);
			self::enqueue_continuation( $product_id );
		} else {
			ImagesGuard::clear( $product_id );
		}

		return $count;
	}
}
