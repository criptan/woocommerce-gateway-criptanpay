<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class that represents admin notices.
 *
 * @since 4.1.0
 */
class WC_Criptanpay_Admin_Notices {
	/**
	 * Notices (array)
	 * @var array
	 */
	public $notices = array();

	/**
	 * Constructor
	 *
	 * @since 4.1.0
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_loaded', array( $this, 'hide_notices' ) );
		add_action( 'woocommerce_criptanpay_updated', array( $this, 'criptanpay_updated' ) );
	}

	/**
	 * Allow this class and other classes to add slug keyed notices (to avoid duplication).
	 *
	 * @since 1.0.0
	 * @version 4.0.0
	 */
	public function add_admin_notice( $slug, $class, $message, $dismissible = false ) {
		$this->notices[ $slug ] = array(
			'class'       => $class,
			'message'     => $message,
			'dismissible' => $dismissible,
		);
	}

	/**
	 * Display any notices we've collected thus far.
	 *
	 * @since 1.0.0
	 * @version 4.0.0
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Main Criptanpay payment method.
		$this->criptanpay_check_environment();

		foreach ( (array) $this->notices as $notice_key => $notice ) {
			echo '<div class="' . esc_attr( $notice['class'] ) . '" style="position:relative;">';

			if ( $notice['dismissible'] ) {
				?>
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wc-criptanpay-hide-notice', $notice_key ), 'wc_criptanpay_hide_notices_nonce', '_wc_criptanpay_notice_nonce' ) ); ?>" class="woocommerce-message-close notice-dismiss" style="position:relative;float:right;padding:9px 0px 9px 9px 9px;text-decoration:none;"></a>
				<?php
			}

			echo '<p>';
			echo wp_kses( $notice['message'], array( 'a' => array( 'href' => array(), 'target' => array() ) ) );
			echo '</p></div>';
		}
	}

	/**
	 * The backup sanity check, in case the plugin is activated in a weird way,
	 * or the environment changes after activation. Also handles upgrade routines.
	 *
	 * @since 1.0.0
	 * @version 4.0.0
	 */
	public function criptanpay_check_environment() {
		$show_style_notice   = get_option( 'wc_criptanpay_show_style_notice' );
		$show_ssl_notice     = get_option( 'wc_criptanpay_show_ssl_notice' );
		$show_keys_notice    = get_option( 'wc_criptanpay_show_keys_notice' );
		$show_phpver_notice  = get_option( 'wc_criptanpay_show_phpver_notice' );
		$show_wcver_notice   = get_option( 'wc_criptanpay_show_wcver_notice' );
		$show_curl_notice    = get_option( 'wc_criptanpay_show_curl_notice' );
		$show_sca_notice     = get_option( 'wc_criptanpay_show_sca_notice' );
		$changed_keys_notice = get_option( 'wc_criptanpay_show_changed_keys_notice' );
		$options             = get_option( 'woocommerce_criptanpay_settings' );
		$testmode            = ( isset( $options['testmode'] ) && 'yes' === $options['testmode'] ) ? true : false;
		$test_secret_key     = isset( $options['test_secret_key'] ) ? $options['test_secret_key'] : '';
		$live_secret_key     = isset( $options['secret_key'] ) ? $options['secret_key'] : '';

		if ( isset( $options['enabled'] ) && 'yes' === $options['enabled'] ) {

			if ( empty( $show_style_notice ) ) {
				/* translators: 1) int version 2) int version */
				$message = __( 'WooCommerce Criptanpay - We recently made changes to Criptanpay that may impact the appearance of your checkout. If your checkout has changed unexpectedly, please follow these <a href="https://docs.woocommerce.com/document/criptanpay/#styling" target="_blank">instructions</a> to fix.', 'woocommerce-gateway-criptanpay' );

				$this->add_admin_notice( 'style', 'notice notice-warning', $message, true );

				return;
			}

			if ( empty( $show_phpver_notice ) ) {
				if ( version_compare( phpversion(), WC_CRIPTANPAY_MIN_PHP_VER, '<' ) ) {
					/* translators: 1) int version 2) int version */
					$message = __( 'WooCommerce Criptanpay - The minimum PHP version required for this plugin is %1$s. You are running %2$s.', 'woocommerce-gateway-criptanpay' );

					$this->add_admin_notice( 'phpver', 'error', sprintf( $message, WC_CRIPTANPAY_MIN_PHP_VER, phpversion() ), true );

					return;
				}
			}

			if ( empty( $show_wcver_notice ) ) {
				if ( WC_Criptanpay_Helper::is_wc_lt( WC_CRIPTANPAY_FUTURE_MIN_WC_VER ) ) {
					/* translators: 1) int version 2) int version */
					$message = __( 'WooCommerce Criptanpay - This is the last version of the plugin compatible with WooCommerce %1$s. All furture versions of the plugin will require WooCommerce %2$s or greater.', 'woocommerce-gateway-criptanpay' );
					$this->add_admin_notice( 'wcver', 'notice notice-warning', sprintf( $message, WC_VERSION, WC_CRIPTANPAY_FUTURE_MIN_WC_VER ), true );
				}
			}

			if ( empty( $show_curl_notice ) ) {
				if ( ! function_exists( 'curl_init' ) ) {
					$this->add_admin_notice( 'curl', 'notice notice-warning', __( 'WooCommerce Criptanpay - cURL is not installed.', 'woocommerce-gateway-criptanpay' ), true );
				}
			}

			if ( empty( $show_keys_notice ) ) {
				$secret = WC_Criptanpay_API::get_secret_key();

				if ( empty( $secret ) && ! ( isset( $_GET['page'], $_GET['section'] ) && 'wc-settings' === $_GET['page'] && 'criptanpay' === $_GET['section'] ) ) {
					$setting_link = $this->get_setting_link();
					/* translators: 1) link */
					$this->add_admin_notice( 'keys', 'notice notice-warning', sprintf( __( 'Criptanpay is almost ready. To get started, <a href="%s">set your Criptanpay account keys</a>.', 'woocommerce-gateway-criptanpay' ), $setting_link ), true );
				}

				// Check if keys are entered properly per live/test mode.
				if ( $testmode ) {
					if (
						! empty( $test_pub_key ) && ! preg_match( '/^pk_test_/', $test_pub_key )
						|| ! empty( $test_secret_key ) && ! preg_match( '/^[rs]k_test_/', $test_secret_key ) ) {
						$setting_link = $this->get_setting_link();
						/* translators: 1) link */
						$this->add_admin_notice( 'keys', 'notice notice-error', sprintf( __( 'Criptanpay is in test mode however your test keys may not be valid. Test keys start with pk_test and sk_test or rk_test. Please go to your settings and, <a href="%s">set your Criptanpay account keys</a>.', 'woocommerce-gateway-criptanpay' ), $setting_link ), true );
					}
				} else {
					if (
						! empty( $live_pub_key ) && ! preg_match( '/^pk_live_/', $live_pub_key )
						|| ! empty( $live_secret_key ) && ! preg_match( '/^[rs]k_live_/', $live_secret_key ) ) {
						$setting_link = $this->get_setting_link();
						/* translators: 1) link */
						$this->add_admin_notice( 'keys', 'notice notice-error', sprintf( __( 'Criptanpay is in live mode however your live keys may not be valid. Live keys start with pk_live and sk_live or rk_live. Please go to your settings and, <a href="%s">set your Criptanpay account keys</a>.', 'woocommerce-gateway-criptanpay' ), $setting_link ), true );
					}
				}
			}

			if ( empty( $show_ssl_notice ) ) {
				// Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected.
				if ( ! wc_checkout_is_https() ) {
					/* translators: 1) link */
					$this->add_admin_notice( 'ssl', 'notice notice-warning', sprintf( __( 'Criptanpay is enabled, but a SSL certificate is not detected. Your checkout may not be secure! Please ensure your server has a valid <a href="%1$s" target="_blank">SSL certificate</a>', 'woocommerce-gateway-criptanpay' ), 'https://en.wikipedia.org/wiki/Transport_Layer_Security' ), true );
				}
			}

			if ( empty( $show_sca_notice ) ) {
				$this->add_admin_notice( 'sca', 'notice notice-success', sprintf( __( 'Criptanpay is now ready for Strong Customer Authentication (SCA) and 3D Secure 2! <a href="%1$s" target="_blank">Read about SCA</a>', 'woocommerce-gateway-criptanpay' ), 'https://woocommerce.com/posts/introducing-strong-customer-authentication-sca/' ), true );
			}

			if ( 'yes' === $changed_keys_notice ) {
				// translators: %s is a the URL for the link.
				$this->add_admin_notice( 'changed_keys', 'notice notice-warning', sprintf( __( 'The public and/or secret keys for the Criptanpay gateway have been changed. This might cause errors for existing customers and saved payment methods. <a href="%s" target="_blank">Click here to learn more</a>.', 'woocommerce-gateway-criptanpay' ), 'https://docs.woocommerce.com/document/criptanpay-fixing-customer-errors/' ), true );
			}
		}
	}

