<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Exception;
use Mockery;
use Nube360\WooCommerce\Images;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\Images
 */
class ImagesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Actions\expectAdded( 'shutdown' )->zeroOrMoreTimes();
	}

	private function background_available( $stub_queue = true ) {
		// The plugin checks function_exists(): stubbing defines the function.
		if ( $stub_queue ) {
			Functions\when( 'as_enqueue_async_action' )->justReturn( 55 );
		}
		Functions\when( 'did_action' )->justReturn( 1 );
		Filters\expectApplied( 'nube360_wc_images_in_background' )->andReturn( true );
	}

	private function product( $has_image = false ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_image_id' )->andReturn( $has_image ? 500 : 0 );
		return $product;
	}

	public function test_it_hooks_the_scheduled_action_callback() {
		Actions\expectAdded( 'nube360_wc_assign_images' )->once();

		new Images();

		$this->addToAssertionCount( 1 );
	}

	public function test_scheduling_without_urls_does_nothing() {
		Functions\expect( 'as_enqueue_async_action' )->never();
		Functions\expect( 'wc_get_product' )->never();

		Images::schedule( 10, array() );
		Images::schedule( 10, array( array( 'src' => '' ), '' ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_it_queues_the_deduplicated_urls_in_action_scheduler() {
		$this->background_available( false );

		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with(
				'nube360_wc_assign_images',
				array( 10, array( 'https://x.test/a.jpg', 'https://x.test/b.jpg' ), 1 ),
				'nube360-for-woocommerce'
			)
			->andReturn( 55 );
		Functions\expect( 'wc_get_product' )->never();

		Images::schedule(
			10,
			array(
				array( 'src' => 'https://x.test/a.jpg' ),
				'https://x.test/b.jpg',
				array( 'src' => 'https://x.test/a.jpg' ),
			)
		);
	}

	public function test_it_falls_back_to_downloading_right_away_when_queueing_fails() {
		$this->background_available();
		Functions\when( 'as_enqueue_async_action' )->justReturn( 0 );

		$product = $this->product();
		$product->shouldReceive( 'set_image_id' )->once()->with( 900 );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'media_sideload_image' )->justReturn( 900 );

		Images::schedule( 10, array( 'https://x.test/a.jpg' ) );
	}

	public function test_it_downloads_synchronously_when_background_processing_is_disabled_by_filter() {
		Functions\when( 'as_enqueue_async_action' )->justReturn( 55 );
		Functions\when( 'did_action' )->justReturn( 1 );
		Filters\expectApplied( 'nube360_wc_images_in_background' )->andReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$product = $this->product();
		$product->shouldReceive( 'set_image_id' )->once()->with( 900 );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'media_sideload_image' )->justReturn( 900 );

		Images::schedule( 10, array( 'https://x.test/a.jpg' ) );
	}

	public function test_the_first_image_is_featured_and_the_rest_are_the_gallery() {
		$product = $this->product();
		$product->shouldReceive( 'set_image_id' )->once()->with( 901 );
		$product->shouldReceive( 'set_gallery_image_ids' )->once()->with( array( 902, 903 ) );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\expect( 'media_sideload_image' )
			->times( 3 )
			->andReturn( 901, 902, 903 );

		( new Images() )->process( 10, array( 'https://x.test/1.jpg', 'https://x.test/2.jpg', 'https://x.test/3.jpg' ), 1 );
	}

	public function test_a_download_that_fails_is_skipped_but_the_others_are_kept() {
		$product = $this->product();
		$product->shouldReceive( 'set_image_id' )->once()->with( 902 );
		$product->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\expect( 'media_sideload_image' )
			->twice()
			->andReturn( new WP_Error( 'http', 'timeout' ), 902 );

		( new Images() )->process( 10, array( 'https://x.test/1.jpg', 'https://x.test/2.jpg' ), 1 );
	}

	public function test_a_product_that_already_has_an_image_is_left_alone() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product( true ) );
		Functions\expect( 'media_sideload_image' )->never();
		Functions\expect( 'as_schedule_single_action' )->never();

		( new Images() )->process( 10, array( 'https://x.test/1.jpg' ), 1 );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_product_that_no_longer_exists_is_not_retried() {
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\expect( 'as_schedule_single_action' )->never();

		( new Images() )->process( 10, array( 'https://x.test/1.jpg' ), 1 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider retry_waits
	 */
	public function test_when_no_image_could_be_downloaded_it_is_rescheduled_with_a_growing_wait( $attempt, $wait ) {
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
		Functions\when( 'media_sideload_image' )->justReturn( new WP_Error( 'http', 'timeout' ) );

		$urls = array( 'https://x.test/1.jpg' );
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::on(
					function ( $timestamp ) use ( $wait ) {
						return abs( $timestamp - ( time() + $wait ) ) <= 2;
					}
				),
				'nube360_wc_assign_images',
				array( 10, $urls, $attempt + 1 ),
				'nube360-for-woocommerce'
			);

		( new Images() )->process( 10, $urls, $attempt );
	}

	public function retry_waits() {
		return array(
			'first attempt waits 1 minute'   => array( 1, 60 ),
			'second attempt waits 2 minutes' => array( 2, 120 ),
		);
	}

	public function test_after_the_last_attempt_it_throws_so_the_action_is_marked_as_failed() {
		Functions\when( 'wc_get_product' )->justReturn( $this->product() );
		Functions\when( 'media_sideload_image' )->justReturn( new WP_Error( 'http', 'timeout' ) );
		Functions\expect( 'as_schedule_single_action' )->never();

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'after 3 attempts' );

		( new Images() )->process( 10, array( 'https://x.test/1.jpg' ), Images::MAX_ATTEMPTS );
	}
}
