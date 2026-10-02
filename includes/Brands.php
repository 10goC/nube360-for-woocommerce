<?php
/**
 * Mapping of product brands (product_brand taxonomy) to/from the format
 * Nube360 expects.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading, creating and updating product brands.
 *
 * Like categories, brands are linked to Nube360 by term id: Nube360 keeps
 * the id of each brand and sends it back, so renaming a brand on either side
 * does not create a duplicate.
 */
class Brands {

	/**
	 * WooCommerce taxonomy for product brands (WooCommerce 9.6+).
	 *
	 * @var string
	 */
	const TAXONOMY = 'product_brand';

	/**
	 * Term meta where WooCommerce keeps the brand image (attachment id).
	 *
	 * @var string
	 */
	const THUMBNAIL_META = 'thumbnail_id';

	/**
	 * Whether the store has the brands taxonomy available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return taxonomy_exists( self::TAXONOMY );
	}

	/**
	 * Lists all brands.
	 *
	 * @return array List of {id, name, image} arrays, id as string, image a
	 *               URL or null.
	 */
	public function list_all() {
		if ( ! $this->is_available() ) {
			return array();
		}

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
			$attachment_id = (int) get_term_meta( $term->term_id, self::THUMBNAIL_META, true );

			$items[] = array(
				'id'    => (string) $term->term_id,
				'name'  => $term->name,
				'image' => $attachment_id ? Images::origin_url( $attachment_id ) : null,
			);
		}

		return $items;
	}

	/**
	 * Finds a brand by name, or creates it if it does not exist.
	 *
	 * @param string $name Brand name.
	 *
	 * @return int|WP_Error Term id, or WP_Error.
	 */
	private function find_or_create( $name ) {
		$existing = get_term_by( 'name', $name, self::TAXONOMY );

		if ( $existing && ! is_wp_error( $existing ) ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, self::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			// Two requests can race to create the same brand: WordPress then
			// answers with the id of the one that won.
			$existing_id = $result->get_error_data( 'term_exists' );

			return $existing_id ? (int) $existing_id : $result;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Creates or updates a brand and makes its image the one at $image_url
	 * (empty removes the image the plugin had downloaded).
	 *
	 * With an id of an existing brand, that brand is renamed if the name
	 * changed. Without an id, or if the id no longer exists, the brand is
	 * found by name or created, which is how a brand that already exists in
	 * the store gets linked the first time.
	 *
	 * @param string          $name      Brand name.
	 * @param string          $image_url Image URL (optional).
	 * @param int|string|null $id        Term id Nube360 has for it (optional).
	 * @param string          $image_name File name for the downloaded image (optional).
	 *
	 * @return array|WP_Error {id: string} or WP_Error.
	 */
	public function sync( $name, $image_url = '', $id = null, $image_name = '' ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return new WP_Error(
				'nube360_wc_invalid_brand',
				__( 'The brand name is required.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->is_available() ) {
			return new WP_Error(
				'nube360_wc_brands_unavailable',
				__( 'This store does not support product brands.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$id   = absint( $id );
		$term = $id ? get_term( $id, self::TAXONOMY ) : null;

		if ( $term && ! is_wp_error( $term ) ) {
			$term_id = (int) $term->term_id;

			if ( $term->name !== $name ) {
				$updated = wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $name ) );

				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
		} else {
			$term_id = $this->find_or_create( $name );

			if ( is_wp_error( $term_id ) ) {
				return $term_id;
			}
		}

		Images::sync_term_image( $term_id, $image_url, $image_name );

		return array( 'id' => (string) $term_id );
	}

	/**
	 * Resolves a list of brand ids to valid, existing term ids.
	 *
	 * @param array $brand_ids Brand ids (string or int).
	 *
	 * @return int[] Valid term ids.
	 */
	public function resolve_ids( $brand_ids ) {
		$ids = array();

		if ( ! $this->is_available() ) {
			return $ids;
		}

		foreach ( (array) $brand_ids as $id ) {
			$id = absint( $id );
			if ( $id && term_exists( $id, self::TAXONOMY ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Ids of the brands assigned to a product.
	 *
	 * @param int $product_id Product id (the parent, for variations).
	 *
	 * @return string[]
	 */
	public function ids_for_product( $product_id ) {
		if ( ! $this->is_available() ) {
			return array();
		}

		$ids = wc_get_product_term_ids( $product_id, self::TAXONOMY );

		return is_wp_error( $ids ) ? array() : array_map( 'strval', $ids );
	}

	/**
	 * Assigns brands to a product, replacing the previous ones.
	 *
	 * @param int   $product_id Product id.
	 * @param int[] $term_ids   Valid brand term ids.
	 */
	public function assign( $product_id, $term_ids ) {
		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $product_id, $term_ids, self::TAXONOMY );
		}
	}

	/**
	 * Deletes a brand. WordPress removes it from every product that had it,
	 * so those products are left without brand. The image the plugin had
	 * downloaded for it goes too.
	 *
	 * @param int|string $id Term id.
	 *
	 * @return array|WP_Error {success: true}, or WP_Error if it is not a brand.
	 */
	public function delete( $id ) {
		$id = absint( $id );

		if ( ! $this->is_available() || ! $id || ! term_exists( $id, self::TAXONOMY ) ) {
			return new WP_Error(
				'nube360_wc_brand_not_found',
				__( 'The brand does not exist.', 'nube360-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		Images::sync_term_image( $id, '' );

		$deleted = wp_delete_term( $id, self::TAXONOMY );

		if ( is_wp_error( $deleted ) || ! $deleted ) {
			return new WP_Error(
				'nube360_wc_brand_not_deleted',
				__( 'The brand could not be deleted.', 'nube360-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}

		return array( 'success' => true );
	}
}
