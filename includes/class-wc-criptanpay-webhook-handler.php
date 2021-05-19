<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WC_Criptanpay_Webhook_Handler.
 *
 * Handles webhooks from Criptanpay on sources that are not immediately chargeable.
 * @since 1.0.0
 */
class WC_Criptanpay_Webhook_Handler extends WC_Gateway_Criptanpay {
	/**
	 * Delay of retries.
	 *
	 * @var int
	 */
	public $retry_interval;

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * The secret to use when verifying webhooks.
	 *
	 * @var string
	 */
	protected $secret;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		$this->retry_interval = 2;
		$criptanpay_settings      = get_option( 'woocommerce_criptanpay_settings', array() );
		$this->testmode       = ( ! empty( $criptanpay_settings['testmode'] ) && 'yes' === $criptanpay_settings['testmode'] ) ? true : false;
		// $secret_key           = ( $this->testmode ? 'test_' : '' ) . 'webhook_secret_key';
		$secret_key           = 'webhook_secret_key';
		$this->secret         = ! empty( $criptanpay_settings[ $secret_key ] ) ? $criptanpay_settings[ $secret_key ] : false;
		
		add_action( 'woocommerce_api_wc_criptanpay', array( $this, 'check_for_webhook' ) );
	}

	/**
	 * Check incoming requests for Criptanpay Webhook data and process them.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function check_for_webhook() {
		
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		
		$request_body    = file_get_contents( 'php://input' );
		$request_headers = array_change_key_case( $this->get_request_headers(), CASE_UPPER );

		if ( $this->is_valid_request( $request_headers, $request_body ) ) {
			$this->process_webhook( $request_body );
			status_header( 200 );
			exit;
		} else {
			WC_Criptanpay_Logger::log( 'Incoming webhook failed validation: ' . print_r( $request_body, true ) );
			echo __( 'Incoming webhook failed validation', 'woocommerce-gateway-criptanpay' );
			status_header( 400 );
			exit;
		}
	}

	/**
	 * Verify the incoming webhook notification to make sure it is legit.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param string $request_headers The request headers from Criptanpay.
	 * @param string $request_body The request body from Criptanpay.
	 * @return bool
	 */
	public function is_valid_request( $request_headers = null, $request_body = null ) {

		if ( null === $request_headers || null === $request_body ) {
			return false;
		}

		if ( ! empty( $this->secret ) && isset( $request_headers['X-SIGNATURE'] ) ) {
			
			// Check for a valid signature.
			$signed_payload = $request_headers['X-SIGNATURE'];
			$expected_signature = hash_hmac('sha256', $request_body, $this->secret );

			if ( $signed_payload !== $expected_signature ) {
				// WC_Criptanpay_Logger::log( 'Incoming webhook failed validation: check the webhook secret key.' );
				return false;
			}
			
		} else {
			return false;
		}

		return true;
	}

	/**
	 * Gets the incoming request headers. Some servers are not using
	 * Apache and "getallheaders()" will not work so we may need to
	 * build our own headers.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function get_request_headers() {
		if ( ! function_exists( 'getallheaders' ) ) {
			$headers = array();

			foreach ( $_SERVER as $name => $value ) {
				if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
					$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}

			return $headers;
		} else {
			return getallheaders();
		}
	}

	/**
	 * Processes the incoming webhook.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param string $request_body
	 */
	public function process_webhook( $request_body ) {
		$notification = json_decode( $request_body );

		if ( property_exists( $notification, 'metadata' ) ) {
			$order = wc_get_order( $notification->metadata->order_id );
		}

		if ( ! property_exists( $notification, 'metadata' ) ) {
			
			$orders = wc_get_orders( array(
				'limit' => 1,
				'meta_key' => '_transaction_id',
				'meta_value' => $notification->id
			));


			
			if ( 1 === count( $orders ) ) {
				foreach ( $orders as $order ) {
					$order = $order;
				}	
			}
			
		}
		

		$status = explode(':', $notification->event )[1];

		switch ( $status ) {
			case 'paid':
				$order->update_status( 'on-hold', sprintf( __( 'Criptanpay charge (ID: %s) awaiting payment confirmation.', 'woocommerce-gateway-criptanpay' ), $notification->id ) );
				break;

			case 'confirmed':
				$order->update_status( 'completed', sprintf( __( 'Criptanpay payment (ID: %s) confirmed.', 'woocommerce-gateway-criptanpay' ), $notification->id ) );
				$localized_message = sprintf( __( 'Criptanpay payment (ID: %s) confirmed.', 'woocommerce-gateway-criptanpay' ), $notification->id );
				$order->add_order_note( $localized_message );
				$order->payment_complete( $notification->id );
				break;

			case 'expired':
				$order->update_status( 'expired', __( 'Criptanpay charge expired.', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'Payment processing failed. The charge has expired without a payment being made.', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				break;

			case 'delayed':
				$order->update_status( 'delayed', __( 'Criptanpay charge paid after payment time limit. Please retry.', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'The payment was made after the specified time', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				break;

			case 'underpaid':
				$order->update_status( 'underpaid', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'Payment processing failed. Please retry.', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				throw new WC_Criptanpay_Exception( print_r( $notification, true ), $localized_message );
				break;

			case 'refund_pending':
				$order->update_status( 'refund-pending', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'A refund has been requested, but it hasn’	t been resolved yet', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				break;

			case 'refunded':
				$order->update_status( 'refunded', __( 'The payment was made for an inferior quantity of what was requested', 'woocommerce-gateway-criptanpay' ) );
				$localized_message = __( 'They payment has been sucessfully refunded', 'woocommerce-gateway-criptanpay' );
				$order->add_order_note( $localized_message );
				break;

		}
	}
}

new WC_Criptanpay_Webhook_Handler();
