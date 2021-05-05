<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Criptanpay_API class.
 *
 * Communicates with Criptanpay API.
 */
class WC_Criptanpay_API {

	/**
	 * Criptanpay API Endpoint
	 */
	const ENDPOINT           = 'https://p2d9p00zue.execute-api.eu-central-1.amazonaws.com/v1/business/';
	const CRIPTANPAY_API_VERSION = '2021-01-0';


	/**
	 * Webhook Secret Key.
	 * @var string
	 */
	private static $webhook_secret_key = '';


	/**
	 * Secret API Key.
	 * @var string
	 */
	private static $secret_key = '';

	/**
	 * Set Webhook Secret Key.
	 * @param string $key
	 */
	public static function set_webhook_secret_key( $webhook_secret_key ) {
		self::$webhook_secret_key = $webhook_secret_key;
	}

	/**
	 * Get Webhook Secret Key.
	 * @return string
	 */
	public static function get_webhook_secret_key() {
		if ( ! self::$webhook_secret_key ) {
			$options = get_option( 'woocommerce_criptanpay_settings' );
			self::set_webhook_secret_key( empty( $options['webhook_secret_key'] ) ? wp_generate_password(24) : $options['webhook_secret_key'] );
		}

		return self::$webhook_secret_key;
	}

	/**
	 * Set secret API Key.
	 * @param string $key
	 */
	public static function set_secret_key( $secret_key ) {
		self::$secret_key = $secret_key;
	}

	/**
	 * Get secret key.
	 * @return string
	 */
	public static function get_secret_key() {
		if ( ! self::$secret_key ) {
			$options = get_option( 'woocommerce_criptanpay_settings' );

			if ( isset( $options['testmode'], $options['secret_key'], $options['test_secret_key'] ) ) {
				self::set_secret_key( 'yes' === $options['testmode'] ? $options['test_secret_key'] : $options['secret_key'] );
			}
		}
		
		return self::$secret_key;
	}

	/**
	 * Generates the headers to pass to API request.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 */
	public static function get_headers() {

		return apply_filters(
			'woocommerce_criptanpay_request_headers',
			array(
				'x-api-key'				=> self::get_secret_key(),
				'Content-Type'			=> 'application/json',
				'Criptanpay-Version' 	=> self::CRIPTANPAY_API_VERSION
			)
		);
	}

	/**
	 * Send the request to Criptanpay's API
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param array $request
	 * @param string $api
	 * @param string $method
	 * @param bool $with_headers To get the response with headers.
	 * @return stdClass|array
	 * @throws WC_Criptanpay_Exception
	 */
	public static function request( $request, $api_url, $endpoint, $method = 'POST', $with_headers = false ) {
		WC_Criptanpay_Logger::log( "{$endpoint} request: " . print_r( $request, true ) );

		$headers         = self::get_headers();
		$request = apply_filters( 'woocommerce_criptanpay_request_body', $request, $endpoint );
		$jsonRequestBody = json_encode( $request );
		
		$response = wp_safe_remote_post(
			$api_url . $endpoint,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $jsonRequestBody,
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) || isset( $response->errors ) ) {

			WC_Criptanpay_Logger::log(
				'Error Response: ' . print_r( $response, true ) . PHP_EOL . PHP_EOL . 'Failed request: ' . print_r(
					array(
						'api'             => $endpoint,
						'request'         => $request,
					),
					true
				)
			);

			throw new WC_Criptanpay_Exception( print_r( $response, true ), __( 'There was a problem connecting to the Criptanpay API endpoint.', 'woocommerce-gateway-criptanpay' ) );
		}

		if ( $with_headers ) {
			return array(
				'headers' => wp_remote_retrieve_headers( $response ),
				'body'    => json_decode( $response['body'] ),
			);
		}

		return json_decode( $response['body'] );
	}

	/**
	 * Retrieve API endpoint.
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @param string $api_url
	 */
	public static function retrieve( $api_url ) {
		WC_Criptanpay_Logger::log( "{$api_url}" );

		$response = wp_safe_remote_get(
			self::ENDPOINT . $api_url,
			array(
				'method'  => 'GET',
				'headers' => self::get_headers(),
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['body'] ) ) {
			WC_Criptanpay_Logger::log( 'Error Response: ' . print_r( $response, true ) );
			return new WP_Error( 'criptanpay_error', __( 'There was a problem connecting to the Criptanpay API endpoint.', 'woocommerce-gateway-criptanpay' ) );
		}

		return json_decode( $response['body'] );
	}

}
