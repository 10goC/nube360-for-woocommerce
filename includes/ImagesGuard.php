<?php
/**
 * Keeps the image queue from getting stuck, without losing images.
 *
 * Downloading a photo and generating its sizes can run out of memory or time.
 * Action Scheduler then either marks the action as failed (when PHP still
 * runs the shutdown functions) or leaves it `in-progress`, with its whole
 * batch claimed, when the process is killed. A claim that is never released
 * counts as a running queue, so with the default of one concurrent queue no
 * other runner starts and everything stays pending.
 *
 * Two layers, both of which put the work back in the queue instead of
 * letting it fail:
 *
 * 1. Prevention (see Images): between two photos of an action the budget
 *    (time and memory left) is checked; when it is short the rest is
 *    continued in a new action, so no action runs for long.
 * 2. Recovery:
 *    - If the action dies with a fatal error that PHP can still report
 *      (memory or time), a shutdown function queues the continuation, marks
 *      the dead action as complete (nothing was lost: its work lives on in
 *      the new action) and releases the batch's claim.
 *    - If the process is killed, on the next queue run
 *      `recover_stuck()` unclaims the pending actions of ours whose claim
 *      went stale and puts the `in-progress` ones back in pending.
 *
 * Every crash of a product is counted. The first one makes its next attempt
 * "degraded" (no thumbnail sizes, the image alone; sizes can be regenerated
 * later with `wp media regenerate`). After MAX_CRASHES the product is left
 * to Action Scheduler (it ends up failed and visible) instead of looping.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use ActionScheduler;
use ActionScheduler_ActionClaim;
use ActionScheduler_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image queue guard.
 */
class ImagesGuard {

	/**
	 * Product meta: crashes of the image actions of a product.
	 */
	const META_CRASHES = '_nube360_wc_images_crashes';

	/**
	 * Product meta: the next attempt skips the thumbnail sizes.
	 */
	const META_DEGRADED = '_nube360_wc_images_degraded';

	/**
	 * Crashes after which a product is given up (left as failed).
	 */
	const MAX_CRASHES = 3;

	/**
	 * Seconds without progress after which a claimed action is stuck. It has to
	 * be lower than Action Scheduler's own timeout (300 s) to get there first.
	 */
	const STUCK_AFTER = 120;

	/**
	 * Transient that keeps the recovery from running on every queue run.
	 */
	const RECOVERY_LOCK = 'nube360_wc_images_recovery';

	/**
	 * Id of the action that is running (0 if none).
	 *
	 * @var int
	 */
	private static $action_id = 0;

	/**
	 * Product the running action works on (0 if none).
	 *
	 * @var int
	 */
	private static $product_id = 0;

	/**
	 * What the running action was asked for, if it is not already stored in the
	 * product (`array( urls, names )`).
	 *
	 * @var array|null
	 */
	private static $wanted = null;

	/**
	 * Memory held back to be able to work after running out of it.
	 *
	 * @var string|null
	 */
	private static $reserve = null;

