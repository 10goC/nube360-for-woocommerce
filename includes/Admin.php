<?php
/**
 * "Settings > Nube360" menu: a form with URL + key, a "Test connection"
 * button that triggers the handshake by hand, and the connection status.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings screen.
 */
class Admin {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nube360-for-woocommerce';

	/**
	 * Nonce action of the settings form.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'nube360_wc_save_settings';

	/**
	 * Capability required to view/edit the settings page.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Constructor: hooks the menu, the saving and the admin assets.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'process_form' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the "Settings > Nube360" submenu.
	 */
	public function register_menu() {
		add_options_page(
			__( 'Nube360', 'nube360-for-woocommerce' ),
			__( 'Nube360', 'nube360-for-woocommerce' ),
			current_user_can( self::CAPABILITY ) ? self::CAPABILITY : 'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the settings page CSS/JS, only there.
	 *
	 * @param string $hook Hook of the current admin page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'nube360-for-woocommerce-admin', URL . 'assets/admin.css', array(), VERSION );
		wp_enqueue_script( 'nube360-for-woocommerce-admin', URL . 'assets/admin.js', array( 'jquery' ), VERSION, true );
		wp_localize_script(
			'nube360-for-woocommerce-admin',
			'nube360WcAdmin',
			array(
				'unsavedChanges' => __( 'You have unsaved changes. "Test connection" uses the saved data, not the form values. Save first?', 'nube360-for-woocommerce' ),
			)
		);
	}

	/**
	 * Processes the settings form submit (save, and/or test connection). It
	 * is hooked on admin_init so it can do a clean redirect afterwards
	 * (POST-Redirect-GET pattern).
	 */
	public function process_form() {
		$is_save = isset( $_POST['nube360_wc_save'] );
		$is_test = isset( $_POST['nube360_wc_test'] );

		if ( ! $is_save && ! $is_test ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'nube360_wc_nonce' );

		$url     = get_option( 'nube360_url', '' );
		$api_key = get_option( 'nube360_api_key', '' );

		if ( $is_save ) {
			$url     = isset( $_POST['nube360_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['nube360_url'] ) ) ) : '';
			$api_key = isset( $_POST['nube360_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['nube360_api_key'] ) ) : '';

			update_option( 'nube360_url', untrailingslashit( $url ) );
			update_option( 'nube360_api_key', $api_key );
		}

		$status = 'saved';

		if ( $url && $api_key ) {
			$result = $this->do_handshake();
			$status = $result['success'] ? 'connected' : 'error';
		} else {
			update_option( 'nube360_wc_connected', false );
			$status = 'error';
		}

		$redirect = add_query_arg(
			array(
				'page'         => self::PAGE_SLUG,
				'nube360_wc' => $status,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Triggers the handshake POST {url}/ecommerce/notifications/vincular with
	 * this site's data, and stores whether it ended up connected.
	 *
	 * @return array {success: bool, error: string}
	 */
	private function do_handshake() {
		$client = new HttpClient();

		$result = $client->post(
			'ecommerce/notifications/vincular',
			array(
				'site_url'  => home_url(),
				'site_name' => get_bloginfo( 'name' ),
				'api_base'  => API_BASE,
			)
		);

		$connected = $result['success'] && is_array( $result['data'] ) && ! empty( $result['data']['success'] );

		update_option( 'nube360_wc_connected', $connected );
		update_option( 'nube360_wc_last_handshake', current_time( 'mysql' ) );

		return array(
			'success' => $connected,
			'error'   => $result['error'],
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url        = get_option( 'nube360_url', '' );
		$api_key    = get_option( 'nube360_api_key', '' );
		$connected  = get_option( 'nube360_wc_connected', false );
		$last_check = get_option( 'nube360_wc_last_handshake', '' );
		$images     = Images::status();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url_status = isset( $_GET['nube360_wc'] ) ? sanitize_key( wp_unslash( $_GET['nube360_wc'] ) ) : '';

		?>
		<div class="wrap nube360-wc-settings">
			<h1><?php esc_html_e( 'Nube360 Settings', 'nube360-for-woocommerce' ); ?></h1>

			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'WooCommerce is not active. Nube360 for WooCommerce needs WooCommerce to work.', 'nube360-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'connected' === $url_status ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Connection with Nube360 established successfully.', 'nube360-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( 'error' === $url_status ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'Could not connect to Nube360. Check the URL and the key.', 'nube360-for-woocommerce' ); ?></p>
				</div>
			<?php elseif ( 'saved' === $url_status ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php esc_html_e( 'Settings saved, but the URL and key must be filled in to connect to Nube360.', 'nube360-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="nube360-wc-status">
				<strong><?php esc_html_e( 'Connection status:', 'nube360-for-woocommerce' ); ?></strong>
				<?php if ( $connected ) : ?>
					<span class="nube360-wc-badge nube360-wc-badge--ok"><?php esc_html_e( 'Connected', 'nube360-for-woocommerce' ); ?></span>
					<?php if ( $last_check ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: date and time of the last successful handshake */
								esc_html__( '(last check: %s)', 'nube360-for-woocommerce' ),
								esc_html( $last_check )
							);
							?>
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="nube360-wc-badge nube360-wc-badge--error"><?php esc_html_e( 'Not connected', 'nube360-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( $images ) : ?>
				<p class="nube360-wc-status">
					<strong><?php esc_html_e( 'Background images:', 'nube360-for-woocommerce' ); ?></strong>
					<?php
					printf(
						/* translators: 1: number of pending actions, 2: number of failed actions */
						esc_html__( '%1$d pending, %2$d failed', 'nube360-for-woocommerce' ),
						(int) $images['pending'],
						(int) $images['failed']
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=' . Images::HOOK ) ); ?>">
						<?php esc_html_e( 'View scheduled actions', 'nube360-for-woocommerce' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, 'nube360_wc_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="nube360_url"><?php esc_html_e( 'Nube360 instance URL', 'nube360-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								id="nube360_url"
								name="nube360_url"
								class="regular-text"
								placeholder="https://mydomain.com/nube360/myinstance"
								value="<?php echo esc_attr( $url ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'Base URL of your Nube360 instance, without a trailing slash.', 'nube360-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nube360_api_key"><?php esc_html_e( 'API key', 'nube360-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="nube360_api_key"
								name="nube360_api_key"
								class="regular-text"
								autocomplete="off"
								placeholder="<?php esc_attr_e( '64-character key provided by Nube360', 'nube360-for-woocommerce' ); ?>"
								value="<?php echo esc_attr( $api_key ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'Generate it from the Nube360 dashboard (E-commerce > Integrations).', 'nube360-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="nube360_wc_save" value="1" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'nube360-for-woocommerce' ); ?>
					</button>
					<button type="submit" name="nube360_wc_test" value="1" class="button">
						<?php esc_html_e( 'Test connection', 'nube360-for-woocommerce' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
