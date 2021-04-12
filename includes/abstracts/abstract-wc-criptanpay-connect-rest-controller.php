<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Criptanpay Connect base REST controller class.
 */
abstract class WC_Criptanpay_Connect_REST_Controller extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v1';

	/**
	 * Criptanpay connect api.
	 *
	 * @var object $api
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param WC_Criptanpay_Connect_API $api criptanpay connect api.
	 */
	public function __construct( WC_Criptanpay_Connect_API $api ) {

		$this->api = $api;
	}

	/**
	 * Registers rest routes for criptanpay connect functionality
	 */
	public function register_routes() {

		if ( method_exists( $this, 'get' ) ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( $this, 'get_internal' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}

		if ( method_exists( $this, 'post' ) ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'post_internal' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}

		if ( method_exists( $this, 'delete' ) ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				array(
					array(
						'methods'             => 'DELETE',
						'callback'            => array( $this, 'delete_internal' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}
	}

	/**
	 * Send get request.
	 *
	 * @param array $request request.
	 *
	 * @return array
	 */
	public function get_internal( $request ) {

		$this->prevent_route_caching();

		return $this->get( $request );
	}

	/**
	 * Send post request.
	 *
	 * @param array $request request.
	 *
	 * @return array
	 */
	public function post_internal( $request ) {

		$this->prevent_route_caching();

		return $this->post( $request );
	}

	/**
	 * Sends delete request.
	 *
	 * @param array $request request.
	 *
	 * @return array
	 */
	public function delete_internal( $request ) {

		$this->prevent_route_caching();

		return $this->delete( $request );
	}

	/**
	 * Validate the requester's permissions
	 *
	 * @param array $request request.
	 *
	 * @return bool
	 */
	public function check_permission( $request ) {

		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Consolidate cache prevention mechanisms.
	 */
	public function prevent_route_caching() {

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Play nice with WP-Super-Cache.
		}

		// Prevent our REST API endpoint responses from being added to browser cache.
		add_filter( 'rest_post_dispatch', array( $this, 'send_nocache_header' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Send a no-cache header for WCS REST API responses. Prompted by cache issues
	 * on the Pantheon hosting platform.
	 *
	 * See: https://pantheon.io/docs/cache-control/
	 *
	 * @param  WP_REST_Response $response REST API response.
	 * @param  WP_REST_Server   $server   server.
	 *
	 * @return WP_REST_Response passthrough $response parameter
	 */
	public function send_nocache_header( $response, $server ) {

		$server->send_header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );

		return $response;
	}
}