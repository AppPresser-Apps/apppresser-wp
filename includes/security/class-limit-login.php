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
 */
class AppPresser_Limit_Login {

	const DB_VERSION = '1.0';

	const OPTION_ALLOWED_RETRIES    = 'apppresser_limit_login_allowed_retries';
	const OPTION_LOCKOUT_MINUTES    = 'apppresser_limit_login_lockout_minutes';
	const OPTION_ALLOWED_LOCKOUTS   = 'apppresser_limit_login_allowed_lockouts';
	const OPTION_LONG_LOCKOUT_HOURS = 'apppresser_limit_login_long_lockout_hours';
	const OPTION_RESET_HOURS        = 'apppresser_limit_login_reset_hours';

	const OPTION_RETRIES       = 'apppresser_limit_login_retries';
	const OPTION_RETRIES_VALID = 'apppresser_limit_login_retries_valid';
	const OPTION_LOCKOUTS      = 'apppresser_limit_login_lockouts';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ) );
		add_filter( 'authenticate', array( $this, 'check_lockout' ), 0, 3 );
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
	 * Block a locked-out IP before the password is checked.
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

		$lockouts = $this->get_lockouts();

		if ( isset( $lockouts[ $ip ] ) && time() < $lockouts[ $ip ] ) {
			return new WP_Error( 'too_many_retries', $this->lockout_message( $lockouts[ $ip ] - time() ) );
		}

		return $user;
	}

	/**
	 * Record a failed login attempt and lock out the IP when the threshold is reached.
	 *
	 * @param string $username Submitted username.
	 */
	public function record_failed_login( $username ) {
		$ip = $this->get_client_ip();

		if ( ! $ip ) {
			return;
		}

		$lockouts = $this->get_lockouts();

		// Already locked out; nothing to record.
		if ( isset( $lockouts[ $ip ] ) && time() < $lockouts[ $ip ] ) {
			return;
		}

		$settings = self::get_settings();

		$retries = $this->get_retries();
		$valid   = $this->get_retries_valid();

		// Increment within the current window, otherwise start a fresh window.
		if ( isset( $retries[ $ip ] ) && isset( $valid[ $ip ] ) && time() < $valid[ $ip ] ) {
			++$retries[ $ip ];
		} else {
			$retries[ $ip ] = 1;
		}
		$valid[ $ip ] = time() + ( $settings['reset_hours'] * HOUR_IN_SECONDS );

		// Only lock out once the retry count reaches a multiple of the allowed retries.
		if ( 0 !== ( $retries[ $ip ] % $settings['allowed_retries'] ) ) {
			$this->save_retries( $retries, $valid );
			return;
		}

		$retries_long = $settings['allowed_retries'] * $settings['allowed_lockouts'];

		if ( $retries[ $ip ] >= $retries_long ) {
			$lockouts[ $ip ] = time() + ( $settings['long_lockout_hours'] * HOUR_IN_SECONDS );
			unset( $retries[ $ip ] );
			unset( $valid[ $ip ] );
			$reason = 'long_lockout';
		} else {
			$lockouts[ $ip ] = time() + ( $settings['lockout_minutes'] * MINUTE_IN_SECONDS );
			$reason          = 'lockout';
		}

		$this->save_retries( $retries, $valid );
		$this->save_lockouts( $lockouts );

		$this->log_lockout( $ip, $username, $reason );
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
	 * Get the retries option.
	 *
	 * @return array
	 */
	private function get_retries() {
		$retries = get_option( self::OPTION_RETRIES, array() );
		return is_array( $retries ) ? $retries : array();
	}

	/**
	 * Get the retries-valid option.
	 *
	 * @return array
	 */
	private function get_retries_valid() {
		$valid = get_option( self::OPTION_RETRIES_VALID, array() );
		return is_array( $valid ) ? $valid : array();
	}

	/**
	 * Get the lockouts option.
	 *
	 * @return array
	 */
	private function get_lockouts() {
		$lockouts = get_option( self::OPTION_LOCKOUTS, array() );
		return is_array( $lockouts ) ? $lockouts : array();
	}

	/**
	 * Persist retries and their validity windows.
	 *
	 * @param array $retries Retries keyed by IP.
	 * @param array $valid   Validity timestamps keyed by IP.
	 */
	private function save_retries( $retries, $valid ) {
		update_option( self::OPTION_RETRIES, $retries, false );
		update_option( self::OPTION_RETRIES_VALID, $valid, false );
	}

	/**
	 * Persist lockouts.
	 *
	 * @param array $lockouts Lockout timestamps keyed by IP.
	 */
	private function save_lockouts( $lockouts ) {
		update_option( self::OPTION_LOCKOUTS, $lockouts, false );
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
	 * Get the lockout log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'apppresser_limit_login_log';
	}

	/**
	 * Create (or update) the lockout log table.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ip VARCHAR(45) NOT NULL,
			username VARCHAR(255) NOT NULL DEFAULT '',
			reason VARCHAR(20) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

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
	 * Remove an active lockout (and its retry counters) for an IP address.
	 *
	 * @param string $ip IP address to unlock.
	 */
	public static function unlock( $ip ) {
		$ip = sanitize_text_field( $ip );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		$lockouts = get_option( self::OPTION_LOCKOUTS, array() );
		$retries  = get_option( self::OPTION_RETRIES, array() );
		$valid    = get_option( self::OPTION_RETRIES_VALID, array() );

		if ( ! is_array( $lockouts ) ) {
			$lockouts = array();
		}
		if ( ! is_array( $retries ) ) {
			$retries = array();
		}
		if ( ! is_array( $valid ) ) {
			$valid = array();
		}

		unset( $lockouts[ $ip ] );
		unset( $retries[ $ip ] );
		unset( $valid[ $ip ] );

		update_option( self::OPTION_LOCKOUTS, $lockouts, false );
		update_option( self::OPTION_RETRIES, $retries, false );
		update_option( self::OPTION_RETRIES_VALID, $valid, false );
	}
}
