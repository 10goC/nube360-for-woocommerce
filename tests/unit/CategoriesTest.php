<?php
namespace Nube360\WooCommerce\Tests\Unit;

use Brain\Monkey\Functions;
use Nube360\WooCommerce\Categories;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\Categories
 */
class CategoriesTest extends TestCase {

	private function term( $id, $name, $parent = 0 ) {
		return (object) array(
			'term_id' => $id,
			'name'    => $name,
			'parent'  => $parent,
		);
	}

	public function test_it_lists_categories_with_string_ids_and_a_null_root_parent() {
		Functions\expect( 'get_terms' )->once()->andReturn( array( $this->term( 15, 'Clothes' ), $this->term( 16, 'Shirts', 15 ) ) );

		$this->assertSame(
			array(
				array( 'id' => '15', 'name' => 'Clothes', 'parent_id' => null ),
				array( 'id' => '16', 'name' => 'Shirts', 'parent_id' => '15' ),
			),
			( new Categories() )->list_all()
		);
	}

	public function test_it_lists_nothing_when_there_are_no_terms_or_the_query_fails() {
		Functions\expect( 'get_terms' )->twice()->andReturn( array(), new WP_Error( 'x', 'broken' ) );

		$this->assertSame( array(), ( new Categories() )->list_all() );
		$this->assertSame( array(), ( new Categories() )->list_all() );
	}

	public function test_a_blank_name_is_a_400() {
		$result = ( new Categories() )->find_or_create( '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nube360_wc_invalid_category', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_it_reuses_an_existing_category_with_the_same_name_and_parent() {
		Functions\when( 'get_term_by' )->justReturn( $this->term( 20, 'Shoes', 15 ) );
		Functions\expect( 'wp_insert_term' )->never();

		$this->assertSame( array( 'id' => '20' ), ( new Categories() )->find_or_create( 'Shoes', '15' ) );
	}

	public function test_it_looks_among_all_same_named_terms_for_the_one_with_the_requested_parent() {
		Functions\when( 'get_term_by' )->justReturn( $this->term( 20, 'Shoes', 99 ) );
		Functions\when( 'get_terms' )->justReturn( array( $this->term( 20, 'Shoes', 99 ), $this->term( 21, 'Shoes', 15 ) ) );
		Functions\expect( 'wp_insert_term' )->never();

		$this->assertSame( array( 'id' => '21' ), ( new Categories() )->find_or_create( 'Shoes', '15' ) );
	}

	public function test_it_creates_the_category_under_its_parent_when_missing() {
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\expect( 'wp_insert_term' )->once()->with( 'Shoes', 'product_cat', array( 'parent' => 15 ) )->andReturn( array( 'term_id' => 30 ) );

		$this->assertSame( array( 'id' => '30' ), ( new Categories() )->find_or_create( ' Shoes ', '15' ) );
	}

	public function test_a_root_category_is_created_with_parent_zero() {
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\expect( 'wp_insert_term' )->once()->with( 'Shoes', 'product_cat', array( 'parent' => 0 ) )->andReturn( array( 'term_id' => 31 ) );

		( new Categories() )->find_or_create( 'Shoes' );
	}

	public function test_a_creation_error_is_returned_as_is() {
		Functions\when( 'get_term_by' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		$error = new WP_Error( 'db', 'could not insert' );
		Functions\when( 'wp_insert_term' )->justReturn( $error );

		$this->assertSame( $error, ( new Categories() )->find_or_create( 'Shoes' ) );
	}

	public function test_it_keeps_only_ids_of_categories_that_exist() {
		Functions\when( 'term_exists' )->alias(
			function ( $id ) {
				return in_array( $id, array( 5, 7 ), true ) ? array( 'term_id' => $id ) : null;
			}
		);

		$this->assertSame( array( 5, 7 ), ( new Categories() )->resolve_ids( array( '5', 'abc', 0, '9', 7 ) ) );
	}
}
