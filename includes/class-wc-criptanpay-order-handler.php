<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles and process orders from asyncronous flows.
 *
 * @since 4.0.0
 */
class WC_Criptanpay_Order_Handler extends WC_Criptanpay_Payment_Gateway {
	private static $_this;
	public $retry_interval;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function __construct() {
		self::$_this = $this;

		$this->retry_interval = 1;

		add_action( 'wp', array( $this, 'maybe_process_redirect_order' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_payment' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_payment' ) );
		add_filter( 'woocommerce_tracks_event_properties', array( $this, 'woocommerce_tracks_event_properties' ), 10, 2 );
	}

	/**
	 * Public access to instance object.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public static function get_instance() {
		return self::$_this;
	}

	/**
	 * Processes payments.
	 * Note at this time the original source has already been
	 * saved to a customer card (if applicable) from process_payment.
	 *
	 * @since 4.0.0
	 * @since 4.1.8 Add $previous_error parameter.
	 * @param int $order_id
	 * @param bool $retry
	 * @param mix $previous_error Any error message from previous request.
	 */
	public function process_redirect_payment( $order_id, $retry = true, $previous_error = false ) {
		try {
			$source = wc_clean( $_GET['source'] );

			if ( empty( $source ) ) {
				return;
			}

			if ( empty( $order_id ) ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! is_object( $order ) ) {
				return;
			}

			if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
				return;
			}

			// Result from Criptanpay API request.
			$response = null;

			// This will throw exception if not valid.
			$this->validate_minimum_order_amount( $order );

			WC_Criptanpay_Logger::log( "Info: (Redirect) Begin processing payment for order $order_id for the amount of {$order->get_total()}" );

			/**
			 * First check if the source is chargeable at this time. If not,
			 * webhook will take care of it later.
			 */
			$source_info = WC_Criptanpay_API::retrieve( 'sources/' . $source );

			if ( ! empty( $source_info->error ) ) {
				throw new WC_Criptanpay_Exception( print_r( $source_info, true ), $source_info->error->message );
			}

			if ( 'failed' === $source_info->status || 'canceled' === $source_info->status ) {
				throw new WC_Criptanpay_Exception( print_r( $source_info, true ), __( 'Unable to process this payment, please try again or use alternative method.', 'woocommerce-gateway-criptanpay' ) );
			}

			// If already consumed, then ignore request.
			if ( 'consumed' === $source_info->status ) {
				return;
			}

			// If not chargeable, then ignore request.
			if ( 'chargeable' !== $source_info->status ) {
				return;
			}

			// Prep source object.
			$source_object           = new stdClass();
			$source_object->token_id = '';
			$source_object->customer = $this->get_criptanpay_customer_id( $order );
			$source_object->source   = $source_info->id;
			$source_object->status   = 'chargeable';

			/* If we're doing a retry and source is chargeable, we need to pass
			 * a different idempotency key and retry for success.
			 */
			if ( $this->need_update_idempotency_key( $source_object, $previous_error ) ) {
				add_filter( 'wc_criptanpay_idempotency_key', array( $this, 'change_idempotency_key' ), 10, 2 );
			}

			// Make the request.
			$response = WC_Criptanpay_API::request( $this->generate_payment_request( $order, $source_object ), 'charges', 'POST', true );
			$headers  = $response['headers'];
			$response = $response['body'];

			if ( ! empty( $response->error ) ) {
				// Customer param wrong? The user may have been deleted on criptanpay's end. Remove customer_id. Can be retried without.
				if ( $this->is_no_such_customer_error( $response->error ) ) {
					delete_user_option( $order->get_customer_id(), '_criptanpay_customer_id' );
					$order->delete_meta_data( '_criptanpay_customer_id' );
					$order->save();
				}

				if ( $this->is_no_such_token_error( $response->error ) && $prepared_source->token_id ) {
					// Source param wrong? The CARD may have been deleted on criptanpay's end. Remove token and show message.
					$wc_token = WC_Payment_Tokens::get( $prepared_source->token_id );
					$wc_token->delete();
					$localized_message = __( 'This card is no longer available and has been removed.', 'woocommerce-gateway-criptanpay' );
					$order->add_order_note( $localized_message );
					throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );
				}

				// We want to retry.
				if ( $this->is_retryable_error( $response->error ) ) {
					if ( $retry ) {
						// Don't do anymore retries after this.
						if ( 5 <= $this->retry_interval ) {
							return $this->process_redirect_payment( $order_id, false, $response->error );
						}

						sleep( $this->retry_interval );

						$this->retry_interval++;
						return $this->process_redirect_payment( $order_id, true, $response->error );
					} else {
						$localized_message = __( 'Sorry, we are unable to process your payment at this time. Please retry later.', 'woocommerce-gateway-criptanpay' );
						$order->add_order_note( $localized_message );
						throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );
					}
				}

				$localized_messages = WC_Criptanpay_Helper::get_localized_messages();

				if ( 'card_error' === $response->error->type ) {
					$message = isset( $localized_messages[ $response->error->code ] ) ? $localized_messages[ $response->error->code ] : $response->error->message;
				} else {
					$message = isset( $localized_messages[ $response->error->type ] ) ? $localized_messages[ $response->error->type ] : $response->error->message;
				}

