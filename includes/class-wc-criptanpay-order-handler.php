<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles and process orders from asyncronous flows.
 *
 * @since 1.0.0
 */
class WC_Criptanpay_Order_Handler extends WC_Gateway_Criptanpay {
	
	private static $_this;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function __construct() {
		self::$_this = $this;

		add_action( 'wp', array( $this, 'maybe_process_redirect_order' ) );
		
		// Todo capture custom order statuses
		add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );

		add_filter( 'woocommerce_tracks_event_properties', array( $this, 'woocommerce_tracks_event_properties' ), 10, 2 );
	}

	/**
	 * Public access to instance object.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public static function get_instance() {
		return self::$_this;
	}

	/**
	 * Processes payments.
	 * Note at this time the original source has already been
	 * saved to a customer card (if applicable) from process_payment.
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Add $previous_error parameter.
	 * @param int $order_id
	 * @param bool $retry
	 * @param mix $previous_error Any error message from previous request.
	 */
	public function process_redirect_payment( $order_id, $retry = true, $previous_error = false ) {

	}

	/**
	 * Processses the orders that are redirected.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public function maybe_process_redirect_order() {
		if ( ! is_order_received_page() || empty( $_GET['client_secret'] ) || empty( $_GET['source'] ) ) {
			return;
		}

		$order_id = wc_clean( $_GET['order_id'] );
		$this->process_redirect_payment( $order_id );
	}

	/**
	 * Capture payment when the order is changed from on-hold to complete or processing.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		
	}

	/**
	 * Cancel pre-auth on refund/cancellation.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param  int $order_id
	 */
	public function cancel_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'criptanpay' === $order->get_payment_method() ) {
			$captured = $order->get_meta( '_criptanpay_charge_captured', true );
			if ( 'no' === $captured ) {
				$this->process_refund( $order_id );
			}

			// This hook fires when admin manually changes order status to cancel.
			do_action( 'woocommerce_criptanpay_process_manual_cancel', $order );
		}
	}

}

new WC_Criptanpay_Order_Handler();
