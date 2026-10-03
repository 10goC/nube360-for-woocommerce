<?php
/**
 * Widget: filter by attribute.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Widget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic widget of {@see AttributeFilter}. Like WooCommerce's own
 * filters, it only shows on the shop and on the product archives.
 */
class AttributeFilterWidget extends WP_Widget {

	/**
	 * Registers the widget.
	 */
	public function __construct() {
		parent::__construct(
			'nube360_wc_attribute_filter',
			__( 'Nube360: filter by attribute', 'nube360-for-woocommerce' ),
			array(
				'description'                 => __( 'Swatches or a list to filter products by an attribute.', 'nube360-for-woocommerce' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Draws the widget.
	 *
	 * @param array $args     Widget area arguments.
	 * @param array $instance Saved settings.
	 */
	public function widget( $args, $instance ) {
		if ( ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$instance = wp_parse_args(
			$instance,
			array(
				'title'     => '',
				'attribute' => '',
				'group_by'  => 'term',
				'display'   => 'auto',
			)
		);

		$html = ( new AttributeFilter() )->render( $instance['attribute'], $instance['group_by'], $instance['display'] );
		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $instance['title'] ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Sanitizes the settings.
	 *
	 * @param array $new_instance New settings.
	 * @param array $old_instance Previous settings (unused).
	 *
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return array(
			'title'     => sanitize_text_field( isset( $new_instance['title'] ) ? $new_instance['title'] : '' ),
			'attribute' => sanitize_title( isset( $new_instance['attribute'] ) ? $new_instance['attribute'] : '' ),
			'group_by'  => isset( $new_instance['group_by'] ) && 'group' === $new_instance['group_by'] ? 'group' : 'term',
			'display'   => isset( $new_instance['display'] ) && in_array( $new_instance['display'], array( 'swatch', 'list' ), true ) ? $new_instance['display'] : 'auto',
		);
	}

	/**
	 * Settings form.
	 *
	 * @param array $instance Saved settings.
	 */
	public function form( $instance ) {
		$instance = wp_parse_args(
			$instance,
			array(
				'title'     => '',
				'attribute' => '',
				'group_by'  => 'term',
				'display'   => 'auto',
			)
		);
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'nube360-for-woocommerce' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'attribute' ) ); ?>"><?php esc_html_e( 'Attribute:', 'nube360-for-woocommerce' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'attribute' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'attribute' ) ); ?>">
				<?php foreach ( ( new AttributeFilter() )->attribute_choices() as $choice ) : ?>
					<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $instance['attribute'], $choice['value'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'group_by' ) ); ?>"><?php esc_html_e( 'Choices:', 'nube360-for-woocommerce' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'group_by' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'group_by' ) ); ?>">
				<option value="term" <?php selected( $instance['group_by'], 'term' ); ?>><?php esc_html_e( 'Each value', 'nube360-for-woocommerce' ); ?></option>
				<option value="group" <?php selected( $instance['group_by'], 'group' ); ?>><?php esc_html_e( 'Each group of values', 'nube360-for-woocommerce' ); ?></option>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>"><?php esc_html_e( 'Display:', 'nube360-for-woocommerce' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'display' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'display' ) ); ?>">
				<option value="auto" <?php selected( $instance['display'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'nube360-for-woocommerce' ); ?></option>
				<option value="swatch" <?php selected( $instance['display'], 'swatch' ); ?>><?php esc_html_e( 'Swatches', 'nube360-for-woocommerce' ); ?></option>
				<option value="list" <?php selected( $instance['display'], 'list' ); ?>><?php esc_html_e( 'List', 'nube360-for-woocommerce' ); ?></option>
			</select>
		</p>
		<?php
	}
}
