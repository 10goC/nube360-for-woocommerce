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
	 * Nonce of the group field of the value forms.
	 *
	 * @var string
	 */
	const NONCE = 'nube360_wc_group_field';

	/**
	 * Attribute term meta: id of the group the value belongs to.
	 *
	 * @var string
	 */
	const META_TERM_GROUP = '_nube360_wc_group_id';

	/**
	 * Ids of the values (terms of the attribute taxonomy) that belong to a group.
	 *
	 * @param int $group_id Group term id.
	 *
	 * @return int[]
	 */
	public static function value_ids( $group_id ) {
		$taxonomy = (string) get_term_meta( $group_id, self::META_ATTRIBUTE, true );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_TERM_GROUP,
						'value' => $group_id,
					),
				),
			)
		);

		return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
	}

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'delete_term', array( $this, 'forget_deleted_group' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'register_columns' ) );
	}

	/**
	 * Admin screens that make the relation visible: the group of each value
	 * in the list of values of an attribute (and a field to change it in the
	 * add/edit form), and the attribute, the number of values and the swatch
	 * of each group in the list of groups.
	 */
	public function register_columns() {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'add_value_columns' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'render_value_column' ), 10, 3 );
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_field' ), 5, 1 );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_field' ), 5, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save_field' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_field' ) );
		}

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( $this, 'add_group_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( $this, 'render_group_column' ), 10, 3 );
	}

	/**
	 * Adds the "Group" column after the name in the list of values.
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public function add_value_columns( $columns ) {
		return $this->insert_after( $columns, 'name', array( 'nube360_wc_group' => __( 'Group', 'nube360-for-woocommerce' ) ) );
	}

	/**
	 * Content of the "Group" column.
	 *
	 * @param string $content     Current content.
	 * @param string $column_name Column.
	 * @param int    $term_id     Value term id.
	 *
	 * @return string
	 */
	public function render_value_column( $content, $column_name, $term_id ) {
		if ( 'nube360_wc_group' !== $column_name ) {
			return $content;
		}

		return esc_html( ( new Attributes() )->term_group_name( $term_id ) );
	}

	/**
	 * Group field of the "add value" form.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 */
	public function render_add_field( $taxonomy ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="form-field">
			<label for="nube360-wc-group"><?php esc_html_e( 'Group', 'nube360-for-woocommerce' ); ?></label>
			<?php $this->render_group_select( $taxonomy, 0 ); ?>
			<p><?php echo esc_html( $this->field_help() ); ?></p>
		</div>
		<?php
	}

	/**
	 * Group field of the "edit value" form.
	 *
	 * @param \WP_Term $term     Value being edited.
	 * @param string   $taxonomy Attribute taxonomy.
	 */
	public function render_edit_field( $term, $taxonomy ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<tr class="form-field">
			<th scope="row"><label for="nube360-wc-group"><?php esc_html_e( 'Group', 'nube360-for-woocommerce' ); ?></label></th>
			<td>
				<?php $this->render_group_select( $taxonomy, ( new Attributes() )->term_group_id( $term->term_id ) ); ?>
				<p class="description"><?php echo esc_html( $this->field_help() ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Help text of the group field.
	 *
	 * @return string
	 */
	private function field_help() {
		return __( 'Groups are created under Products > Attribute groups. Nube360 is notified and updates the group of the value on its side.', 'nube360-for-woocommerce' );
	}

	/**
	 * Select with the groups of an attribute.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 * @param int    $selected Group of the value, 0 for none.
	 */
	private function render_group_select( $taxonomy, $selected ) {
		?>
		<select name="nube360_wc_group_id" id="nube360-wc-group">
			<option value="0"><?php esc_html_e( '— No group —', 'nube360-for-woocommerce' ); ?></option>
			<?php foreach ( $this->groups_of( $taxonomy ) as $group ) : ?>
				<option value="<?php echo esc_attr( $group->term_id ); ?>" <?php selected( (int) $selected, $group->term_id ); ?>><?php echo esc_html( $group->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Groups that belong to an attribute.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 *
	 * @return \WP_Term[]
	 */
	private function groups_of( $taxonomy ) {
		$groups = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_ATTRIBUTE,
						'value' => $taxonomy,
					),
				),
			)
		);

		return is_wp_error( $groups ) ? array() : $groups;
	}

	/**
	 * Saves the group chosen in the add/edit form. A group of another
	 * attribute (or that does not exist) leaves the value without group.
	 *
	 * @param int $term_id Value term id.
	 */
	public function save_field( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$term     = get_term( $term_id );
		$group_id = isset( $_POST['nube360_wc_group_id'] ) ? absint( $_POST['nube360_wc_group_id'] ) : 0;

		if ( $group_id && $term && ! is_wp_error( $term ) && get_term_meta( $group_id, self::META_ATTRIBUTE, true ) === $term->taxonomy ) {
			update_term_meta( $term_id, self::META_TERM_GROUP, $group_id );
		} else {
			delete_term_meta( $term_id, self::META_TERM_GROUP );
		}
	}

	/**
	 * Replaces the product count of the list of groups (meaningless here)
	 * with the attribute, the number of values and the swatch.
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public function add_group_columns( $columns ) {
		unset( $columns['posts'] );

		return $this->insert_after(
			$columns,
			'name',
			array(
				'nube360_wc_attribute' => __( 'Attribute', 'nube360-for-woocommerce' ),
				'nube360_wc_values'    => __( 'Values', 'nube360-for-woocommerce' ),
				'nube360_wc_swatch'    => __( 'Swatch', 'nube360-for-woocommerce' ),
			)
		);
	}

	/**
	 * Content of the columns of the list of groups.
	 *
	 * @param string $content     Current content.
	 * @param string $column_name Column.
	 * @param int    $term_id     Group term id.
	 *
	 * @return string
	 */
	public function render_group_column( $content, $column_name, $term_id ) {
		switch ( $column_name ) {
			case 'nube360_wc_attribute':
				$taxonomy = (string) get_term_meta( $term_id, self::META_ATTRIBUTE, true );

				return esc_html( taxonomy_exists( $taxonomy ) ? wc_attribute_label( $taxonomy ) : $taxonomy );

			case 'nube360_wc_values':
				return (string) count( self::value_ids( $term_id ) );

			case 'nube360_wc_swatch':
				$swatch = Swatches::get( $term_id );
				if ( $swatch['image_url'] ) {
					return '<img src="' . esc_url( $swatch['image_url'] ) . '" alt="" width="28" height="28" style="border-radius:50%;object-fit:cover" />';
				}
				if ( $swatch['color'] ) {
					return '<span style="display:inline-block;width:28px;height:28px;border-radius:50%;border:1px solid #c3c4c7;background:' . esc_attr( $swatch['color'] ) . '"></span>';
				}

				return '&mdash;';
		}

		return $content;
	}

	/**
	 * Inserts columns right after another one.
	 *
	 * @param array  $columns Columns.
	 * @param string $after   Key to insert after (appended at the end if missing).
	 * @param array  $added   Columns to insert.
	 *
	 * @return array
	 */
	private function insert_after( $columns, $after, $added ) {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( $key === $after ) {
				$result = array_merge( $result, $added );
				$added  = array();
			}
		}

		return array_merge( $result, $added );
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
