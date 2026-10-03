<?php
/**
 * Swatches: the "colour or image" a value or a group is drawn with.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `nube360_swatch` attribute type and the admin fields that set the
 * colour and the image of a group and of each value of a swatch attribute.
 *
 * The colour and the image live in the term meta `color` (hex) and `image_id`
 * (attachment id) of the group or the value. When both are set the image wins.
 * Nube360 does not know about them: they are never read from or written by
 * the REST API.
 */
class Swatches {

	/**
	 * Attribute type (column `attribute_type` of WooCommerce attributes).
	 *
	 * @var string
	 */
	const TYPE = 'nube360_swatch';

	/**
	 * Nonce action of the term forms.
	 *
	 * @var string
	 */
	const NONCE = 'nube360_wc_swatch';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'product_attributes_type_selector', array( $this, 'add_type' ) );
		add_action( 'admin_init', array( $this, 'register_term_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the swatch type to the attribute type selector of WooCommerce.
	 *
	 * @param array $types {type => label}.
	 *
	 * @return array
	 */
	public function add_type( $types ) {
		$types[ self::TYPE ] = __( 'Swatch (colour or image)', 'nube360-for-woocommerce' );

		return $types;
	}

	/**
	 * Whether an attribute taxonomy was made a swatch attribute.
	 *
	 * @param string $taxonomy Attribute taxonomy (e.g. "pa_color").
	 *
	 * @return bool
	 */
	public static function is_swatch_attribute( $taxonomy ) {
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( wc_attribute_taxonomy_name( $attribute->attribute_name ) === $taxonomy ) {
				return self::TYPE === $attribute->attribute_type;
			}
		}

		return false;
	}

	/**
	 * What to draw for a group or a value.
	 *
	 * @param int $term_id Term id.
	 *
	 * @return array {color: hex or '', image_id: int, image_url: string or ''}
	 */
	public static function get( $term_id ) {
		$image_id = (int) get_term_meta( $term_id, AttributeGroups::META_IMAGE, true );
		$url      = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

		return array(
			'color'     => (string) get_term_meta( $term_id, AttributeGroups::META_COLOR, true ),
			'image_id'  => $url ? $image_id : 0,
			'image_url' => $url ? $url : '',
		);
	}

	/**
	 * Hooks the colour/image fields on the groups and on every swatch attribute.
	 */
	public function register_term_fields() {
		$taxonomies = array( AttributeGroups::TAXONOMY );

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( self::TYPE === $attribute->attribute_type ) {
				$taxonomies[] = wc_attribute_taxonomy_name( $attribute->attribute_name );
			}
		}

		foreach ( $taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_fields' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save_fields' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_fields' ) );
		}
	}

	/**
	 * Fields of the "add term" form.
	 */
	public function render_add_fields() {
		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<div class="form-field nube360-wc-swatch-field">
			<label for="nube360-wc-color"><?php esc_html_e( 'Swatch colour', 'nube360-for-woocommerce' ); ?></label>
			<?php $this->render_color_input( '' ); ?>
		</div>
		<div class="form-field nube360-wc-swatch-field">
			<label><?php esc_html_e( 'Swatch image', 'nube360-for-woocommerce' ); ?></label>
			<?php $this->render_image_input( 0, '' ); ?>
			<p><?php esc_html_e( 'When there is an image it is shown instead of the colour.', 'nube360-for-woocommerce' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Fields of the "edit term" form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_edit_fields( $term ) {
		$swatch = self::get( $term->term_id );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<tr class="form-field nube360-wc-swatch-field">
			<th scope="row"><label for="nube360-wc-color"><?php esc_html_e( 'Swatch colour', 'nube360-for-woocommerce' ); ?></label></th>
			<td><?php $this->render_color_input( $swatch['color'] ); ?></td>
		</tr>
		<tr class="form-field nube360-wc-swatch-field">
			<th scope="row"><label><?php esc_html_e( 'Swatch image', 'nube360-for-woocommerce' ); ?></label></th>
			<td>
				<?php $this->render_image_input( $swatch['image_id'], $swatch['image_url'] ); ?>
				<p class="description"><?php esc_html_e( 'When there is an image it is shown instead of the colour.', 'nube360-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Colour field.
	 *
	 * @param string $color Current hex colour.
	 */
	private function render_color_input( $color ) {
		printf(
			'<input type="text" id="nube360-wc-color" name="nube360_wc_color" class="nube360-wc-color-picker" value="%s" />',
			esc_attr( $color )
		);
	}

	/**
	 * Image field: preview, id and the buttons the script wires up.
	 *
	 * @param int    $image_id  Attachment id, 0 for none.
	 * @param string $image_url Preview URL.
	 */
	private function render_image_input( $image_id, $image_url ) {
		?>
		<div class="nube360-wc-image-field">
			<span class="nube360-wc-image-preview"><?php echo $image_url ? '<img src="' . esc_url( $image_url ) . '" alt="" width="60" height="60" />' : ''; ?></span>
			<input type="hidden" name="nube360_wc_image_id" class="nube360-wc-image-id" value="<?php echo esc_attr( $image_id ); ?>" />
			<button type="button" class="button nube360-wc-image-select"><?php esc_html_e( 'Select image', 'nube360-for-woocommerce' ); ?></button>
			<button type="button" class="button nube360-wc-image-remove"<?php echo $image_id ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Remove image', 'nube360-for-woocommerce' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Saves the colour and the image of a term.
	 *
	 * @param int $term_id Term id.
	 */
	public function save_fields( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$color = isset( $_POST['nube360_wc_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['nube360_wc_color'] ) ) : '';
		if ( $color ) {
			update_term_meta( $term_id, AttributeGroups::META_COLOR, $color );
		} else {
			delete_term_meta( $term_id, AttributeGroups::META_COLOR );
		}

		$image_id = isset( $_POST['nube360_wc_image_id'] ) ? absint( $_POST['nube360_wc_image_id'] ) : 0;
		if ( $image_id && wp_attachment_is_image( $image_id ) ) {
			update_term_meta( $term_id, AttributeGroups::META_IMAGE, $image_id );
		} else {
			delete_term_meta( $term_id, AttributeGroups::META_IMAGE );
		}
	}

	/**
	 * Loads the colour picker and the media library on the term screens that
	 * have the fields.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( AttributeGroups::TAXONOMY !== $taxonomy && ! self::is_swatch_attribute( $taxonomy ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'nube360-for-woocommerce-swatch-admin', URL . 'assets/swatch-admin.js', array( 'jquery', 'wp-color-picker' ), VERSION, true );
		wp_localize_script(
			'nube360-for-woocommerce-swatch-admin',
			'nube360WcSwatch',
			array( 'chooseImage' => __( 'Select image', 'nube360-for-woocommerce' ) )
		);
	}
}
