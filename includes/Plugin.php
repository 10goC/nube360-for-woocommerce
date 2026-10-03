<?php
/**
 * Plugin bootstrap class.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class. It only boots the classes in includes/; all the logic
 * lives in those classes.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns (creating it if needed) the single plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: use Plugin::instance().
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Hooks the plugin classes into WordPress.
	 *
	 * Important: the "is WooCommerce active?" check CANNOT be done up here,
	 * at file/constructor level. WordPress includes the main file of each
	 * active plugin in the order they appear in the `active_plugins`
	 * option, and only fires `plugins_loaded` once it has included all of
	 * them. If this plugin came before "woocommerce/woocommerce.php" in that
	 * list (it is alphabetical by default, but not guaranteed), the
	 * `WooCommerce` class would not exist yet at this point and the plugin
	 * would deactivate itself even though WooCommerce is active. That is why
	 * everything that depends on WooCommerce is hooked on `plugins_loaded`.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'boot_if_woocommerce' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Boots the classes that depend on WooCommerce, or shows the admin
	 * notice if it is not active (hooked on `plugins_loaded`).
	 */
	public function boot_if_woocommerce() {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

		new AttributeGroups();
		new Swatches();
		new AttributeFilter();
		new Images();
		new TaxId();
		new RestController();
		new Webhooks();

		if ( is_admin() ) {
			new Admin();
		}
	}

	/**
	 * Checks whether WooCommerce is active (class available).
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Renders the admin notice for a missing WooCommerce (hooked from
	 * boot_if_woocommerce() when WooCommerce is not active).
	 */
	public function render_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'Nube360 for WooCommerce requires WooCommerce to be installed and active. The plugin stays inactive until then.', 'nube360-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Loads the plugin text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'nube360-for-woocommerce', false, dirname( BASENAME ) . '/languages' );
	}
}
