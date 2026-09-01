<?php
/**
 * WooCommerce exemption for the bot-block rate limiter.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Bot_WooCommerce
 *
 * The rate limiter inspects every POST regardless of endpoint, which is
 * fine for a contact form but too strict for WooCommerce's POST-heavy
 * cart/checkout flow. This exempts WooCommerce's own traffic via the
 * apb_rate_limit_exempt filter rather than touching the limiter's core
 * logic. Only loaded when WooCommerce is active.
 */
class AppPresser_Bot_WooCommerce {

	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'apb_rate_limit_exempt', array( $this, 'exempt_woocommerce_requests' ), 10, 2 );
	}

	/**
	 * Exempt WooCommerce's classic AJAX and Store API traffic.
	 *
	 * @param bool   $exempt Whether the request is already exempt.
	 * @param string $ip     Client IP.
	 * @return bool
	 */
	public function exempt_woocommerce_requests( $exempt, $ip ) {
		if ( $exempt ) {
			return $exempt;
		}

		// Classic AJAX: admin-ajax.php?action=woocommerce_* / ?wc-ajax=*.
		if ( ! empty( $_REQUEST['wc-ajax'] ) ) {
			return true;
		}

		if ( isset( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'woocommerce_' ) ) {
			return true;
		}

		// WooCommerce Blocks / Store API.
		if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wc/store/' ) ) {
			return true;
		}

		return $exempt;
	}
}