				throw new WC_Criptanpay_Exception( print_r( $response, true ), $message );
			}

			// To prevent double processing the order on WC side.
			if ( ! $this->is_original_request( $headers ) ) {
				return;
			}

			do_action( 'wc_gateway_criptanpay_process_redirect_payment', $response, $order );

			$this->process_response( $response, $order );

		} catch ( WC_Criptanpay_Exception $e ) {
			WC_Criptanpay_Logger::log( 'Error: ' . $e->getMessage() );

			do_action( 'wc_gateway_criptanpay_process_redirect_payment_error', $e, $order );

			/* translators: error message */
			$order->update_status( 'failed', sprintf( __( 'Criptanpay payment failed: %s', 'woocommerce-gateway-criptanpay' ), $e->getLocalizedMessage() ) );

			wc_add_notice( $e->getLocalizedMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Processses the orders that are redirected.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
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
	 * @since 3.1.0
	 * @version 4.0.0
	 * @param  int $order_id
	 */
	public function capture_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( 'criptanpay' === $order->get_payment_method() ) {
			$charge             = $order->get_transaction_id();
			$captured           = $order->get_meta( '_criptanpay_charge_captured', true );
			$is_criptanpay_captured = false;

			if ( $charge && 'no' === $captured ) {
				$order_total = $order->get_total();

				if ( 0 < $order->get_total_refunded() ) {
					$order_total = $order_total - $order->get_total_refunded();
				}

				$intent = $this->get_intent_from_order( $order );
				if ( $intent ) {
					// If the order has a Payment Intent, then the Intent itself must be captured, not the Charge
					if ( ! empty( $intent->error ) ) {
						/* translators: error message */
						$order->add_order_note( sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-criptanpay' ), $intent->error->message ) );
					} elseif ( 'requires_capture' === $intent->status ) {
						$level3_data = $this->get_level3_data_from_order( $order );
						$result = WC_Criptanpay_API::request_with_level3_data(
							array(
								'amount'   => WC_Criptanpay_Helper::get_criptanpay_amount( $order_total ),
								'expand[]' => 'charges.data.balance_transaction',
							),
							'payment_intents/' . $intent->id . '/capture',
							$level3_data,
							$order
						);

						if ( ! empty( $result->error ) ) {
							/* translators: error message */
							$order->update_status( 'failed', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-criptanpay' ), $result->error->message ) );
						} else {
							$is_criptanpay_captured = true;
							$result = end( $result->charges->data );
						}
					} elseif ( 'succeeded' === $intent->status ) {
						$is_criptanpay_captured = true;
					}
				} else {
					// The order doesn't have a Payment Intent, fall back to capturing the Charge directly

					// First retrieve charge to see if it has been captured.
					$result = WC_Criptanpay_API::retrieve( 'charges/' . $charge );

					if ( ! empty( $result->error ) ) {
						/* translators: error message */
						$order->add_order_note( sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-criptanpay' ), $result->error->message ) );
					} elseif ( false === $result->captured ) {
						$level3_data = $this->get_level3_data_from_order( $order );
						$result = WC_Criptanpay_API::request_with_level3_data(
							array(
								'amount'   => WC_Criptanpay_Helper::get_criptanpay_amount( $order_total ),
								'expand[]' => 'balance_transaction',
							),
							'charges/' . $charge . '/capture',
							$level3_data,
							$order
						);

						if ( ! empty( $result->error ) ) {
							/* translators: error message */
							$order->update_status( 'failed', sprintf( __( 'Unable to capture charge! %s', 'woocommerce-gateway-criptanpay' ), $result->error->message ) );
						} else {
							$is_criptanpay_captured = true;
						}
					} elseif ( true === $result->captured ) {
						$is_criptanpay_captured = true;
					}
				}

				if ( $is_criptanpay_captured ) {
					/* translators: transaction id */
					$order->add_order_note( sprintf( __( 'Criptanpay charge complete (Charge ID: %s)', 'woocommerce-gateway-criptanpay' ), $result->id ) );
					$order->update_meta_data( '_criptanpay_charge_captured', 'yes' );

					// Store other data such as fees
					$order->set_transaction_id( $result->id );

					if ( is_callable( array( $order, 'save' ) ) ) {
						$order->save();
					}

					$this->update_fees( $order, $result->balance_transaction->id );
				}

				// This hook fires when admin manually changes order status to processing or completed.
				do_action( 'woocommerce_criptanpay_process_manual_capture', $order, $result );
			}
		}
	}

	/**
	 * Cancel pre-auth on refund/cancellation.
	 *
	 * @since 3.1.0
	 * @version 4.2.2
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

	/**
	 * Filter. Adds additional meta data to Tracks events.
	 * Note that this filter is only called if WC_Site_Tracking::is_tracking_enabled.
	 *
	 * @since 4.5.1
	 * @param array Properties to be appended to.
	 * @param string Event name, e.g. orders_edit_status_change.
	 */
	public function woocommerce_tracks_event_properties( $properties, $prefixed_event_name ) {
		// Not the desired event? Bail.
		if ( 'wcadmin_orders_edit_status_change' != $prefixed_event_name ) {
			return $properties;
		}

		// Properties not an array? Bail.
		if ( ! is_array( $properties ) ) {
			return $properties;
		}

		// No payment_method in properties? Bail.
		if ( ! array_key_exists( 'payment_method', $properties ) ) {
			return $properties;
		}

		// Not criptanpay? Bail.
		if ( 'criptanpay' != $properties[ 'payment_method' ] ) {
			return $properties;
		}

		// Due diligence done. Collect the metadata.
		$is_live         = true;
		$criptanpay_settings = get_option( 'woocommerce_criptanpay_settings', array() );
		if ( array_key_exists( 'testmode', $criptanpay_settings ) ) {
			$is_live = 'no' === $criptanpay_settings[ 'testmode' ];
		}

		$properties[ 'admin_email' ]                        = get_option( 'admin_email' );
		$properties[ 'is_live' ]                            = $is_live;
		$properties[ 'woocommerce_gateway_criptanpay_version' ] = WC_CRIPTANPAY_VERSION;
		$properties[ 'woocommerce_default_country' ]        = get_option( 'woocommerce_default_country' );

		return $properties;
	}
}

new WC_Criptanpay_Order_Handler();