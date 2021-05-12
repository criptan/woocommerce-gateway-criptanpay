<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Criptanpay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Criptanpay extends WC_Payment_Gateway {

	/**
	 * Criptanpay API Endpoints
	 */
	private $api_url;

	/**
	 * Webkook secret key
	 *
	 * @var string
	 */
	public $webhook_secret_key;


	/**
	 * API access secret key
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                 = 'criptanpay';
		$this->method_title       = __( 'Criptanpay', 'woocommerce-gateway-criptanpay' );
		$this->method_description = __( 'Redirects customers to Criptanpay where they can send the cryptocurrency to your account', 'woocommerce-gateway-criptanpay' );
		$this->has_fields         = true;
		$this->supports           = array(
			'products'
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values.
		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->enabled              = $this->get_option( 'enabled' );
		$this->webhook_secret_key   = $this->get_option( 'webhook_secret_key' );
		$this->testmode             = 'yes' === $this->get_option( 'testmode' );
		$this->secret_key           = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );

		$this->set_api_url();

		// Hooks.
		add_action( 'init', array( $this, 'register_wc_statuses' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts') );
		
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_wc_statuses' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'paynow_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		// add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_payout' ), 20 );
		// add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 99999, 2 );
		// add_filter( 'woocommerce_get_checkout_payment_url', array( $this, 'get_checkout_payment_url' ), 10, 2 );

		// Note: display error is in the parent class.
		// add_action( 'admin_notices', array( $this, 'display_errors' ), 9999 );

	}

	/**
	 * Checks if gateway should be available to use.
	 *
	 * @since 1.0.0
	 */
	public function is_available() {
		if ( is_add_payment_method_page() ) {
			return false;
		}

		return parent::is_available();
	}

	public function set_api_url() {
		if ( ! isset( $this->api_url ) ) {
			if ( 'yes' === $this->get_option( 'testmode' ) ) {
				$this->api_url = 'https://api.staging.cashbilly.com/business/';
			} else {
				$this->api_url = 'https://api.criptan.com/business/';
			}
		}
	}

	public function enqueue_scripts() {

		wp_enqueue_script( 'criptanpay-main', WC_CRIPTANPAY_PLUGIN_URL . '/assets/js/criptanpay.js', array('jquery'), WC_CRIPTANPAY_VERSION, true );

	}

	public function get_api_url() {
		return $this->api_url;
	}

	public function get_id() {
		return $this->id;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = require( dirname( __FILE__ ) . '/admin/criptanpay-settings.php' );
	}

	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );

			if ( ! is_object( $order ) ) {
				return;
			}
	
			$response = null;
	
			WC_Criptanpay_Logger::log( "Info: (Redirect) Begin processing payment for order $order_id for the amount of {$order->get_total()}" );

			$response = WC_Criptanpay_API::request( $this->generate_payment_request( $order ), $this->get_api_url(), 'charge', 'POST', false );
			
			if ( ! is_array( $response ) && property_exists( $response, 'error' ) ) {
				// TODO: wc_add_notice();
				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

			// Process valid response.
			$this->process_response( $response, $order );
	
			// Remove cart.
			if ( isset( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}
	
			// Return thank you page redirect.
			// Return receipt_page redirect
			return array(
				'result'   => 'success',
				//'redirect' => $this->get_return_url( $order ),
				'redirect'	=> $order->get_checkout_payment_url( true )
			);

		} catch( WC_Criptanpay_Exception $e  ) {
			do_action( 'wc_gateway_criptanpay_process_payment_error', $e, $order );

			/* translators: error message */
			$order->update_status( 'failed' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}
	}

	/**
	 * Store extra meta data for an order from a Criptanpay Response.
	 */
	public function process_response( $response, $order ) {
		WC_Criptanpay_Logger::log( 'Processing response: ' . print_r( $response, true ) );
		
		$order_id = $order->get_id();
		$charged_captured = property_exists( $response, 'id' ) ? true : false;

		if ( $charged_captured ) {

			// get status of charge create responses
			$status = ( property_exists( $response, 'paymentStatus' ) ? $response->paymentStatus : false );
			// get status of charge info responses
			$status = ( property_exists( $response, 'status') ? $response->status : false );

			if ( ! $status ) {
				$localized_message = __( 'An error occurred while trying to process the response through the Criptanpay payment gateway. Review settings or contact Criptanpay support', 'woocommerce-gateway-criptanpay' );
				throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );
			}

			$status = strtolower( $status );

			/**
			 * Charge can be captured but in a pending state. Payment methods
			 * that are asynchronous may take couple days to clear. Webhook will
			 * take care of the status changes.
			 */

			// Default
			if ( 'pending' === $status) {
				$order_stock_reduced = $order->get_meta( '_order_stock_reduced', true );

				if ( 'yes' !== $order_stock_reduced ) {
					wc_reduce_stock_levels( $order_id );
				}

				$order->set_transaction_id( $response->id  );
				$order->update_meta_data( '_criptanpay_checkout',  $response->checkout );

			}
			
			// Charge has been paid, but hasn't been confirmed yet
			// Mapped to wc status: on-hold
			if ( 'paid' === $status ) {
				$order->update_status( 'on-hold', sprintf( __( 'Criptanpay charge (ID: %s) awaiting payment confirmation.', 'woocommerce-gateway-criptanpay' ), $response->id ) );
			}
			
			// Charge has been confirmed and the associated payment is completed
			// Mapped to wc status: completed
			if ( 'confirmed' === $status ) {
				$order->update_status( 'completed', sprintf( __( 'Criptanpay payment (ID: %s) confirmed.', 'woocommerce-gateway-criptanpay' ), $response->id ) );
				$localized_message = sprintf( __( 'Criptanpay payment (ID: %s) confirmed.', 'woocommerce-gateway-criptanpay' ), $response->id );
				$order->add_order_note( $localized_message );
				$order->payment_complete( $response->id );
			}

			// Charge has expired without a payment being made
			// Custom wc status: expired
			// TODO: on deactivation set to default: failed
			if ( 'expired' === $status ) {
				$order->update_status( 'expired', __( 'Criptanpay charge expired.', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'Payment processing failed. The charge has expired without a payment being made.', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
			}

			// The payment was made after the specified time
			// Custom wc status: delayed
			// TODO: on deactivation set to default: failed
			if ( 'delayed' === $status ) {
				$order->update_status( 'delayed', __( 'Criptanpay charge paid after payment time limit. Please retry.', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'The payment was made after the specified time', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
			}

			// The payment was made for an inferior quantity of what was requested
			// Custom wc status: underpaid
			// TODO: on deactivation set to default: failed
			if ( 'underpaid' === $status ) {
				$order->update_status( 'underpaid', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'Payment processing failed. Please retry.', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );
			}

			// A refund has been requested, but it hasn't been resolved yet
			// Custom wc status: refund-pending
			// TODO: on deactivation set to default: failed
			if ( 'refund_pending' === $status ) {
				$order->update_status( 'refund-pending', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'A refund has been requested, but it hasn’	t been resolved yet', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
			}

			// They payment has been sucessfully refunded
			// Mapped to wc status: refunded
			if ( 'refunded' === $status ) {
				$order->update_status( 'refunded', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'They payment has been sucessfully refunded', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
			}


		} else {

			if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
				wc_reduce_stock_levels( $order_id );
			}

			$order->update_status( 'on-hold', __( 'Criptanpay charge authorized. Process order to take payment, or cancel to remove the pre-authorization.', 'woocommerce-gateway-criptanpay' ) );
			$localized_message = __( 'An error occurred while trying to charge a payment through the Criptanpay payment gateway. Review settings or contact Criptanpay support', 'woocommerce-gateway-criptanpay' );
			throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );

		}

		if ( is_callable( array( $order, 'save' ) ) ) {
			$order->save();
		}

		do_action( 'wc_gateway_criptanpay_process_response', $response, $order );

		return $response;
	}

	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! is_object( $order ) ) {
			return;
		}


	}
	public function paynow_page( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! is_object( $order ) ) {
			return;
		}

		$checkout = $order->get_meta('_criptanpay_checkout', true);

		echo '<div class="clear"></div>' .
			'<div id="criptanpay-gateway-redirect">' .
			'<p>' .
			__( 'Now redirecting to the Criptanpay gateway…', 'woocommerce-gateway-criptanpay' ) .
			'<br/>' .
			sprintf( __( 'Not getting redirected? <a class="button button-primary " href="%s">Pay now</a>', 'woocommerce-gateway-criptanpay' ), $checkout ) .
			'</p>' .
			'</div>';
	}

	/**
	 * Generate the request for the payment.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param  WC_Order $order
	 * @param  object $prepared_source
	 * @return array()
	 */
	public function generate_payment_request( $order ) {
		$settings              = get_option( 'woocommerce_criptanpay_settings', array() );
		
		$post_data             = array();
		$post_data['currency'] = $order->get_currency();
		$post_data['amount']   = absint( $order->get_total() );
		$post_data['cancelUrl'] 	= $this->get_return_url( $order );
		$post_data['continueUrl'] = $this->get_return_url( $order );
		$post_data['ttl'] = 5;
		$post_data['description'] = sprintf( __( '%1$s - Order %2$s', 'woocommerce-gateway-criptanpay' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
		
		$products = array();

		foreach ( $order->get_items() as $item_id => $item ) {

			$product = $item->get_product();
			$price = $product->get_price();
			$image_id  = $product->get_image_id();
			$image_url = wp_get_attachment_image_url( $image_id );

			if ( ! $image_url ) {
				$image_url = wc_placeholder_img_src();
			}

			$name = $item->get_name();
			$quantity = $item->get_quantity();

			$products[] = array(
				'title' => $name,
				'description' => '',
				// 'image' => $image_url,
				'quantity' => $quantity,
				'price' => absint( $price )
			);
		}

		$post_data['products'] = apply_filters( 'wc_criptanpay_payment_products', $products, $order );

		$metadata = array(
			'order_id' => $order->get_id(),
			'site_url' => esc_url( get_site_url() )
		);

		$post_data['metadata'] = apply_filters( 'wc_criptanpay_payment_metadata', $metadata, $order );

		/**
		 * Filter the return value of the WC_Payment_Gateway_CC::generate_payment_request.
		 *
		 * @since 1.0.0
		 * @param array $post_data
		 * @param WC_Order $order
		 * @param object $source
		 */
		return apply_filters( 'wc_criptanpay_generate_payment_request', $post_data, $order );
	}

	/**
	 * Register Woocommerce custom order statuses
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function register_wc_statuses() {

		register_post_status( 
			'wc-expired', 
			array(
				'label'                     => __( 'Expired payment', 'woocommerce-gateway-criptanpay' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);

		register_post_status( 
			'wc-delayed', 
			array(
				'label'                     => __( 'Delayed payment', 'woocommerce-gateway-criptanpay' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);

		register_post_status( 
			'wc-underpaid', 
			array(
				'label'                     => __( 'Underpaid payment', 'woocommerce-gateway-criptanpay' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);

		register_post_status( 
			'wc-refund-pending', 
			array(
				'label'                     => __( 'Refund pending', 'woocommerce-gateway-criptanpay' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);

	}

	/**
	 * Add custom order statuses to admin
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function add_wc_statuses( $order_statuses ) {
		$new_order_statuses = array();

		foreach ( $order_statuses as $key => $status ) {
	 
			$new_order_statuses[ $key ] = $status;
	 
			if ( 'wc-pending' === $key ) {
				$new_order_statuses['wc-expired'] = __( 'Expired payment', 'woocommerce-gateway-criptanpay' );
				$new_order_statuses['wc-delayed'] = __( 'Delayed payment', 'woocommerce-gateway-criptanpay' );
				$new_order_statuses['wc-underpaid'] = __( 'Underpaid payment', 'woocommerce-gateway-criptanpay' );
			}

			if ( 'wc-cancelled' === $key ) {
				$new_order_statuses['wc-refund-pending'] = __( 'Refund pending', 'woocommerce-gateway-criptanpay' );
			}

		}

		return $new_order_statuses;
	}

	/**
	 * All payment icons that work with Criptanpay. Some icons references
	 * WC core icons.
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function payment_icons() {
		return apply_filters(
			'wc_criptanpay_payment_icons',
			array(
				'bitcoin'        => '<img src="' . WC_CRIPTANPAY_PLUGIN_URL . '/assets/images/bitcoin.svg" class="criptanpay-bitcoin-icon criptanpay-icon" alt="Bitcoin" />',
				'ethereum'       => '<img src="' . WC_CRIPTANPAY_PLUGIN_URL . '/assets/images/ethereum.svg" class="criptanpay-ethereum-icon criptanpay-icon" alt="Ethereum" />',
				'litecoin'       => '<img src="' . WC_CRIPTANPAY_PLUGIN_URL . '/assets/images/litecoin.svg" class="criptanpay-litecoin-icon criptanpay-icon" alt="Litecoin" />',
			)
		);
	}

	/**
	 * Displays the admin settings webhook description.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function display_admin_settings_webhook_description() {
		return sprintf( __( 'The webhook endpoint settings must be added to your <a href="https://www.criptanpay.com/dashboard/settings" target="_blank">Criptanpay account settings</a>. This will enable you to receive notifications on the charge statuses.', 'woocommerce-gateway-criptanpay' ) );
	}
	

	/**
	 * Displays the admin settings webhook key.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function display_admin_settings_webhook_url() {
		return WC_Criptanpay_Helper::get_webhook_url();
	}


	/**
	 * Displays the admin settings webhook key.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function display_admin_settings_webhook_secret_key() {
		return WC_Criptanpay_API::get_webhook_secret_key();
	}

}
