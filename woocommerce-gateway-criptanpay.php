<?php
/**
 * Plugin Name: WooCommerce CriptanPay Gateway
 * Plugin URI: https://wordpress.org/plugins/woocommerce-gateway-criptanpay/
 * Description: Take Bitcoin and other cryptocurrency payments on your store using CriptanPay.
 * Author: Criptan
 * Author URI: https://www.criptanpay.com/
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

				$this->init();

			}
			
			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 * @since 1.0.0
			 * @version 1.0.0
			 */
			public function init() {

				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-exception.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-gateway-criptanpay.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-logger.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-helper.php';
				include_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-api.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-order-handler.php';
				require_once dirname( __FILE__ ) . '/includes/class-wc-criptanpay-webhook-handler.php';

				add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );
				add_filter( 'pre_update_option_woocommerce_criptanpay_settings', array( $this, 'gateway_settings_update' ), 10, 2 );
				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

			}

			/**
			 * Updates the plugin version in db
			 *
			 * @since 1.0.0
			 * @version 1.0.0
			 */
			public function update_plugin_version() {
				delete_option( 'wc_criptanpay_version' );
				update_option( 'wc_criptanpay_version', WC_CRIPTANPAY_VERSION );
			}


			/**
			 * Add plugin action links.
			 *
			 * @since 1.0.0
			 * @version 1.0.0
			 */
			public function plugin_action_links( $links ) {
				$plugin_links = array(
					'<a href="admin.php?page=wc-settings&tab=checkout&section=criptanpay">' . esc_html__( 'Settings', 'woocommerce-gateway-criptanpay' ) . '</a>',
				);
				return array_merge( $plugin_links, $links );
			}

			/**
			 * Add the gateways to WooCommerce.
			 *
			 * @since 1.0.0
			 * @version 1.0.0
			 */
			public function add_gateways( $methods ) {

				$methods[] = 'WC_Gateway_Criptanpay';

				return $methods;
			}

			/**
			 * Provide default values for missing settings on initial gateway settings save.
			 *
			 * @since 1.0.0
			 * @version 1.0.0
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