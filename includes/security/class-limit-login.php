<?php
/**
 * Limit login attempts.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Limit_Login
 *
 * Blocks an IP address after a configurable number of failed login attempts.
 * After a configurable number of lockouts the lockout duration is extended
 * from minutes to hours. Failed-attempt counters reset after a configurable
 * number of hours. Lockout events are recorded to a custom table for review
 * on the Logs settings page.
 *
 * Retry counters and lockouts live in a per-IP database table and are written
 * with atomic INSERT ... ON DUPLICATE KEY UPDATE / conditional UPDATE
 * statements. The alternatives (serialized option arrays, transients) do a
 * read-modify-write per request, which loses increments and duplicates
 * lockouts when brute-force logins arrive concurrently - with the result that
 * a burst of attempts is never blocked and a log row is written per request.
 * The SQL primitives below hold a row lock for the duration of each
 * statement, so counters are exact and exactly one concurrent request can
 * transition an IP into a lockout (and log it).
 */
class AppPresser_Limit_Login {

	const DB_VERSION = '1.1';

	const OPTION_ALLOWED_RETRIES    = 'apppresser_limit_login_allowed_retries';
	const OPTION_LOCKOUT_MINUTES    = 'apppresser_limit_login_lockout_minutes';
	const OPTION_ALLOWED_LOCKOUTS   = 'apppresser_limit_login_allowed_lockouts';
	const OPTION_LONG_LOCKOUT_HOURS = 'apppresser_limit_login_long_lockout_hours';
	const OPTION_RESET_HOURS        = 'apppresser_limit_login_reset_hours';

