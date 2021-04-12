<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Criptanpay class.
 *
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Criptanpay extends WC_Criptanpay_Payment_Gateway {
	/**
	 * The delay between retries.
	 *
	 * @var int
	 */
	public $retry_interval;

	/**
	 * Alternate credit card statement name
	 *
	 * @var bool
	 */
	public $statement_descriptor;

	/**
	 * API access secret key
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Do we accept Payment Request?
	 *
	 * @var bool
	 */
	public $payment_request;

	/**
	 * Is test mode active?
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Inline CC form styling
	 *
	 * @var string
	 */
	public $inline_cc_form;

	/**
	 * Pre Orders Object
	 *
	 * @var object
	 */
	public $pre_orders;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->retry_interval = 1;
		$this->id             = 'criptanpay';
		$this->method_title   = __( 'Criptanpay', 'woocommerce-gateway-criptanpay' );
		/* translators: 1) link to Criptanpay register page 2) link to Criptanpay api keys page */
		$this->method_description = __( 'Redirects customers to Criptanpay where they can send the cryptocurrency to your account', 'woocommerce-gateway-criptanpay' );
		$this->has_fields         = true;
		$this->supports           = array(
			'products',
			'refunds',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
			'pre-orders',
		);

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values.
		$this->title                = $this->get_option( 'title' );
		$this->description          = $this->get_option( 'description' );
		$this->enabled              = $this->get_option( 'enabled' );
		$this->testmode             = 'yes' === $this->get_option( 'testmode' );
		$this->inline_cc_form       = 'yes' === $this->get_option( 'inline_cc_form' );
		$this->statement_descriptor = WC_Criptanpay_Helper::clean_statement_descriptor( $this->get_option( 'statement_descriptor' ) );
		$this->secret_key           = $this->testmode ? $this->get_option( 'test_secret_key' ) : $this->get_option( 'secret_key' );
		$this->payment_request      = 'yes' === $this->get_option( 'payment_request', 'yes' );

		WC_Criptanpay_API::set_secret_key( $this->secret_key );

		// Hooks.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_order_payout' ), 20 );
		add_action( 'woocommerce_customer_save_address', array( $this, 'show_update_card_notice' ), 10, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'prepare_order_pay_page' ) );
		add_action( 'woocommerce_account_view-order_endpoint', array( $this, 'check_intent_status_on_order_page' ), 1 );
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'modify_successful_payment_result' ), 99999, 2 );
		add_action( 'set_logged_in_cookie', array( $this, 'set_cookie_on_current_request' ) );
		add_filter( 'woocommerce_get_checkout_payment_url', array( $this, 'get_checkout_payment_url' ), 10, 2 );
		add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'settings_api_sanitized_fields' ) );

		// Note: display error is in the parent class.
		add_action( 'admin_notices', array( $this, 'display_errors' ), 9999 );

		if ( WC_Criptanpay_Helper::is_pre_orders_exists() ) {
			$this->pre_orders = new WC_Criptanpay_Pre_Orders_Compat();

			add_action( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this->pre_orders, 'process_pre_order_release_payment' ) );
		}
	}

	/**
	 * Checks if gateway should be available to use.
	 *
	 * @since 4.0.2
	 */
	public function is_available() {
		if ( is_add_payment_method_page() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Adds a notice for customer when they update their billing address.
	 *
	 * @since 1.0.0
	 * @param int    $user_id      The ID of the current user.
	 * @param string $load_address The address to load.
	 */
	public function show_update_card_notice( $user_id, $load_address ) {
		if ( ! WC_Criptanpay_Payment_Tokens::customer_has_saved_methods( $user_id ) || 'billing' !== $load_address ) {
			return;
		}

		/* translators: 1) Opening anchor tag 2) closing anchor tag */
		wc_add_notice( sprintf( __( 'If your billing address has been changed for saved payment methods, be sure to remove any %1$ssaved payment methods%2$s on file and re-add them.', 'woocommerce-gateway-criptanpay' ), '<a href="' . esc_url( wc_get_endpoint_url( 'payment-methods' ) ) . '" class="wc-criptanpay-update-card-notice" style="text-decoration:underline;">', '</a>' ), 'notice' );
	}

	/**
	 * Get_icon function.
	 *
	 * @since 1.0.0
	 * @version 4.0.0
	 * @return string
	 */
	public function get_icon() {
		$icons = $this->payment_icons();

		$icons_str = '';

		$icons_str .= isset( $icons['visa'] ) ? $icons['visa'] : '';
		$icons_str .= isset( $icons['amex'] ) ? $icons['amex'] : '';
		$icons_str .= isset( $icons['mastercard'] ) ? $icons['mastercard'] : '';

		if ( 'USD' === get_woocommerce_currency() ) {
			$icons_str .= isset( $icons['discover'] ) ? $icons['discover'] : '';
			$icons_str .= isset( $icons['jcb'] ) ? $icons['jcb'] : '';
			$icons_str .= isset( $icons['diners'] ) ? $icons['diners'] : '';
		}

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = require( dirname( __FILE__ ) . '/admin/criptanpay-settings.php' );
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
		global $wp;
		$user                 = wp_get_current_user();
		$display_tokenization = $this->supports( 'tokenization' ) && is_checkout();
		$total                = WC()->cart->total;
		$user_email           = '';
		$description          = $this->get_description();
		$description          = ! empty( $description ) ? $description : '';
		$firstname            = '';
		$lastname             = '';

		// If paying from order, we need to get total from order not cart.
		if ( isset( $_GET['pay_for_order'] ) && ! empty( $_GET['key'] ) ) { // wpcs: csrf ok.
			$order      = wc_get_order( wc_clean( $wp->query_vars['order-pay'] ) ); // wpcs: csrf ok, sanitization ok.
			$total      = $order->get_total();
			$user_email = $order->get_billing_email();
		} else {
			if ( $user->ID ) {
				$user_email = get_user_meta( $user->ID, 'billing_email', true );
				$user_email = $user_email ? $user_email : $user->user_email;
			}
		}

		if ( is_add_payment_method_page() ) {
			$firstname       = $user->user_firstname;
			$lastname        = $user->user_lastname;
		}

		ob_start();

		echo '<div
			id="criptanpay-payment-data"
			data-email="' . esc_attr( $user_email ) . '"
			data-full-name="' . esc_attr( $firstname . ' ' . $lastname ) . '"
			data-currency="' . esc_attr( strtolower( get_woocommerce_currency() ) ) . '"
		>';

		if ( $this->testmode ) {
			/* translators: link to Criptanpay testing page */
			$description .= ' ' . sprintf( __( 'TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date or check the <a href="%s" target="_blank">Testing Criptanpay documentation</a> for more card numbers.', 'woocommerce-gateway-criptanpay' ), 'https://criptanpay.com/docs/testing' );
		}

		$description = trim( $description );

		echo apply_filters( 'wc_criptanpay_description', wpautop( wp_kses_post( $description ) ), $this->id ); // wpcs: xss ok.

		if ( $display_tokenization ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
		}

		$this->elements_form();

		if ( apply_filters( 'wc_criptanpay_display_save_payment_method_checkbox', $display_tokenization ) && ! is_add_payment_method_page() && ! isset( $_GET['change_payment_method'] ) ) { // wpcs: csrf ok.

			$this->save_payment_method_checkbox();
		}

		do_action( 'wc_criptanpay_cards_payment_fields', $this->id );

		echo '</div>';

		ob_end_flush();
	}

	/**
	 * Renders the Criptanpay elements form.
	 *
	 * @since 4.0.0
	 * @version 4.0.0
	 */
	public function elements_form() {
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">
			<?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>

			<?php if ( $this->inline_cc_form ) { ?>
				<label for="card-element">
					<?php esc_html_e( 'Credit or debit card', 'woocommerce-gateway-criptanpay' ); ?>
				</label>

				<div id="criptanpay-card-element" class="wc-criptanpay-elements-field">
				<!-- a Criptanpay Element will be inserted here. -->
				</div>
			<?php } else { ?>
				<div class="form-row form-row-wide">
					<label for="criptanpay-card-element"><?php esc_html_e( 'Card Number', 'woocommerce-gateway-criptanpay' ); ?> <span class="required">*</span></label>
					<div class="criptanpay-card-group">
						<div id="criptanpay-card-element" class="wc-criptanpay-elements-field">
						<!-- a Criptanpay Element will be inserted here. -->
						</div>

						<i class="criptanpay-credit-card-brand criptanpay-card-brand" alt="Credit Card"></i>
					</div>
				</div>

				<div class="form-row form-row-first">
					<label for="criptanpay-exp-element"><?php esc_html_e( 'Expiry Date', 'woocommerce-gateway-criptanpay' ); ?> <span class="required">*</span></label>

					<div id="criptanpay-exp-element" class="wc-criptanpay-elements-field">
					<!-- a Criptanpay Element will be inserted here. -->
					</div>
				</div>

				<div class="form-row form-row-last">
					<label for="criptanpay-cvc-element"><?php esc_html_e( 'Card Code (CVC)', 'woocommerce-gateway-criptanpay' ); ?> <span class="required">*</span></label>
				<div id="criptanpay-cvc-element" class="wc-criptanpay-elements-field">
				<!-- a Criptanpay Element will be inserted here. -->
				</div>
				</div>
				<div class="clear"></div>
			<?php } ?>

			<!-- Used to display form errors -->
			<div class="criptanpay-source-errors" role="alert"></div>
			<br />
			<?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Load admin scripts.
	 *
	 * @since 3.1.0
	 * @version 3.1.0
	 */
	public function admin_scripts() {
		if ( 'woocommerce_page_wc-settings' !== get_current_screen()->id ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'woocommerce_criptanpay_admin', plugins_url( 'assets/js/criptanpay-admin' . $suffix . '.js', WC_CRIPTANPAY_MAIN_FILE ), array(), WC_CRIPTANPAY_VERSION, true );

		$params = array(
			'time'             => time(),
			'i18n_out_of_sync' => wp_kses(
				__( '<strong>Warning:</strong> your site\'s time does not match the time on your browser and may be incorrect. Some payment methods depend on webhook verification and verifying webhooks with a signing secret depends on your site\'s time being correct, so please check your site\'s time before setting a webhook secret. You may need to contact your site\'s hosting provider to correct the site\'s time.', 'woocommerce-gateway-criptanpay' ),
				array( 'strong' => array() )
			),
		);
		wp_localize_script( 'woocommerce_criptanpay_admin', 'wc_criptanpay_settings_params', $params );

		wp_enqueue_script( 'woocommerce_criptanpay_admin' );
	}

	/**
	 * Payment_scripts function.
	 *
	 * Outputs scripts used for criptanpay payment
	 *
	 * @since 3.1.0
	 * @version 4.0.0
	 */
	public function payment_scripts() {
		global $wp;
		if (
			! is_product()
			&& ! is_cart()
			&& ! is_checkout()
			&& ! isset( $_GET['pay_for_order'] ) // wpcs: csrf ok.
			&& ! is_add_payment_method_page()
			&& ! isset( $_GET['change_payment_method'] ) // wpcs: csrf ok.
			&& ! ( ! empty( get_query_var( 'view-subscription' ) ) && is_callable( 'WCS_Early_Renewal_Manager::is_early_renewal_via_modal_enabled' ) && WCS_Early_Renewal_Manager::is_early_renewal_via_modal_enabled() )
			|| ( is_order_received_page() )
		) {
			return;
		}

		// If Criptanpay is not enabled bail.
		if ( 'no' === $this->enabled ) {
			return;
		}

		// If keys are not set bail.
		if ( ! $this->are_keys_set() ) {
			WC_Criptanpay_Logger::log( 'Keys are not set correctly.' );
			return;
		}

		// If no SSL bail.
		if ( ! $this->testmode && ! is_ssl() ) {
			WC_Criptanpay_Logger::log( 'Criptanpay live mode requires SSL.' );
			return;
		}

		$current_theme = wp_get_theme();

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_style( 'criptanpay_styles', plugins_url( 'assets/css/criptanpay-styles.css', WC_CRIPTANPAY_MAIN_FILE ), array(), WC_CRIPTANPAY_VERSION );
		wp_enqueue_style( 'criptanpay_styles' );

		wp_register_script( 'criptanpay', 'https://js.criptanpay.es/v3/', '', '1.0', true );
		wp_register_script( 'woocommerce_criptanpay', plugins_url( 'assets/js/criptanpay' . $suffix . '.js', WC_CRIPTANPAY_MAIN_FILE ), array( 'jquery-payment', 'criptanpay' ), WC_CRIPTANPAY_VERSION, true );

		$criptanpay_params = array(
			'key'                  => 'no_publishable_key',
			'i18n_terms'           => __( 'Please accept the terms and conditions first', 'woocommerce-gateway-criptanpay' ),
			'i18n_required_fields' => __( 'Please fill in required checkout fields first', 'woocommerce-gateway-criptanpay' ),
		);

		// If we're on the pay page we need to pass criptanpay.js the address of the order.
		if ( isset( $_GET['pay_for_order'] ) && 'true' === $_GET['pay_for_order'] ) { // wpcs: csrf ok.
			$order_id = wc_clean( $wp->query_vars['order-pay'] ); // wpcs: csrf ok, sanitization ok, xss ok.
			$order    = wc_get_order( $order_id );

			if ( is_a( $order, 'WC_Order' ) ) {
				$criptanpay_params['billing_first_name'] = $order->get_billing_first_name();
				$criptanpay_params['billing_last_name']  = $order->get_billing_last_name();
				$criptanpay_params['billing_address_1']  = $order->get_billing_address_1();
				$criptanpay_params['billing_address_2']  = $order->get_billing_address_2();
				$criptanpay_params['billing_state']      = $order->get_billing_state();
				$criptanpay_params['billing_city']       = $order->get_billing_city();
				$criptanpay_params['billing_postcode']   = $order->get_billing_postcode();
				$criptanpay_params['billing_country']    = $order->get_billing_country();
			}
		}

		$sepa_elements_options = apply_filters(
			'wc_criptanpay_sepa_elements_options',
			array(
				'supportedCountries' => array( 'SEPA' ),
				'placeholderCountry' => WC()->countries->get_base_country(),
				'style'              => array( 'base' => array( 'fontSize' => '15px' ) ),
			)
		);

		$criptanpay_params['no_prepaid_card_msg']       = __( 'Sorry, we\'re not accepting prepaid cards at this time. Your credit card has not been charged. Please try with alternative payment method.', 'woocommerce-gateway-criptanpay' );
		$criptanpay_params['no_sepa_owner_msg']         = __( 'Please enter your IBAN account name.', 'woocommerce-gateway-criptanpay' );
		$criptanpay_params['no_sepa_iban_msg']          = __( 'Please enter your IBAN account number.', 'woocommerce-gateway-criptanpay' );
		$criptanpay_params['payment_intent_error']      = __( 'We couldn\'t initiate the payment. Please try again.', 'woocommerce-gateway-criptanpay' );
		$criptanpay_params['sepa_mandate_notification'] = apply_filters( 'wc_criptanpay_sepa_mandate_notification', 'email' );
		$criptanpay_params['allow_prepaid_card']        = apply_filters( 'wc_criptanpay_allow_prepaid_card', true ) ? 'yes' : 'no';
		$criptanpay_params['inline_cc_form']            = $this->inline_cc_form ? 'yes' : 'no';
		$criptanpay_params['is_checkout']               = ( is_checkout() && empty( $_GET['pay_for_order'] ) ) ? 'yes' : 'no'; // wpcs: csrf ok.
		$criptanpay_params['return_url']                = $this->get_criptanpay_return_url();
		$criptanpay_params['ajaxurl']                   = WC_AJAX::get_endpoint( '%%endpoint%%' );
		$criptanpay_params['criptanpay_nonce']              = wp_create_nonce( '_wc_criptanpay_nonce' );
		$criptanpay_params['statement_descriptor']      = $this->statement_descriptor;
		$criptanpay_params['elements_options']          = apply_filters( 'wc_criptanpay_elements_options', array() );
		$criptanpay_params['sepa_elements_options']     = $sepa_elements_options;
		$criptanpay_params['invalid_owner_name']        = __( 'Billing First Name and Last Name are required.', 'woocommerce-gateway-criptanpay' );
		$criptanpay_params['is_change_payment_page']    = isset( $_GET['change_payment_method'] ) ? 'yes' : 'no'; // wpcs: csrf ok.
		$criptanpay_params['is_add_payment_page']       = is_wc_endpoint_url( 'add-payment-method' ) ? 'yes' : 'no';
		$criptanpay_params['is_pay_for_order_page']     = is_wc_endpoint_url( 'order-pay' ) ? 'yes' : 'no';
		$criptanpay_params['elements_styling']          = apply_filters( 'wc_criptanpay_elements_styling', false );
		$criptanpay_params['elements_classes']          = apply_filters( 'wc_criptanpay_elements_classes', false );
		$criptanpay_params['add_card_nonce']            = wp_create_nonce( 'wc_criptanpay_create_si' );

		// Merge localized messages to be use in JS.
		$criptanpay_params = array_merge( $criptanpay_params, WC_Criptanpay_Helper::get_localized_messages() );

		wp_localize_script( 'woocommerce_criptanpay', 'wc_criptanpay_params', apply_filters( 'wc_criptanpay_params', $criptanpay_params ) );

		$this->tokenization_script();
		wp_enqueue_script( 'woocommerce_criptanpay' );
	}

	/**
	 * Checks if a source object represents a prepaid credit card and
	 * throws an exception if it is one, but that is not allowed.
	 *
	 * @since 4.2.0
	 * @param object $prepared_source The object with source details.
	 * @throws WC_Criptanpay_Exception An exception if the card is prepaid, but prepaid cards are not allowed.
	 */
	public function maybe_disallow_prepaid_card( $prepared_source ) {
		// Check if we don't allow prepaid credit cards.
		if ( apply_filters( 'wc_criptanpay_allow_prepaid_card', true ) || ! $this->is_prepaid_card( $prepared_source->source_object ) ) {
			return;
		}

		$localized_message = __( 'Sorry, we\'re not accepting prepaid cards at this time. Your credit card has not been charged. Please try with alternative payment method.', 'woocommerce-gateway-criptanpay' );
		throw new WC_Criptanpay_Exception( print_r( $prepared_source->source_object, true ), $localized_message );
	}

	/**
	 * Checks whether a source exists.
	 *
	 * @since 4.2.0
	 * @param  object $prepared_source The source that should be verified.
	 * @throws WC_Criptanpay_Exception     An exception if the source ID is missing.
	 */
	public function check_source( $prepared_source ) {
		if ( empty( $prepared_source->source ) ) {
			$localized_message = __( 'Payment processing failed. Please retry.', 'woocommerce-gateway-criptanpay' );
			throw new WC_Criptanpay_Exception( print_r( $prepared_source, true ), $localized_message );
		}
	}

	/**
	 * Customer param wrong? The user may have been deleted on criptanpay's end. Remove customer_id. Can be retried without.
	 *
	 * @since 4.2.0
	 * @param object   $error The error that was returned from Criptanpay's API.
	 * @param WC_Order $order The order those payment is being processed.
	 * @return bool           A flag that indicates that the customer does not exist and should be removed.
	 */
	public function maybe_remove_non_existent_customer( $error, $order ) {
		if ( ! $this->is_no_such_customer_error( $error ) ) {
			return false;
		}

		delete_user_option( $order->get_customer_id(), '_criptanpay_customer_id' );
		$order->delete_meta_data( '_criptanpay_customer_id' );
		$order->save();

		return true;
	}

	/**
	 * Completes an order without a positive value.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order             The order to complete.
	 * @param WC_Order $prepared_source   Payment source and customer data.
	 * @param boolean  $force_save_source Whether the payment source must be saved, like when dealing with a Subscription setup.
	 * @return array                      Redirection data for `process_payment`.
	 */
	public function complete_free_order( $order, $prepared_source, $force_save_source ) {
		if ( $force_save_source ) {
			$intent_secret = $this->setup_intent( $order, $prepared_source );

			if ( ! empty( $intent_secret ) ) {
				// `get_return_url()` must be called immediately before returning a value.
				return array(
					'result'              => 'success',
					'redirect'            => $this->get_return_url( $order ),
					'setup_intent_secret' => $intent_secret,
				);
			}
		}

		// Remove cart.
		WC()->cart->empty_cart();

		$order->payment_complete();

		// Return thank you page redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process the payment
	 *
	 * @since 1.0.0
	 * @since 1.0.0 Add 4th parameter to track previous error.
	 * @param int  $order_id Reference.
	 * @param bool $retry Should we retry on fail.
	 * @param bool $force_save_source Force save the payment source.
	 * @param mix  $previous_error Any error message from previous request.
	 * @param bool $use_order_source Whether to use the source, which should already be attached to the order.
	 *
	 * @throws Exception If payment will not be accepted.
	 * @return array|void
	 */
	public function process_payment( $order_id, $retry = true, $force_save_source = false, $previous_error = false, $use_order_source = false ) {
		try {
			$order = wc_get_order( $order_id );

			// ToDo: `process_pre_order` saves the source to the order for a later payment.
			// This might not work well with PaymentIntents.
			if ( $this->maybe_process_pre_orders( $order_id ) ) {
				return $this->pre_orders->process_pre_order( $order_id );
			}

			// Check whether there is an existing intent.
			$intent = $this->get_intent_from_order( $order );
			if ( isset( $intent->object ) && 'setup_intent' === $intent->object ) {
				$intent = false; // This function can only deal with *payment* intents
			}

			$criptanpay_customer_id = null;
			if ( $intent && ! empty( $intent->customer ) ) {
				$criptanpay_customer_id = $intent->customer;
			}

			// For some payments the source should already be present in the order.
			if ( $use_order_source ) {
				$prepared_source = $this->prepare_order_source( $order );
			} else {
				$prepared_source = $this->prepare_source( get_current_user_id(), $force_save_source, $criptanpay_customer_id );
			}

			$this->maybe_disallow_prepaid_card( $prepared_source );
			$this->check_source( $prepared_source );
			$this->save_source_to_order( $order, $prepared_source );

			if ( 0 >= $order->get_total() ) {
				return $this->complete_free_order( $order, $prepared_source, $force_save_source );
			}

			// This will throw exception if not valid.
			$this->validate_minimum_order_amount( $order );

			WC_Criptanpay_Logger::log( "Info: Begin processing payment for order $order_id for the amount of {$order->get_total()}" );

			if ( $intent ) {
				$intent = $this->update_existing_intent( $intent, $order, $prepared_source );
			} else {
				$intent = $this->create_intent( $order, $prepared_source );
			}

			// Confirm the intent after locking the order to make sure webhooks will not interfere.
			if ( empty( $intent->error ) ) {
				$this->lock_order_payment( $order, $intent );
				$intent = $this->confirm_intent( $intent, $order, $prepared_source );
			}

			if ( ! empty( $intent->error ) ) {
				$this->maybe_remove_non_existent_customer( $intent->error, $order );

				// We want to retry.
				if ( $this->is_retryable_error( $intent->error ) ) {
					return $this->retry_after_error( $intent, $order, $retry, $force_save_source, $previous_error, $use_order_source );
				}

				$this->unlock_order_payment( $order );
				$this->throw_localized_message( $intent, $order );
			}

			if ( ! empty( $intent ) ) {
				// Use the last charge within the intent to proceed.
				$response = end( $intent->charges->data );

				// If the intent requires a 3DS flow, redirect to it.
				if ( 'requires_action' === $intent->status ) {
					$this->unlock_order_payment( $order );

					if ( is_wc_endpoint_url( 'order-pay' ) ) {
						$redirect_url = add_query_arg( 'wc-criptanpay-confirmation', 1, $order->get_checkout_payment_url( false ) );

						return array(
							'result'   => 'success',
							'redirect' => $redirect_url,
						);
					} else {
						/**
						 * This URL contains only a hash, which will be sent to `checkout.js` where it will be set like this:
						 * `window.location = result.redirect`
						 * Once this redirect is sent to JS, the `onHashChange` function will execute `handleCardPayment`.
						 */

						return array(
							'result'                => 'success',
							'redirect'              => $this->get_return_url( $order ),
							'payment_intent_secret' => $intent->client_secret,
						);
					}
				}
			}

			// Process valid response.
			$this->process_response( $response, $order );

			// Remove cart.
			if ( isset( WC()->cart ) ) {
				WC()->cart->empty_cart();
			}

			// Unlock the order.
			$this->unlock_order_payment( $order );

			// Return thank you page redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);

		} catch ( WC_Criptanpay_Exception $e ) {
			wc_add_notice( $e->getLocalizedMessage(), 'error' );
			WC_Criptanpay_Logger::log( 'Error: ' . $e->getMessage() );

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
	 * Displays the Criptanpay fee
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_fee( $order_id ) {
		if ( apply_filters( 'wc_criptanpay_hide_display_order_fee', false, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$fee      = WC_Criptanpay_Helper::get_criptanpay_fee( $order );
		$currency = WC_Criptanpay_Helper::get_criptanpay_currency( $order );

		if ( ! $fee || ! $currency ) {
			return;
		}

		?>

		<tr>
			<td class="label criptanpay-fee">
				<?php echo wc_help_tip( __( 'This represents the fee Criptanpay collects for the transaction.', 'woocommerce-gateway-criptanpay' ) ); // wpcs: xss ok. ?>
				<?php esc_html_e( 'Criptanpay Fee:', 'woocommerce-gateway-criptanpay' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				-&nbsp;<?php echo wc_price( $fee, array( 'currency' => $currency ) ); // wpcs: xss ok. ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Displays the net total of the transaction without the charges of Criptanpay.
	 *
	 * @since 1.0.0
	 *
	 * @param int $order_id The ID of the order.
	 */
	public function display_order_payout( $order_id ) {
		if ( apply_filters( 'wc_criptanpay_hide_display_order_payout', false, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$net      = WC_Criptanpay_Helper::get_criptanpay_net( $order );
		$currency = WC_Criptanpay_Helper::get_criptanpay_currency( $order );

		if ( ! $net || ! $currency ) {
			return;
		}

		?>

		<tr>
			<td class="label criptanpay-payout">
				<?php echo wc_help_tip( __( 'This represents the net total that will be credited to your Criptanpay bank account. This may be in the currency that is set in your Criptanpay account.', 'woocommerce-gateway-criptanpay' ) ); // wpcs: xss ok. ?>
				<?php esc_html_e( 'Criptanpay Payout:', 'woocommerce-gateway-criptanpay' ); ?>
			</td>
			<td width="1%"></td>
			<td class="total">
				<?php echo wc_price( $net, array( 'currency' => $currency ) ); // wpcs: xss ok. ?>
			</td>
		</tr>

		<?php
	}

	/**
	 * Generates a localized message for an error from a response.
	 *
	 * @since 4.3.2
	 *
	 * @param stdClass $response The response from the Criptanpay API.
	 *
	 * @return string The localized error message.
	 */
	public function get_localized_error_message_from_response( $response ) {
		$localized_messages = WC_Criptanpay_Helper::get_localized_messages();

		if ( 'card_error' === $response->error->type ) {
			$localized_message = isset( $localized_messages[ $response->error->code ] ) ? $localized_messages[ $response->error->code ] : $response->error->message;
		} else {
			$localized_message = isset( $localized_messages[ $response->error->type ] ) ? $localized_messages[ $response->error->type ] : $response->error->message;
		}

		return $localized_message;
	}

	/**
	 * Gets a localized message for an error from a response, adds it as a note to the order, and throws it.
	 *
	 * @since 4.2.0
	 * @param  stdClass $response  The response from the Criptanpay API.
	 * @param  WC_Order $order     The order to add a note to.
	 * @throws WC_Criptanpay_Exception An exception with the right message.
	 */
	public function throw_localized_message( $response, $order ) {
		$localized_message = $this->get_localized_error_message_from_response( $response );

		$order->add_order_note( $localized_message );

		throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message );
	}

	/**
	 * Retries the payment process once an error occured.
	 *
	 * @since 4.2.0
	 * @param object   $response          The response from the Criptanpay API.
	 * @param WC_Order $order             An order that is being paid for.
	 * @param bool     $retry             A flag that indicates whether another retry should be attempted.
	 * @param bool     $force_save_source Force save the payment source.
	 * @param mixed    $previous_error    Any error message from previous request.
	 * @param bool     $use_order_source  Whether to use the source, which should already be attached to the order.
	 * @throws WC_Criptanpay_Exception        If the payment is not accepted.
	 * @return array|void
	 */
	public function retry_after_error( $response, $order, $retry, $force_save_source, $previous_error, $use_order_source ) {
		if ( ! $retry ) {
			$localized_message = __( 'Sorry, we are unable to process your payment at this time. Please retry later.', 'woocommerce-gateway-criptanpay' );
			$order->add_order_note( $localized_message );
			throw new WC_Criptanpay_Exception( print_r( $response, true ), $localized_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.
		}

		// Don't do anymore retries after this.
		if ( 5 <= $this->retry_interval ) {
			return $this->process_payment( $order->get_id(), false, $force_save_source, $response->error, $previous_error );
		}

		sleep( $this->retry_interval );
		$this->retry_interval++;

		return $this->process_payment( $order->get_id(), true, $force_save_source, $response->error, $previous_error, $use_order_source );
	}

	/**
	 * Adds the necessary hooks to modify the "Pay for order" page in order to clean
	 * it up and prepare it for the Criptanpay PaymentIntents modal to confirm a payment.
	 *
	 * @since 4.2
	 * @param WC_Payment_Gateway[] $gateways A list of all available gateways.
	 * @return WC_Payment_Gateway[]          Either the same list or an empty one in the right conditions.
	 */
	public function prepare_order_pay_page( $gateways ) {
		if ( ! is_wc_endpoint_url( 'order-pay' ) || ! isset( $_GET['wc-criptanpay-confirmation'] ) ) { // wpcs: csrf ok.
			return $gateways;
		}

		try {
			$this->prepare_intent_for_order_pay_page();
		} catch ( WC_Criptanpay_Exception $e ) {
			// Just show the full order pay page if there was a problem preparing the Payment Intent
			return $gateways;
		}

		add_filter( 'woocommerce_checkout_show_terms', '__return_false' );
		add_filter( 'woocommerce_pay_order_button_html', '__return_false' );
		add_filter( 'woocommerce_available_payment_gateways', '__return_empty_array' );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( $this, 'change_no_available_methods_message' ) );
		add_action( 'woocommerce_pay_order_after_submit', array( $this, 'render_payment_intent_inputs' ) );

		return array();
	}

	/**
	 * Changes the text of the "No available methods" message to one that indicates
	 * the need for a PaymentIntent to be confirmed.
	 *
	 * @since 4.2
	 * @return string the new message.
	 */
	public function change_no_available_methods_message() {
		return wpautop( __( "Almost there!\n\nYour order has already been created, the only thing that still needs to be done is for you to authorize the payment with your bank.", 'woocommerce-gateway-criptanpay' ) );
	}

	/**
	 * Prepares the Payment Intent for it to be completed in the "Pay for Order" page.
	 *
	 * @param WC_Order|null $order Order object, or null to get the order from the "order-pay" URL parameter
	 *
	 * @throws WC_Criptanpay_Exception
	 * @since 4.3
	 */
	public function prepare_intent_for_order_pay_page( $order = null ) {
		if ( ! isset( $order ) || empty( $order ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		}
		$intent = $this->get_intent_from_order( $order );

		if ( ! $intent ) {
			throw new WC_Criptanpay_Exception( 'Payment Intent not found', __( 'Payment Intent not found for order #' . $order->get_id(), 'woocommerce-gateway-criptanpay' ) );
		}

		if ( 'requires_payment_method' === $intent->status && isset( $intent->last_payment_error )
		     && 'authentication_required' === $intent->last_payment_error->code ) {
			$level3_data = $this->get_level3_data_from_order( $order );
			$intent      = WC_Criptanpay_API::request_with_level3_data(
				array(
					'payment_method' => $intent->last_payment_error->source->id,
				),
				'payment_intents/' . $intent->id . '/confirm',
				$level3_data,
				$order
			);

			if ( isset( $intent->error ) ) {
				throw new WC_Criptanpay_Exception( print_r( $intent, true ), $intent->error->message );
			}
		}

		$this->order_pay_intent = $intent;
	}

	/**
	 * Renders hidden inputs on the "Pay for Order" page in order to let Criptanpay handle PaymentIntents.
	 *
	 * @param WC_Order|null $order Order object, or null to get the order from the "order-pay" URL parameter
	 *
	 * @throws WC_Criptanpay_Exception
	 * @since 4.2
	 */
	public function render_payment_intent_inputs( $order = null ) {
		if ( ! isset( $order ) || empty( $order ) ) {
			$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
		}
		if ( ! isset( $this->order_pay_intent ) ) {
			$this->prepare_intent_for_order_pay_page( $order );
		}

		$verification_url = add_query_arg(
			array(
				'order'            => $order->get_id(),
				'nonce'            => wp_create_nonce( 'wc_criptanpay_confirm_pi' ),
				'redirect_to'      => rawurlencode( $this->get_return_url( $order ) ),
				'is_pay_for_order' => true,
			),
			WC_AJAX::get_endpoint( 'wc_criptanpay_verify_intent' )
		);

		echo '<input type="hidden" id="criptanpay-intent-id" value="' . esc_attr( $this->order_pay_intent->client_secret ) . '" />';
		echo '<input type="hidden" id="criptanpay-intent-return" value="' . esc_attr( $verification_url ) . '" />';
	}

	/**
	 * Adds an error message wrapper to each saved method.
	 *
	 * @since 4.2.0
	 * @param WC_Payment_Token $token Payment Token.
	 * @return string                 Generated payment method HTML
	 */
	public function get_saved_payment_method_option_html( $token ) {
		$html          = parent::get_saved_payment_method_option_html( $token );
		$error_wrapper = '<div class="criptanpay-source-errors" role="alert"></div>';

		return preg_replace( '~</(\w+)>\s*$~', "$error_wrapper</$1>", $html );
	}

	/**
	 * Attempt to manually complete the payment process for orders, which are still pending
	 * before displaying the View Order page. This is useful in case webhooks have not been set up.
	 *
	 * @since 4.2.0
	 * @param int $order_id The ID that will be used for the thank you page.
	 */
	public function check_intent_status_on_order_page( $order_id ) {
		if ( empty( $order_id ) || absint( $order_id ) <= 0 ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order ) {
			return;
		}

		$this->verify_intent_after_checkout( $order );
	}

	/**
	 * Attached to `woocommerce_payment_successful_result` with a late priority,
	 * this method will combine the "naturally" generated redirect URL from
	 * WooCommerce and a payment/setup intent secret into a hash, which contains both
	 * the secret, and a proper URL, which will confirm whether the intent succeeded.
	 *
	 * @since 4.2.0
	 * @param array $result   The result from `process_payment`.
	 * @param int   $order_id The ID of the order which is being paid for.
	 * @return array
	 */
	public function modify_successful_payment_result( $result, $order_id ) {
		if ( ! isset( $result['payment_intent_secret'] ) && ! isset( $result['setup_intent_secret'] ) ) {
			// Only redirects with intents need to be modified.
			return $result;
		}

		// Put the final thank you page redirect into the verification URL.
		$verification_url = add_query_arg(
			array(
				'order'       => $order_id,
				'nonce'       => wp_create_nonce( 'wc_criptanpay_confirm_pi' ),
				'redirect_to' => rawurlencode( $result['redirect'] ),
			),
			WC_AJAX::get_endpoint( 'wc_criptanpay_verify_intent' )
		);

		if ( isset( $result['payment_intent_secret'] ) ) {
			$redirect = sprintf( '#confirm-pi-%s:%s', $result['payment_intent_secret'], rawurlencode( $verification_url ) );
		} else if ( isset( $result['setup_intent_secret'] ) ) {
			$redirect = sprintf( '#confirm-si-%s:%s', $result['setup_intent_secret'], rawurlencode( $verification_url ) );
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Proceed with current request using new login session (to ensure consistent nonce).
	 */
	public function set_cookie_on_current_request( $cookie ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	}

	/**
	 * Executed between the "Checkout" and "Thank you" pages, this
	 * method updates orders based on the status of associated PaymentIntents.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order The order which is in a transitional state.
	 */
	public function verify_intent_after_checkout( $order ) {
		$payment_method = $order->get_payment_method();
		if ( $payment_method !== $this->id ) {
			// If this is not the payment method, an intent would not be available.
			return;
		}

		$intent = $this->get_intent_from_order( $order );
		if ( ! $intent ) {
			// No intent, redirect to the order received page for further actions.
			return;
		}

		// A webhook might have modified or locked the order while the intent was retreived. This ensures we are reading the right status.
		clean_post_cache( $order->get_id() );
		$order = wc_get_order( $order->get_id() );

		if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
			// If payment has already been completed, this function is redundant.
			return;
		}

		if ( $this->lock_order_payment( $order, $intent ) ) {
			return;
		}

		if ( 'setup_intent' === $intent->object && 'succeeded' === $intent->status ) {
			WC()->cart->empty_cart();
			if ( WC_Criptanpay_Helper::is_pre_orders_exists() && WC_Pre_Orders_Order::order_contains_pre_order( $order ) ) {
				WC_Pre_Orders_Order::mark_order_as_pre_ordered( $order );
			} else {
				$order->payment_complete();
			}
		} else if ( 'succeeded' === $intent->status || 'requires_capture' === $intent->status ) {
			// Proceed with the payment completion.
			$this->handle_intent_verification_success( $order, $intent );
		} else if ( 'requires_payment_method' === $intent->status ) {
			// `requires_payment_method` means that SCA got denied for the current payment method.
			$this->handle_intent_verification_failure( $order, $intent );
		}

		$this->unlock_order_payment( $order );
	}

	/**
	 * Called after an intent verification succeeds, this allows
	 * specific APNs or children of this class to modify its behavior.
	 *
	 * @param WC_Order $order The order whose verification succeeded.
	 * @param stdClass $intent The Payment Intent object.
	 */
	protected function handle_intent_verification_success( $order, $intent ) {
		$this->process_response( end( $intent->charges->data ), $order );
	}

	/**
	 * Called after an intent verification fails, this allows
	 * specific APNs or children of this class to modify its behavior.
	 *
	 * @param WC_Order $order The order whose verification failed.
	 * @param stdClass $intent The Payment Intent object.
	 */
	protected function handle_intent_verification_failure( $order, $intent ) {
		$this->failed_sca_auth( $order, $intent );
	}

	/**
	 * Checks if the payment intent associated with an order failed and records the event.
	 *
	 * @since 4.2.0
	 * @param WC_Order $order  The order which should be checked.
	 * @param object   $intent The intent, associated with the order.
	 */
	public function failed_sca_auth( $order, $intent ) {
		// If the order has already failed, do not repeat the same message.
		if ( $order->has_status( 'failed' ) ) {
			return;
		}

		// Load the right message and update the status.
		$status_message = isset( $intent->last_payment_error )
			/* translators: 1) The error message that was received from Criptanpay. */
			? sprintf( __( 'Criptanpay SCA authentication failed. Reason: %s', 'woocommerce-gateway-criptanpay' ), $intent->last_payment_error->message )
			: __( 'Criptanpay SCA authentication failed.', 'woocommerce-gateway-criptanpay' );
		$order->update_status( 'failed', $status_message );
	}

	/**
	 * Preserves the "wc-criptanpay-confirmation" URL parameter so the user can complete the SCA authentication after logging in.
	 *
	 * @param string $pay_url Current computed checkout URL for the given order.
	 * @param WC_Order $order Order object.
	 *
	 * @return string Checkout URL for the given order.
	 */
	public function get_checkout_payment_url( $pay_url, $order ) {
		global $wp;
		if ( isset( $_GET['wc-criptanpay-confirmation'] ) && isset( $wp->query_vars['order-pay'] ) && $wp->query_vars['order-pay'] == $order->get_id() ) {
			$pay_url = add_query_arg( 'wc-criptanpay-confirmation', 1, $pay_url );
		}
		return $pay_url;
	}

	/**
	 * Checks whether new keys are being entered when saving options.
	 */
	public function process_admin_options() {
		// Load all old values before the new settings get saved.
		$old_secret_key           = $this->get_option( 'secret_key' );
		$old_test_secret_key      = $this->get_option( 'test_secret_key' );

		parent::process_admin_options();

		// Load all old values after the new settings have been saved.
		$new_secret_key           = $this->get_option( 'secret_key' );
		$new_test_secret_key      = $this->get_option( 'test_secret_key' );

		// Checks whether a value has transitioned from a non-empty value to a new one.
		$has_changed = function( $old_value, $new_value ) {
			return ! empty( $old_value ) && ( $old_value !== $new_value );
		};

		// Look for updates.
		if (
			$has_changed( $old_secret_key, $new_secret_key )
			|| $has_changed( $old_test_secret_key, $new_test_secret_key )
		) {
			update_option( 'wc_criptanpay_show_changed_keys_notice', 'yes' );
		}
	}

	public function validate_secret_key_field( $key, $value ) {
		$value = $this->validate_text_field( $key, $value );

		return $value;
	}

	public function validate_test_secret_key_field( $key, $value ) {
		$value = $this->validate_text_field( $key, $value );

		return $value;
	}

	/**
	 * Ensures the statement descriptor about to be saved to options does not contain any invalid characters.
	 *
	 * @since 4.8.0
	 * @param $settings WC_Settings_API settings to be filtered
	 * @return Filtered settings
	 */
	public function settings_api_sanitized_fields( $settings ) {
		if ( is_array( $settings ) ) {
			if ( array_key_exists( 'statement_descriptor', $settings ) ) {
				$settings['statement_descriptor'] = WC_Criptanpay_Helper::clean_statement_descriptor( $settings['statement_descriptor']);
			}
		}
		return $settings;
	}
}
