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
	 * @return array List of {id, name, parent_id, image} arrays, ids as strings.
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
		$attachment_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

		return array(
			'id'        => (string) $term->term_id,
			'name'      => $term->name,
			'parent_id' => $term->parent ? (string) $term->parent : null,
			'image'     => $attachment_id ? Images::origin_url( $attachment_id ) : null,
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
	 * Updates a category from Nube360: its name (alone, not the hierarchical
	 * path Nube360 shows) and/or its image. Only the keys present in $body
	 * are touched; an empty `image` removes the image the plugin had
	 * downloaded.
	 *
	 * @param int|string $id   Category id.
	 * @param array      $body Subset of {name, image}; `image_name` is the file
	 *                          name to give the downloaded image (optional).
	 *
	 * @return array|WP_Error {id: string} or WP_Error.
	 */
	public function update( $id, $body ) {
		$id = absint( $id );

		if ( ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			return new WP_Error(
				'nube360_wc_category_not_found',
				__( 'The category does not exist.', 'nube360-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		if ( array_key_exists( 'name', $body ) ) {
			$name = trim( (string) $body['name'] );
			$term = get_term( $id, self::TAXONOMY );

			if ( '' !== $name && $term->name !== $name ) {
				$updated = wp_update_term( $id, self::TAXONOMY, array( 'name' => $name ) );

				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
		}

		if ( array_key_exists( 'image', $body ) ) {
			Images::sync_term_image( $id, (string) $body['image'], isset( $body['image_name'] ) ? (string) $body['image_name'] : '' );
		}

		return array( 'id' => (string) $id );
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
