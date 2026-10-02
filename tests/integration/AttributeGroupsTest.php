<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\AttributeGroups;
use Nube360\WooCommerce\Attributes;
use Nube360\WooCommerce\Products;

/**
 * Groups of attribute values, and values that repeat their title in
 * different groups.
 *
 * @covers \Nube360\WooCommerce\Attributes
 * @covers \Nube360\WooCommerce\AttributeGroups
 */
class AttributeGroupsTest extends TestCase {

	/**
	 * @var Attributes
	 */
	private $attributes;

	public function set_up() {
		parent::set_up();
		$this->attributes = new Attributes();
	}

	/**
	 * Body of a POST /products for a family with a single "Size" attribute.
	 */
	private function size_body( $sku, $value, $group = '' ) {
		$pair = array( 'attribute' => 'Size', 'value' => $value );
		if ( '' !== $group ) {
			$pair['group'] = $group;
		}

		return array(
			'title'             => 'Sock',
			'family_ref'        => '501',
			'family_attributes' => array( 'Size' ),
			'variant'           => array(
				'sku'    => $sku,
				'price'  => 10,
				'stock'  => 1,
				'values' => array( $pair ),
			),
		);
	}

	private function group_of_term( $taxonomy, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $this->attributes->term_group_name( $term->term_id );
	}

	public function test_the_groups_taxonomy_is_registered() {
		$this->assertTrue( taxonomy_exists( AttributeGroups::TAXONOMY ) );
	}

	public function test_a_value_is_created_inside_its_group() {
		$result = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );

