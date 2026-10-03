<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\AttributeFilter;
use Nube360\WooCommerce\AttributeFilterWidget;
use Nube360\WooCommerce\AttributeGroups;
use Nube360\WooCommerce\Swatches;

/**
 * The swatch attribute type, the colour/image of groups and values, and the
 * storefront filter built on them.
 *
 * @covers \Nube360\WooCommerce\AttributeFilter
 * @covers \Nube360\WooCommerce\AttributeFilterWidget
 * @covers \Nube360\WooCommerce\Swatches
 */
class AttributeFilterTest extends TestCase {

	/**
	 * @var AttributeFilter
	 */
	private $filter;

	public function set_up() {
		parent::set_up();
		$this->filter = new AttributeFilter();
	}

	public function tear_down() {
		unset( $_GET['filter_color'], $_GET['query_type_color'], $_POST['nube360_wc_color'], $_POST['nube360_wc_image_id'], $_POST[ Swatches::NONCE ] );
		parent::tear_down();
	}

	private function create_product( $sku, $color, $group = '' ) {
		$pair = array( 'attribute' => 'Color', 'value' => $color );
		if ( '' !== $group ) {
			$pair['group'] = $group;
		}

		return $this->api_ok(
			'POST',
			'/products',
			array(
				'title'             => 'Shirt ' . $sku,
				'family_ref'        => $sku,
				'family_attributes' => array( 'Color' ),
				'variant'           => array( 'sku' => $sku, 'price' => 10, 'stock' => 1, 'values' => array( $pair ) ),
			)
		);
	}

	/**
	 * Navy and Sky are "Blue", Rose is "Red", Grey has no group.
	 */
	private function create_catalog() {
		$this->create_product( 'S-1', 'Navy', 'Blue' );
		$this->create_product( 'S-2', 'Sky', 'Blue' );
		$this->create_product( 'S-3', 'Rose', 'Red' );
		$this->create_product( 'S-4', 'Grey' );
		$this->start_new_request();
	}

	private function make_swatch_attribute() {
		wc_update_attribute( wc_attribute_taxonomy_id_by_name( 'pa_color' ), array( 'type' => Swatches::TYPE ) );
		delete_transient( 'wc_attribute_taxonomies' );
	}

	private function group_id( $name ) {
		return get_term_by( 'slug', 'pa_color-' . strtolower( $name ), AttributeGroups::TAXONOMY )->term_id;
	}

	public function test_the_swatch_type_is_offered_among_the_attribute_types() {
		$this->assertArrayHasKey( Swatches::TYPE, apply_filters( 'product_attributes_type_selector', array() ) );
	}

	public function test_only_swatch_attributes_are_swatch_attributes() {
		$this->create_catalog();

		$this->assertFalse( Swatches::is_swatch_attribute( 'pa_color' ) );
		$this->make_swatch_attribute();
		$this->assertTrue( Swatches::is_swatch_attribute( 'pa_color' ) );
		$this->assertFalse( Swatches::is_swatch_attribute( 'pa_nope' ) );
	}

	public function test_grouped_choices_are_the_groups_without_the_ungrouped_values() {
		$this->create_catalog();

		$html = $this->filter->render( 'color', 'group' );

		$this->assertStringContainsString( 'title="Blue"', $html );
		$this->assertStringContainsString( 'title="Red"', $html );
		$this->assertStringNotContainsString( 'Grey', $html );
		$this->assertStringNotContainsString( 'Navy', $html );
	}

	public function test_a_group_link_selects_all_of_its_values_with_the_native_woocommerce_parameters() {
		$this->create_catalog();

		$html = $this->filter->render( 'color', 'group' );

		$this->assertMatchesRegularExpression( '/filter_color=navy,sky/', $html );
		$this->assertStringContainsString( 'query_type_color=or', $html );
	}

	public function test_a_group_is_active_when_all_its_values_are_selected_and_its_link_deselects_them() {
		$this->create_catalog();
		$_GET['filter_color'] = 'navy,sky,rose';

		$html = $this->filter->render( 'color', 'group' );

		$this->assertSame( 2, substr_count( $html, 'is-active' ) );
		// Deselecting Blue leaves Red selected.
		$this->assertMatchesRegularExpression( '/<a href="[^"]*filter_color=rose[^"]*" rel="nofollow" title="Blue"/', $html );

		$_GET['filter_color'] = 'navy';
		$this->assertStringNotContainsString( 'is-active', $this->filter->render( 'color', 'group' ), 'A partly selected group is not active.' );
	}

	public function test_without_groups_every_value_is_a_choice() {
		$this->create_catalog();

		$html = $this->filter->render( 'color', 'term' );

		foreach ( array( 'Navy', 'Sky', 'Rose', 'Grey' ) as $name ) {
			$this->assertStringContainsString( 'title="' . $name . '"', $html );
		}
	}

