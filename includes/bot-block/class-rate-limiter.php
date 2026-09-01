<?php
/**
 * POST rate limiter + IP ban enforcement.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Bot_Rate_Limiter
 *
 * Rate-limits and bans abusive POST requests. Has no Gravity Forms or
 * WooCommerce dependency; those integrate via the `apb_rate_limit_exempt`
 * filter and Gravity Forms hooks in their own classes.
 */
class AppPresser_Bot_Rate_Limiter {

	const TRANSIENT_PREFIX = 'apppresser_bot_rl_';
	const COOKIE_NAME      = 'apppresser_bot_rl_uid';

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
		add_action( 'init', array( $this, 'maybe_enforce_rate_limit' ), 0 );
	}

	/**
	 * Assemble the current bot-block settings from their individual options.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return array(
			'enabled'               => (bool) get_option( 'apppresser_botblock_enabled', true ),
			'max_requests'          => max( 1, (int) get_option( 'apppresser_botblock_max_requests', 3 ) ),
			'window'                => max( 1, (int) get_option( 'apppresser_botblock_window', 60 ) ),
			'ban_length'            => max( 1, (int) get_option( 'apppresser_botblock_ban_length', 15 ) ) * 60,
			'whitelist'             => (array) get_option( 'apppresser_botblock_whitelist', array() ),
			'blocked_ip_ranges'     => (array) get_option( 'apppresser_botblock_blocked_ip_ranges', array() ),
			'gf_url_block'          => (bool) get_option( 'apppresser_botblock_gf_url_block', true ),
			'time_trap'             => (bool) get_option( 'apppresser_botblock_time_trap', true ),
			'time_trap_seconds'     => max( 0, (int) get_option( 'apppresser_botblock_time_trap_seconds', 2 ) ),
			'blocked_words'         => (array) get_option( 'apppresser_botblock_blocked_words', array() ),
			'blocked_email_domains' => (array) get_option( 'apppresser_botblock_blocked_email_domains', array() ),
		);
	}

	/**
	 * Inspect the current request and enforce rate limits / bans if needed.
	 */
	public function maybe_enforce_rate_limit() {
		$settings = self::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( wp_doing_cron() ) {
			return;
		}

		if ( $this->is_admin_or_login_request() ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$ip = $this->get_client_ip();

		if ( ! $ip ) {
			return;
		}

		if ( in_array( $ip, $settings['whitelist'], true ) ) {
			return;
		}

		/**
		 * Filter whether a given request is exempt from rate limiting.
		 *
		 * @param bool   $exempt Whether the request is exempt.
		 * @param string $ip     The client IP address.
		 */
		if ( apply_filters( 'apb_rate_limit_exempt', false, $ip ) ) {
			return;
		}

		if ( $this->is_ip_in_blocked_ranges( $ip, $settings['blocked_ip_ranges'] ) ) {
			$this->block_forbidden();
		}

		$max_requests = $settings['max_requests'];
		$window       = $settings['window'];
		$ban_length   = $settings['ban_length'];

		// IP-wide backstop: catches floods from clients that don't persist the identifier cookie
		// (e.g. bots), using a much higher threshold so a shared IP with many legitimate
		// browsers isn't punished collectively.
		$ip_ban_key = md5( 'ip_' . $ip );
		$ip_ban     = AppPresser_Bot_Ban_Store::get_ban( $ip_ban_key );

		if ( $ip_ban ) {
			$this->block_request( AppPresser_Bot_Ban_Store::get_ban_ttl( $ip_ban ) );
		}

		$ip_max_requests = $max_requests * 10;
		$ip_key          = self::TRANSIENT_PREFIX . 'ip_' . md5( $ip );
		$ip_count        = get_transient( $ip_key );

		if ( false === $ip_count ) {
			set_transient( $ip_key, 1, $window );
		} else {
			$ip_count = (int) $ip_count + 1;
			set_transient( $ip_key, $ip_count, $window );

			if ( $ip_count > $ip_max_requests ) {
				AppPresser_Bot_Ban_Store::ban( $ip_ban_key, $ip, $ban_length, 'ip_flood' );
				$this->block_request( $ban_length );
			}
		}

		$identifier = $this->get_client_identifier( $ip );

		$ban_key = md5( 'id_' . $identifier );
		$ban     = AppPresser_Bot_Ban_Store::get_ban( $ban_key );

		if ( $ban ) {
			$this->block_request( AppPresser_Bot_Ban_Store::get_ban_ttl( $ban ) );
		}

		$key   = self::TRANSIENT_PREFIX . md5( $identifier );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, $window );
			return;
		}

		$count = (int) $count + 1;
		set_transient( $key, $count, $window );

		if ( $count > $max_requests ) {
			AppPresser_Bot_Ban_Store::ban( $ban_key, $ip, $ban_length, 'rate_limit' );
			$this->block_request( $ban_length );
		}
	}

	/**
	 * Whether this is a dashboard/login request that should be exempt.
	 *
	 * @return bool
	 */
	private function is_admin_or_login_request() {
		// Exempt wp-admin dashboard pages, but keep admin-ajax.php POSTs (used by front-end forms) limited.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return true;
		}

		if ( ! empty( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Get (or issue) the per-browser identifier cookie and combine with the IP.
	 *
	 * @param string $ip Client IP.
	 * @return string
	 */
	private function get_client_identifier( $ip ) {
		if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) && preg_match( '/^[a-f0-9]{32}$/', $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$uid = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		} else {
			$uid = md5( wp_generate_password( 32, false ) );

			if ( ! headers_sent() ) {
				setcookie( self::COOKIE_NAME, $uid, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		return $ip . '|' . $uid;
	}

	/**
	 * Whether an IP matches any of the given IP/CIDR ranges.
	 *
	 * @param string $ip     Client IP.
	 * @param array  $ranges List of IP or IP/CIDR strings.
	 * @return bool
	 */
	private function is_ip_in_blocked_ranges( $ip, $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $this->ip_in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IP falls within a plain IP or CIDR range.
	 *
	 * @param string $ip   Client IP.
	 * @param string $cidr Plain IP or "ip/bits" CIDR string.
	 * @return bool
	 */
	private function ip_in_range( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $cidr === $ip;
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits = (int) $bits;

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$mask = -1 << ( 32 - $bits );
			return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );

			if ( false === $ip_bin || false === $subnet_bin ) {
				return false;
			}

			$bytes     = intdiv( $bits, 8 );
			$remainder = $bits % 8;

			if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
				return false;
			}

			if ( $remainder > 0 ) {
				$mask = chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );
				if ( ( substr( $ip_bin, $bytes, 1 ) & $mask ) !== ( substr( $subnet_bin, $bytes, 1 ) & $mask ) ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Get and validate the client's REMOTE_ADDR.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $ip;
	}

	/**
	 * Send a 429 Too Many Requests response and stop execution.
	 *
	 * @param int $retry_after Seconds until the client may retry.
	 */
	private function block_request( $retry_after ) {
		status_header( 429 );
		header( 'Retry-After: ' . (int) $retry_after );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();

		echo esc_html__( 'Too many requests. Please try again later.', 'apppresser-wp' );
		exit;
	}

	/**
	 * Send a 403 Forbidden response and stop execution.
	 */
	private function block_forbidden() {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();

		echo esc_html__( 'Forbidden.', 'apppresser-wp' );
		exit;
	}
}
