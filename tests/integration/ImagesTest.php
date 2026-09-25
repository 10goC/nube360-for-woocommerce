<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Exception;
use Nube360\WooCommerce\Images;
use Nube360\WooCommerce\Products;
use Nube360\WooCommerce\Webhooks;
use WC_Post_Data;
use WP_Error;

/**
 * Downloading and assigning product images. No network is used: the images
 * come from a filter on `pre_http_request`.
 *
 * @covers \Nube360\WooCommerce\Images
 */
class ImagesTest extends TestCase {

	// A valid 1x1 PNG.
	const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * URLs requested from the fake image host.
	 *
	 * @var string[]
	 */
	private $downloads = array();

	public function set_up() {
		parent::set_up();

		$this->downloads = array();
		add_filter( 'pre_http_request', array( $this, 'serve_images' ), 10, 3 );
		// Run the download in the request, not in Action Scheduler.
		add_filter( 'nube360_wc_images_in_background', '__return_false' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve_images' ), 10 );
		parent::tear_down();
	}

	/**
	 * The fake image host: any URL is a PNG, except the ones containing "broken".
	 */
	public function serve_images( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://images.test/' ) ) {
			return $preempt;
		}

		$this->downloads[] = $url;

		if ( false !== strpos( $url, 'broken' ) ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		}

		file_put_contents( $args['filename'], base64_decode( self::PNG ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'headers'  => array( 'content-type' => 'image/png' ),
			'body'     => '',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}

	private function product() {
		$result = ( new Products() )->create( array( 'title' => 'Mug', 'variant' => array( 'sku' => 'MUG-1', 'price' => 1 ) ) );
		return wc_get_product( $result['id'] );
	}

	public function test_the_first_image_is_featured_and_the_rest_are_the_gallery() {
		$product = $this->product();

		Images::schedule(
			$product->get_id(),
			array(
				array( 'src' => 'https://images.test/one.png' ),
				array( 'src' => 'https://images.test/two.png' ),
				array( 'src' => 'https://images.test/three.png' ),
			)
		);

		wp_cache_flush();
		$product = wc_get_product( $product->get_id() );
		$this->assertNotSame( 0, $product->get_image_id() );
		$this->assertCount( 2, $product->get_gallery_image_ids() );
		$this->assertSame( 'attachment', get_post_type( $product->get_image_id() ) );
		$this->assertSame( $product->get_id(), (int) get_post( $product->get_image_id() )->post_parent );
	}

	public function test_it_does_not_download_the_same_url_twice() {
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/one.png', array( 'src' => 'https://images.test/one.png' ) ) );

		$this->assertCount( 1, $this->downloads );
	}

	public function test_a_failed_download_is_skipped_and_the_others_are_kept() {
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/broken.png', 'https://images.test/ok.png' ) );

		wp_cache_flush();
		$product = wc_get_product( $product->get_id() );
		$this->assertNotSame( 0, $product->get_image_id() );
		$this->assertSame( array(), $product->get_gallery_image_ids() );
	}

	public function test_when_every_download_fails_the_product_stays_without_image() {
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/broken.png' ) );

		wp_cache_flush();
		$this->assertEmpty( wc_get_product( $product->get_id() )->get_image_id() );
	}

	public function test_a_product_that_already_has_an_image_is_not_touched() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );
		wp_cache_flush();
		$first_image = wc_get_product( $product->get_id() )->get_image_id();
		$this->downloads = array();

		Images::schedule( $product->get_id(), array( 'https://images.test/two.png' ) );

		$this->assertSame( array(), $this->downloads );
		wp_cache_flush();
		$this->assertSame( $first_image, wc_get_product( $product->get_id() )->get_image_id() );
	}

	public function test_the_scheduled_action_callback_assigns_the_images() {
		$product = $this->product();

		( new Images() )->process( $product->get_id(), array( 'https://images.test/one.png' ), 1 );

		wp_cache_flush();
		$this->assertNotSame( 0, wc_get_product( $product->get_id() )->get_image_id() );
	}

	public function test_the_action_callback_hooks_the_action_scheduler_hook() {
		new Images();

		$this->assertNotFalse( has_action( Images::HOOK ) );
		$this->assertSame( 'nube360_wc_assign_images', Images::HOOK );
	}

