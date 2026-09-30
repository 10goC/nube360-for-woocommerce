<?php
/**
 * National ID / tax number of the customer: a field in the WooCommerce
 * registration form (also editable from My Account and the user profile in
 * the admin) that lets Nube360 match a store customer with one of its own,
 * which are identified by their tax number.
 *
 * It is stored as user meta with digits only (no dots or dashes); Nube360
 * looks customers up ignoring those separators.
 *
 * @package Nube360\WooCommerce
 */

namespace Nube360\WooCommerce;

use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture, validation and storage of the customer's tax id.
 */
class TaxId {

	/**
	 * User meta key and form field name.
	 */
	const META_KEY = 'nube360_wc_tax_id';

	/**
	 * Hooks into the registration form, My Account and the user profile.
	 */
	public function __construct() {
		// Registration.
		add_action( 'woocommerce_register_form', array( $this, 'render_registration_field' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_registration' ), 10, 1 );

		// My Account > Account details.
		add_action( 'woocommerce_edit_account_form', array( $this, 'render_account_field' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_account' ), 10, 1 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_account' ), 10, 1 );

		// Admin user profile.
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate_profile' ), 10, 3 );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * Keeps only the digits of a tax id.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	public static function normalize( $value ) {
		return preg_replace( '/\D/', '', (string) $value );
	}

	/**
	 * Checks a national ID / tax number: 7 or 8 digits (national ID) or 11
	 * digits with a valid check digit (tax number).
	 *
	 * @param mixed $value Raw or normalized value.
	 *
	 * @return bool
	 */
	public static function is_valid( $value ) {
		$digits = self::normalize( $value );

		if ( preg_match( '/^\d{7,8}$/', $digits ) ) {
			return true;
		}

		if ( ! preg_match( '/^\d{11}$/', $digits ) ) {
			return false;
		}

		$weights = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$sum     = 0;
		foreach ( $weights as $i => $weight ) {
			$sum += (int) $digits[ $i ] * $weight;
		}

		$check = 11 - ( $sum % 11 );
		if ( 11 === $check ) {
			$check = 0;
		}

		// A remainder that would give 10 has no valid check digit.
		return 10 !== $check && (int) $digits[10] === $check;
	}

	/**
	 * Gets the stored tax id of a user.
	 *
	 * @param int $user_id User id.
	 *
	 * @return string Empty when there is none.
	 */
	public static function get_for_user( $user_id ) {
		return (string) get_user_meta( absint( $user_id ), self::META_KEY, true );
	}

	/**
	 * Whether the field is mandatory when registering.
	 *
	 * @return bool
	 */
	private function is_required() {
		/**
		 * Lets the site make the tax id optional in the registration form.
		 *
		 * @param bool $required True by default.
		 */
		return (bool) apply_filters( 'nube360_wc_tax_id_required', true );
	}

	/**
	 * Reads the submitted value (already normalized), or null when the
	 * field was not part of the request.
	 *
	 * @return string|null
	 */
	private function submitted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonces are verified by WooCommerce / WordPress before these hooks fire.
		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return self::normalize( sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) );
	}

	/**
	 * Validates a submitted value.
	 *
	 * @param string $value    Normalized value.
	 * @param bool   $required Whether an empty value is an error.
	 *
	 * @return string|null Error message, null when valid.
	 */
	private function error_for( $value, $required ) {
		if ( '' === $value ) {
			return $required ? __( 'Please enter your national ID / tax number.', 'nube360-for-woocommerce' ) : null;
		}

		return self::is_valid( $value ) ? null : __( 'The national ID / tax number you entered is not valid.', 'nube360-for-woocommerce' );
	}

