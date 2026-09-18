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

		// Logged-in users are never rate limited.
		if ( is_user_logged_in() ) {
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
			$this->block_request( AppPresser_Bot_Ban_Store::get_ban_ttl( $ip_ban ), $ip_ban->reason );
		}

		$ip_max_requests = $max_requests * 10;
		$ip_key          = self::TRANSIENT_PREFIX . 'ip_' . md5( $ip );
		$ip_count        = $this->increment_counter( $ip_key, $window );

		if ( $ip_count > $ip_max_requests ) {
			AppPresser_Bot_Ban_Store::ban( $ip_ban_key, $ip, $ban_length, 'ip_flood', $this->get_request_payload() );
			$this->block_request( $ban_length, 'ip_flood' );
		}

		$identifier = $this->get_client_identifier( $ip );

		$ban_key = md5( 'id_' . $identifier );
		$ban     = AppPresser_Bot_Ban_Store::get_ban( $ban_key );

		if ( $ban ) {
			$this->block_request( AppPresser_Bot_Ban_Store::get_ban_ttl( $ban ), $ban->reason );
		}

		$key   = self::TRANSIENT_PREFIX . md5( $identifier );
		$count = $this->increment_counter( $key, $window );

		if ( $count > $max_requests ) {
			AppPresser_Bot_Ban_Store::ban( $ban_key, $ip, $ban_length, 'rate_limit', $this->get_request_payload() );
			$this->block_request( $ban_length, 'rate_limit' );
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
	 * Captured payload keys that must never be stored (credentials, secrets,
	 * payment data). Matched case-insensitively against the full field path.
	 */
	const SENSITIVE_KEY_PATTERN = '/pass(word|wd|phrase)?|pwd|credential|secret|token|nonce|auth|api[-_]?key|card|cvv|ssn|social/i';

	/**
	 * Stringify the current POST body for admin review on a ban record.
	 *
	 * wp-login.php requests never reach this code (they are exempt), but a
	 * front-end login form can POST elsewhere, so any field whose name looks
	 * like a credential/secret is redacted rather than stored.
	 *
	 * @return string
	 */
	private function get_request_payload() {
		if ( empty( $_POST ) || ! is_array( $_POST ) ) {
			return '';
		}

		$lines = array();
		$this->flatten_post( $_POST, '', $lines );

		$payload = implode( "\n", $lines );

		if ( strlen( $payload ) > 10000 ) {
			$payload = substr( $payload, 0, 10000 ) . "\n… (truncated)";
		}

		return $payload;
	}

	/**
	 * Flatten a (possibly nested) POST array into "key = value" lines.
	 *
	 * @param array  $data   Data to flatten.
	 * @param string $prefix Key path prefix for nested arrays.
	 * @param array  $lines  Accumulator for output lines.
	 * @param int    $depth  Current nesting depth (guards against deep input).
	 */
	private function flatten_post( $data, $prefix, &$lines, $depth = 0 ) {
		if ( $depth > 5 ) {
			$lines[] = $prefix . ' = [nested data omitted]';
			return;
		}

		foreach ( $data as $key => $value ) {
			$key   = sanitize_text_field( wp_unslash( (string) $key ) );
			$label = '' === $prefix ? $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				$this->flatten_post( $value, $label, $lines, $depth + 1 );
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( preg_match( self::SENSITIVE_KEY_PATTERN, $label ) ) {
				$lines[] = $label . ' = [redacted]';
				continue;
			}

			$value = sanitize_textarea_field( wp_unslash( (string) $value ) );

			if ( strlen( $value ) > 500 ) {
				$value = substr( $value, 0, 500 ) . '…';
			}

			$lines[] = $label . ' = ' . $value;
		}
	}

	/**
	 * Increment a fixed-window request counter.
	 *
	 * The window starts at the first request in the window and does NOT
	 * slide: writes store the window start time and refresh the transient
	 * with only the remaining TTL, so a steady trickle of requests below
	 * the limit can still accumulate across the whole window without
	 * resetting the clock and can only reset after $window seconds pass
	 * from the first request.
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Window length in seconds.
	 * @return int The request count within the current window.
	 */
	private function increment_counter( $key, $window ) {
		$now  = time();
		$data = get_transient( $key );

		if ( is_array( $data ) && isset( $data['count'], $data['start'] ) ) {
			$elapsed = $now - (int) $data['start'];

			if ( $elapsed < $window ) {
				$count = (int) $data['count'] + 1;

				set_transient(
					$key,
					array(
						'count' => $count,
						'start' => (int) $data['start'],
					),
					max( 1, $window - $elapsed )
				);

				return $count;
			}
		}

		// First request of a new window. Counters written by older versions
		// (plain integers) carry their count forward into a fresh window.
		$count = is_numeric( $data ) ? (int) $data + 1 : 1;

		set_transient(
			$key,
			array(
				'count' => $count,
				'start' => $now,
			),
			$window
		);

		return $count;
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
		$bits                  = (int) $bits;

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
	 * Get and validate the client's IP address.
	 *
	 * Only REMOTE_ADDR is trusted by default. If the site sits behind a
	 * reverse proxy or load balancer, add the proxy's IP(s) via the
	 * apb_trusted_proxy_ips filter so the real client IP is read from
	 * X-Forwarded-For; otherwise every visitor shares the proxy's IP and
	 * the IP-wide flood backstop will ban them collectively.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$remote_addr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		/**
		 * Filter the list of trusted proxy IPs allowed to provide the client
		 * IP via the X-Forwarded-For header.
		 *
		 * @param array  $trusted_proxies IPs of reverse proxies/load balancers.
		 * @param string $remote_addr     The direct connection IP.
		 */
		$trusted_proxies = (array) apply_filters( 'apb_trusted_proxy_ips', array(), $remote_addr );

		if ( ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

			// The client is the left-most entry; walk down the chain until a
			// valid, non-trusted IP is found.
			foreach ( $forwarded as $candidate ) {
				$candidate = trim( $candidate );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) && ! in_array( $candidate, $trusted_proxies, true ) ) {
					return $candidate;
				}
			}
		}

		return $remote_addr;
	}

	/**
	 * Record a blocked request so admins can review blocks after the
	 * fact. Bans are short-lived and the bans table only shows active
	 * bans, so without this log there is no trace of what was blocked.
	 * Capped at the 50 most recent events; stored with autoload off.
	 *
	 * @param string $reason Short reason code.
	 */
	private function record_block( $reason ) {
		$log = (array) get_option( 'apppresser_bot_block_log', array() );

		array_unshift(
			$log,
			array(
				'time'   => gmdate( 'Y-m-d H:i:s' ),
				'ip'     => $this->get_client_ip(),
				'reason' => $reason,
			)
		);

		update_option( 'apppresser_bot_block_log', array_slice( $log, 0, 50 ), false );
	}

	/**
	 * The recorded block events, newest first.
	 *
	 * @return array
	 */
	public static function get_block_log() {
		return (array) get_option( 'apppresser_bot_block_log', array() );
	}

	/**
	 * Clear all recorded block events.
	 */
	public static function clear_block_log() {
		delete_option( 'apppresser_bot_block_log' );
	}

	/**
	 * Send a 429 Too Many Requests response and stop execution.
	 *
	 * @param int    $retry_after Seconds until the client may retry.
	 * @param string $reason      Short reason code (rate_limit or ip_flood).
	 */
	private function block_request( $retry_after, $reason = '' ) {
		$this->record_block( $reason );

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
		$this->record_block( 'blocked_range' );

		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		nocache_headers();

		echo esc_html__( 'Forbidden.', 'apppresser-wp' );
		exit;
	}
}
