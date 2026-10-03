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
		$value = get_term_by( 'slug', '2', 'pa_size' );
		update_term_meta( $group->term_id, AttributeGroups::META_COLOR, '#ffcc00' );

		$data = $this->api_ok( 'GET', '/attributes' );

		$this->assertSame(
			array(
				array(
					'name'   => 'Size',
					'values' => array( array( 'id' => (string) $value->term_id, 'value' => '2', 'group' => 'Babies' ) ),
					'groups' => array( array( 'id' => (string) $group->term_id, 'name' => 'Babies', 'color' => '#ffcc00', 'image' => null ) ),
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

	public function test_the_admin_lists_show_the_group_of_each_value_and_the_values_of_each_group() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '3', 'Babies' ) );
		$this->start_new_request();
		$groups = new AttributeGroups();
		$value  = get_term_by( 'slug', '2', 'pa_size' );
		$group  = get_term_by( 'slug', 'pa_size-babies', AttributeGroups::TAXONOMY );

		$this->assertSame( 'Babies', $groups->render_value_column( '', 'nube360_wc_group', $value->term_id ) );
		$this->assertSame( '', $groups->render_value_column( '', 'nube360_wc_group', get_term_by( 'slug', '3', 'pa_size' )->term_id + 999 ) );
		$this->assertSame( '2', $groups->render_group_column( '', 'nube360_wc_values', $group->term_id ) );
		$this->assertSame( 'Size', $groups->render_group_column( '', 'nube360_wc_attribute', $group->term_id ) );

		$columns = $groups->add_group_columns( array( 'cb' => '', 'name' => 'Name', 'posts' => 'Count' ) );
		$this->assertSame( array( 'cb', 'name', 'nube360_wc_attribute', 'nube360_wc_values', 'nube360_wc_swatch' ), array_keys( $columns ) );
		$this->assertSame( array( 'name', 'nube360_wc_group', 'slug' ), array_keys( $groups->add_value_columns( array( 'name' => 'Name', 'slug' => 'Slug' ) ) ) );
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

	public function test_the_value_forms_offer_only_the_groups_of_their_attribute() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->attributes->ensure_term( $this->attributes->ensure_attribute( 'Color' ), 'Navy', 'Blue' );
		$groups = new AttributeGroups();
		$term   = get_term_by( 'slug', '2', 'pa_size' );

		ob_start();
		$groups->render_edit_field( $term, 'pa_size' );
		$edit = ob_get_clean();
		ob_start();
		$groups->render_add_field( 'pa_size' );
		$add = ob_get_clean();

		$this->assertStringContainsString( 'Babies', $edit );
		$this->assertStringNotContainsString( 'Blue', $edit );
		$this->assertMatchesRegularExpression( '/<option value="\d+"\s+selected=\'selected\'>Babies/', $edit );
		$this->assertStringNotContainsString( 'selected', $add );
	}

	public function test_saving_the_value_form_sets_and_clears_the_group() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '3', 'Juvenile' ) );
		$groups   = new AttributeGroups();
		$term_id  = get_term_by( 'slug', '2', 'pa_size' )->term_id;
		$juvenile = get_term_by( 'slug', 'pa_size-juvenile', AttributeGroups::TAXONOMY )->term_id;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST[ AttributeGroups::NONCE ] = wp_create_nonce( AttributeGroups::NONCE );

		$_POST['nube360_wc_group_id'] = (string) $juvenile;
		$groups->save_field( $term_id );
		$this->assertSame( 'Juvenile', $this->attributes->term_group_name( $term_id ) );

		$_POST['nube360_wc_group_id'] = '0';
		$groups->save_field( $term_id );
		$this->assertSame( '', $this->attributes->term_group_name( $term_id ) );

		unset( $_POST[ AttributeGroups::NONCE ], $_POST['nube360_wc_group_id'] );
	}

	public function test_a_group_of_another_attribute_or_without_nonce_is_not_saved() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->attributes->ensure_term( $this->attributes->ensure_attribute( 'Color' ), 'Navy', 'Blue' );
		$groups   = new AttributeGroups();
		$term_id  = get_term_by( 'slug', '2', 'pa_size' )->term_id;
		$blue     = get_term_by( 'slug', 'pa_color-blue', AttributeGroups::TAXONOMY )->term_id;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['nube360_wc_group_id'] = (string) $blue;
		$groups->save_field( $term_id );
		$this->assertSame( 'Babies', $this->attributes->term_group_name( $term_id ), 'No nonce: nothing changes.' );

		$_POST[ AttributeGroups::NONCE ] = wp_create_nonce( AttributeGroups::NONCE );
		$groups->save_field( $term_id );
		$this->assertSame( '', $this->attributes->term_group_name( $term_id ), 'A group of another attribute leaves it without group.' );

		unset( $_POST[ AttributeGroups::NONCE ], $_POST['nube360_wc_group_id'] );
	}

	private function put_values( array $values ) {
		return $this->api_ok( 'PUT', '/attributes', array( 'attributes' => array( array( 'name' => 'Size', 'values' => $values ) ) ) );
	}

	public function test_a_value_is_moved_to_another_group_by_its_term_id() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$term = get_term_by( 'slug', '2', 'pa_size' );

		$data = $this->put_values( array( array( 'id' => $term->term_id, 'value' => '2', 'group' => 'Juvenile' ) ) );

		$this->assertSame( array(), $data['conflicts'] );
		$this->assertSame( 'Juvenile', $this->attributes->term_group_name( $term->term_id ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ), 'The same term, not a new one.' );
		$this->assertSame( '2', get_term( $term->term_id )->slug, 'Its slug does not change: the variations point to it.' );
		$this->assertSame( array(), $this->sent_events(), 'Nothing is echoed back to Nube360.' );
	}

	public function test_a_value_is_moved_by_the_group_it_had_before() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$term = get_term_by( 'slug', '2', 'pa_size' );

		$this->put_values( array( array( 'value' => '2', 'group' => 'Juvenile', 'previous_group' => 'Babies' ) ) );

		$this->assertSame( 'Juvenile', $this->attributes->term_group_name( $term->term_id ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
	}

	public function test_a_value_can_be_taken_out_of_its_group() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$term = get_term_by( 'slug', '2', 'pa_size' );

		$this->put_values( array( array( 'id' => $term->term_id, 'value' => '2', 'group' => '' ) ) );

		$this->assertSame( '', $this->attributes->term_group_name( $term->term_id ) );
	}

	public function test_a_move_that_would_duplicate_a_value_in_its_new_group_is_reported_and_left_alone() {
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-1', '2', 'Babies' ) );
		$this->api_ok( 'POST', '/products', $this->size_body( 'SOCK-2', '2', 'Juvenile' ) );
		$babies = get_term_by( 'slug', '2', 'pa_size' );

		$data = $this->put_values( array( array( 'id' => $babies->term_id, 'value' => '2', 'group' => 'Juvenile' ) ) );

		$this->assertSame( array( array( 'attribute' => 'Size', 'value' => '2', 'group' => 'Juvenile' ) ), $data['conflicts'] );
		$this->assertSame( 'Babies', $this->attributes->term_group_name( $babies->term_id ) );
	}

	public function test_an_id_or_previous_group_that_matches_nothing_creates_the_value_as_usual() {
		$this->put_values(
			array(
				array( 'id' => 999999, 'value' => '2', 'group' => 'Babies' ),
				array( 'value' => '3', 'group' => 'Babies', 'previous_group' => 'Nowhere' ),
			)
		);

		$this->assertCount( 2, get_terms( array( 'taxonomy' => 'pa_size', 'hide_empty' => false ) ) );
		$this->assertSame( 'Babies', $this->group_of_term( 'pa_size', '2' ) );
		$this->assertSame( 'Babies', $this->group_of_term( 'pa_size', '3' ) );
	}
}