	/**
	 * Prints the field in the registration form.
	 */
	public function render_registration_field() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : '';
		$this->render_woocommerce_row( $value, $this->is_required(), 'reg_' . self::META_KEY );
	}

	/**
	 * Validates the registration form.
	 *
	 * @param WP_Error $errors Errors collected so far.
	 *
	 * @return WP_Error
	 */
	public function validate_registration( $errors ) {
		$message = $this->error_for( (string) $this->submitted(), $this->is_required() );
		if ( null !== $message ) {
			$errors->add( 'nube360_wc_tax_id_error', $message );
		}

		return $errors;
	}

	/**
	 * Stores the tax id of a newly registered customer.
	 *
	 * @param int $customer_id User id.
	 */
	public function save_registration( $customer_id ) {
		$value = $this->submitted();
		if ( null !== $value && '' !== $value ) {
			update_user_meta( $customer_id, self::META_KEY, $value );
		}
	}

	/**
	 * Prints the field in My Account > Account details.
	 */
	public function render_account_field() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = isset( $_POST[ self::META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_KEY ] ) ) : self::get_for_user( get_current_user_id() );
		$this->render_woocommerce_row( $value, false, 'account_' . self::META_KEY );
	}

	/**
	 * Validates the account details form.
	 *
	 * @param WP_Error $errors Errors collected so far.
	 */
	public function validate_account( $errors ) {
		$value = $this->submitted();
		if ( null === $value ) {
			return;
		}

		$message = $this->error_for( $value, false );
		if ( null !== $message ) {
			$errors->add( 'nube360_wc_tax_id_error', $message );
		}
	}

	/**
	 * Stores the tax id edited from My Account.
	 *
	 * @param int $user_id User id.
	 */
	public function save_account( $user_id ) {
		$this->save_submitted( $user_id );
	}

	/**
	 * Prints the field in the admin user profile.
	 *
	 * @param WP_User $user Edited user.
	 */
	public function render_profile_field( $user ) {
		?>
		<h2><?php esc_html_e( 'Nube360', 'nube360-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="<?php echo esc_attr( self::META_KEY ); ?>"><?php esc_html_e( 'National ID / tax number', 'nube360-for-woocommerce' ); ?></label></th>
				<td>
					<input type="text" name="<?php echo esc_attr( self::META_KEY ); ?>" id="<?php echo esc_attr( self::META_KEY ); ?>" value="<?php echo esc_attr( self::get_for_user( $user->ID ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Used to match this customer with a Nube360 customer.', 'nube360-for-woocommerce' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Validates the admin user profile.
	 *
	 * @param WP_Error $errors Errors collected so far.
	 */
	public function validate_profile( $errors ) {
		$value = $this->submitted();
		if ( null === $value ) {
			return;
		}

		$message = $this->error_for( $value, false );
		if ( null !== $message ) {
			$errors->add( 'nube360_wc_tax_id_error', $message );
		}
	}

	/**
	 * Stores the tax id edited from the admin user profile.
	 *
	 * @param int $user_id User id.
	 */
	public function save_profile( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$this->save_submitted( $user_id );
	}

	/**
	 * Saves (or clears) the submitted value when it is valid.
	 *
	 * @param int $user_id User id.
	 */
	private function save_submitted( $user_id ) {
		$value = $this->submitted();
		if ( null === $value ) {
			return;
		}

		if ( '' === $value ) {
			delete_user_meta( $user_id, self::META_KEY );
		} elseif ( self::is_valid( $value ) ) {
			update_user_meta( $user_id, self::META_KEY, $value );
		}
	}

	/**
	 * Prints a form row with WooCommerce's markup.
	 *
	 * @param string $value    Current value.
	 * @param bool   $required Whether to mark it as required.
	 * @param string $id       Input id.
	 */
	private function render_woocommerce_row( $value, $required, $id ) {
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'National ID / tax number', 'nube360-for-woocommerce' ); ?>
				<?php if ( $required ) : ?>
					<span class="required" aria-hidden="true">*</span>
				<?php endif; ?>
			</label>
			<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="<?php echo esc_attr( self::META_KEY ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" inputmode="numeric" autocomplete="off"<?php echo $required ? ' required aria-required="true"' : ''; ?> />
		</p>
		<?php
	}
}
