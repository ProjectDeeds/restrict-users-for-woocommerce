<?php
/**
 * Plugin Name: Restrict Users for WooCommerce
 * Description: Restrict selected WooCommerce customers from completing checkout while allowing normal browsing and cart use.
 * Version: 1.0.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Ben Dishler, CBT Hospitality Supplies
 * Author URI: https://bendishler.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: restrict-users-for-woocommerce
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

final class WCRU_Plugin {
	const OPTION = 'wcru_settings';
	const USERS_OPTION = 'wcru_restricted_user_ids';
	const VERSION = '1.0.0';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'block_store_api_checkout' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		if ( false === get_option( self::USERS_OPTION, false ) ) {
			$settings = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
			add_option( self::USERS_OPTION, array_values( array_unique( array_filter( array_map( 'absint', (array) $settings['restricted_user_ids'] ) ) ) ) );
		}
	}

	public static function defaults() {
		return array(
			'enabled'             => false,
			'restricted_user_ids' => array(),
			'login_message'       => 'We truly value having you as a customer, and we are so grateful for your business with CBT Hospitality Supplies! It looks like there is a past-due balance on your account that needs to be taken care of before you can complete this purchase. We want to make this as easy as possible for you, so please give us a call at 843-236-5038 to update your account. We appreciate you and look forward to helping you finish your order!',
			'checkout_message'    => 'Checkout is disabled until your past-due balance is paid in full.',
		);
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );
			return;
		}

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_management_form' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'woocommerce_account_content', array( $this, 'account_notice' ), 1 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'checkout_notice' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'checkout_assets' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_errors' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_payment_gateways' ) );
		add_filter( 'render_block_woocommerce/checkout', array( $this, 'render_restricted_checkout_block' ) );
	}

	public function woocommerce_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Restrict Users for WooCommerce requires WooCommerce to be active.', 'restrict-users-for-woocommerce' ) . '</p></div>';
		}
	}

	private function settings() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	private function restricted_user_ids() {
		$ids = get_option( self::USERS_OPTION, false );
		if ( false === $ids ) {
			$ids = (array) $this->settings()['restricted_user_ids'];
			add_option( self::USERS_OPTION, $ids );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	private function update_restricted_user_ids( $user_ids ) {
		update_option( self::USERS_OPTION, array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) ) );
	}

	private function is_restricted( $user_id = 0 ) {
		$settings = $this->settings();
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		return ! empty( $settings['enabled'] ) && $user_id && in_array( $user_id, $this->restricted_user_ids(), true );
	}

	public function account_notice() {
		if ( $this->is_restricted() ) {
			wc_print_notice( $this->settings()['checkout_message'], 'notice' );
		}
	}

	public function checkout_notice() {
		if ( $this->is_restricted() ) {
			echo '<div class="woocommerce-error wcru-checkout-notice" role="alert">' . esc_html( $this->settings()['login_message'] ) . '</div>';
		}
	}

	public function checkout_assets() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && $this->is_restricted() ) {
			wp_enqueue_script( 'wcru-checkout', plugins_url( 'assets/js/checkout.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
			wp_enqueue_style( 'wcru-checkout', plugins_url( 'assets/css/checkout.css', __FILE__ ), array(), self::VERSION );
		}
	}

	public function render_restricted_checkout_block( $block_content ) {
		if ( $this->is_restricted() && function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			return '<div class="woocommerce-error wcru-checkout-notice" role="alert">' . esc_html( $this->settings()['login_message'] ) . '</div>';
		}
		return $block_content;
	}

	/**
	 * Block a Checkout Block / Store API order attempt before WooCommerce creates
	 * an order or sends a payment request. Classic checkout is blocked separately
	 * through WooCommerce validation below.
	 */
	public function block_store_api_checkout( $result ) {
		if ( is_wp_error( $result ) || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return $result;
		}

		global $wp;
		$route = isset( $wp->query_vars['rest_route'] ) ? sanitize_text_field( $wp->query_vars['rest_route'] ) : '';
		if ( false === strpos( $route, '/wc/store' ) || false === strpos( $route, '/checkout' ) || ! $this->is_restricted() ) {
			return $result;
		}

		return new WP_Error( 'wcru_checkout_disabled', $this->settings()['login_message'], array( 'status' => 403 ) );
	}

	public function validate_checkout_errors( $data, $errors ) {
		if ( $this->is_restricted() ) {
			$errors->add( 'wcru_checkout_disabled', $this->settings()['login_message'] );
		}
	}

	public function remove_payment_gateways( $gateways ) {
		return $this->is_restricted() && is_checkout() ? array() : $gateways;
	}

	public function admin_menu() {
		add_submenu_page( 'woocommerce', __( 'Restrict Users', 'restrict-users-for-woocommerce' ), __( 'Restrict Users', 'restrict-users-for-woocommerce' ), 'manage_options', 'restrict-users-for-woocommerce', array( $this, 'admin_page' ) );
	}

	public function register_settings() {
		register_setting( 'wcru_settings_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function sanitize_settings( $input ) {
		$current = $this->settings();
		return array(
			'enabled'             => isset( $input['enabled'] ) ? ! empty( $input['enabled'] ) : $current['enabled'],
			'restricted_user_ids' => $current['restricted_user_ids'],
			'login_message'       => isset( $input['login_message'] ) ? sanitize_textarea_field( wp_unslash( $input['login_message'] ) ) : $current['login_message'],
			'checkout_message'    => isset( $input['checkout_message'] ) ? sanitize_textarea_field( wp_unslash( $input['checkout_message'] ) ) : $current['checkout_message'],
		);
	}

	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_restrict-users-for-woocommerce' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wcru-admin', plugins_url( 'assets/css/admin.css', __FILE__ ), array(), self::VERSION );
	}

	private function verify_management_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage restricted users.', 'restrict-users-for-woocommerce' ) );
		}
	}

	private function redirect_to_settings( $notice, $notice_type = 'success' ) {
		set_transient(
			'wcru_admin_notice_' . get_current_user_id(),
			array(
				'message' => $notice,
				'type'    => $notice_type,
			),
		MINUTE_IN_SECONDS
		);
		$url = add_query_arg( 'page', 'restrict-users-for-woocommerce', admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Process the restriction-management forms on the current admin screen.
	 * Keeping these submissions on admin.php avoids unreliable admin-post
	 * navigation behavior introduced by some admin page-transition scripts.
	 */
	public function maybe_handle_management_form() {
		if ( ! isset( $_GET['page'], $_POST['wcru_action'] ) || 'restrict-users-for-woocommerce' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wcru_action'] ) );
		if ( ! in_array( $action, array( 'add_users', 'remove_user', 'toggle_restrictions' ), true ) ) {
			return;
		}
		$this->verify_management_permission();
		check_admin_referer( 'wcru_' . $action );

		if ( 'add_users' === $action ) {
			$this->handle_add_users();
		}
		if ( 'remove_user' === $action ) {
			$this->handle_remove_user();
		}
		if ( 'toggle_restrictions' === $action ) {
			$this->handle_toggle_restrictions();
		}
	}

	public function handle_toggle_restrictions() {
		$this->verify_management_permission();
		check_admin_referer( 'wcru_toggle_restrictions' );
		$settings            = $this->settings();
		$settings['enabled'] = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		update_option( self::OPTION, $settings );
		$this->redirect_to_settings( $settings['enabled'] ? __( 'Checkout restrictions enabled.', 'restrict-users-for-woocommerce' ) : __( 'Checkout restrictions paused.', 'restrict-users-for-woocommerce' ) );
	}

	public function handle_add_users() {
		$this->verify_management_permission();
		check_admin_referer( 'wcru_add_users' );
		$raw_ids = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '';
		$ids     = array_filter( array_unique( array_map( 'absint', preg_split( '/[\s,]+/', $raw_ids ) ) ) );
		if ( empty( $ids ) ) {
			$this->redirect_to_settings( __( 'Enter at least one valid user ID.', 'restrict-users-for-woocommerce' ), 'error' );
		}
		$restricted_ids = $this->restricted_user_ids();
		$added = 0;
		$invalid = array();
		foreach ( $ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				$invalid[] = $user_id;
				continue;
			}
			if ( ! in_array( $user_id, $restricted_ids, true ) ) {
				$restricted_ids[] = $user_id;
				++$added;
			}
		}
		$this->update_restricted_user_ids( $restricted_ids );
		/* translators: %d: Number of users added to the restriction list. */
		$message = $added ? sprintf( _n( '%d user added to the restriction list.', '%d users added to the restriction list.', $added, 'restrict-users-for-woocommerce' ), $added ) : __( 'Those users are already in the restriction list.', 'restrict-users-for-woocommerce' );
		if ( ! empty( $invalid ) ) {
			/* translators: %s: Comma-separated list of user IDs that were not found. */
			$message .= ' ' . sprintf( __( 'User IDs not found: %s.', 'restrict-users-for-woocommerce' ), implode( ', ', $invalid ) );
		}
		$this->redirect_to_settings( $message, $invalid ? 'warning' : 'success' );
	}

	public function handle_remove_user() {
		$this->verify_management_permission();
		check_admin_referer( 'wcru_remove_user' );
		$user_id  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$this->update_restricted_user_ids( array_diff( $this->restricted_user_ids(), array( $user_id ) ) );
		$this->redirect_to_settings( __( 'User removed from the restriction list.', 'restrict-users-for-woocommerce' ) );
	}

	private function customer_data( $user ) {
		return array(
			'id'       => (int) $user->ID,
			'company'  => get_user_meta( $user->ID, 'billing_company', true ),
			'username' => $user->user_login,
			'email'    => $user->user_email,
		);
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'restrict-users-for-woocommerce' ) );
		}
		$settings       = $this->settings();
		$restricted_ids  = $this->restricted_user_ids();
		$users           = array();
		foreach ( $restricted_ids as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$users[] = $user;
			}
		}
		$restricted_rows = array();
		foreach ( $users as $user ) {
			$restricted_rows[] = $this->customer_data( $user );
		}

		/* Remove stale IDs so this table always represents active restrictions only. */
		$valid_ids = wp_list_pluck( $restricted_rows, 'id' );
		if ( $restricted_ids !== $valid_ids ) {
			$this->update_restricted_user_ids( $valid_ids );
		}
		?>