		$variation = wc_get_product( (int) $result['variant_id'] );
		$this->assertSame( array( 'pa_size' => '2' ), $variation->get_attributes() );
		$this->assertSame( 'Babies', $this->group_of_term( 'pa_size', '2' ) );
	}

	public function test_the_same_title_in_two_groups_is_two_different_values() {
		$babies   = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$juvenile = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '2', 'Juvenile' ) );
		$loose    = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-3', '2' ) );

		// The first one to arrive gets the plain slug, the next ones the group, then a number.
		$this->assertSame( array( 'pa_size' => '2' ), wc_get_product( (int) $babies['variant_id'] )->get_attributes() );
		$this->assertSame( array( 'pa_size' => '2-juvenile' ), wc_get_product( (int) $juvenile['variant_id'] )->get_attributes() );
		$this->assertSame( array( 'pa_size' => '2-2' ), wc_get_product( (int) $loose['variant_id'] )->get_attributes() );

		$terms = get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) );
		$this->assertCount( 3, $terms );
		$this->assertSame( array( '2', '2', '2' ), wp_list_pluck( $terms, 'name' ) );
	}

	public function test_a_value_is_reused_when_it_comes_again_with_the_same_group() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '2', 'Babies' ) );

		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => AttributeGroups::TAXONOMY, 'hide_empty' => false ) ) );
	}

	public function test_a_value_that_had_no_group_adopts_it_instead_of_being_duplicated() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', 'XL' ) );
		$result = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', 'XL', 'Adults' ) );

		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
		$this->assertSame( 'Adults', $this->group_of_term( 'pa_size', 'xl' ) );
		$this->assertSame( array( 'pa_size' => 'xl' ), wc_get_product( (int) $result['variant_id'] )->get_attributes() );
	}

	public function test_a_grouped_value_does_not_become_ungrouped_when_the_group_is_missing() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '2' ) );

		$this->assertCount( 2, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
	}

	public function test_the_group_is_in_the_name_and_values_with_entities_are_still_found() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', 'S & M', 'Mix' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', 'S & M', 'Mix' ) );

		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
	}

	public function test_groups_of_different_attributes_do_not_clash() {
		$this->attributes->ensure_term( $this->attributes->ensure_attribute( 'Color' ), 'Navy', 'Other' );
		$this->attributes->ensure_term( $this->attributes->ensure_attribute( 'Size' ), 'One', 'Other' );

		$groups = get_terms( array( 'taxonomy' => AttributeGroups::TAXONOMY, 'hide_empty' => false ) );
		$this->assertCount( 2, $groups );
		$this->assertEqualSets(
			array( 'pa_color', 'pa_size' ),
			array_map(
				function ( $group ) {
					return get_term_meta( $group->term_id, AttributeGroups::META_ATTRIBUTE, true );
				},
				$groups
			)
		);
	}

	public function test_reading_a_product_returns_the_group_of_each_value() {
		$created = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '3' ) );

		$this->start_new_request();
		$family = $this->api_ok( 'GET', '/products/' . $created['id'] );
		$by_sku = array();
		foreach ( $family['items'] as $item ) {
			$by_sku[ $item['sku'] ] = $item['attributes'];
		}

		$this->assertSame( array( array( 'attribute' => 'Size', 'value' => '2', 'group' => 'Babies' ) ), $by_sku['SOCK-1'] );
		$this->assertSame( array( array( 'attribute' => 'Size', 'value' => '3' ) ), $by_sku['SOCK-2'] );
	}

	public function test_deleting_a_group_leaves_its_values_without_group() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$term = get_term_by( 'slug', '2', 'pa_size' );
		$group = get_term_by( 'slug', 'pa_size-babies', AttributeGroups::TAXONOMY );

		wp_delete_term( $group->term_id, AttributeGroups::TAXONOMY );

		$this->assertSame( 0, $this->attributes->term_group_id( $term->term_id ) );
		$this->assertSame( '', $this->attributes->term_group_name( $term->term_id ) );
	}

	public function test_attributes_are_listed_with_values_groups_and_swatches() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$group = get_term_by( 'slug', 'pa_size-babies', AttributeGroups::TAXONOMY );
		update_term_meta( $group->term_id, AttributeGroups::META_COLOR, '#ffcc00' );

		$data = $this->api_ok( 'GET', '/attributes' );

		$this->assertSame(
			array(
				array(
					'name'   => 'Size',
					'values' => array( array( 'value' => '2', 'group' => 'Babies' ) ),
					'groups' => array( array( 'name' => 'Babies', 'color' => '#ffcc00', 'image' => null ) ),
				),
			),
			$data['attributes']
		);
	}

	public function test_putting_attributes_creates_values_and_groups_without_a_product() {
		$data = $this->api_ok(
			'PUT',
			'/attributes',
			array(
				'attributes' => array(
					array(
						'name'   => 'Size',
						'values' => array(
							array( 'value' => '2', 'group' => 'Babies' ),
							array( 'value' => '2', 'group' => 'Juvenile' ),
							array( 'value' => 'One size' ),
						),
					),
				),
			)
		);

		$this->assertCount( 3, $data['attributes'][0]['values'] );
		$this->assertCount( 2, $data['attributes'][0]['groups'] );
		$this->assertSame( array(), $this->sent_events(), 'Nothing is echoed back to Nube360.' );
	}

	public function test_putting_nothing_is_rejected() {
		$response = $this->api( 'PUT', '/attributes', array( 'attributes' => array() ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_the_attributes_route_requires_the_api_key() {
		$this->assertSame( 401, $this->api( 'GET', '/attributes', null, array(), null )->get_status() );
	}

	public function test_the_group_is_only_added_to_the_slug_when_the_plain_one_is_taken() {
		$first  = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', 'White', 'White' ) );
		$second = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', 'White', 'Cream' ) );
		$third  = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-3', 'White Navy', 'White' ) );

		$this->assertSame( array( 'pa_size' => 'white' ), wc_get_product( (int) $first['variant_id'] )->get_attributes() );
		$this->assertSame( array( 'pa_size' => 'white-cream' ), wc_get_product( (int) $second['variant_id'] )->get_attributes() );
		$this->assertSame( array( 'pa_size' => 'white-navy' ), wc_get_product( (int) $third['variant_id'] )->get_attributes() );
	}

	public function test_a_value_without_group_does_not_take_the_slug_of_a_grouped_one() {
		$grouped = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', 'White', 'White' ) );
		$loose   = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', 'White' ) );

		$this->assertSame( array( 'pa_size' => 'white' ), wc_get_product( (int) $grouped['variant_id'] )->get_attributes() );
		$this->assertSame( array( 'pa_size' => 'white-2' ), wc_get_product( (int) $loose['variant_id'] )->get_attributes() );
		$this->assertCount( 2, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
	}

	public function test_a_value_without_group_that_gets_one_keeps_its_slug() {
		$loose   = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', 'White' ) );
		$grouped = $this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', 'White', 'White' ) );

		$this->assertSame( array( 'pa_size' => 'white' ), wc_get_product( (int) $grouped['variant_id'] )->get_attributes() );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
	}
}
