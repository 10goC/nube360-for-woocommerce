<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\AttributeGroups;
use Nube360\WooCommerce\Attributes;

/**
 * Nube360 is told, by term id, when the group of an attribute value changes
 * by hand in WordPress, and can read the value back.
 *
 * @covers \Nube360\WooCommerce\Webhooks
 * @covers \Nube360\WooCommerce\Attributes::get_value
 */
class AttributeEventsTest extends TestCase {

	/**
	 * @var Attributes
	 */
	private $attributes;

	/**
	 * @var \WP_Term[]
	 */
	private $values;

	public function set_up() {
		parent::set_up();
		$this->attributes = new Attributes();

		$taxonomy     = $this->attributes->ensure_attribute( 'Size' );
		$this->values = array(
			'two'   => $this->attributes->ensure_term( $taxonomy, '2', 'Babies' ),
			'three' => $this->attributes->ensure_term( $taxonomy, '3', 'Babies' ),
			'four'  => $this->attributes->ensure_term( $taxonomy, '4', 'Juvenile' ),
		);

		// Creating them queued events of their own: forget about them.
		$this->flush_events();
		$this->erp_requests = array();
	}

	private function flush_events() {
		foreach ( $GLOBALS['wp_filter']['shutdown']->callbacks[20] ?? array() as $callback ) {
			if ( is_array( $callback['function'] ) && 'flush_queued' === $callback['function'][1] ) {
				call_user_func( $callback['function'] );
			}
		}
	}

	private function group_id( $name ) {
		return get_term_by( 'slug', 'pa_size-' . strtolower( $name ), AttributeGroups::TAXONOMY )->term_id;
	}

	private function event_for( $term ) {
		return array( 'event' => 'attribute_value.updated', 'id' => (string) $term->term_id );
	}

	public function test_moving_a_value_to_another_group_notifies_that_value_once() {
		update_term_meta( $this->values['two']->term_id, AttributeGroups::META_TERM_GROUP, $this->group_id( 'Juvenile' ) );
		$this->assertSame( array(), $this->sent_events(), 'Nothing is sent before the request ends.' );

		$this->flush_events();

		$this->assertSame( array( $this->event_for( $this->values['two'] ) ), $this->sent_events() );
	}

	public function test_taking_a_value_out_of_its_group_notifies() {
		delete_term_meta( $this->values['two']->term_id, AttributeGroups::META_TERM_GROUP );
		$this->flush_events();

		$this->assertSame( array( $this->event_for( $this->values['two'] ) ), $this->sent_events() );
	}

	public function test_renaming_a_group_notifies_each_of_its_values() {
		wp_update_term( $this->group_id( 'Babies' ), AttributeGroups::TAXONOMY, array( 'name' => 'Infants' ) );
		$this->flush_events();

		$this->assertSame(
			array( $this->event_for( $this->values['two'] ), $this->event_for( $this->values['three'] ) ),
			$this->sent_events()
		);
	}

	public function test_editing_only_the_swatch_of_a_group_notifies_nothing() {
		$group_id = $this->group_id( 'Babies' );
		wp_update_term( $group_id, AttributeGroups::TAXONOMY, array( 'name' => 'Babies' ) );
		update_term_meta( $group_id, AttributeGroups::META_COLOR, '#ffcc00' );
		$this->flush_events();

		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_deleting_a_group_notifies_each_of_its_values() {
		wp_delete_term( $this->group_id( 'Babies' ), AttributeGroups::TAXONOMY );
		$this->flush_events();

		$this->assertEqualSets(
			array( $this->event_for( $this->values['two'] ), $this->event_for( $this->values['three'] ) ),
			$this->sent_events()
		);
	}

	public function test_groups_changed_by_an_incoming_nube360_request_do_not_notify() {
		$this->api_ok( 'PUT', '/attributes', array( 'attributes' => array( array( 'name' => 'Size', 'values' => array( array( 'value' => '9', 'group' => 'Babies' ), array( 'value' => '4', 'group' => 'Babies' ) ) ) ) ) );
		$this->flush_events();

		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_a_value_can_be_read_back_by_its_term_id() {
		$this->start_new_request();
		$data = $this->api_ok( 'GET', '/attributes/values/' . $this->values['two']->term_id );

		$this->assertSame(
			array( 'id' => (string) $this->values['two']->term_id, 'attribute' => 'Size', 'value' => '2', 'group' => 'Babies' ),
			$data
		);
	}

	public function test_a_value_without_group_is_read_back_with_an_empty_group() {
		delete_term_meta( $this->values['two']->term_id, AttributeGroups::META_TERM_GROUP );

		$data = $this->api_ok( 'GET', '/attributes/values/' . $this->values['two']->term_id );

		$this->assertSame( '', $data['group'] );
	}

	public function test_only_attribute_values_can_be_read() {
		$category = self::factory()->term->create( array( 'taxonomy' => 'product_cat', 'name' => 'Shoes' ) );

		$this->assertSame( 404, $this->api( 'GET', '/attributes/values/' . $category )->get_status() );
		$this->assertSame( 404, $this->api( 'GET', '/attributes/values/999999' )->get_status() );
		$this->assertSame( 401, $this->api( 'GET', '/attributes/values/' . $this->values['two']->term_id, null, array(), null )->get_status() );
	}

	public function test_after_renaming_a_group_the_new_name_is_still_the_same_group() {
		$group_id = $this->group_id( 'Babies' );
		wp_update_term( $group_id, AttributeGroups::TAXONOMY, array( 'name' => 'Infants' ) );

		$term = $this->attributes->ensure_term( 'pa_size', '2', 'Infants' );

		$this->assertSame( $this->values['two']->term_id, $term->term_id );
		$this->assertCount( 2, get_terms( array( 'taxonomy' => AttributeGroups::TAXONOMY, 'hide_empty' => false ) ) );
	}

	public function test_a_group_named_like_a_renamed_one_gets_its_own_slug() {
		wp_update_term( $this->group_id( 'Babies' ), AttributeGroups::TAXONOMY, array( 'name' => 'Infants' ) );

		$term = $this->attributes->ensure_term( 'pa_size', '5', 'Babies' );

		$this->assertSame( 'Babies', $this->attributes->term_group_name( $term->term_id ) );
		$this->assertCount( 3, get_terms( array( 'taxonomy' => AttributeGroups::TAXONOMY, 'hide_empty' => false ) ) );
	}
}