<div class="wrap wcru-wrap">
    <div class="wcru-hero"><span class="dashicons dashicons-shield-alt"></span>
        <div>
            <h1><?php esc_html_e( 'Restrict Users for WooCommerce', 'restrict-users-for-woocommerce' ); ?></h1>
            <p><?php esc_html_e( 'Manage customer checkout restrictions with confidence.', 'restrict-users-for-woocommerce' ); ?></p>
        </div>
    </div>
    <?php $notice = get_transient( 'wcru_admin_notice_' . get_current_user_id() ); if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : $notice_type = in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'success'; delete_transient( 'wcru_admin_notice_' . get_current_user_id() ); ?>
    <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
        <p><?php echo esc_html( $notice['message'] ); ?></p>
    </div>
    <?php endif; ?>
    <form class="wcru-toggle-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=restrict-users-for-woocommerce' ) ); ?>"><?php wp_nonce_field( 'wcru_toggle_restrictions' ); ?><input type="hidden" name="wcru_action" value="toggle_restrictions">
        <section class="wcru-card wcru-switch-row">
            <div>
                <h2><?php esc_html_e( 'Checkout restrictions', 'restrict-users-for-woocommerce' ); ?></h2>
                <p><?php esc_html_e( 'Enable or pause restrictions for every listed customer.', 'restrict-users-for-woocommerce' ); ?></p>
            </div><label class="wcru-switch"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" onchange="this.form.submit()" <?php checked( ! empty( $settings['enabled'] ) ); ?>><span class="wcru-slider"></span><span class="screen-reader-text"><?php esc_html_e( 'Enable checkout restrictions', 'restrict-users-for-woocommerce' ); ?></span></label><noscript><button type="submit" class="button"><?php esc_html_e( 'Save toggle', 'restrict-users-for-woocommerce' ); ?></button></noscript>
        </section>
    </form>
    <form action="options.php" method="post">
        <?php settings_fields( 'wcru_settings_group' ); ?>
        <section class="wcru-card">
            <h2><?php esc_html_e( 'Customer Messages', 'restrict-users-for-woocommerce' ); ?></h2><label for="wcru-login-message"><?php esc_html_e( 'Checkout Restriction Message', 'restrict-users-for-woocommerce' ); ?></label><textarea id="wcru-login-message" name="wcru_settings[login_message]" rows="5" class="large-text"><?php echo esc_textarea( $settings['login_message'] ); ?></textarea><label for="wcru-checkout-message"><?php esc_html_e( 'My Account Message', 'restrict-users-for-woocommerce' ); ?></label><textarea id="wcru-checkout-message" name="wcru_settings[checkout_message]" rows="3" class="large-text"><?php echo esc_textarea( $settings['checkout_message'] ); ?></textarea>
            <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'restrict-users-for-woocommerce' ); ?></button></p>
        </section>
    </form>
    <section class="wcru-card">
        <h2><?php esc_html_e( 'Purchase Restricted Users', 'restrict-users-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Enter one or more WordPress user IDs, separated by commas. Use WooCommerce Orders to look up customer information.', 'restrict-users-for-woocommerce' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>"><?php esc_html_e( 'Open WooCommerce Orders', 'restrict-users-for-woocommerce' ); ?></a></p>
        <form class="wcru-user-id-entry" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=restrict-users-for-woocommerce' ) ); ?>"><?php wp_nonce_field( 'wcru_add_users' ); ?><input type="hidden" name="wcru_action" value="add_users"><label for="wcru-user-ids"><?php esc_html_e( 'User IDs to restrict', 'restrict-users-for-woocommerce' ); ?></label>
            <div class="wcru-entry-controls"><input id="wcru-user-ids" name="user_ids" type="text" inputmode="numeric" autocomplete="off" required placeholder="<?php esc_attr_e( 'Example: 24, 56, 89', 'restrict-users-for-woocommerce' ); ?>"><button type="submit" class="button button-primary"><?php esc_html_e( 'Add users', 'restrict-users-for-woocommerce' ); ?></button></div>
        </form>
        <div class="wcru-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'User ID', 'restrict-users-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Company Name', 'restrict-users-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Username', 'restrict-users-for-woocommerce' ); ?></th>
                        <th><?php esc_html_e( 'Email Address', 'restrict-users-for-woocommerce' ); ?></th>
                        <th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'restrict-users-for-woocommerce' ); ?></span></th>
                    </tr>
                </thead>
                <tbody id="wcru-restricted-list"><?php foreach ( $restricted_rows as $data ) : ?><tr data-user-id="<?php echo esc_attr( $data['id'] ); ?>">
                        <td><?php echo esc_html( $data['id'] ); ?></td>
                        <td><?php echo esc_html( $data['company'] ); ?></td>
                        <td><?php echo esc_html( $data['username'] ); ?></td>
                        <td><?php echo esc_html( $data['email'] ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=restrict-users-for-woocommerce' ) ); ?>"><?php wp_nonce_field( 'wcru_remove_user' ); ?><input type="hidden" name="wcru_action" value="remove_user"><input type="hidden" name="user_id" value="<?php echo esc_attr( $data['id'] ); ?>"><button type="submit" class="wcru-remove button-link-delete" aria-label="<?php esc_attr_e( 'Remove user restriction', 'restrict-users-for-woocommerce' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="red" stroke-width="3" stroke-linecap="round" aria-hidden="true" focusable="false">
                                        <line x1="18" y1="6" x2="6" y2="18" />
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                    </svg></button></form>
                        </td>
                    </tr><?php endforeach; ?></tbody>
            </table>
        </div>
    </section>
</div>
<?php
	}
}

register_activation_hook( __FILE__, array( 'WCRU_Plugin', 'activate' ) );
WCRU_Plugin::instance();