	/**
	 * Hides any admin notices.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function hide_notices() {
		if ( isset( $_GET['wc-criptanpay-hide-notice'] ) && isset( $_GET['_wc_criptanpay_notice_nonce'] ) ) {
			if ( ! wp_verify_nonce( $_GET['_wc_criptanpay_notice_nonce'], 'wc_criptanpay_hide_notices_nonce' ) ) {
				wp_die( __( 'Action failed. Please refresh the page and retry.', 'woocommerce-gateway-criptanpay' ) );
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce-gateway-criptanpay' ) );
			}

			$notice = wc_clean( $_GET['wc-criptanpay-hide-notice'] );

			switch ( $notice ) {
				case 'style':
					update_option( 'wc_criptanpay_show_style_notice', 'no' );
					break;
				case 'phpver':
					update_option( 'wc_criptanpay_show_phpver_notice', 'no' );
					break;
				case 'wcver':
					update_option( 'wc_criptanpay_show_wcver_notice', 'no' );
					break;
				case 'curl':
					update_option( 'wc_criptanpay_show_curl_notice', 'no' );
					break;
				case 'ssl':
					update_option( 'wc_criptanpay_show_ssl_notice', 'no' );
					break;
				case 'keys':
					update_option( 'wc_criptanpay_show_keys_notice', 'no' );
					break;
				case 'sca':
					update_option( 'wc_criptanpay_show_sca_notice', 'no' );
					break;
				case 'changed_keys':
					update_option( 'wc_criptanpay_show_changed_keys_notice', 'no' );
			}
		}
	}

	/**
	 * Get setting link.
	 *
	 * @since 1.0.0
	 *
	 * @return string Setting link
	 */
	public function get_setting_link() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=criptanpay' );
	}

	/**
	 * Saves options in order to hide notices based on the gateway's version.
	 *
	 * @since 4.3.0
	 */
	public function criptanpay_updated() {
		$previous_version = get_option( 'wc_criptanpay_version' );

		// Only show the style notice if the plugin was installed and older than 4.1.4.
		if ( empty( $previous_version ) || version_compare( $previous_version, '4.1.4', 'ge' ) ) {
			update_option( 'wc_criptanpay_show_style_notice', 'no' );
		}

		// Only show the SCA notice on pre-4.3.0 installs.
		if ( empty( $previous_version ) || version_compare( $previous_version, '4.3.0', 'ge' ) ) {
			update_option( 'wc_criptanpay_show_sca_notice', 'no' );
		}
	}
}

new WC_Criptanpay_Admin_Notices();
