<?php
/**
 * 404 hit log storage.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Redirect_404_Log
 *
 * Records 404 hits into a custom DB table so administrators can review
 * broken URLs and turn them into redirects.
 */
class AppPresser_Redirect_404_Log {

	const DB_VERSION = '1.0';

	/**
	 * Get the 404 log table name.
	 *
	 * Uses the per-site prefix so each site in a multisite network tracks
	 * its own 404s independently.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->get_blog_prefix() . 'apppresser_404_log';
	}

	/**
	 * Create (or update) the 404 log table.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(255) NOT NULL DEFAULT '',
			hits INT UNSIGNED NOT NULL DEFAULT 1,
			last_seen DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url (url),
			KEY last_seen (last_seen)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'apppresser_404_log_db_version', self::DB_VERSION );
	}

	/**
	 * Re-run install() if the schema version has changed, e.g. on a
	 * git-pull deploy that skips the activation hook.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'apppresser_404_log_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Record (or increment) a 404 hit for a URL.
	 *
	 * @param string $url Normalized request path.
	 */
	public static function log_404( $url ) {
		global $wpdb;

		$url   = substr( $url, 0, 255 );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (url, hits, last_seen, created_at) VALUES (%s, 1, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %s",
				$url,
				$now,
				$now,
				$now
			)
		);
	}

	/**
	 * Retrieve 404 log entries, most recent first.
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
				"SELECT * FROM $table ORDER BY last_seen DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Total number of logged 404s.
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
	 * Delete a single 404 log entry by id.
	 *
	 * @param int $id Log entry id.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete all 404 log entries.
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}
