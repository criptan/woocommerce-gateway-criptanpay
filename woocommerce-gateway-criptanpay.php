<?php
/**
 * Plugin Name: WooCommerce CriptanPay Gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-gateway-criptanpay/
 * Description: Take Bitcoin and other cryptocurrency payments on your store using CriptanPay.
 * Author: Criptan
 * Author URI: https://www.criptanpay.es/
 * Version: 1.0.0
 * Requires at least: 4.4
 * Tested up to: 5.6
 * WC requires at least: 3.0
 * WC tested up to: 4.9
 * Text Domain: woocommerce-gateway-criptanpay
 * Domain Path: /languages
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required minimums and constants
 */
define( 'WC_CRIPTANPAY_VERSION', '1.0.0' );
define( 'WC_CRIPTANPAY_MIN_PHP_VER', '5.6.0' );
define( 'WC_CRIPTANPAY_MIN_WC_VER', '3.0' );
define( 'WC_CRIPTANPAY_FUTURE_MIN_WC_VER', '3.3' );
define( 'WC_CRIPTANPAY_MAIN_FILE', __FILE__ );
define( 'WC_CRIPTANPAY_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );
define( 'WC_CRIPTANPAY_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

/**
 * WooCommerce fallback notice.
 *
 * @since 1.0.0
 * @return string
 */
function woocommerce_criptanpay_missing_wc_notice() {
	/* translators: 1. URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'CriptanPay requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-gateway-criptanpay' ), '<a href="https://www.woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

/**
 * WooCommerce not supported fallback notice.
 *
 * @since 1.0.0
 * @return string
 */
function woocommerce_criptanpay_wc_not_supported() {
	/* translators: $1. Minimum WooCommerce version. $2. Current WooCommerce version. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'CriptanPay requires WooCommerce %1$s or greater to be installed and active. WooCommerce %2$s is no longer supported.', 'woocommerce-gateway-criptanpay' ), WC_CRIPTANPAY_MIN_WC_VER, WC_VERSION ) . '</strong></p></div>';
}

function woocommerce_gateway_criptanpay() {

	static $plugin;

	if ( ! isset( $plugin ) ) {
		
		class WC_Criptanpay {

			/**
			 * @var Singleton The reference the *Singleton* instance of this class
			 */
			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return Singleton The *Singleton* instance.
			 */
			public static function get_instance() {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}

			/**
			 * Criptanpay Connect API
			 *
			 * @var WC_Criptanpay_Connect_API
			 */
			private $api;

			/**
			 * Criptanpay Connect
			 *
			 * @var WC_Criptanpay_Connect
			 */
			public $connect;

			/**
			 * Private clone method to prevent cloning of the instance of the
			 * *Singleton* instance.
			 *
			 * @return void
			 */
			public function __clone() {}

			/**
			 * Private unserialize method to prevent unserializing of the *Singleton*
			 * instance.
			 *
			 * @return void
			 */
			public function __wakeup() {}

			/**
			 * Protected constructor to prevent creating a new instance of the
			 * *Singleton* via the `new` operator from outside of this class.
			 */
			public function __construct() {
				add_action( 'admin_init', array( $this, 'install' ) );

				$this->init();

#				$this->api     = new WC_Criptanpay_Connect_API();
#				$this->connect = new WC_Criptanpay_Connect( $this->api );

#				add_action( 'rest_api_init', array( $this, 'register_connect_routes' ) );
			}
			
			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function init() {

				if ( is_admin() ) {
					require_once dirname( __FILE__ ) . '/includes/admin/class-wc-criptanpay-privacy.php';
				}

				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-exception.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-logger.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-helper.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-api.php';
				require_once dirname( __FILE__ ) . '/includes/abstracts/abstract-wc-criptanpay-payment-gateway.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-webhook-handler.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-criptanpay.php';


#				require_once dirname( __FILE__ ) . '/includes/compat/class-wc-criptanpay-subs-compat.php';
#				require_once dirname( __FILE__ ) . '/includes/compat/class-wc-criptanpay-sepa-subs-compat.php';

#				require_once dirname( __FILE__ ) . '/includes/connect/class-wc-criptanpay-connect.php';
#				require_once dirname( __FILE__ ) . '/includes/connect/class-wc-criptanpay-connect-api.php';

#				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-order-handler.php';
#				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-payment-tokens.php';
#				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-customer.php';
#				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-intent-controller.php';

#				require_once dirname( __FILE__ ) . '/includes/admin/class-wc-criptanpay-inbox-notes.php';
#
				if ( is_admin() ) {
					require_once dirname( __FILE__ ) . '/includes/admin/class-wc-criptanpay-admin-notices.php';
				}
#
				add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
				add_filter( 'pre_update_option_woocommerce_criptanpay_settings', array( $this, 'gateway_settings_update' ), 10, 2 );
				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
				add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
#
#				// Modify emails emails.
#				add_filter( 'woocommerce_email_classes', array( $this, 'add_emails' ), 20 );
#
#				if ( version_compare( WC_VERSION, '3.4', '<' ) ) {
#					add_filter( 'woocommerce_get_sections_checkout', array( $this, 'filter_gateway_order_admin' ) );
#				}
			}

			/**
			 * Updates the plugin version in db
			 *
			 * @since 3.1.0
			 * @version 4.0.0
			 */
			public function update_plugin_version() {
				delete_option( 'wc_criptanpay_version' );
				update_option( 'wc_criptanpay_version', WC_CRIPTANPAY_VERSION );
			}

			/**
			 * Handles upgrade routines.
			 *
			 * @since 3.1.0
			 * @version 3.1.0
			 */
			public function install() {
				if ( ! is_plugin_active( plugin_basename( __FILE__ ) ) ) {
					return;
				}

				if ( ! defined( 'IFRAME_REQUEST' ) && ( WC_CRIPTANPAY_VERSION !== get_option( 'wc_criptanpay_version' ) ) ) {
					do_action( 'woocommerce_criptanpay_updated' );

					if ( ! defined( 'WC_CRIPTANPAY_INSTALLING' ) ) {
						define( 'WC_CRIPTANPAY_INSTALLING', true );
					}

					$this->update_plugin_version();
				}
			}

			/**
			 * Add plugin action links.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function plugin_action_links( $links ) {
				$plugin_links = array(
					'<a href="admin.php?page=wc-settings&tab=checkout&section=criptanpay">' . esc_html__( 'Settings', 'woocommerce-gateway-criptanpay' ) . '</a>',
				);
				return array_merge( $plugin_links, $links );
			}

			/**
			 * Add plugin action links.
			 *
			 * @since 4.3.4
			 * @param  array  $links Original list of plugin links.
			 * @param  string $file  Name of current file.
			 * @return array  $links Update list of plugin links.
			 */
			public function plugin_row_meta( $links, $file ) {
				if ( plugin_basename( __FILE__ ) === $file ) {
					$row_meta = array(
						'docs'    => '<a href="' . esc_url( apply_filters( 'woocommerce_gateway_criptanpay_docs_url', 'https://docs.woocommerce.com/document/criptanpay/' ) ) . '" title="' . esc_attr( __( 'View Documentation', 'woocommerce-gateway-criptanpay' ) ) . '">' . __( 'Docs', 'woocommerce-gateway-criptanpay' ) . '</a>',
						'support' => '<a href="' . esc_url( apply_filters( 'woocommerce_gateway_criptanpay_support_url', 'https://woocommerce.com/my-account/create-a-ticket?select=18627' ) ) . '" title="' . esc_attr( __( 'Open a support request at WooCommerce.com', 'woocommerce-gateway-criptanpay' ) ) . '">' . __( 'Support', 'woocommerce-gateway-criptanpay' ) . '</a>',
					);
					return array_merge( $links, $row_meta );
				}
				return (array) $links;
			}

			/**
			 * Add the gateways to WooCommerce.
			 *
			 * @since 1.0.0
			 * @version 4.0.0
			 */
			public function add_gateways( $methods ) {

				$methods[] = 'WC_Gateway_Criptanpay';

				return $methods;
			}

			/**
			 * Provide default values for missing settings on initial gateway settings save.
			 *
			 * @since 4.5.4
			 * @version 4.5.4
			 *
			 * @param array $settings New settings to save
			 * @param array|bool $old_settings Existing settings, if any.
			 * @return array New value but with defaults initially filled in for missing settings.
			 */
			public function gateway_settings_update( $settings, $old_settings ) {
				if ( false === $old_settings ) {
					$gateway  = new WC_Gateway_Criptanpay();
					$fields   = $gateway->get_form_fields();
					$defaults = array_merge( array_fill_keys( array_keys( $fields ), '' ), wp_list_pluck( $fields, 'default' ) );
					return array_merge( $defaults, $settings );
				}
				return $settings;
			}

			/**
			 * Adds the failed SCA auth email to WooCommerce.
			 *
			 * @param WC_Email[] $email_classes All existing emails.
			 * @return WC_Email[]
			 */
			public function add_emails( $email_classes ) {
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/compat/class-wc-criptanpay-email-failed-authentication.php';
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/compat/class-wc-criptanpay-email-failed-renewal-authentication.php';
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/compat/class-wc-criptanpay-email-failed-preorder-authentication.php';
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/compat/class-wc-criptanpay-email-failed-authentication-retry.php';

				// Add all emails, generated by the gateway.
				$email_classes['WC_Criptanpay_Email_Failed_Renewal_Authentication']  = new WC_Criptanpay_Email_Failed_Renewal_Authentication( $email_classes );
				$email_classes['WC_Criptanpay_Email_Failed_Preorder_Authentication'] = new WC_Criptanpay_Email_Failed_Preorder_Authentication( $email_classes );
				$email_classes['WC_Criptanpay_Email_Failed_Authentication_Retry'] = new WC_Criptanpay_Email_Failed_Authentication_Retry( $email_classes );

				return $email_classes;
			}
			
			/**
			 * Register Criptanpay connect rest routes.
			 */
			public function register_connect_routes() {

				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/abstracts/abstract-wc-criptanpay-connect-rest-controller.php';
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/connect/class-wc-criptanpay-connect-rest-oauth-init-controller.php';
				require_once WC_CRIPTANPAY_PLUGIN_PATH . '/includes/connect/class-wc-criptanpay-connect-rest-oauth-connect-controller.php';

				$oauth_init    = new WC_Criptanpay_Connect_REST_Oauth_Init_Controller( $this->connect, $this->api );
				$oauth_connect = new WC_Criptanpay_Connect_REST_Oauth_Connect_Controller( $this->connect, $this->api );

				$oauth_init->register_routes();
				$oauth_connect->register_routes();
			}
		}

		$plugin = WC_Criptanpay::get_instance();

	}

	return $plugin;
}

add_action( 'plugins_loaded', 'woocommerce_gateway_criptanpay_init' );

function woocommerce_gateway_criptanpay_init() {
	load_plugin_textdomain( 'woocommerce-gateway-criptanpay', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woocommerce_criptanpay_missing_wc_notice' );
		return;
	}

	if ( version_compare( WC_VERSION, WC_CRIPTANPAY_MIN_WC_VER, '<' ) ) {
		add_action( 'admin_notices', 'woocommerce_criptanpay_wc_not_supported' );
		return;
	}

	woocommerce_gateway_criptanpay();
}