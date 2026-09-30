<?php
/**
 * Plugin Name:       Nube360 for WooCommerce
 * Plugin URI:        https://nube360plus.com
 * Description:       Syncs the catalog (products, variations, attributes, categories, images) between WooCommerce and Nube360, and reports orders and stock/price changes.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Nube360
 * Author URI:        https://nube360plus.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nube360-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants (namespaced: Nube360\WooCommerce\VERSION, etc.).
define( __NAMESPACE__ . '\VERSION', '1.0.0' );
define( __NAMESPACE__ . '\FILE', __FILE__ );
define( __NAMESPACE__ . '\DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\URL', plugin_dir_url( __FILE__ ) );
define( __NAMESPACE__ . '\BASENAME', plugin_basename( __FILE__ ) );
define( __NAMESPACE__ . '\REST_NAMESPACE', 'nube360/v1' );
define( __NAMESPACE__ . '\API_BASE', '/wp-json/nube360/v1' );

/**
 * Autoloader (PSR-4): Nube360\WooCommerce\Products -> includes/Products.php.
 * The file name must match the class name exactly, case included.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

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
	 * plugins_loaded callback: if WooCommerce is active, boots the classes
	 * that depend on it; otherwise, shows the admin notice.
	 */
	public function boot_if_woocommerce() {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_notice' ) );
			return;
		}

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

Plugin::instance();

/**
 * Declares compatibility with WooCommerce's High-Performance Order Storage
 * (custom order tables). The plugin only reads orders through the CRUD API
 * (wc_get_order()) and never touches the posts/postmeta tables directly.
 * It has to be hooked at file level: WooCommerce fires this action before
 * `plugins_loaded`.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', FILE, true );
		}
	}
);

/**
 * Activation hook: nothing to create yet (there are no custom tables). The
 * hook is kept for the rewrite rules flush so the REST routes get registered.
 */
function activate() {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation hook: flushes the rewrite rules. It does not delete options
 * (that is uninstall.php's job, which only runs if the user deletes the plugin).
 */
function deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
