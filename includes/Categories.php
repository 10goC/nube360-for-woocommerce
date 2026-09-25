<?php
/**
 * Mapping of product categories (product_cat taxonomy) to/from the format
 * Nube360 expects.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Error;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading and creating product categories.
 */
class Categories {

	/**
	 * WooCommerce taxonomy for product categories.
	 *
	 * @var string
	 */
	const TAXONOMY = 'product_cat';

	/**
	 * Lists all product categories.
	 *
	 * @return array List of {id, name, parent_id} arrays, ids as strings.
	 */
	public function list_all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$items = array();

		foreach ( $terms as $term ) {
			$items[] = $this->format( $term );
		}

		return $items;
	}

	/**
	 * Shapes a WP_Term the way the REST contract expects it.
	 *
	 * @param WP_Term $term Term of the product_cat taxonomy.
	 *
	 * @return array
	 */
	private function format( $term ) {
		return array(
			'id'        => (string) $term->term_id,
			'name'      => $term->name,
			'parent_id' => $term->parent ? (string) $term->parent : null,
		);
	}

	/**
	 * Finds an existing category by name + parent, or creates it if it does
	 * not exist.
	 *
	 * @param string      $name      Category name.
	 * @param string|null $parent_id Parent category ID, if any.
	 *
	 * @return array|WP_Error {id: string} or WP_Error.
	 */
	public function find_or_create( $name, $parent_id = null ) {
		$name      = trim( (string) $name );
		$parent_id = $parent_id ? absint( $parent_id ) : 0;

		if ( '' === $name ) {
			return new WP_Error(
				'nube360_wc_invalid_category',
				__( 'The category name is required.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$existing = get_term_by( 'name', $name, self::TAXONOMY );

		if ( $existing && ! is_wp_error( $existing ) && (int) $existing->parent === $parent_id ) {
			return array( 'id' => (string) $existing->term_id );
		}

		// get_term_by() does not filter by parent; among all the terms with
		// that name, look for the one with the requested parent.
		$candidates = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'name'       => $name,
			)
		);

		if ( ! is_wp_error( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				if ( (int) $candidate->parent === $parent_id ) {
					return array( 'id' => (string) $candidate->term_id );
				}
			}
		}

		$result = wp_insert_term(
			$name,
			self::TAXONOMY,
			array( 'parent' => $parent_id )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'id' => (string) $result['term_id'] );
	}

	/**
	 * Resolves a list of category ids to valid, existing term_ids, to assign
	 * to a product.
	 *
	 * @param array $category_ids Category ids (string or int).
	 *
	 * @return int[] Valid term ids.
	 */
	public function resolve_ids( $category_ids ) {
		$ids = array();

		foreach ( (array) $category_ids as $id ) {
			$id = absint( $id );
			if ( $id && term_exists( $id, self::TAXONOMY ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
