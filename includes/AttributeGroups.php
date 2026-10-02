<?php
/**
 * Groups of attribute values (e.g. the "Blue" group of the Color attribute,
 * or the "Babies" group of the Size attribute).
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the taxonomy that holds the groups.
 *
 * A group is a term of {@see AttributeGroups::TAXONOMY} that belongs to one
 * attribute taxonomy (term meta `nube360_wc_attribute`, e.g. "pa_color"). A
 * value (a term of "pa_color") points to its group with the term meta
 * `_nube360_wc_group_id`; a value belongs to at most one group. The group
 * name is the only identity Nube360 knows (it has no group id), so a group is
 * found by attribute + name.
 *
 * The taxonomy is attached to products only so WordPress lists it under
 * Products; nothing is ever assigned to a product with it.
 */
class AttributeGroups {

	/**
	 * Taxonomy of the groups.
	 *
	 * @var string
	 */
	const TAXONOMY = 'nube360_wc_attr_group';

	/**
	 * Group term meta: attribute taxonomy the group belongs to ("pa_color").
	 *
	 * @var string
	 */
	const META_ATTRIBUTE = 'nube360_wc_attribute';

	/**
	 * Group term meta: swatch colour (hex, e.g. "#1e90ff").
	 *
	 * @var string
	 */
	const META_COLOR = 'color';

	/**
	 * Group term meta: attachment id of the swatch image.
	 *
	 * @var string
	 */
	const META_IMAGE = 'image_id';

	/**
	 * Attribute term meta: id of the group the value belongs to.
	 *
	 * @var string
	 */
	const META_TERM_GROUP = '_nube360_wc_group_id';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'delete_term', array( $this, 'forget_deleted_group' ), 10, 3 );
	}

	/**
	 * Registers the groups taxonomy.
	 */
	public function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			'product',
			array(
				'labels'             => array(
					'name'          => __( 'Attribute groups', 'nube360-for-woocommerce' ),
					'singular_name' => __( 'Attribute group', 'nube360-for-woocommerce' ),
					'menu_name'     => __( 'Attribute groups', 'nube360-for-woocommerce' ),
					'all_items'     => __( 'All attribute groups', 'nube360-for-woocommerce' ),
					'add_new_item'  => __( 'Add new attribute group', 'nube360-for-woocommerce' ),
					'edit_item'     => __( 'Edit attribute group', 'nube360-for-woocommerce' ),
					'search_items'  => __( 'Search attribute groups', 'nube360-for-woocommerce' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_admin_column'  => false,
				'meta_box_cb'        => false,
				'rewrite'            => false,
				'query_var'          => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms',
				),
			)
		);
	}

	/**
	 * Deleting a group leaves its values without group instead of pointing at
	 * a term that no longer exists.
	 *
	 * @param int    $term_id  Deleted term id.
	 * @param int    $tt_id    Term taxonomy id (unused).
	 * @param string $taxonomy Taxonomy of the deleted term.
	 */
	public function forget_deleted_group( $term_id, $tt_id, $taxonomy ) {
		if ( self::TAXONOMY === $taxonomy ) {
			delete_metadata( 'term', 0, self::META_TERM_GROUP, (int) $term_id, true );
		}
	}
}
