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

	public function test_a_variation_gets_only_the_first_image_of_its_gallery() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'family_attributes' => array( 'Color' ),
				'variant'           => array(
					'sku'    => 'SH-RED',
					'price'  => 1,
					'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ),
					'images' => array(
						array( 'src' => 'https://images.test/red-1.png' ),
						array( 'src' => 'https://images.test/red-2.png' ),
					),
				),
			)
		);

		wp_cache_flush();
		$variation = wc_get_product( $result['variant_id'] );
		$this->assertNotSame( 0, $variation->get_image_id() );
		$this->assertSame( array( 'https://images.test/red-1.png' ), $this->downloads, 'WooCommerce has one image per variation: only the first one is downloaded.' );
		$this->assertEmpty( wc_get_product( $result['id'] )->get_image_id(), 'The variation image is not set on the parent.' );
	}

	public function test_a_variation_image_is_replaced_by_the_first_one_of_the_new_gallery() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'family_attributes' => array( 'Color' ),
				'variant'           => array(
					'sku'    => 'SH-RED',
					'price'  => 1,
					'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ),
					'images' => array( array( 'src' => 'https://images.test/red-1.png' ) ),
				),
			)
		);
		$this->downloads = array();

		( new Products() )->update(
			$result['id'],
			$result['variant_id'],
			array(
				'images' => array(
					array( 'src' => 'https://images.test/red-2.png' ),
					array( 'src' => 'https://images.test/red-3.png' ),
				),
			)
		);

		wp_cache_flush();
		$this->assertSame( array( 'https://images.test/red-2.png' ), $this->downloads );
		$this->assertTrue( (bool) wc_get_product( $result['variant_id'] )->get_image_id() );

		( new Products() )->update( $result['id'], $result['variant_id'], array( 'images' => array() ) );
		wp_cache_flush();
		$this->assertSame( 0, wc_get_product( $result['variant_id'] )->get_image_id() );
	}

	public function test_a_variation_gets_its_own_image_when_the_parent_already_has_one() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'images'            => array( array( 'src' => 'https://images.test/family.png' ) ),
				'family_attributes' => array( 'Color' ),
				'variant'           => array(
					'sku'    => 'SH-RED',
					'price'  => 1,
					'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ),
					'images' => array( array( 'src' => 'https://images.test/red.png' ) ),
				),
			)
		);

		wp_cache_flush();
		$parent_image = wc_get_product( $result['id'] )->get_image_id( 'edit' );
		$own_image    = wc_get_product( $result['variant_id'] )->get_image_id( 'edit' );

		$this->assertNotEmpty( $parent_image );
		$this->assertNotEmpty( $own_image, 'WooCommerce answers with the parent image in the view context: that is not the variation image.' );
		$this->assertNotSame( $parent_image, $own_image );
		$this->assertSame( 'https://images.test/red.png', get_post_meta( $own_image, Images::META_SOURCE_URL, true ) );

		// Replacing the variation image must never delete the parent one.
		( new Products() )->update( $result['id'], $result['variant_id'], array( 'images' => array( array( 'src' => 'https://images.test/red-2.png' ) ) ) );
		wp_cache_flush();
		$this->assertNotNull( get_post( $parent_image ), 'The image of the parent was deleted.' );
		$this->assertNull( get_post( $own_image ), 'The replaced image of the variation should be gone.' );
	}

	private function variation_with_image( $family_ref, $sku, $size, $url ) {
		return ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'family_ref'        => $family_ref,
				'family_attributes' => array( 'Color', 'Size' ),
				'variant'           => array(
					'sku'    => $sku,
					'price'  => 1,
					'values' => array(
						array( 'attribute' => 'Color', 'value' => 'Red' ),
						array( 'attribute' => 'Size', 'value' => $size ),
					),
					'images' => array( array( 'src' => $url ) ),
				),
			)
		);
	}

	private function attachments_from( $url ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => Images::META_SOURCE_URL, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	public function test_variations_that_share_a_photo_share_one_attachment() {
		$a = $this->variation_with_image( 'fam-1', 'SH-RED-S', 'S', 'https://images.test/red.png' );
		$b = $this->variation_with_image( 'fam-1', 'SH-RED-M', 'M', 'https://images.test/red.png' );
		$c = $this->variation_with_image( 'fam-1', 'SH-RED-L', 'L', 'https://images.test/red.png' );

		wp_cache_flush();
		$ids = array();
		foreach ( array( $a, $b, $c ) as $result ) {
			$ids[] = wc_get_product( $result['variant_id'] )->get_image_id( 'edit' );
		}

		$this->assertNotEmpty( $ids[0] );
		$this->assertSame( array( $ids[0], $ids[0], $ids[0] ), $ids, 'Every variation should use the same attachment.' );
		$this->assertCount( 1, $this->attachments_from( 'https://images.test/red.png' ) );
		$this->assertSame( array( 'https://images.test/red.png' ), $this->downloads, 'The photo is downloaded once.' );
	}

	public function test_replacing_a_shared_image_does_not_delete_it_for_the_others() {
		$a = $this->variation_with_image( 'fam-1', 'SH-RED-S', 'S', 'https://images.test/red.png' );
		$b = $this->variation_with_image( 'fam-1', 'SH-RED-M', 'M', 'https://images.test/red.png' );

		wp_cache_flush();
		$shared = wc_get_product( $a['variant_id'] )->get_image_id( 'edit' );

		( new Products() )->update( $a['id'], $a['variant_id'], array( 'images' => array( array( 'src' => 'https://images.test/blue.png' ) ) ) );
		wp_cache_flush();

		$this->assertNotSame( $shared, wc_get_product( $a['variant_id'] )->get_image_id( 'edit' ) );
		$this->assertNotNull( get_post( $shared ), 'The attachment is still used by the other variation.' );
		$this->assertSame( $shared, wc_get_product( $b['variant_id'] )->get_image_id( 'edit' ) );

		// Once nobody uses it, it goes.
		( new Products() )->update( $b['id'], $b['variant_id'], array( 'images' => array() ) );
		wp_cache_flush();
		$this->assertNull( get_post( $shared ) );
	}

	public function test_the_family_gallery_and_a_variation_share_the_attachment() {
		$result = ( new Products() )->create(
			array(
				'title'             => 'Shirt',
				'images'            => array( array( 'src' => 'https://images.test/red.png' ) ),
				'family_attributes' => array( 'Color' ),
				'variant'           => array(
					'sku'    => 'SH-RED',
					'price'  => 1,
					'values' => array( array( 'attribute' => 'Color', 'value' => 'Red' ) ),
					'images' => array( array( 'src' => 'https://images.test/red.png' ) ),
				),
			)
		);

		wp_cache_flush();
		$this->assertEquals(
			wc_get_product( $result['id'] )->get_image_id( 'edit' ),
			wc_get_product( $result['variant_id'] )->get_image_id( 'edit' )
		);
		$this->assertCount( 1, $this->attachments_from( 'https://images.test/red.png' ) );
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

	/* ------------------------------------------------------------- replacing */

	private function ids_of( $product ) {
		$product = wc_get_product( $product->get_id() );
		return array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
	}

	private function sources_of( $product ) {
		return array_map(
			function ( $id ) {
				return get_post_meta( $id, '_source_url', true );
			},
			$this->ids_of( $product )
		);
	}

	public function test_replace_swaps_the_gallery_reusing_what_did_not_change_and_deleting_the_rest() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png', 'https://images.test/two.png' ) );
		$before = $this->ids_of( $product );
		$this->downloads = array();

		Images::replace( $product->get_id(), array( 'https://images.test/two.png', 'https://images.test/three.png' ) );

		$this->assertSame( array( 'https://images.test/two.png', 'https://images.test/three.png' ), $this->sources_of( $product ) );
		$this->assertSame( array( 'https://images.test/three.png' ), $this->downloads, 'Only the new image is downloaded.' );
		$this->assertEquals( $before[1], $this->ids_of( $product )[0], 'The unchanged image is reused.' );
		$this->assertNull( get_post( $before[0] ), 'The image that is gone is deleted.' );
	}

	public function test_replace_with_an_empty_list_removes_the_images() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );

		Images::replace( $product->get_id(), array() );

		$this->assertSame( array(), $this->ids_of( $product ) );
	}

	public function test_replace_with_the_same_list_changes_nothing() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );
		$this->downloads = array();

		Images::replace( $product->get_id(), array( 'https://images.test/one.png' ) );

		$this->assertSame( array(), $this->downloads );
	}

	public function test_replace_keeps_the_current_images_if_none_of_the_new_ones_downloads() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );

		Images::replace( $product->get_id(), array( 'https://images.test/broken.png' ) );

		$this->assertSame( array( 'https://images.test/one.png' ), $this->sources_of( $product ) );
	}

	public function test_replace_never_deletes_an_image_it_did_not_download() {
		$product       = $this->product();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$product->set_image_id( $attachment_id );
		$product->save();

		Images::replace( $product->get_id(), array( 'https://images.test/one.png' ) );

		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertSame( array( 'https://images.test/one.png' ), array_slice( $this->sources_of( $product ), 0, 1 ) );
	}

	public function test_replace_does_not_notify_nube360() {
		$product = $this->product();

		Images::replace( $product->get_id(), array( 'https://images.test/one.png' ) );
		WC_Post_Data::do_deferred_product_sync();
		Webhooks::resume();

		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_replace_is_queued_and_the_last_request_wins() {
		remove_filter( 'nube360_wc_images_in_background', '__return_false' );
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );

		Images::replace( $product->get_id(), array( 'https://images.test/two.png' ) );
		Images::replace( $product->get_id(), array( 'https://images.test/three.png' ) );

		$this->assertCount( 1, $this->pending_actions( array( 'hook' => Images::REPLACE_HOOK ) ), 'Repeated changes share one queued action.' );
		( new Images() )->process_replace( $product->get_id() );
		$this->assertSame( array( 'https://images.test/three.png' ), $this->sources_of( $product ) );
	}

	public function test_a_term_image_is_replaced_and_the_old_one_deleted() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );

		Images::sync_term_image( $term_id, 'https://images.test/a.png' );
		$first = (int) get_term_meta( $term_id, 'thumbnail_id', true );
		Images::sync_term_image( $term_id, 'https://images.test/b.png' );
		$second = (int) get_term_meta( $term_id, 'thumbnail_id', true );

		$this->assertNotSame( $first, $second );
		$this->assertSame( 'https://images.test/b.png', get_post_meta( $second, '_source_url', true ) );
		$this->assertNull( get_post( $first ) );
	}

	public function test_a_term_image_with_the_same_url_is_not_downloaded_again() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		Images::sync_term_image( $term_id, 'https://images.test/a.png' );
		$this->downloads = array();

		Images::sync_term_image( $term_id, 'https://images.test/a.png' );

		$this->assertSame( array(), $this->downloads );
	}

	public function test_an_empty_url_removes_a_term_image_that_the_plugin_downloaded() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		Images::sync_term_image( $term_id, 'https://images.test/a.png' );
		$image = (int) get_term_meta( $term_id, 'thumbnail_id', true );

		Images::sync_term_image( $term_id, '' );

		$this->assertSame( '', (string) get_term_meta( $term_id, 'thumbnail_id', true ) );
		$this->assertNull( get_post( $image ) );
	}

	public function test_an_empty_url_leaves_an_image_the_plugin_did_not_download() {
		$term_id       = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

		Images::sync_term_image( $term_id, '' );

		$this->assertSame( $attachment_id, (int) get_term_meta( $term_id, 'thumbnail_id', true ) );
	}

	public function test_the_api_sets_the_image_of_a_category_and_a_brand() {
		$category = $this->api_ok( 'POST', '/categories', array( 'name' => 'Shoes', 'image' => 'https://images.test/cat.png' ) );
		$this->assertNotEmpty( get_term_meta( (int) $category['id'], 'thumbnail_id', true ) );

		$this->api_ok( 'PUT', '/categories/' . $category['id'], array( 'image' => null ) );
		$this->assertEmpty( get_term_meta( (int) $category['id'], 'thumbnail_id', true ) );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$brand = $this->api_ok( 'POST', '/brands', array( 'name' => 'Acme', 'image' => 'https://images.test/brand.png' ) );
			$this->assertSame( 'https://images.test/brand.png', get_post_meta( get_term_meta( (int) $brand['id'], 'thumbnail_id', true ), '_source_url', true ) );

			// Same id, new name and image: the brand is updated, not duplicated.
			$again = $this->api_ok( 'POST', '/brands', array( 'id' => $brand['id'], 'name' => 'Acme Corp', 'image' => 'https://images.test/brand2.png' ) );
			$this->assertSame( $brand['id'], $again['id'] );
			$this->assertSame( 'https://images.test/brand2.png', get_post_meta( get_term_meta( (int) $brand['id'], 'thumbnail_id', true ), '_source_url', true ) );
			$this->assertSame( 'Acme Corp', $this->api_ok( 'GET', '/brands' )[0]['name'] );
		}

		$this->assertSame( 404, $this->api( 'PUT', '/categories/999999', array( 'image' => '' ) )->get_status() );
	}

	public function test_a_request_to_create_a_category_without_image_keeps_its_image() {
		$category = $this->api_ok( 'POST', '/categories', array( 'name' => 'Shoes', 'image' => 'https://images.test/cat.png' ) );

		$this->api_ok( 'POST', '/categories', array( 'name' => 'Shoes' ) );

		$this->assertNotEmpty( get_term_meta( (int) $category['id'], 'thumbnail_id', true ) );
	}

	public function test_the_api_replaces_the_gallery_of_a_product() {
		$product = $this->product();
		Images::schedule( $product->get_id(), array( 'https://images.test/one.png' ) );

		$this->api_ok( 'PUT', '/products/' . $product->get_id(), array( 'images' => array( array( 'src' => 'https://images.test/two.png' ) ) ) );

		$this->assertSame( array( 'https://images.test/two.png' ), $this->sources_of( $product ) );
	}

	/* ----------------------------- images uploaded by hand are not duplicated */

	/**
	 * What Nube360 sends back for an image it took from WordPress: its own
	 * copy, saved under a hash of the original URL.
	 */
	private function erp_copy_of( $attachment_id ) {
		return 'https://erp.test/assets/uploads/files/' . Images::local_name( wp_get_attachment_url( $attachment_id ) );
	}

	public function test_replace_recognizes_an_image_uploaded_by_hand_and_does_not_download_it_again() {
		$product       = $this->product();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$product->set_image_id( $attachment_id );
		$product->save();
		$erp_copy = $this->erp_copy_of( $attachment_id );
		$this->erp_requests = array();

		Images::replace( $product->get_id(), array( array( 'src' => $erp_copy ), array( 'src' => 'https://images.test/new.png' ) ) );

		$ids = $this->ids_of( $product );
		$this->assertEquals( $attachment_id, $ids[0], 'The attachment already there is kept in its place.' );
		$this->assertCount( 2, $ids );
		$this->assertSame( array( 'https://images.test/new.png' ), $this->downloads, 'Only the new image is downloaded.' );
		$this->assertSame( array(), $this->erp_requests );
	}

	public function test_a_term_image_uploaded_by_hand_is_recognized_too() {
		$term_id       = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

		Images::sync_term_image( $term_id, $this->erp_copy_of( $attachment_id ) );

		$this->assertSame( array(), $this->downloads );
		$this->assertSame( $attachment_id, (int) get_term_meta( $term_id, 'thumbnail_id', true ) );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	public function test_the_local_name_is_a_hash_of_the_url_plus_its_extension() {
		$this->assertSame( md5( 'https://wp.test/a/b.jpg' ) . '.jpg', Images::local_name( 'https://wp.test/a/b.jpg' ) );
		$this->assertSame( md5( 'https://wp.test/a/b' ), Images::local_name( 'https://wp.test/a/b' ) );
	}

	/* ------------------------------------------------------ category name, brand delete */

	public function test_the_api_renames_a_category_without_touching_its_image() {
		$category = $this->api_ok( 'POST', '/categories', array( 'name' => 'Shoes', 'image' => 'https://images.test/cat.png' ) );
		$image    = get_term_meta( (int) $category['id'], 'thumbnail_id', true );

		$this->api_ok( 'PUT', '/categories/' . $category['id'], array( 'name' => 'Footwear' ) );

		$this->assertSame( 'Footwear', get_term( (int) $category['id'], 'product_cat' )->name );
		$this->assertSame( $image, get_term_meta( (int) $category['id'], 'thumbnail_id', true ) );
	}

	public function test_deleting_a_brand_through_the_api_leaves_its_products_without_brand() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
		$brand   = $this->api_ok( 'POST', '/brands', array( 'name' => 'Acme', 'image' => 'https://images.test/brand.png' ) );
		$product = $this->product();
		wp_set_object_terms( $product->get_id(), array( (int) $brand['id'] ), 'product_brand' );
		$image = (int) get_term_meta( (int) $brand['id'], 'thumbnail_id', true );

		$this->api_ok( 'DELETE', '/brands/' . $brand['id'] );
		foreach ( $GLOBALS['wp_filter']['shutdown']->callbacks[20] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && 'flush_queued' === $callback['function'][1] ) {
				call_user_func( $callback['function'] );
			}
		}

		$this->assertEmpty( term_exists( (int) $brand['id'], 'product_brand' ) );
		$this->assertSame( array(), wp_get_object_terms( $product->get_id(), 'product_brand' ) );
		$this->assertNull( get_post( $image ), 'The image the plugin downloaded goes with the brand.' );
		$this->assertSame( array(), $this->sent_events(), 'A deletion that Nube360 asked for is not echoed back.' );
		$this->assertSame( 404, $this->api( 'DELETE', '/brands/' . $brand['id'] )->get_status() );
	}

	/* ------------------------------------------------------------- file names */

	private function file_name_of( $attachment_id ) {
		return wp_basename( get_attached_file( $attachment_id ) );
	}

	/**
	 * WordPress adds a numeric suffix when a file with that name is already in
	 * the uploads folder (leftovers of earlier runs included).
	 */
	private function assertNamed( $expected, $attachment_id ) {
		$base = preg_quote( pathinfo( $expected, PATHINFO_FILENAME ), '/' );
		$ext  = preg_quote( pathinfo( $expected, PATHINFO_EXTENSION ), '/' );
		$this->assertMatchesRegularExpression( "/^{$base}(-\\d+)?\\.{$ext}$/", $this->file_name_of( $attachment_id ) );
	}

	public function test_an_image_is_saved_under_the_name_nube360_asks_for() {
		$product = $this->product();

		Images::schedule( $product->get_id(), array( array( 'src' => 'https://images.test/0009c-mug.png', 'name' => 'mug.png' ) ) );

		$ids = $this->ids_of( $product );
		$this->assertNamed( 'mug.png', $ids[0] );
		$this->assertSame( 'https://images.test/0009c-mug.png', get_post_meta( $ids[0], '_source_url', true ), 'It is still recognized by the URL it came from.' );
	}

	public function test_without_a_name_the_image_keeps_the_one_in_its_url() {
		$product = $this->product();

		Images::schedule( $product->get_id(), array( 'https://images.test/0009c-mug.png' ) );

		$this->assertNamed( '0009c-mug.png', $this->ids_of( $product )[0] );
	}

	public function test_two_images_with_the_same_name_do_not_overwrite_each_other() {
		$product = $this->product();

		Images::schedule(
			$product->get_id(),
			array(
				array( 'src' => 'https://images.test/0009c-mug.png', 'name' => 'mug.png' ),
				array( 'src' => 'https://images.test/1f3a2-mug.png', 'name' => 'mug.png' ),
			)
		);

		$ids = $this->ids_of( $product );
		$this->assertCount( 2, $ids );
		$this->assertNotSame( $this->file_name_of( $ids[0] ), $this->file_name_of( $ids[1] ) );
	}

	public function test_the_name_also_applies_when_the_gallery_is_replaced_and_to_term_images() {
		$product = $this->product();
		Images::replace( $product->get_id(), array( array( 'src' => 'https://images.test/0009c-a.png', 'name' => 'a.png' ) ) );
		$this->assertNamed( 'a.png', $this->ids_of( $product )[0] );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );
		Images::sync_term_image( $term_id, 'https://images.test/0009c-cat.png', 'cat.png' );
		$this->assertNamed( 'cat.png', (int) get_term_meta( $term_id, 'thumbnail_id', true ) );
	}

	public function test_the_name_travels_through_the_queue() {
		$this->enable_background_processing();
		$product = $this->product();

		Images::schedule( $product->get_id(), array( array( 'src' => 'https://images.test/0009c-mug.png', 'name' => 'mug.png' ) ) );

		$args = array_values( $this->pending_actions() )[0]->get_args();
		$this->assertCount( 4, $args, 'The names are the fourth argument of the action.' );
		( new Images() )->process( ...$args );

		$this->assertNamed( 'mug.png', $this->ids_of( $product )[0] );
	}
}
