<?php
/**
 * Persistent IP ban storage for the bot-block rate limiter.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Bot_Ban_Store
 *
 * Bans (unlike rate-limit counters) live in a custom DB table rather than
 * transients, so an object-cache flush or restart can't silently un-ban
 * every currently-banned IP.
 */
class AppPresser_Bot_Ban_Store {

	const DB_VERSION = '1.1';

	/**
	 * Get the bans table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'apppresser_bot_bans';
	}

	/**
	 * Create (or update) the bans table.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ban_key VARCHAR(32) NOT NULL,
			ip VARCHAR(45) NOT NULL,
			reason VARCHAR(20) NOT NULL DEFAULT '',
			payload LONGTEXT,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ban_key (ban_key),
			KEY expires_at (expires_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'apppresser_bot_db_version', self::DB_VERSION );
	}

	/**
	 * Re-run install() if the schema version has changed, e.g. on a
	 * git-pull deploy that skips the activation hook.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'apppresser_bot_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Ban (or re-ban, extending expiry) a given key for a number of seconds.
	 *
	 * @param string $key     Ban key (md5 hash).
	 * @param string $ip      IP address being banned.
	 * @param int    $seconds Ban duration in seconds.
	 * @param string $reason  Short reason code.
	 * @param string $payload Optional stringified request data for admin review.
	 */
	public static function ban( $key, $ip, $seconds, $reason = '', $payload = '' ) {
		global $wpdb;

		$table   = self::table_name();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $seconds ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (ban_key, ip, reason, payload, created_at, expires_at) VALUES (%s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE ip = VALUES(ip), reason = VALUES(reason), payload = VALUES(payload), created_at = VALUES(created_at), expires_at = VALUES(expires_at)",
				$key,
				$ip,
				$reason,
				$payload,
				$now,
				$expires
			)
		);
	}

	/**
	 * Return the active ban row for a key, or null if not banned.
	 *
	 * @param string $key Ban key.
	 * @return object|null
	 */
	public static function get_ban( $key ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE ban_key = %s AND expires_at > UTC_TIMESTAMP()",
				$key
			)
		);
	}

	/**
	 * Seconds remaining until a ban expires.
	 *
	 * @param object $ban Ban row.
	 * @return int
	 */
	public static function get_ban_ttl( $ban ) {
		$expires = strtotime( $ban->expires_at . ' UTC' );

		return max( 1, $expires - time() );
	}

	/**
	 * Remove a ban by row id.
	 *
	 * @param int $id Ban row id.
	 */
	public static function unban( $id ) {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * All currently active (non-expired) bans, most recently expiring first.
	 *
	 * @return array
	 */
	public static function get_active_bans() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		return $wpdb->get_results( "SELECT * FROM $table WHERE expires_at > UTC_TIMESTAMP() ORDER BY expires_at DESC" );
	}

	/**
	 * Delete expired ban rows. Purely table hygiene since expired rows are
	 * already invisible to get_ban()/get_active_bans().
	 */
	public static function cleanup_expired() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query( "DELETE FROM $table WHERE expires_at <= UTC_TIMESTAMP()" );
	}
}
