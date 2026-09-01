<?php
/**
 * Outgoing email log storage.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Email_Log
 *
 * Records every email sent through wp_mail() into a custom DB table so
 * administrators can review outgoing mail from the Logs settings page.
 */
class AppPresser_Email_Log {

	const DB_VERSION = '1.0';

	/**
	 * Option key controlling whether email logging is enabled.
	 *
	 * @var string
	 */
	const ENABLED_OPTION = 'apppresser_email_log_enabled';

	/**
	 * Id of the most recently logged email, used to mark failures.
	 *
	 * @var int|null
	 */
	private static $last_log_id = null;

	/**
	 * Get the email log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'apppresser_email_log';
	}

	/**
	 * Create (or update) the email log table.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			to_email VARCHAR(255) NOT NULL DEFAULT '',
			subject TEXT NOT NULL,
			message LONGTEXT NOT NULL,
			headers TEXT NOT NULL,
			attachments TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'apppresser_email_log_db_version', self::DB_VERSION );
	}

	/**
	 * Re-run install() if the schema version has changed, e.g. on a
	 * git-pull deploy that skips the activation hook.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'apppresser_email_log_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Whether email logging is currently enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::ENABLED_OPTION, true );
	}

	/**
	 * Log an outgoing email from the wp_mail filter.
	 *
	 * @param array $atts wp_mail() attributes (to, subject, message, headers, attachments).
	 * @return array The unchanged attributes.
	 */
	public static function log( $atts ) {
		if ( ! self::is_enabled() ) {
			return $atts;
		}

		global $wpdb;

		$to          = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject     = isset( $atts['subject'] ) ? $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : '';

		// Normalize values that may be arrays into readable strings.
		$to          = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		$headers     = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
		$attachments = is_array( $attachments ) ? implode( "\n", $attachments ) : (string) $attachments;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->insert(
			$table,
			array(
				'to_email'    => $to,
				'subject'     => $subject,
				'message'     => $message,
				'headers'     => $headers,
				'attachments' => $attachments,
				'status'      => 'sent',
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		self::$last_log_id = (int) $wpdb->insert_id;

		return $atts;
	}

	/**
	 * Mark the most recently logged email as failed.
	 *
	 * @param WP_Error $error The error returned by wp_mail().
	 */
	public static function mark_failed( $error ) {
		if ( ! self::$last_log_id ) {
			return;
		}

		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->update(
			$table,
			array( 'status' => 'failed' ),
			array( 'id' => self::$last_log_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::$last_log_id = null;
	}

	/**
	 * Retrieve email log entries, most recent first.
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
	 * Total number of logged emails.
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
	 * Delete a single log entry by id.
	 *
	 * @param int $id Log entry id.
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete all log entries.
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table is a fixed, safe identifier.
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}
