<?php
namespace Nube360\WooCommerce\Tests\Integration;

use Nube360\WooCommerce\Categories;
use WP_Error;

/**
 * @covers \Nube360\WooCommerce\Categories
 */
class CategoriesTest extends TestCase {

	/**
	 * @var Categories
	 */
	private $categories;

	public function set_up() {
		parent::set_up();
		$this->categories = new Categories();
	}

	public function test_it_creates_a_category_and_finds_it_again() {
		$created = $this->categories->find_or_create( 'Shoes' );
		$again   = $this->categories->find_or_create( 'Shoes' );

		$this->assertSame( $created, $again );
		$term = get_term( (int) $created['id'], 'product_cat' );
		$this->assertSame( 'Shoes', $term->name );
		$this->assertSame( 0, $term->parent );
	}

	public function test_the_same_name_under_different_parents_are_different_categories() {
		$men   = $this->categories->find_or_create( 'Men' );
		$women = $this->categories->find_or_create( 'Women' );

		$men_shoes   = $this->categories->find_or_create( 'Shoes', $men['id'] );
		$women_shoes = $this->categories->find_or_create( 'Shoes', $women['id'] );

		$this->assertNotSame( $men_shoes['id'], $women_shoes['id'] );
		$this->assertSame( $men_shoes, $this->categories->find_or_create( 'Shoes', $men['id'] ) );
		$this->assertSame( (int) $women['id'], get_term( (int) $women_shoes['id'], 'product_cat' )->parent );
	}

	public function test_a_name_that_exists_only_under_another_parent_is_created_under_the_requested_one() {
		$men   = $this->categories->find_or_create( 'Men' );
		$women = $this->categories->find_or_create( 'Women' );
		$this->categories->find_or_create( 'Shoes', $men['id'] );

		$created = $this->categories->find_or_create( 'Shoes', $women['id'] );

		$this->assertSame( (int) $women['id'], get_term( (int) $created['id'], 'product_cat' )->parent );
	}

	public function test_the_name_is_trimmed() {
		$a = $this->categories->find_or_create( '  Bags ' );
		$b = $this->categories->find_or_create( 'Bags' );

		$this->assertSame( $a, $b );
	}

	public function test_a_blank_name_is_rejected() {
		$this->assertInstanceOf( WP_Error::class, $this->categories->find_or_create( '' ) );
	}

	public function test_it_lists_the_categories_with_their_parents() {
		$parent = $this->categories->find_or_create( 'Clothes' );
		$child  = $this->categories->find_or_create( 'Shirts', $parent['id'] );

		$by_id = array();
		foreach ( $this->categories->list_all() as $category ) {
			$by_id[ $category['id'] ] = $category;
		}

		$this->assertSame( array( 'id' => $parent['id'], 'name' => 'Clothes', 'parent_id' => null ), $by_id[ $parent['id'] ] );
		$this->assertSame( array( 'id' => $child['id'], 'name' => 'Shirts', 'parent_id' => $parent['id'] ), $by_id[ $child['id'] ] );
	}

	public function test_empty_categories_are_listed_too() {
		$empty = $this->categories->find_or_create( 'Nothing here' );

		$this->assertContains( $empty['id'], wp_list_pluck( $this->categories->list_all(), 'id' ) );
	}

	public function test_it_keeps_only_the_ids_that_are_product_categories() {
		$category = $this->categories->find_or_create( 'Shoes' );
		$tag      = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'A tag' ) );

		$this->assertSame(
			array( (int) $category['id'] ),
			$this->categories->resolve_ids( array( $category['id'], (string) $tag, '999999', 'abc' ) )
		);
	}
}
