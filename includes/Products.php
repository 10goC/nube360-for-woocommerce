<?php
/**
 * All the WooCommerce <-> Nube360 payload mapping logic: list, find by SKU,
 * create, update and delete products/variants.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use Exception;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapping and CRUD of products/variants against WooCommerce.
 */
class Products {

	/**
	 * Parent product meta where the Nube360 family reference is stored, so
	 * that two variants of the same family sent without an `id` (e.g. in the
	 * same batch, before Nube360 knows the id WooCommerce assigned) find the
	 * same parent instead of creating two families.
	 */
	const META_FAMILY_REF = '_nube360_wc_family_ref';

	/**
	 * Maximum number of items per batch, to bound the time of a single request.
	 */
	const MAX_BATCH = 100;

	/**
	 * Request cache: family reference => parent product id.
	 *
	 * @var array
	 */
	private static $families_by_ref = array();

	/**
	 * Categories helper, used to resolve ids when creating products.
	 *
	 * @var Categories
	 */
	private $categories;

	/**
	 * Brands helper, used to assign brands when creating products and to
	 * read them back.
	 *
	 * @var Brands
	 */
	private $brands;

	/**
	 * Attributes helper: global attributes, their values and groups.
	 *
	 * @var Attributes
	 */
	private $attributes;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->categories = new Categories();
		$this->brands     = new Brands();
		$this->attributes = new Attributes();
	}

	/**
	 * Lists products, paginated, expanding each variation of a variable
	 * product into its own item.
	 *
	 * Scalability note: to compute total_pages over the number of ITEMS (not
	 * products) the whole catalog has to be expanded before paginating. For
	 * the expected catalog size (small/medium self-hosted stores) that is
	 * acceptable; if the catalog grows a lot this listing should be cached.
	 *
	 * @param int $page     Requested page (1-based).
	 * @param int $per_page Items per page.
	 *
	 * @return array {items: array, total_pages: int}
	 */
	public function list_all( $page = 1, $per_page = 100 ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );

		$product_ids = wc_get_products(
			array(
				'status'  => array( 'publish', 'private' ),
				'limit'   => -1,
				'return'  => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$items = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$items = array_merge( $items, $this->format_product_items( $product ) );
		}

		$total_items = count( $items );
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;

		return array(
			'items'       => array_slice( $items, $offset, $per_page ),
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Shapes all the items that represent a WooCommerce product (a single
	 * one if it is simple, one per variation if it is variable).
	 *
	 * @param WC_Product $product Product.
	 *
	 * @return array[]
	 */
	private function format_product_items( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$items = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$items[] = $this->format_item( $product, $variation );
			}
			return $items;
		}

		if ( $product->is_type( 'variation' ) ) {
			return array();
		}

		return array( $this->format_item( $product, null ) );
	}

	/**
	 * Gets ALL the variants of a product by family id (or the only item if
	 * it is a simple product), wrapped in {"items": [...]} as in GET
	 * /products. It exists because GET /products/{id} only receives the
	 * family id, with no specific variant id.
	 *
	 * @param int $id Product id (family or simple).
	 *
	 * @return array|WP_Error {"items": array[]}
	 */
	public function get_family( $id ) {
		$id      = absint( $id );
		$product = wc_get_product( $id );

		if ( ! $product || $product->is_type( 'variation' ) ) {
			return $this->not_found_error();
		}

		return array( 'items' => $this->format_product_items( $product ) );
	}

	/**
	 * Finds an item by exact SKU.
	 *
	 * @param string $sku SKU to look for.
	 *
	 * @return array|WP_Error
	 */
	public function get_by_sku( $sku ) {
		$product_id = wc_get_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			return $this->not_found_error();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $this->not_found_error();
		}

		if ( 'variation' === $product->get_type() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return $this->not_found_error();
			}
			return $this->format_item( $parent, $product );
		}

		return $this->format_item( $product, null );
	}

	/**
	 * Creates a new product/variant, or adds a variant to an existing family
	 * if the body carries an "id".
	 *
	 * @param array $body Decoded body of POST /products.
	 *
	 * @return array|WP_Error
	 */
	public function create( $body ) {
		$title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error(
				'nube360_wc_title_required',
				__( 'The "title" field is required.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$variant = ( isset( $body['variant'] ) && is_array( $body['variant'] ) ) ? $body['variant'] : array();

		if ( empty( $variant['sku'] ) ) {
			return new WP_Error(
				'nube360_wc_sku_required',
				__( 'The "variant.sku" field is required.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// Idempotent by SKU: if it already exists, what is there is returned
		// (same format as a successful creation) instead of failing. This
		// saves Nube360 from checking by SKU first (an extra request per
		// product) and makes it safe to retry an export cut off halfway.
		$existing = $this->ids_by_sku( $variant['sku'] );
		if ( $existing ) {
			return $existing;
		}

		$category_ids = isset( $body['categories'] ) ? $this->categories->resolve_ids( $body['categories'] ) : array();
		$images       = isset( $body['images'] ) && is_array( $body['images'] ) ? $body['images'] : array();
		// Like the photos: it is family data (or of the simple product, which
		// has no separate "family" concept), it only arrives in the call that
		// creates the product/family for the first time — Nube360 does not
		// resend it when adding another variant to an existing family.
		$description  = isset( $body['description'] ) ? wp_kses_post( $body['description'] ) : '';
		$existing_id  = isset( $body['id'] ) ? absint( $body['id'] ) : 0;
		$family_ref   = isset( $body['family_ref'] ) ? sanitize_text_field( (string) $body['family_ref'] ) : '';

		if ( ! $existing_id && '' !== $family_ref ) {
			$existing_id = $this->find_family_by_ref( $family_ref );
		}

		if ( $existing_id ) {
			return $this->add_variant( $existing_id, $variant );
		}

		$family_attributes = array();
		if ( ! empty( $body['family_attributes'] ) && is_array( $body['family_attributes'] ) ) {
			foreach ( $body['family_attributes'] as $name ) {
				$name = sanitize_text_field( $name );
				if ( '' !== $name ) {
					$family_attributes[] = $name;
				}
			}
		}

		if ( empty( $family_attributes ) ) {
			$result = $this->create_simple_product( $title, $variant, $category_ids, $images, $description );
		} else {
			$result = $this->create_variable_product( $title, $family_attributes, $variant, $category_ids, $images, $description, $family_ref );
		}

		// Like the description and the photos, the brands are family data:
		// they only arrive in the call that creates the product/family.
		if ( ! is_wp_error( $result ) && ! empty( $body['brands'] ) ) {
			$this->brands->assign( absint( $result['id'] ), $this->brands->resolve_ids( $body['brands'] ) );
		}

		return $result;
	}

	/**
	 * Creates several products/variants in a single request. Each item is a
	 * `POST /products` body and is processed in order with `create()`, so the
	 * same rules apply (idempotency by SKU, family creation on the first one
	 * and `add_variant` on the following ones via `family_ref`). An item that
	 * fails does not stop the others: each one returns its own result, in the
	 * same order they arrived.
	 *
	 * Term counting (categories/attributes) is deferred until the end:
	 * without that WooCommerce recalculates it on every save.
	 *
	 * @param array $items List of POST /products bodies.
	 *
	 * @return array|WP_Error {"results": array[]}
	 */
	public function create_batch( $items ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error(
				'nube360_wc_empty_batch',
				__( 'The batch has no items.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $items ) > self::MAX_BATCH ) {
			return new WP_Error(
				'nube360_wc_batch_too_large',
				/* translators: %d: maximum number of items per batch */
				sprintf( __( 'The batch exceeds the maximum of %d items.', 'nube360-for-woocommerce' ), self::MAX_BATCH ),
				array( 'status' => 400 )
			);
		}

		wc_set_time_limit( 300 );
		wp_defer_term_counting( true );

		$results = array();
		try {
			foreach ( $items as $item ) {
				$result = is_array( $item ) ? $this->create( $item ) : $this->invalid_item_error();
				if ( is_wp_error( $result ) ) {
					$result = array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}
				$results[] = $result;
			}
		} finally {
			wp_defer_term_counting( false );
		}

		return array( 'results' => $results );
	}

	/**
	 * Id of the parent product that already has a Nube360 family reference,
	 * or 0 if it does not exist yet.
	 *
	 * @param string $family_ref Family reference.
	 *
	 * @return int
	 */
	private function find_family_by_ref( $family_ref ) {
		if ( isset( self::$families_by_ref[ $family_ref ] ) ) {
			return self::$families_by_ref[ $family_ref ];
		}

		$ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::META_FAMILY_REF, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $family_ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$id = $ids ? (int) $ids[0] : 0;
		if ( $id ) {
			self::$families_by_ref[ $family_ref ] = $id;
		}

		return $id;
	}

	/**
	 * Creates a WC_Product_Simple.
	 *
	 * @param string $title        Product title.
	 * @param array  $variant      Variant data (sku, price, stock).
	 * @param int[]  $category_ids Category ids to assign.
	 * @param array  $images       Images to sideload.
	 * @param string $description  Product description (HTML allowed).
	 *
	 * @return array|WP_Error
	 */
	private function create_simple_product( $title, $variant, $category_ids, $images, $description = '' ) {
		$product = new WC_Product_Simple();
		$product->set_name( $title );
		$product->set_sku( sanitize_text_field( $variant['sku'] ) );
		$product->set_status( 'publish' );
		if ( '' !== $description ) {
			$product->set_description( $description );
		}

		$this->apply_price_and_stock( $product, $variant );

		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}

		Webhooks::suppress();
		try {
			$product_id = $product->save();
		} catch ( Exception $e ) {
			return $this->save_error( $e );
		} finally {
			Webhooks::resume();
		}

		Images::schedule( $product_id, $images );

		return array(
			'success'    => true,
			'id'         => (string) $product_id,
			'variant_id' => (string) $product_id,
		);
	}

	/**
	 * Creates a WC_Product_Variable with its first variation.
	 *
	 * @param string $title             Parent product title.
	 * @param array  $family_attributes Names of the attributes that define the family.
	 * @param array  $variant           Data of the initial variant.
	 * @param int[]  $category_ids      Category ids to assign.
	 * @param array  $images            Images to sideload.
	 * @param string $description       Family description (HTML allowed).
	 * @param string $family_ref        Nube360 family reference (optional).
	 *
	 * @return array|WP_Error
	 */
	private function create_variable_product( $title, $family_attributes, $variant, $category_ids, $images, $description = '', $family_ref = '' ) {
		$resolved = $this->attributes->resolve( $this->extract_values( $variant ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$wc_attributes = array();

		foreach ( $family_attributes as $attr_name ) {
			$taxonomy = $this->attributes->ensure_attribute( $attr_name );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$options = array();

			if ( isset( $resolved[ $attr_name ] ) ) {
				$options[] = $resolved[ $attr_name ]['term']->term_id;
			}

			$wc_attr = new WC_Product_Attribute();
			$wc_attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
			$wc_attr->set_name( $taxonomy );
			$wc_attr->set_options( $options );
			$wc_attr->set_position( count( $wc_attributes ) );
			$wc_attr->set_visible( true );
			$wc_attr->set_variation( true );
			$wc_attributes[] = $wc_attr;
		}

		$product = new WC_Product_Variable();
		$product->set_name( $title );
		$product->set_status( 'publish' );
		if ( '' !== $description ) {
			$product->set_description( $description );
		}
		if ( '' !== $family_ref ) {
			$product->update_meta_data( self::META_FAMILY_REF, $family_ref );
		}

		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}

		$product->set_attributes( $wc_attributes );

		Webhooks::suppress();
		try {
			$product_id = $product->save();
		} catch ( Exception $e ) {
			return $this->save_error( $e );
		} finally {
			Webhooks::resume();
		}

		if ( '' !== $family_ref ) {
			self::$families_by_ref[ $family_ref ] = $product_id;
		}

		Images::schedule( $product_id, $images );

		$variation_id = $this->create_variation( $product_id, $variant, $resolved );
		if ( is_wp_error( $variation_id ) ) {
			return $variation_id;
		}

		return array(
			'success'    => true,
			'id'         => (string) $product_id,
			'variant_id' => (string) $variation_id,
		);
	}

	/**
	 * Adds a new variant to an existing family (WC_Product_Variable).
	 *
	 * @param int   $parent_id Parent product id.
	 * @param array $variant   Data of the new variant.
	 *
	 * @return array|WP_Error
	 */
	private function add_variant( $parent_id, $variant ) {
		$parent = wc_get_product( $parent_id );

		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return new WP_Error(
				'nube360_wc_invalid_family',
				__( 'The given product does not exist or is not a family with variants.', 'nube360-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		$resolved = $this->attributes->resolve( $this->extract_values( $variant ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$result = $this->ensure_product_attributes( $parent, $resolved );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$variation_id = $this->create_variation( $parent_id, $variant, $resolved );
		if ( is_wp_error( $variation_id ) ) {
			return $variation_id;
		}

		return array(
			'success'    => true,
			'id'         => (string) $parent_id,
			'variant_id' => (string) $variation_id,
		);
	}

	/**
	 * Extracts the {attribute name => {value, group}} map from the variant
	 * body. The group is an empty string when the value has none.
	 *
	 * @param array $variant Variant data.
	 *
	 * @return array
	 */
	private function extract_values( $variant ) {
		$values = array();

		if ( empty( $variant['values'] ) || ! is_array( $variant['values'] ) ) {
			return $values;
		}

		foreach ( $variant['values'] as $pair ) {
			if ( ! isset( $pair['attribute'], $pair['value'] ) ) {
				continue;
			}
			$values[ sanitize_text_field( $pair['attribute'] ) ] = array(
				'value' => sanitize_text_field( $pair['value'] ),
				'group' => isset( $pair['group'] ) ? sanitize_text_field( (string) $pair['group'] ) : '',
			);
		}

		return $values;
	}

	/**
	 * Makes sure the parent product has, among its attributes, each
	 * attribute/term used by a new variant, and saves the parent if it
	 * changed.
	 *
	 * @param WC_Product_Variable $parent   Parent product.
	 * @param array               $resolved {attribute name => {taxonomy, term}} map
	 *                                      (see Attributes::resolve()).
	 *
	 * @return true|WP_Error
	 */
	private function ensure_product_attributes( $parent, $resolved ) {
		// get_attributes() IS indexed by sanitize_title(taxonomy)
		// (WC_Product::set_attributes() reindexes it that way on save), but
		// there is a different and subtler trap: if you mutate the EXISTING
		// WC_Product_Attribute object in place (->set_options(...)) and then
		// pass it back to set_attributes(), WooCommerce compares the "before"
		// array against the "after" one to decide whether `attributes`
		// changed (WC_Data::get_changes(), used by update_attributes() in the
		// data store) — but since it is the SAME already-mutated object on
		// both sides, the comparison detects no difference and save()
		// persists nothing (it does not even throw an error). That is why
		// the first variant came out right (it is created with an attribute
		// array built from scratch) and the following ones did not: a NEW
		// WC_Product_Attribute has to be built for the entry being updated,
		// never mutating the one that was already there.
		$attributes = $parent->get_attributes();
		$changed    = false;

		foreach ( $resolved as $entry ) {
			$taxonomy = $entry['taxonomy'];
			$term_id  = $entry['term']->term_id;

			$existing_attribute = null;
			foreach ( $attributes as $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute && $attribute->get_name() === $taxonomy ) {
					$existing_attribute = $attribute;
					break;
				}
			}

			if ( $existing_attribute ) {
				$options           = $existing_attribute->get_options();
				$missing_option    = ! in_array( $term_id, $options, true );
				$missing_variation = ! $existing_attribute->get_variation();
				if ( $missing_option || $missing_variation ) {
					if ( $missing_option ) {
						$options[] = $term_id;
					}
					$replacement = clone $existing_attribute;
					$replacement->set_options( $options );
					$replacement->set_variation( true );
					$attributes = array_map(
						function ( $a ) use ( $existing_attribute, $replacement ) {
							return $a === $existing_attribute ? $replacement : $a;
						},
						$attributes
					);
					$changed = true;
				}
			} else {
				$wc_attr = new WC_Product_Attribute();
				$wc_attr->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$wc_attr->set_name( $taxonomy );
				$wc_attr->set_options( array( $term_id ) );
				$wc_attr->set_position( count( $attributes ) );
				$wc_attr->set_visible( true );
				$wc_attr->set_variation( true );
				$attributes[] = $wc_attr;
				$changed      = true;
			}
		}

		if ( $changed ) {
			$parent->set_attributes( array_values( $attributes ) );
			Webhooks::suppress();
			try {
				$parent->save();
			} catch ( Exception $e ) {
				return $this->save_error( $e );
			} finally {
				Webhooks::resume();
			}
		}

		return true;
	}

	/**
	 * Creates the WC_Product_Variation child of a variable product.
	 *
	 * @param int   $parent_id           Parent product id.
	 * @param array $variant             Variant data (sku, price, stock).
	 * @param array $resolved    Resolved {attribute name => {taxonomy, term}} map.
	 *
	 * @return int|WP_Error Id of the created variation.
	 */
	private function create_variation( $parent_id, $variant, $resolved ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );

		$this->apply_price_and_stock( $variation, $variant );

		$variation_attributes = array();
		foreach ( $resolved as $entry ) {
			$variation_attributes[ $entry['taxonomy'] ] = $entry['term']->slug;
		}
		$variation->set_attributes( $variation_attributes );

		Webhooks::suppress();
		try {
			return $variation->save();
		} catch ( Exception $e ) {
			return $this->save_error( $e );
		} finally {
			Webhooks::resume();
		}
	}

	/**
	 * Sets sku/price/stock from a variant payload on a WC_Product (simple or
	 * variation) without saving it.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param array      $variant Data.
	 */
	private function apply_price_and_stock( $product, $variant ) {
		if ( ! empty( $variant['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $variant['sku'] ) );
		}

		if ( isset( $variant['price'] ) && '' !== $variant['price'] ) {
			$product->set_regular_price( (string) floatval( $variant['price'] ) );
		}

		if ( isset( $variant['stock'] ) && '' !== $variant['stock'] ) {
			$stock = intval( $variant['stock'] );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		}
	}

	/**
	 * Updates price/stock/title/sku of a simple product or a specific
	 * variation.
	 *
	 * @param int   $id         Product id (family if it is a variant).
	 * @param int   $variant_id Variant id, or equal to $id if it is simple.
	 * @param array $body       Subset of {price, stock, title, description, sku}.
	 *
	 * @return array|WP_Error
	 */
	public function update( $id, $variant_id, $body ) {
		$id         = absint( $id );
		$variant_id = absint( $variant_id );

		$product = $this->resolve_product_or_variant( $id, $variant_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( array_key_exists( 'price', $body ) ) {
			$product->set_regular_price( (string) floatval( $body['price'] ) );
		}

		if ( array_key_exists( 'stock', $body ) ) {
			$stock = intval( $body['stock'] );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		}

		if ( array_key_exists( 'title', $body ) ) {
			$product->set_name( sanitize_text_field( $body['title'] ) );
		}

		if ( array_key_exists( 'description', $body ) ) {
			$product->set_description( wp_kses_post( $body['description'] ) );
		}

		if ( array_key_exists( 'sku', $body ) ) {
			$product->set_sku( sanitize_text_field( $body['sku'] ) );
		}

		// This change originates in Nube360: we suppress the outgoing webhook
		// for this specific operation to avoid the Nube360 -> plugin ->
		// Nube360 loop.
		Webhooks::suppress();
		try {
			$product->save();
		} catch ( Exception $e ) {
			return $this->save_error( $e );
		} finally {
			Webhooks::resume();
		}

		return array( 'success' => true );
	}

	/**
	 * Updates the title and/or description of the product itself: a simple
	 * product, or the parent of a family (where the texts of all its
	 * variations live). It does not accept the id of a variation.
	 *
	 * @param int   $id   Product id (or the family's parent id).
	 * @param array $body Subset of {title, description, images}.
	 *
	 * @return array|WP_Error
	 */
	public function update_texts( $id, $body ) {
		$product = wc_get_product( absint( $id ) );

		if ( ! $product || 'variation' === $product->get_type() ) {
			return $this->not_found_error();
		}

		if ( array_key_exists( 'title', $body ) ) {
			$product->set_name( sanitize_text_field( $body['title'] ) );
		}

		if ( array_key_exists( 'description', $body ) ) {
			$product->set_description( wp_kses_post( $body['description'] ) );
		}

		try {
			$product->save();
		} catch ( Exception $e ) {
			return $this->save_error( $e );
		}

		// The gallery is product (or family) data, like the texts. A list,
		// even an empty one, replaces the images the product has.
		if ( array_key_exists( 'images', $body ) && is_array( $body['images'] ) ) {
			Images::replace( $product->get_id(), $body['images'] );
		}

		return array( 'success' => true );
	}

	/**
	 * Deletes a variation, or an entire simple product.
	 *
	 * @param int $id         Product id (family if it is a variant).
	 * @param int $variant_id Variant id, or equal to $id if it is simple.
	 *
	 * @return array|WP_Error
	 */
	public function delete( $id, $variant_id ) {
		$id         = absint( $id );
		$variant_id = absint( $variant_id );

		$product = $this->resolve_product_or_variant( $id, $variant_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		Webhooks::suppress();
		$deleted = $product->delete( true );
		Webhooks::resume();

		if ( ! $deleted ) {
			return new WP_Error(
				'nube360_wc_delete_error',
				__( 'The given product/variant could not be deleted.', 'nube360-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Resolves the WC_Product (simple or variation) a PUT/DELETE applies to,
	 * validating that the variant belongs to the given product.
	 *
	 * @param int $id         Product id.
	 * @param int $variant_id Variant id.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function resolve_product_or_variant( $id, $variant_id ) {
		$is_simple = ( $id === $variant_id );
		$product   = wc_get_product( $is_simple ? $id : $variant_id );

		if ( ! $product ) {
			return $this->not_found_error();
		}

		if ( ! $is_simple && ( 'variation' !== $product->get_type() || (int) $product->get_parent_id() !== $id ) ) {
			return $this->not_found_error();
		}

		return $product;
	}

	/**
	 * Shapes a product/variation as an item of the REST contract.
	 *
	 * @param WC_Product                $product   Product (the parent, if there is a variation).
	 * @param WC_Product_Variation|null $variation Specific variation, or null if it is simple.
	 *
	 * @return array
	 */
	private function format_item( $product, $variation ) {
		$price_object = $variation ? $variation : $product;

		$price      = $price_object->get_regular_price();
		$sale_price = null;
		if ( '' !== (string) $price_object->get_sale_price() ) {
			$sale_price = (float) $price_object->get_sale_price();
		}

		$stock = $price_object->get_stock_quantity();

		$category_ids = wc_get_product_term_ids( $product->get_id(), 'product_cat' );

		if ( $variation ) {
			$attributes   = $this->resolve_variation_attributes( $variation, $product );
			$title_values = wp_list_pluck( $attributes, 'value' );
			$title        = $product->get_name() . ( $title_values ? ' - ' . implode( ' / ', $title_values ) : '' );
			$sku          = $variation->get_sku();
			$images       = $this->get_images( $variation );
			if ( empty( $images ) ) {
				$images = $this->get_images( $product );
			}

			return array(
				'id'                 => (string) $product->get_id(),
				'variant_id'         => (string) $variation->get_id(),
				'title'              => $title,
				'sku'                => $sku ? $sku : null,
				'code'               => null,
				'price'              => null !== $price && '' !== $price ? (float) $price : 0.0,
				'sale_price'         => $sale_price,
				'stock'              => null === $stock ? 0 : (int) $stock,
				'categories'         => array_map( 'strval', $category_ids ),
				'brands'             => $this->brands->ids_for_product( $product->get_id() ),
				'attributes'         => $attributes,
				'images'             => $images,
				'is_family'          => true,
				'family_title'       => $product->get_name(),
				// The name/description of a family live on the parent; the
				// variation has no text of its own (its "title" above is
				// built from name + attributes and is not stored in
				// WooCommerce).
				'description'        => null,
				'family_description' => $product->get_description(),
				// The gallery of a family lives on the parent, like the texts;
				// `images` above may be the one of the variation.
				'family_images'      => $this->get_images( $product ),
			);
		}

		$sku = $product->get_sku();

		return array(
			'id'           => (string) $product->get_id(),
			'variant_id'   => (string) $product->get_id(),
			'title'        => $product->get_name(),
			'sku'          => $sku ? $sku : null,
			'code'         => null,
			'price'        => null !== $price && '' !== $price ? (float) $price : 0.0,
			'sale_price'   => $sale_price,
			'stock'        => null === $stock ? 0 : (int) $stock,
			'categories'   => array_map( 'strval', $category_ids ),
			'brands'       => $this->brands->ids_for_product( $product->get_id() ),
			'attributes'   => array(),
			'images'       => $this->get_images( $product ),
			'is_family'    => false,
			'family_title' => null,
			'description'  => $product->get_description(),
		);
	}

	/**
	 * Resolves the {attribute, value} pairs of a specific variation,
	 * translating pa_* taxonomy slugs into readable names/labels.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @param WC_Product           $parent    Parent product.
	 *
	 * @return array[]
	 */
	private function resolve_variation_attributes( $variation, $parent ) {
		$result = array();

		foreach ( $variation->get_attributes() as $key => $value ) {
			if ( '' === $value ) {
				continue; // "Any" - does not apply to a specific variant.
			}

			if ( taxonomy_exists( $key ) ) {
				$label          = wc_attribute_label( $key );
				$term           = get_term_by( 'slug', $value, $key );
				$readable_value = $term && ! is_wp_error( $term ) ? $term->name : $value;
				$group          = $term && ! is_wp_error( $term ) ? $this->attributes->term_group_name( $term->term_id ) : '';
			} else {
				$label          = $key;
				$readable_value = $value;
				$group          = '';
				foreach ( $parent->get_attributes() as $attr ) {
					if ( ! $attr->is_taxonomy() && sanitize_title( $attr->get_name() ) === $key ) {
						$label = $attr->get_name();
						break;
					}
				}
			}

			$pair = array(
				'attribute' => $label,
				'value'     => $readable_value,
			);
			if ( '' !== $group ) {
				$pair['group'] = $group;
			}
			$result[] = $pair;
		}

		return $result;
	}

	/**
	 * Returns the image URLs (featured + gallery) of a product or variation.
	 *
	 * @param WC_Product $product Product or variation.
	 *
	 * @return string[]
	 */
	private function get_images( $product ) {
		$ids = array();

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$ids[] = $image_id;
		}

		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			$ids = array_merge( $ids, $product->get_gallery_image_ids() );
		}

		$urls = array();
		foreach ( array_unique( $ids ) as $id ) {
			$url = Images::origin_url( $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Standard "not found" WP_Error (404).
	 *
	 * @return WP_Error
	 */
	private function not_found_error() {
		return new WP_Error(
			'nube360_wc_not_found',
			__( 'The given product or variant does not exist.', 'nube360-for-woocommerce' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * WP_Error for a batch item that is not an object (400).
	 *
	 * @return WP_Error
	 */
	private function invalid_item_error() {
		return new WP_Error(
			'nube360_wc_invalid_item',
			__( 'The batch item is not valid.', 'nube360-for-woocommerce' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Ids {id, variant_id} of what already exists with a given SKU, in the
	 * same format a successful creation returns, or null if nothing has that SKU.
	 *
	 * @param string $sku SKU to look for.
	 *
	 * @return array|null
	 */
	private function ids_by_sku( $sku ) {
		$product_id = wc_get_product_id_by_sku( sanitize_text_field( $sku ) );
		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$id = 'variation' === $product->get_type() ? $product->get_parent_id() : $product->get_id();

		return array(
			'success'    => true,
			'id'         => (string) $id,
			'variant_id' => (string) $product_id,
			'existing'   => true,
		);
	}

	/**
	 * Standard save-error WP_Error (500), wrapping the WooCommerce exception.
	 *
	 * @param Exception $e Original exception.
	 *
	 * @return WP_Error
	 */
	private function save_error( $e ) {
		return new WP_Error(
			'nube360_wc_save_error',
			$e->getMessage(),
			array( 'status' => 500 )
		);
	}
}
