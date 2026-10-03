<?php
/**
 * Global attributes (pa_* taxonomies), their values and the groups of values.
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
 * Creating attributes and values from what Nube360 sends, and exporting them.
 *
 * Nube360 identifies a value by attribute + group + title: the same title
 * ("2") can exist in two groups ("Babies" and "Juvenile"). WooCommerce
 * variations reference the value by term slug, so each (title, group) pair
 * is a different term: the ungrouped one keeps the plain slug (what the
 * plugin has always created) and the grouped ones get `title-group`.
 */
class Attributes {

	/**
	 * Makes sure the global attribute (pa_{slug} taxonomy) exists for an
	 * attribute name, creating it in WooCommerce if needed, and leaves it
	 * registered as a taxonomy so it can be used within the same request.
	 *
	 * @param string $name Visible attribute name (e.g. "Color").
	 *
	 * @return string|WP_Error Taxonomy name (e.g. "pa_color").
	 */
	public function ensure_attribute( $name ) {
		$slug     = wc_sanitize_taxonomy_name( $name );
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}

		$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );

		if ( ! $attribute_id ) {
			$attribute_id = wc_create_attribute(
				array(
					'name'         => $name,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);

			if ( is_wp_error( $attribute_id ) ) {
				return $attribute_id;
			}
		}

		// WooCommerce only registers the taxonomy on the next request (init
		// hook); we register it now so it can be used right away.
		register_taxonomy(
			$taxonomy,
			apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
			apply_filters(
				'woocommerce_taxonomy_args_' . $taxonomy, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
				array(
					'labels'       => array( 'name' => $name ),
					'hierarchical' => true,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			)
		);

		delete_transient( 'wc_attribute_taxonomies' );

		return $taxonomy;
	}

	/**
	 * Makes sure a value exists within an attribute taxonomy, creating it if
	 * needed, and that it belongs to the given group.
	 *
	 * With a group, a value with that title and no group at all is adopted
	 * (it gets the group) before creating a new one: that is how a value that
	 * was synced before groups existed, or whose group was edited in Nube360,
	 * keeps being the same term.
	 *
	 * @param string $taxonomy Taxonomy (e.g. "pa_color").
	 * @param string $value    Value title (e.g. "Red").
	 * @param string $group    Group name; empty for no group.
	 *
	 * @return WP_Term|WP_Error
	 */
	public function ensure_term( $taxonomy, $value, $group = '' ) {
		$group    = trim( (string) $group );
		$group_id = 0;

		if ( '' !== $group ) {
			$group_id = $this->ensure_group( $taxonomy, $group );
			if ( is_wp_error( $group_id ) ) {
				return $group_id;
			}
		}

		$candidates = $this->terms_named( $taxonomy, $value );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $candidate ) {
			if ( $this->term_group_id( $candidate->term_id ) === $group_id ) {
				return $candidate;
			}
		}

		if ( $group_id ) {
			foreach ( $candidates as $candidate ) {
				if ( 0 === $this->term_group_id( $candidate->term_id ) ) {
					update_term_meta( $candidate->term_id, AttributeGroups::META_TERM_GROUP, $group_id );
					return $candidate;
				}
			}
		}

		$result = wp_insert_term( $value, $taxonomy, array( 'slug' => self::slug_for( $taxonomy, $value, $group ) ) );

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() && $result->get_error_data() ) {
				return get_term( (int) $result->get_error_data(), $taxonomy );
			}
			return $result;
		}

		if ( $group_id ) {
			update_term_meta( $result['term_id'], AttributeGroups::META_TERM_GROUP, $group_id );
		}

		return get_term( (int) $result['term_id'], $taxonomy );
	}

	/**
	 * Slug a value gets in an attribute taxonomy.
	 *
	 * Variations reference a value by its slug, and the same title can exist
	 * in several groups, so the slug only has to be unique: it is the plain
	 * slug of the title ("2") for the first value that asks for it; when it
	 * is taken, the title with the group ("2-babies"); and only if that is
	 * taken too, a number. A value is identified by title + group, never by
	 * its slug, so which one got the short slug does not matter.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param string $value    Value title.
	 * @param string $group    Group name; empty for no group.
	 * @param int    $own_id   Term that is being renamed, which may already have the slug.
	 *
	 * @return string
	 */
	public static function slug_for( $taxonomy, $value, $group = '', $own_id = 0 ) {
		$is_free = function ( $slug ) use ( $taxonomy, $own_id ) {
			$taken = get_term_by( 'slug', $slug, $taxonomy );

			return ! $taken || (int) $taken->term_id === (int) $own_id;
		};

		$slug = sanitize_title( $value );
		if ( $is_free( $slug ) ) {
			return $slug;
		}

		$group = trim( (string) $group );
		if ( '' !== $group ) {
			$slug = sanitize_title( $value . '-' . $group );
			if ( $is_free( $slug ) ) {
				return $slug;
			}
		}

		return wp_unique_term_slug(
			$slug,
			(object) array(
				'taxonomy' => $taxonomy,
				'parent'   => 0,
			)
		);
	}

	/**
	 * Terms of an attribute taxonomy with a given name (several when the same
	 * title exists in more than one group).
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param string $value    Value title.
	 *
	 * @return WP_Term[]|WP_Error
	 */
	private function terms_named( $taxonomy, $value ) {
		return get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name'       => $value,
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Finds the group of an attribute by name (not by slug: renaming a group
	 * in WordPress does not change its slug).
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param string $name     Group name.
	 *
	 * @return int Group term id, 0 when there is none.
	 */
	private function find_group( $taxonomy, $name ) {
		$found = get_terms(
			array(
				'taxonomy'   => AttributeGroups::TAXONOMY,
				'name'       => $name,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => AttributeGroups::META_ATTRIBUTE,
						'value' => $taxonomy,
					),
				),
			)
		);

		return ! is_wp_error( $found ) && ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Makes sure a group exists for an attribute, creating it if needed.
	 *
	 * @param string $taxonomy Attribute taxonomy (e.g. "pa_color").
	 * @param string $name     Group name (e.g. "Blue").
	 *
	 * @return int|WP_Error Group term id.
	 */
	private function ensure_group( $taxonomy, $name ) {
		$found = $this->find_group( $taxonomy, $name );
		if ( $found ) {
			return $found;
		}

		$slug   = wp_unique_term_slug(
			sanitize_title( $taxonomy . '-' . $name ),
			(object) array(
				'taxonomy' => AttributeGroups::TAXONOMY,
				'parent'   => 0,
			)
		);
		$result = wp_insert_term( $name, AttributeGroups::TAXONOMY, array( 'slug' => $slug ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_term_meta( $result['term_id'], AttributeGroups::META_ATTRIBUTE, $taxonomy );

		return (int) $result['term_id'];
	}

	/**
	 * Id of the group a value belongs to.
	 *
	 * @param int $term_id Value term id.
	 *
	 * @return int 0 when the value has no group.
	 */
	public function term_group_id( $term_id ) {
		return (int) get_term_meta( $term_id, AttributeGroups::META_TERM_GROUP, true );
	}

	/**
	 * Name of the group a value belongs to.
	 *
	 * @param int $term_id Value term id.
	 *
	 * @return string Empty when the value has no group.
	 */
	public function term_group_name( $term_id ) {
		$group_id = $this->term_group_id( $term_id );
		if ( ! $group_id ) {
			return '';
		}

		$group = get_term( $group_id, AttributeGroups::TAXONOMY );

		return $group instanceof WP_Term ? wp_specialchars_decode( $group->name, ENT_QUOTES ) : '';
	}

	/**
	 * Resolves the values a variant sends ({attribute name => {value, group}})
	 * into the terms they stand for, creating attributes, groups and values.
	 *
	 * @param array $values_by_attribute {attribute name => {value, group}} map.
	 *
	 * @return array|WP_Error {attribute name => {taxonomy, term}} map.
	 */
	public function resolve( $values_by_attribute ) {
		$resolved = array();

		foreach ( $values_by_attribute as $name => $pair ) {
			$taxonomy = $this->ensure_attribute( $name );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}

			$term = $this->ensure_term( $taxonomy, $pair['value'], $pair['group'] );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$resolved[ $name ] = array(
				'taxonomy' => $taxonomy,
				'term'     => $term,
			);
		}

		return $resolved;
	}

	/**
	 * Lists the global attributes with their values and groups.
	 *
	 * @param string[]|null $only Taxonomies to list; null for all of them.
	 *
	 * @return array List of {name, values: [{value, group}], groups: [{name, color, image}]}.
	 */
	public function list_all( $only = null ) {
		$list = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			if ( ! taxonomy_exists( $taxonomy ) || ( null !== $only && ! in_array( $taxonomy, $only, true ) ) ) {
				continue;
			}

			$values = array();
			$terms  = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$values[] = array(
					'id'    => (string) $term->term_id,
					'value' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'group' => $this->term_group_name( $term->term_id ),
				);
			}

			$list[] = array(
				'name'   => $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name,
				'values' => $values,
				'groups' => $this->list_groups( $taxonomy ),
			);
		}

		return $list;
	}

	/**
	 * One value of an attribute, with its group. This is what Nube360 asks
	 * for when it is told that the group of a value changed.
	 *
	 * @param int $term_id Value term id.
	 *
	 * @return array|WP_Error {id, attribute, value, group}
	 */
	public function get_value( $term_id ) {
		$term = get_term( (int) $term_id );

		if ( ! $term instanceof WP_Term || 0 !== strpos( $term->taxonomy, 'pa_' ) ) {
			return new WP_Error(
				'nube360_wc_not_found',
				__( 'The given attribute value does not exist.', 'nube360-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'id'        => (string) $term->term_id,
			'attribute' => wc_attribute_label( $term->taxonomy ),
			'value'     => wp_specialchars_decode( $term->name, ENT_QUOTES ),
			'group'     => $this->term_group_name( $term->term_id ),
		);
	}

	/**
	 * Groups of an attribute with what the storefront needs to draw them.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 *
	 * @return array List of {name, color, image} (image is a URL or null).
	 */
	private function list_groups( $taxonomy ) {
		$groups = get_terms(
			array(
				'taxonomy'   => AttributeGroups::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => AttributeGroups::META_ATTRIBUTE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $taxonomy, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$list = array();
		foreach ( is_wp_error( $groups ) ? array() : $groups as $group ) {
			$image_id = (int) get_term_meta( $group->term_id, AttributeGroups::META_IMAGE, true );
			$list[]   = array(
				'id'    => (string) $group->term_id,
				'name'  => wp_specialchars_decode( $group->name, ENT_QUOTES ),
				'color' => (string) get_term_meta( $group->term_id, AttributeGroups::META_COLOR, true ),
				'image' => $image_id ? wp_get_attachment_url( $image_id ) : null,
			);
		}

		return $list;
	}

	/**
	 * The term an item of {@see Attributes::sync()} refers to when it is a
	 * value that already exists and has to be moved to another group, not a
	 * new one: by its term id, or by the group it had in Nube360 before.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param array  $item     Item {value, group, id?, previous_group?}.
	 * @param string $value    Value title (sanitized).
	 *
	 * @return WP_Term|null
	 */
	private function find_term_to_move( $taxonomy, $item, $value ) {
		if ( ! empty( $item['id'] ) ) {
			$term = get_term( absint( $item['id'] ), $taxonomy );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		if ( ! isset( $item['previous_group'] ) || ! is_string( $item['previous_group'] ) ) {
			return null;
		}

		$previous = trim( sanitize_text_field( $item['previous_group'] ) );
		$wanted   = '' === $previous ? 0 : $this->find_group( $taxonomy, $previous );
		if ( '' !== $previous && ! $wanted ) {
			return null;
		}

		$candidates = $this->terms_named( $taxonomy, $value );
		foreach ( is_wp_error( $candidates ) ? array() : $candidates as $candidate ) {
			if ( $this->term_group_id( $candidate->term_id ) === $wanted ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Resolves one item of {@see Attributes::sync()}: moves an existing value to
	 * its group, or finds/creates it.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param array  $item     Item {value, group, id?, previous_group?}.
	 * @param string $value    Value title (sanitized).
	 * @param string $group    Group name (sanitized), empty for none.
	 *
	 * @return WP_Term|WP_Error|null Null when the value cannot go to that group
	 *                               because another one with its title is there.
	 */
	private function resolve_item( $taxonomy, $item, $value, $group ) {
		$term = $this->find_term_to_move( $taxonomy, $item, $value );
		if ( ! $term ) {
			return $this->ensure_term( $taxonomy, $value, $group );
		}

		$target = '' === $group ? 0 : $this->ensure_group( $taxonomy, $group );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( $this->term_group_id( $term->term_id ) === $target ) {
			return $term;
		}

		$siblings = $this->terms_named( $taxonomy, $term->name );
		foreach ( is_wp_error( $siblings ) ? array() : $siblings as $sibling ) {
			if ( $sibling->term_id !== $term->term_id && $this->term_group_id( $sibling->term_id ) === $target ) {
				return null;
			}
		}

		if ( $target ) {
			update_term_meta( $term->term_id, AttributeGroups::META_TERM_GROUP, $target );
		} else {
			delete_term_meta( $term->term_id, AttributeGroups::META_TERM_GROUP );
		}

		return $term;
	}

	/**
	 * Creates, finds or moves the values Nube360 sends, with their groups,
	 * without needing a product. Body: [{name, values: [{value, group, id?,
	 * previous_group?}]}].
	 *
	 * An item with the `id` of a term, or with the `previous_group` the value
	 * had in Nube360, is an existing value that changed group: it is moved
	 * (so it stays the same term, with the same slug and the same products)
	 * instead of creating a new one in the new group. A value that cannot be
	 * moved because another one with its title is already in the new group is
	 * left where it is and reported in `conflicts`.
	 *
	 * @param mixed $attributes List of attributes with their values.
	 *
	 * @return array|WP_Error {attributes: what {@see Attributes::list_all()} returns for the
	 *                        attributes touched, conflicts: [{attribute, value, group}]}
	 */
	public function sync( $attributes ) {
		if ( ! is_array( $attributes ) || empty( $attributes ) ) {
			return new WP_Error(
				'nube360_wc_empty_attributes',
				__( 'There are no attributes to synchronize.', 'nube360-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$touched   = array();
		$conflicts = array();

		foreach ( $attributes as $attribute ) {
			$name = isset( $attribute['name'] ) ? sanitize_text_field( (string) $attribute['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			$taxonomy = $this->ensure_attribute( $name );
			if ( is_wp_error( $taxonomy ) ) {
				return $taxonomy;
			}
			$touched[] = $taxonomy;

			foreach ( isset( $attribute['values'] ) && is_array( $attribute['values'] ) ? $attribute['values'] : array() as $item ) {
				$value = isset( $item['value'] ) ? sanitize_text_field( (string) $item['value'] ) : '';
				if ( '' === $value ) {
					continue;
				}

				$group = isset( $item['group'] ) ? trim( sanitize_text_field( (string) $item['group'] ) ) : '';
				$term  = $this->resolve_item( $taxonomy, $item, $value, $group );
				if ( is_wp_error( $term ) ) {
					return $term;
				}
				if ( null === $term ) {
					$conflicts[] = array(
						'attribute' => $name,
						'value'     => $value,
						'group'     => $group,
					);
				}
			}
		}

		return array(
			'attributes' => $this->list_all( $touched ),
			'conflicts'  => $conflicts,
		);
	}
}