	public function test_after_the_last_attempt_the_action_fails_so_it_stays_visible() {
		$product = $this->product();

		$this->expectException( Exception::class );

		( new Images() )->process( $product->get_id(), array( 'https://images.test/broken.png' ), Images::MAX_ATTEMPTS );
	}

	public function test_a_family_gets_its_images_on_the_parent_without_notifying_nube360() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'family_attributes' => array( 'Color' ),
				'variant'           => array( 'sku' => 'SH-RED', 'price' => 1, 'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ) ),
			)
		);
		$this->erp_requests = array();

		( new Images() )->process( $result['id'], array( 'https://images.test/one.png' ), 1 );
		// End of the request: WooCommerce syncs the parent, then the guard is released.
		WC_Post_Data::do_deferred_product_sync();
		Webhooks::resume();

		wp_cache_flush();
		$this->assertNotSame( 0, wc_get_product( $result['id'] )->get_image_id() );
		$this->assertSame( array(), $this->sent_events(), 'Assigning the images is our own change and must not echo to Nube360.' );
	}

	/* ------------------------------------------------- background (Action Scheduler) */

	private function enable_background_processing() {
		remove_filter( 'nube360_wc_images_in_background', '__return_false' );
	}

	private function pending_actions( array $args = array() ) {
		return as_get_scheduled_actions(
			array_merge(
				array(
					'hook'     => Images::HOOK,
					'group'    => Images::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 50,
				),
				$args
			)
		);
	}

	public function test_by_default_the_images_are_queued_instead_of_downloaded() {
		$this->enable_background_processing();
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/one.png', 'https://images.test/two.png' ) );

		$this->assertSame( array(), $this->downloads, 'The request does not wait for the images.' );
		wp_cache_flush();
		$this->assertEmpty( wc_get_product( $product->get_id() )->get_image_id() );

		$actions = $this->pending_actions();
		$this->assertCount( 1, $actions );
		$action = array_values( $actions )[0];
		$this->assertSame( array( $product->get_id(), array( 'https://images.test/one.png', 'https://images.test/two.png' ), 1 ), $action->get_args() );
		$this->assertSame( 'nube360-for-woocommerce', $action->get_group() );
	}

	public function test_the_queued_action_assigns_the_images_when_it_runs() {
		$this->enable_background_processing();
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );

		$args = array_values( $this->pending_actions() )[0]->get_args();
		( new Images() )->process( ...$args );

		wp_cache_flush();
		$this->assertNotEmpty( wc_get_product( $product->get_id() )->get_image_id() );
	}

	public function test_a_failed_attempt_is_rescheduled_with_a_growing_wait() {
		$this->enable_background_processing();
		$product = $this->product();
		$urls    = array( 'https://images.test/broken.png' );

		( new Images() )->process( $product->get_id(), $urls, 1 );
		( new Images() )->process( $product->get_id(), $urls, 2 );

		$second = as_next_scheduled_action( Images::HOOK, array( $product->get_id(), $urls, 2 ), Images::GROUP );
		$third  = as_next_scheduled_action( Images::HOOK, array( $product->get_id(), $urls, 3 ), Images::GROUP );
		$this->assertEqualsWithDelta( time() + 60, $second, 5, 'First retry after 1 minute.' );
		$this->assertEqualsWithDelta( time() + 120, $third, 5, 'Second retry after 2 minutes.' );
	}

	public function test_the_status_counts_the_pending_actions() {
		$this->enable_background_processing();
		$before  = Images::status();
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );
		$after = Images::status();

		$this->assertSame( array( 'pending', 'failed' ), array_keys( $after ) );
		$this->assertSame( $before['pending'] + 1, $after['pending'] );
		$this->assertIsInt( $after['failed'] );
	}

	public function test_the_status_reports_pending_and_failed_actions_when_action_scheduler_is_ready() {
		$status = Images::status();

		if ( ! did_action( 'action_scheduler_init' ) ) {
			$this->assertNull( $status );
			return;
		}

		$this->assertSame( array( 'pending', 'failed' ), array_keys( $status ) );
		$this->assertIsInt( $status['pending'] );
		$this->assertIsInt( $status['failed'] );
	}
}
