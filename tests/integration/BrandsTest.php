<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Brands;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\Brands
 */
class BrandsTest extends TestCase {

	/**
	 * @var Brands
	 */
	private $brands;

	public function set_up() {
		parent::set_up();
		$this->brands = new Brands();

		if ( ! $this->brands->is_available() ) {
			$this->markTestSkipped( 'This WooCommerce has no product_brand taxonomy.' );
		}
	}

	public function test_without_an_id_it_creates_the_brand_and_then_finds_it_by_name() {
		$created = $this->brands->sync( 'Acme' );
		$again   = $this->brands->sync( '  Acme ' );

		$this->assertSame( $created, $again );
		$this->assertSame( 'Acme', get_term( (int) $created['id'], 'product_brand' )->name );
	}

	public function test_an_empty_name_is_rejected() {
		$this->assertInstanceOf( WP_Error::class, $this->brands->sync( '   ' ) );
	}

	public function test_with_an_id_the_brand_is_renamed_instead_of_duplicated() {
		$created = $this->brands->sync( 'Acme' );

		$renamed = $this->brands->sync( 'Acme Corp', '', $created['id'] );

		$this->assertSame( $created, $renamed );
		$this->assertSame( 'Acme Corp', get_term( (int) $created['id'], 'product_brand' )->name );
		$this->assertCount( 1, $this->brands->list_all() );
	}

	public function test_an_id_that_no_longer_exists_falls_back_to_the_name() {
		$existing = $this->brands->sync( 'Acme' );

		$linked = $this->brands->sync( 'Acme', '', '999999' );

		$this->assertSame( $existing, $linked );
	}

	public function test_it_lists_the_brands_with_their_image() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$with          = $this->brands->sync( 'Acme' );
		update_term_meta( (int) $with['id'], 'thumbnail_id', $attachment_id );
		$without = $this->brands->sync( 'Globex' );

		$list = array_column( $this->brands->list_all(), null, 'id' );

		$this->assertSame( wp_get_attachment_url( $attachment_id ), $list[ $with['id'] ]['image'] );
		$this->assertSame( 'Acme', $list[ $with['id'] ]['name'] );
		$this->assertNull( $list[ $without['id'] ]['image'] );
	}

	public function test_resolve_ids_drops_what_is_not_a_brand() {
		$brand    = $this->brands->sync( 'Acme' );
		$category = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Kitchen' ) );

		$this->assertSame( array( (int) $brand['id'] ), $this->brands->resolve_ids( array( $brand['id'], (string) $category, '999999' ) ) );
	}

	public function test_it_reads_the_brands_assigned_to_a_product() {
		$product_id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$brand      = $this->brands->sync( 'Acme' );

		$this->assertSame( array(), $this->brands->ids_for_product( $product_id ) );

		$this->brands->assign( $product_id, array( (int) $brand['id'] ) );

		$this->assertSame( array( $brand['id'] ), $this->brands->ids_for_product( $product_id ) );
	}
}
