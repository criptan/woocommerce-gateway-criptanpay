<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


return apply_filters(
	'wc_criptanpay_settings',
	array(
		'enabled'                       => array(
			'title'       => __( 'Enable/Disable', 'woocommerce-gateway-criptanpay' ),
			'label'       => __( 'Enable Criptanpay', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'checkbox',
			'description' => '',
			'default'     => 'no',
		),
		'title'                         => array(
			'title'       => __( 'Title', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'text',
			'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-gateway-criptanpay' ),
			'default'     => __( 'CriptanPay cryptocurrency payments', 'woocommerce-gateway-criptanpay' ),
			'desc_tip'    => true,
		),
		'description'                   => array(
			'title'       => __( 'Description', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'text',
			'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-gateway-criptanpay' ),
			'default'     => __( 'Pay with cryptocurrency via Criptanpay.', 'woocommerce-gateway-criptanpay' ),
			'desc_tip'    => true,
		),
		'webhook'                       => array(
			'title'       => __( 'Webhook settings', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'title',
			'description' => $this->display_admin_settings_webhook_description(),
		),
		'webhook_url'                         => array(
			'title'       => __( 'Endpoint URL', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'text',
			'description' => __( 'Copy the Webhook endpoint URL to your Criptanpay settings panel.', 'woocommerce-gateway-criptanpay' ),
			'default'     => $this->display_admin_settings_webhook_url(),
			'desc_tip'    => true,
			'custom_attributes' => array( 'readonly' => 'readonly' ),
		),
		'webhook_secret_key'                         => array(
			'title'       => __( 'Secret key', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'text',
			'description' => __( 'Copy the secret key to your Criptanpay settings panel.', 'woocommerce-gateway-criptanpay' ),
			'default'     => $this->display_admin_settings_webhook_secret_key(),
			'desc_tip'    => true,
		),
		'api_credentials'               => array(
			'title'       => __( 'Criptanpay Account Keys', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'title',
			'description' => __( '', 'woocommerce-gateway-criptanpay' ),
		),
		'testmode'                      => array(
			'title'       => __( 'Test mode', 'woocommerce-gateway-criptanpay' ),
			'label'       => __( 'Enable Test Mode', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'checkbox',
			'description' => __( 'Place the payment gateway in test mode using test API keys.', 'woocommerce-gateway-criptanpay' ),
			'default'     => 'yes',
			'desc_tip'    => true,
		),
		'test_secret_key'               => array(
			'title'       => __( 'Test Secret Key', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'password',
			'description' => __( 'Get your API keys from your criptanpay account. Invalid values will be rejected. Only values starting with "sk_test_" or "rk_test_" will be saved.', 'woocommerce-gateway-criptanpay' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'secret_key'                    => array(
			'title'       => __( 'Live Secret API Key', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'password',
			'description' => __( 'Get your API keys from your criptanpay account. Invalid values will be rejected. Only values starting with "sk_live_" or "rk_live_" will be saved.', 'woocommerce-gateway-criptanpay' ),
			'default'     => '',
			'desc_tip'    => true,
		),
		'logging'                       => array(
			'title'       => __( 'Logging', 'woocommerce-gateway-criptanpay' ),
			'label'       => __( 'Log debug messages', 'woocommerce-gateway-criptanpay' ),
			'type'        => 'checkbox',
			'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'woocommerce-gateway-criptanpay' ),
			'default'     => 'no',
			'desc_tip'    => true,
		),
	)
);
