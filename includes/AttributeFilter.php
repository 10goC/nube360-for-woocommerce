<?php
/**
 * Storefront filter by attribute: a swatch palette or a list.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the choices of an attribute as links to the shop. The links use the
 * parameters of WooCommerce's own layered navigation
 * (`filter_{attribute}=a,b&query_type_{attribute}=or`), so the products are
 * filtered, counted and cached by WooCommerce, not by this plugin: a group is
 * only a shortcut that selects all of its values at once.
 *
 * The same renderer backs the shortcode, the widget and the block.
 */
class AttributeFilter {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'nube360_attribute_filter';

	/**
	 * Block name.
	 *
	 * @var string
	 */
	const BLOCK = 'nube360/attribute-filter';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_style' ) );
	}

	/**
	 * Registers the widget.
	 */
	public function register_widget() {
		register_widget( AttributeFilterWidget::class );
	}

	/**
	 * Registers the stylesheet (it is only enqueued when a filter is drawn).
	 */
	public function register_style() {
		wp_register_style( 'nube360-for-woocommerce-filter', URL . 'assets/filter.css', array(), VERSION );
	}

	/**
	 * Registers the block (server-side rendered).
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'nube360-for-woocommerce-filter-block',
			URL . 'assets/filter-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			VERSION,
			true
		);
		wp_localize_script(
			'nube360-for-woocommerce-filter-block',
			'nube360WcFilterBlock',
			array(
				'attributes' => $this->attribute_choices(),
				'strings'    => array(
					'title'     => __( 'Filter by attribute', 'nube360-for-woocommerce' ),
					'attribute' => __( 'Attribute', 'nube360-for-woocommerce' ),
					'groupBy'   => __( 'Choices', 'nube360-for-woocommerce' ),
					'display'   => __( 'Display', 'nube360-for-woocommerce' ),
					'byTerm'    => __( 'Each value', 'nube360-for-woocommerce' ),
					'byGroup'   => __( 'Each group of values', 'nube360-for-woocommerce' ),
					'auto'      => __( 'Automatic', 'nube360-for-woocommerce' ),
					'swatch'    => __( 'Swatches', 'nube360-for-woocommerce' ),
					'list'      => __( 'List', 'nube360-for-woocommerce' ),
					'pick'      => __( 'Choose an attribute in the block settings.', 'nube360-for-woocommerce' ),
				),
			)
		);

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Filter by attribute', 'nube360-for-woocommerce' ),
				'editor_script'   => 'nube360-for-woocommerce-filter-block',
				'attributes'      => array(
					'attribute' => array(
						'type'    => 'string',
						'default' => '',
					),
					'groupBy'   => array(
						'type'    => 'string',
						'default' => 'term',
					),
					'display'   => array(
						'type'    => 'string',
						'default' => 'auto',
					),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Attributes the store has, as {slug, label} for the pickers.
	 *
	 * @return array
	 */
	public function attribute_choices() {
		$choices = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$choices[] = array(
				'value' => $attribute->attribute_name,
				'label' => $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name,
			);
		}

		return $choices;
	}

	/**
	 * Shortcode `[nube360_attribute_filter attribute="color" group_by="group" display="swatch"]`.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'attribute' => '',
				'group_by'  => 'term',
				'display'   => 'auto',
			),
			$atts,
			self::SHORTCODE
		);

		return $this->render( $atts['attribute'], $atts['group_by'], $atts['display'] );
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string
	 */
	public function render_block( $attributes ) {
		return $this->render(
			isset( $attributes['attribute'] ) ? $attributes['attribute'] : '',
			isset( $attributes['groupBy'] ) ? $attributes['groupBy'] : 'term',
			isset( $attributes['display'] ) ? $attributes['display'] : 'auto'
		);
	}

	/**
	 * Draws the filter.
	 *
	 * @param string $attribute Attribute slug or name ("color", "Color").
	 * @param string $group_by  "term" for one choice per value, "group" for one per group.
	 * @param string $display   "swatch", "list" or "auto" (swatch when the attribute is a swatch attribute).
	 *
	 * @return string HTML; empty when there is nothing to choose from.
	 */
	public function render( $attribute, $group_by = 'term', $display = 'auto' ) {
		$slug     = wc_sanitize_taxonomy_name( $attribute );
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$by_group = 'group' === $group_by;
		$choices  = $by_group ? $this->group_choices( $taxonomy ) : $this->term_choices( $taxonomy );

		if ( empty( $choices ) ) {
			return '';
		}

		if ( 'swatch' !== $display && 'list' !== $display ) {
			$display = Swatches::is_swatch_attribute( $taxonomy ) ? 'swatch' : 'list';
		}

		$selected = $this->selected( $slug );

		wp_enqueue_style( 'nube360-for-woocommerce-filter' );

		$html = sprintf( '<ul class="nube360-wc-filter nube360-wc-filter--%s" data-attribute="%s">', esc_attr( $display ), esc_attr( $slug ) );

		foreach ( $choices as $choice ) {
			$active = ! array_diff( $choice['slugs'], $selected );
			$url    = $this->url( $slug, $active ? array_diff( $selected, $choice['slugs'] ) : array_merge( $selected, $choice['slugs'] ) );

			$html .= sprintf(
				'<li class="nube360-wc-filter__item%s"><a href="%s" rel="nofollow" title="%s"%s>%s</a></li>',
				$active ? ' is-active' : '',
				esc_url( $url ),
				esc_attr( $choice['label'] ),
				$active ? ' aria-current="true"' : '',
				'swatch' === $display ? $this->swatch_html( $choice ) : esc_html( $choice['label'] )
			);
		}

		return $html . '</ul>';
	}

	/**
	 * One choice per value of the attribute.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 *
	 * @return array List of {label, slugs, color, image_url}.
	 */
	private function term_choices( $taxonomy ) {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		);
		if ( 'menu_order' === wc_attribute_orderby( $taxonomy ) ) {
			$args['menu_order'] = 'ASC';
		}

		$terms   = get_terms( $args );
		$choices = array();

		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$choices[] = array(
				'label' => $term->name,
				'slugs' => array( $term->slug ),
			) + Swatches::get( $term->term_id );
		}

		return $choices;
	}

	/**
	 * One choice per group of the attribute. The values without group are
	 * left out, and so are the groups with no value that has products.
	 *
	 * @param string $taxonomy Attribute taxonomy.
	 *
	 * @return array List of {label, slugs, color, image_url}.
	 */
	private function group_choices( $taxonomy ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'meta_key'   => AttributeGroups::META_TERM_GROUP, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$slugs_by_group = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$slugs_by_group[ (int) get_term_meta( $term->term_id, AttributeGroups::META_TERM_GROUP, true ) ][] = $term->slug;
		}

		unset( $slugs_by_group[0] );
		if ( empty( $slugs_by_group ) ) {
			return array();
		}

		$groups = get_terms(
			array(
				'taxonomy'   => AttributeGroups::TAXONOMY,
				'hide_empty' => false,
				'include'    => array_keys( $slugs_by_group ),
			)
		);

		$choices = array();
		foreach ( is_wp_error( $groups ) ? array() : $groups as $group ) {
			$choices[] = array(
				'label' => $group->name,
				'slugs' => $slugs_by_group[ $group->term_id ],
			) + Swatches::get( $group->term_id );
		}

		return $choices;
	}

	/**
	 * HTML of a swatch: its image, else its colour, else the text.
	 *
	 * @param array $choice Choice.
	 *
	 * @return string
	 */
	private function swatch_html( $choice ) {
		$label = '<span class="screen-reader-text">' . esc_html( $choice['label'] ) . '</span>';

		if ( $choice['image_url'] ) {
			return '<span class="nube360-wc-swatch nube360-wc-swatch--image" style="background-image:url(' . esc_url( $choice['image_url'] ) . ')"></span>' . $label;
		}

		if ( $choice['color'] ) {
			return '<span class="nube360-wc-swatch" style="background-color:' . esc_attr( $choice['color'] ) . '"></span>' . $label;
		}

		return '<span class="nube360-wc-swatch nube360-wc-swatch--text">' . esc_html( $choice['label'] ) . '</span>';
	}

	/**
	 * Values selected in the current request (WooCommerce's `filter_*`).
	 *
	 * @param string $slug Attribute slug.
	 *
	 * @return string[]
	 */
	private function selected( $slug ) {
		$key = 'filter_' . $slug;
		if ( empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $_GET[ $key ] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Current URL with the selection of this attribute replaced.
	 *
	 * @param string   $slug     Attribute slug.
	 * @param string[] $selected New selection.
	 *
	 * @return string
	 */
	private function url( $slug, $selected ) {
		$url = remove_query_arg( array( 'paged', 'filter_' . $slug, 'query_type_' . $slug ) );

		if ( empty( $selected ) ) {
			return $url;
		}

		return add_query_arg(
			array(
				'filter_' . $slug     => implode( ',', array_unique( $selected ) ),
				'query_type_' . $slug => 'or',
			),
			$url
		);
	}
}