	/**
	 * Whether the shutdown function is registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hooks the tracking of the running action and the recovery.
	 */
	public static function init() {
		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'track_action' ), 10, 1 );
		add_action( 'action_scheduler_before_process_queue', array( __CLASS__, 'recover_stuck' ) );
	}

	/**
	 * Remembers which action is running.
	 *
	 * @param int $action_id Action id.
	 */
	public static function track_action( $action_id ) {
		self::$action_id = (int) $action_id;
	}

	/**
	 * Marks the start of the work on a product: from now until `release()` a
	 * fatal error is recovered instead of losing the work.
	 *
	 * @param int        $product_id Product id.
	 * @param array|null $wanted     `array( 'urls' => ..., 'names' => ... )` to store in
	 *                               the product if the action has to be continued (leave
	 *                               it null when the product already has it).
	 */
	public static function protect( $product_id, $wanted = null ) {
		self::$product_id = (int) $product_id;
		self::$wanted     = $wanted;
		self::$reserve    = str_repeat( 'x', 2 * MB_IN_BYTES );

		if ( ! self::$registered ) {
			self::$registered = true;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
	}

	/**
	 * Marks the end of the work on a product.
	 */
	public static function release() {
		self::$product_id = 0;
		self::$wanted     = null;
		self::$reserve    = null;
	}

	/**
	 * Shutdown function: if the action died with a fatal error, continues it.
	 */
	public static function on_shutdown() {
		if ( ! self::$product_id ) {
			return;
		}

		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		self::$reserve = null; // Memory to work with.

		self::recover_crash( self::$product_id, self::$action_id, self::$wanted, $error['message'] );
	}

	/**
	 * Gives the work of a crashed action a new life.
	 *
	 * @param int        $product_id Product id.
	 * @param int        $action_id  The action that died (0 if unknown).
	 * @param array|null $wanted     See `protect()`.
	 * @param string     $reason     What happened.
	 *
	 * @return bool Whether the work was queued again.
	 */
	public static function recover_crash( $product_id, $action_id, $wanted, $reason ) {
		$crashes = self::count_crash( $product_id );

		if ( $crashes >= self::MAX_CRASHES ) {
			self::log( sprintf( 'Giving up the images of product #%1$d after %2$d crashes. Last error: %3$s', $product_id, $crashes, $reason ) );
			return false;
		}

		update_post_meta( $product_id, self::META_DEGRADED, 1 );

		if ( is_array( $wanted ) ) {
			update_post_meta( $product_id, Images::META_WANTED, wp_json_encode( $wanted ) );
		}

		$next = Images::enqueue_continuation( $product_id );
		if ( ! $next ) {
			return false;
		}

		self::log( sprintf( 'The image action of product #%1$d crashed (%2$s). Continued in action #%3$d, without thumbnail sizes.', $product_id, $reason, $next ) );

		if ( $action_id ) {
			self::close_action( $action_id, sprintf( 'Crashed (%1$s); its work continues in action #%2$d.', $reason, $next ) );
		}

		return true;
	}

	/**
	 * Adds one crash to a product.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int Crashes so far, this one included.
	 */
	private static function count_crash( $product_id ) {
		$crashes = (int) get_post_meta( $product_id, self::META_CRASHES, true ) + 1;
		update_post_meta( $product_id, self::META_CRASHES, $crashes );

		return $crashes;
	}

	/**
	 * Closes a dead action as complete (its work was queued again) and frees
	 * the claim of its batch, which would otherwise block every runner.
	 *
	 * @param int    $action_id Action id.
	 * @param string $message   Log entry.
	 */
	private static function close_action( $action_id, $message ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}

		$store    = ActionScheduler::store();
		$claim_id = (int) $store->get_claim_id( $action_id );

		$store->mark_complete( $action_id );
		ActionScheduler::logger()->log( $action_id, $message );

		if ( $claim_id ) {
			try {
				$store->release_claim( new ActionScheduler_ActionClaim( $claim_id, array() ) );
			} catch ( \Exception $e ) {
				self::log( 'The claim of the crashed batch could not be released: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Whether there is time and memory left to start another photo.
	 *
	 * @return bool
	 */
	public static function has_budget() {
		$forced = apply_filters( 'nube360_wc_images_has_budget', null );
		if ( null !== $forced ) {
			return (bool) $forced;
		}

		return self::seconds_left() >= 12 && self::memory_left() >= 64 * MB_IN_BYTES;
	}

	/**
	 * Seconds the request can still run.
	 *
	 * @return float
	 */
	public static function seconds_left() {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}

		$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return $limit - ( microtime( true ) - $started );
	}

	/**
	 * Bytes of memory the request can still use.
	 *
	 * @return float
	 */
	public static function memory_left() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}

		return $limit - memory_get_usage( true );
	}

	/**
	 * Whether the next attempt of a product has to be light (no thumbnail sizes).
	 *
	 * @param int $product_id Product id (0 for a term image).
	 *
	 * @return bool
	 */
	public static function is_degraded( $product_id ) {
		return $product_id && (bool) get_post_meta( $product_id, self::META_DEGRADED, true );
	}

	/**
	 * The work on a product finished: forgets its crashes.
	 *
	 * @param int $product_id Product id.
	 */
	public static function clear( $product_id ) {
		delete_post_meta( $product_id, self::META_CRASHES );
		delete_post_meta( $product_id, self::META_DEGRADED );
	}

	/**
	 * Runs before every queue run (Action Scheduler's own cleanup comes
	 * right after, and the claim stuck here would block it).
	 *
	 * Frees the actions of ours that a runner that died left behind:
	 * - pending ones still claimed, whose claim went stale: unclaimed;
	 * - ones `in-progress` for too long (a running action lasts seconds): back to
	 *   pending, so they run again instead of ending up failed. The crash is
	 *   counted and, after MAX_CRASHES, left alone.
	 */
	public static function recover_stuck() {
		if ( get_transient( self::RECOVERY_LOCK ) || ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}
		set_transient( self::RECOVERY_LOCK, 1, MINUTE_IN_SECONDS );

		$store  = ActionScheduler::store();
		$cutoff = as_get_datetime_object( (int) apply_filters( 'nube360_wc_images_stuck_after', self::STUCK_AFTER ) . ' seconds ago' );
		$base   = array(
			'group'            => Images::GROUP,
			'modified'         => $cutoff,
			'modified_compare' => '<=',
			'per_page'         => 100,
			'orderby'          => 'none',
		);

		foreach ( $store->query_actions( array_merge( $base, array( 'status' => ActionScheduler_Store::STATUS_RUNNING ) ) ) as $action_id ) {
			self::requeue_running( (int) $action_id );
		}

		$claimed = array_merge(
			$base,
			array(
				'status'  => ActionScheduler_Store::STATUS_PENDING,
				'claimed' => true,
			)
		);
		foreach ( $store->query_actions( $claimed ) as $action_id ) {
			$store->unclaim_action( $action_id );
			ActionScheduler::logger()->log( $action_id, 'Its claim went stale (the runner died): released.' );
		}
	}

	/**
	 * Puts an action that has been `in-progress` for too long back in pending.
	 *
	 * @param int $action_id Action id.
	 */
	private static function requeue_running( $action_id ) {
		global $wpdb;

		$action     = ActionScheduler::store()->fetch_action( $action_id );
		$args       = $action->get_args();
		$product_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $product_id ) {
			if ( self::count_crash( $product_id ) >= self::MAX_CRASHES ) {
				self::log( sprintf( 'Giving up the images of product #%1$d after %2$d crashes (action #%3$d left to Action Scheduler).', $product_id, self::MAX_CRASHES, $action_id ) );
				return;
			}
			update_post_meta( $product_id, self::META_DEGRADED, 1 );
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->actionscheduler_actions,
			array(
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'claim_id' => 0,
			),
			array(
				'action_id' => $action_id,
				'status'    => ActionScheduler_Store::STATUS_RUNNING,
			)
		);

		if ( $updated ) {
			ActionScheduler::logger()->log( $action_id, 'It was in progress for too long (the runner died): back to pending.' );
		}
	}

	/**
	 * Writes to WooCommerce > Status > Logs (source `nube360-for-woocommerce`).
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'nube360-for-woocommerce' ) );
		}
	}
}
