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
		$secret_key           = ( $this->testmode ? 'test_' : '' ) . 'webhook_secret';
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
		if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] )
			|| ! isset( $_GET['wc-api'] )
			|| ( 'wc_criptanpay' !== $_GET['wc-api'] )
		) {
			return;
		}

		$request_body    = file_get_contents( 'php://input' );
		$request_headers = array_change_key_case( $this->get_request_headers(), CASE_UPPER );

		// Validate it to make sure it is legit.
		if ( $this->is_valid_request( $request_headers, $request_body ) ) {
			$this->process_webhook( $request_body );
			status_header( 200 );
			exit;
		} else {
			WC_Criptanpay_Logger::log( 'Incoming webhook failed validation: ' . print_r( $request_body, true ) );
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

		if ( ! empty( $this->secret ) ) {

			// TODO: Verify the webhook_secret.
			
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
	 * Process webhook payments.
	 * This is where we charge the source.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param object $notification
	 * @param bool $retry
	 */
	public function process_webhook_payment( $notification, $retry = true ) {

	}


	/**
	 * Process webhook charge succeeded. This is used for payment methods
	 * that takes time to clear which is asynchronous. e.g. SEPA, SOFORT.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param object $notification
	 */
	public function process_webhook_charge_succeeded( $notification ) {

	}

	/**
	 * Process webhook charge failed.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param object $notification
	 */
	public function process_webhook_charge_failed( $notification ) {

	}

	/**
	 * Process webhook source canceled. This is used for payment methods
	 * that redirects and awaits payments from customer.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param object $notification
	 */
	public function process_webhook_source_canceled( $notification ) {

	}

	/**
	 * Process webhook refund.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param object $notification
	 */
	public function process_webhook_refund( $notification ) {

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

		switch ( $notification->charge ) {
			case 'paid':
				$this->process_webhook_paid( $notification );
				break;

			case 'confirmed':
				$this->process_webhook_source_confirmed( $notification );
				break;

			case 'expired':
				$this->process_webhook_charge_expired( $notification );
				break;

			case 'delayed':
				$this->process_webhook_charge_delayed( $notification );
				break;

			case 'underpaid':
				$this->process_webhook_underpaid( $notification );
				break;

			case 'refund_pending':
				$this->process_webhook_refund_pending( $notification );
				break;

			case 'refunded':
				$this->process_webhook_refunded( $notification );
				break;

		}
	}
}

new WC_Criptanpay_Webhook_Handler();