	// Only used to migrate pre-1.1 counters/lockouts into the state table.
	const OPTION_RETRIES       = 'apppresser_limit_login_retries';
	const OPTION_RETRIES_VALID = 'apppresser_limit_login_retries_valid';
	const OPTION_LOCKOUTS      = 'apppresser_limit_login_lockouts';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ), 10, 2 );
		// Run after core's username/password/email/cookie handlers (priorities 20-30)
		// so a lockout overrides both a successful WP_User and core's own errors.
		add_filter( 'authenticate', array( $this, 'check_lockout' ), 99, 3 );
	}

	/**
	 * Assemble the current limit-login settings from their individual options.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return array(
			'allowed_retries'    => max( 1, (int) get_option( self::OPTION_ALLOWED_RETRIES, 4 ) ),
			'lockout_minutes'    => max( 1, (int) get_option( self::OPTION_LOCKOUT_MINUTES, 20 ) ),
			'allowed_lockouts'   => max( 1, (int) get_option( self::OPTION_ALLOWED_LOCKOUTS, 4 ) ),
			'long_lockout_hours' => max( 1, (int) get_option( self::OPTION_LONG_LOCKOUT_HOURS, 24 ) ),
			'reset_hours'        => max( 1, (int) get_option( self::OPTION_RESET_HOURS, 12 ) ),
		);
	}

	/**
	 * Block a locked-out IP regardless of whether the credentials were valid.
	 *
	 * @param WP_User|WP_Error|null $user     Current user object or error.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null
	 */
	public function check_lockout( $user, $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$ip = $this->get_client_ip();

		if ( ! $ip ) {
			return $user;
		}

		$lockout_until = $this->get_lockout_until( $ip );

		if ( $lockout_until && time() < $lockout_until ) {
			return new WP_Error( 'too_many_retries', $this->lockout_message( $lockout_until - time() ) );
		}

		return $user;
	}

	/**
	 * Record a failed login attempt and lock out the IP when the threshold is reached.
	 *
	 * @param string        $username Submitted username.
	 * @param WP_Error|null $error    The authentication error, when available.
	 */
	public function record_failed_login( $username, $error = null ) {
		// The site's own lockout error should never be counted as an attempt.
		if ( $error instanceof WP_Error && 'too_many_retries' === $error->get_error_code() ) {
			return;
		}

		$ip = $this->get_client_ip();

		if ( ! $ip ) {
			return;
		}

		if ( $this->get_lockout_until( $ip ) > time() ) {
			// Already locked out; nothing to record.
			return;
		}

		$settings = self::get_settings();

		// Atomically increment this IP's counter (starting a new window when
		// the previous one has lapsed) and get the exact post-write count.
		$retries = $this->increment_retries( $ip, $settings['reset_hours'] );

		if ( $retries < 1 || 0 !== ( $retries % $settings['allowed_retries'] ) ) {
			return;
		}

		$retries_long = $settings['allowed_retries'] * $settings['allowed_lockouts'];
		$is_long      = $retries >= $retries_long;

		if ( $is_long ) {
			$until  = time() + ( $settings['long_lockout_hours'] * HOUR_IN_SECONDS );
			$reason = 'long_lockout';
		} else {
			$until  = time() + ( $settings['lockout_minutes'] * MINUTE_IN_SECONDS );
			$reason = 'lockout';
		}

		if ( $this->apply_lockout( $ip, $until, $is_long ) ) {
			// Only the request that actually transitions the IP into a lockout
			// records it, so concurrent threshold-crossing attempts cannot
			// each write their own log row.
			$this->log_lockout( $ip, $username, $reason );
		}

		// Occasionally purge rows whose lockout and retry window have both
		// lapsed, so abandoned bot IPs cannot accumulate indefinitely.
		if ( ! wp_rand( 0, 99 ) ) {
			self::purge_expired_state();
		}
	}

	/**
	 * Build the lockout error message with the remaining time.
	 *
	 * @param int $seconds Seconds remaining in the lockout.
	 * @return string
	 */
	private function lockout_message( $seconds ) {
		$message = __( '<strong>ERROR</strong>: Too many failed login attempts.', 'apppresser-wp' );

		if ( $seconds > 60 ) {
			$minutes = (int) ceil( $seconds / 60 );

			if ( $minutes > 60 ) {
				$hours    = (int) ceil( $minutes / 60 );
				$message .= ' ' . sprintf(
					/* translators: %d: number of hours */
					_n( 'Please try again in %d hour.', 'Please try again in %d hours.', $hours, 'apppresser-wp' ),
					$hours
				);
			} else {
				$message .= ' ' . sprintf(
					/* translators: %d: number of minutes */
					_n( 'Please try again in %d minute.', 'Please try again in %d minutes.', $minutes, 'apppresser-wp' ),
					$minutes
				);
			}
		} else {
			$message .= ' ' . __( 'Please try again in a minute.', 'apppresser-wp' );
		}

		return $message;
	}

	/**
	 * Get the current lockout expiry timestamp for an IP, or 0 when not locked.
	 *
	 * @param string $ip Client IP.
	 * @return int
	 */
	private function get_lockout_until( $ip ) {
		global $wpdb;

		$table = self::state_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
		$until = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
				"SELECT lockout_until FROM $table WHERE ip = %s",
				$ip
			)
		);

		return null === $until ? 0 : (int) $until;
	}

	/**
	 * Atomically increment the failed-attempt counter for an IP and return the
	 * exact count after this write.
	 *
	 * The single statement holds a row lock while it runs, so concurrent
	 * requests cannot lose increments. LAST_INSERT_ID(expr) publishes the new
	 * counter to this connection (returned by SELECT LAST_INSERT_ID()); on a
	 * fresh row it publishes the initial value of 1. When the previous retry
	 * window has lapsed the counter restarts at 1.
	 *
	 * @param string $ip          Client IP.
	 * @param int    $reset_hours Hours a retry window stays open.
	 * @return int Exact counter value after this write; 0 when the write failed.
	 */
	private function increment_retries( $ip, $reset_hours ) {
		global $wpdb;

		$table       = self::state_table_name();
		$now         = time();
		$valid_until = $now + ( $reset_hours * HOUR_IN_SECONDS );
		$now_gmt     = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
				"INSERT INTO $table (ip, retries, retries_valid, lockout_until, updated_at)
				VALUES (%s, LAST_INSERT_ID(1), %d, 0, %s)
				ON DUPLICATE KEY UPDATE
					retries       = LAST_INSERT_ID( IF( retries_valid >= %d, retries + 1, 1 ) ),
					retries_valid = %d,
					updated_at    = %s",
				$ip,
				$valid_until,
				$now_gmt,
				$now,
				$valid_until,
				$now_gmt
			)
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/**
	 * Atomically transition an IP into a lockout, unless it is already locked.
	 *
	 * The WHERE clause makes the write conditional on the IP currently being
	 * unlocked, so no matter how many concurrent requests pass the retry
	 * threshold simultaneously, exactly one of them applies the lockout and
	 * gets to log it.
	 *
	 * @param string $ip            Client IP.
	 * @param int    $until         Lockout expiry timestamp.
	 * @param bool   $reset_retries Whether to restart the retry counter (long lockouts).
	 * @return bool True when this request transitioned the IP into a lockout.
	 */
	private function apply_lockout( $ip, $until, $reset_retries ) {
		global $wpdb;

		$table = self::state_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
				"UPDATE $table
				SET lockout_until = %d,
					retries       = IF( %d = 1, 0, retries ),
					updated_at    = %s
				WHERE ip = %s AND lockout_until <= %d",
				$until,
				$reset_retries ? 1 : 0,
				gmdate( 'Y-m-d H:i:s' ),
				$ip,
				time()
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Delete state rows whose lockout and retry window have both expired.
	 */
	public static function purge_expired_state() {
		global $wpdb;

		$table = self::state_table_name();
		$now   = time();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, safe identifier.
				"DELETE FROM $table WHERE lockout_until < %d AND retries_valid < %d",
				$now,
				$now
			)
		);
	}

	/**
	 * Get and validate the client's IP address.
	 *
	 * Only REMOTE_ADDR is trusted by default. If the site sits behind a
	 * reverse proxy or load balancer, add the proxy's IP(s) or CIDR ranges
	 * via the apb_trusted_proxy_ips filter (shared with the bot-block rate
	 * limiter) so the real client IP is read from X-Forwarded-For; otherwise
	 * every visitor shares the proxy's IP and one lockout blocks everyone.
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
		 * Filter the list of trusted proxy IPs/CIDR ranges allowed to provide
		 * the client IP via the X-Forwarded-For header.
		 *
		 * @param array  $trusted_proxies IPs or CIDR ranges of reverse proxies/load balancers.
		 * @param string $remote_addr     The direct connection IP.
		 */
		$trusted_proxies = (array) apply_filters( 'apb_trusted_proxy_ips', array(), $remote_addr );

		if ( ! empty( $trusted_proxies ) && $this->ip_in_list( $remote_addr, $trusted_proxies ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

			// The client is the left-most entry; walk down the chain until a
			// valid, non-trusted IP is found.
			foreach ( $forwarded as $candidate ) {
				$candidate = trim( $candidate );

				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) && ! $this->ip_in_list( $candidate, $trusted_proxies ) ) {
					return $candidate;
				}
			}
		}

		return $remote_addr;
	}

	/**
	 * Check whether an IP matches any entry in a list of IPs / CIDR ranges.
	 *
	 * @param string $ip   IP address to test.
	 * @param array  $list Plain IPs or CIDR ranges (IPv4 or IPv6).
	 * @return bool
	 */
	private function ip_in_list( $ip, $list ) {
		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( false === strpos( $entry, '/' ) ) {
				if ( $ip === $entry ) {
					return true;
				}
				continue;
			}

			list( $subnet, $bits ) = explode( '/', $entry, 2 );

			$ip_bin     = inet_pton( $ip );
			$subnet_bin = inet_pton( $subnet );

			// Skip malformed entries and mismatched IPv4/IPv6 families.
			if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
				continue;
			}

			$bits = (int) $bits;
			$max  = strlen( $ip_bin ) * 8;

			if ( $bits < 0 || $bits > $max ) {
				continue;
			}

			$full_bytes = intdiv( $bits, 8 );
			$rem_bits   = $bits % 8;

			if ( $full_bytes > 0 && 0 !== strncmp( $ip_bin, $subnet_bin, $full_bytes ) ) {
				continue;
			}

			if ( 0 === $rem_bits ) {
				return true;
			}

			$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;

			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $full_bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the lockout log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'apppresser_limit_login_log';
	}

	/**
	 * Get the per-IP lockout state table name.
	 *
	 * @return string
	 */
	public static function state_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'apppresser_limit_login_state';
	}

	/**
	 * Create (or update) the lockout log and lockout state tables.
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$log_table = 'CREATE TABLE ' . self::table_name() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip VARCHAR(45) NOT NULL,
			username VARCHAR(255) NOT NULL DEFAULT '',
			reason VARCHAR(20) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $log_table );

		$state_table = 'CREATE TABLE ' . self::state_table_name() . " (
			ip VARCHAR(45) NOT NULL,
			retries INT UNSIGNED NOT NULL DEFAULT 0,
			retries_valid BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lockout_until BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (ip)
		) $charset_collate;";
		dbDelta( $state_table );

		self::migrate_options_to_state();

		update_option( 'apppresser_limit_login_db_version', self::DB_VERSION );
	}

	/**
	 * Re-run install() if the schema version has changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'apppresser_limit_login_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Import counters/lockouts stored by pre-1.1 versions into the state table,
	 * then remove the legacy options.
	 */
	private static function migrate_options_to_state() {
		global $wpdb;

		$lockouts = get_option( self::OPTION_LOCKOUTS, array() );
		$retries  = get_option( self::OPTION_RETRIES, array() );
		$valid    = get_option( self::OPTION_RETRIES_VALID, array() );

		$lockouts = is_array( $lockouts ) ? $lockouts : array();
		$retries  = is_array( $retries ) ? $retries : array();
		$valid    = is_array( $valid ) ? $valid : array();

		$ips = array_unique( array_merge( array_keys( $lockouts ), array_keys( $retries ) ) );

		foreach ( $ips as $ip ) {
			$ip = (string) $ip;

			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			$wpdb->replace(
				self::state_table_name(),
				array(
					'ip'            => $ip,
					'retries'       => isset( $retries[ $ip ] ) ? (int) $retries[ $ip ] : 0,
					'retries_valid' => isset( $valid[ $ip ] ) ? (int) $valid[ $ip ] : 0,
					'lockout_until' => isset( $lockouts[ $ip ] ) ? (int) $lockouts[ $ip ] : 0,
					'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%d', '%d', '%d', '%s' )
			);
		}

		delete_option( self::OPTION_LOCKOUTS );
		delete_option( self::OPTION_RETRIES );
		delete_option( self::OPTION_RETRIES_VALID );
	}

	/**
	 * Record a lockout event.
	 *
	 * @param string $ip       Locked-out IP address.
	 * @param string $username Submitted username.
	 * @param string $reason   Short reason code.
	 */
	private function log_lockout( $ip, $username, $reason ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->insert(
			$table,
			array(
				'ip'         => $ip,
				'username'   => sanitize_user( $username ),
				'reason'     => $reason,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Retrieve lockout log entries, most recent first.
	 *
	 * @param int $limit  Maximum number of rows to return.
	 * @param int $offset Number of rows to skip.
	 * @return array
	 */
	public static function get_logs( $limit = 100, $offset = 0 ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Total number of lockout log entries.
	 *
	 * @return int
	 */
	public static function get_count() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Delete a single lockout log entry by id.
	 *
	 * @param int $id Log entry id.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete all lockout log entries.
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query( "TRUNCATE TABLE $table" );
	}

	/**
	 * Remove an active lockout (and its retry counter) for an IP address.
	 *
	 * @param string $ip IP address to unlock.
	 */
	public static function unlock( $ip ) {
		global $wpdb;

		$ip = sanitize_text_field( $ip );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		$wpdb->delete( self::state_table_name(), array( 'ip' => $ip ), array( '%s' ) );
	}
}