	public function test_groups_and_values_without_products_are_not_offered() {
		$this->create_catalog();
		$this->api_ok( 'PUT', '/attributes', array( 'attributes' => array( array( 'name' => 'Color', 'values' => array( array( 'value' => 'Lime', 'group' => 'Green' ) ) ) ) ) );

		$this->assertStringNotContainsString( 'Green', $this->filter->render( 'color', 'group' ) );
		$this->assertStringNotContainsString( 'Lime', $this->filter->render( 'color', 'term' ) );
	}

	public function test_it_draws_a_list_unless_the_attribute_is_a_swatch_attribute() {
		$this->create_catalog();

		$this->assertStringContainsString( 'nube360-wc-filter--list', $this->filter->render( 'color', 'group' ) );

		$this->make_swatch_attribute();
		$this->assertStringContainsString( 'nube360-wc-filter--swatch', $this->filter->render( 'color', 'group' ) );
		$this->assertStringContainsString( 'nube360-wc-filter--list', $this->filter->render( 'color', 'group', 'list' ) );
	}

	public function test_a_swatch_is_drawn_with_its_colour_or_its_image() {
		$this->create_catalog();
		$this->make_swatch_attribute();
		update_term_meta( $this->group_id( 'Blue' ), AttributeGroups::META_COLOR, '#1e90ff' );
		$attachment = $this->factory()->attachment->create( array( 'post_mime_type' => 'image/png', 'file' => 'reds.png' ) );
		update_term_meta( $this->group_id( 'Red' ), AttributeGroups::META_IMAGE, $attachment );

		$html = $this->filter->render( 'color', 'group' );

		$this->assertStringContainsString( 'background-color:#1e90ff', $html );
		$this->assertStringContainsString( 'nube360-wc-swatch--image', $html );
		$this->assertStringContainsString( 'reds', $html );
	}

	public function test_a_swatch_without_colour_or_image_falls_back_to_its_name() {
		$this->create_catalog();
		$this->make_swatch_attribute();

		$this->assertStringContainsString( 'nube360-wc-swatch--text">Blue<', $this->filter->render( 'color', 'group' ) );
	}

	public function test_an_unknown_attribute_draws_nothing() {
		$this->assertSame( '', $this->filter->render( 'nope' ) );
		$this->assertSame( '', $this->filter->render( '' ) );
	}

	public function test_the_shortcode_renders_the_filter() {
		$this->create_catalog();

		$html = do_shortcode( '[nube360_attribute_filter attribute="Color" group_by="group"]' );

		$this->assertStringContainsString( 'data-attribute="color"', $html );
		$this->assertStringContainsString( 'title="Blue"', $html );
	}

	public function test_the_block_is_registered_and_renders_like_the_shortcode() {
		$this->create_catalog();

		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( AttributeFilter::BLOCK ) );
		$this->assertSame(
			$this->filter->render( 'color', 'group', 'list' ),
			render_block( array( 'blockName' => AttributeFilter::BLOCK, 'attrs' => array( 'attribute' => 'color', 'groupBy' => 'group', 'display' => 'list' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) )
		);
	}

	public function test_the_widget_sanitizes_its_settings() {
		$saved = ( new AttributeFilterWidget() )->update(
			array( 'title' => '<b>Colours</b>', 'attribute' => 'Color', 'group_by' => 'evil', 'display' => 'evil' ),
			array()
		);

		$this->assertSame(
			array( 'title' => 'Colours', 'attribute' => 'color', 'group_by' => 'term', 'display' => 'auto' ),
			$saved
		);
	}

	public function test_saving_a_term_stores_a_valid_colour_and_image() {
		$this->create_catalog();
		$term_id    = $this->group_id( 'Blue' );
		$attachment = $this->factory()->attachment->create( array( 'post_mime_type' => 'image/png', 'file' => 'blue.png' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST[ Swatches::NONCE ]   = wp_create_nonce( Swatches::NONCE );
		$_POST['nube360_wc_color']  = '#0000ff';
		$_POST['nube360_wc_image_id'] = (string) $attachment;
		( new Swatches() )->save_fields( $term_id );

		$swatch = Swatches::get( $term_id );
		$this->assertSame( '#0000ff', $swatch['color'] );
		$this->assertSame( $attachment, $swatch['image_id'] );

		$_POST['nube360_wc_color']    = 'not-a-colour';
		$_POST['nube360_wc_image_id'] = '999999';
		( new Swatches() )->save_fields( $term_id );

		$this->assertSame( '', Swatches::get( $term_id )['color'] );
		$this->assertSame( 0, Swatches::get( $term_id )['image_id'] );
	}

	public function test_saving_a_term_needs_the_nonce_and_the_capability() {
		$this->create_catalog();
		$term_id = $this->group_id( 'Blue' );
		$_POST['nube360_wc_color'] = '#0000ff';

		( new Swatches() )->save_fields( $term_id );
		$this->assertSame( '', Swatches::get( $term_id )['color'], 'No nonce.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST[ Swatches::NONCE ] = wp_create_nonce( Swatches::NONCE );
		( new Swatches() )->save_fields( $term_id );
		$this->assertSame( '', Swatches::get( $term_id )['color'], 'No capability.' );
	}
}
