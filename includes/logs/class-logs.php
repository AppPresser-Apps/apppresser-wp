<?php
/**
 * Logs admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Logs
 *
 * Registers an admin submenu page under Settings that displays plugin logs.
 * The first section shows all outgoing emails sent through wp_mail().
 */
class AppPresser_Logs {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-logs';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Record outgoing emails.
		add_filter( 'wp_mail', array( 'AppPresser_Email_Log', 'log' ) );
		add_action( 'wp_mail_failed', array( 'AppPresser_Email_Log', 'mark_failed' ) );

		// AJAX handlers for the React logs UI.
		add_action( 'wp_ajax_apppresser_logs_get_emails', array( $this, 'handle_get_emails' ) );
		add_action( 'wp_ajax_apppresser_logs_clear_emails', array( $this, 'handle_clear_emails' ) );
		add_action( 'wp_ajax_apppresser_logs_delete_email', array( $this, 'handle_delete_email' ) );
		add_action( 'wp_ajax_apppresser_logs_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'wp_ajax_apppresser_logs_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'wp_ajax_apppresser_logs_get_lockouts', array( $this, 'handle_get_lockouts' ) );
		add_action( 'wp_ajax_apppresser_logs_clear_lockouts', array( $this, 'handle_clear_lockouts' ) );
		add_action( 'wp_ajax_apppresser_logs_unlock_lockout', array( $this, 'handle_unlock_lockout' ) );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Logs', 'apppresser-wp' ),
			__( 'Logs', 'apppresser-wp' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for the admin page.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . $this->page_slug !== $hook_suffix ) {
			return;
		}

		$asset_file = APPRESSER_WP_DIR . 'build/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = require $asset_file;

			wp_enqueue_script(
				'apppresser-logs',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				'apppresser-logs',
				'apppresserLogs',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'apppresser_logs_nonce' ),
					'emailLog'   => array(
						'enabled' => AppPresser_Email_Log::is_enabled(),
						'entries' => $this->get_email_entries_data(),
						'total'   => AppPresser_Email_Log::get_count(),
					),
					'lockoutLog' => array(
						'entries' => $this->get_lockout_entries_data(),
						'total'   => AppPresser_Limit_Login::get_count(),
					),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-logs',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * Format email log rows for the JS panel.
	 *
	 * @return array<int, array{id: int, to: string, subject: string, message: string, headers: string, attachments: string, status: string, created_at: string}>
	 */
	private function get_email_entries_data() {
		$logs   = AppPresser_Email_Log::get_logs( 100 );
		$result = array();

		foreach ( $logs as $log ) {
			$result[] = array(
				'id'          => (int) $log->id,
				'to'          => $log->to_email,
				'subject'     => $log->subject,
				'message'     => $log->message,
				'headers'     => $log->headers,
				'attachments' => $log->attachments,
				'status'      => $log->status,
				'created_at'  => get_date_from_gmt( $log->created_at ),
			);
		}

		return $result;
	}

	/**
	 * Format lockout log rows for the JS panel.
	 *
	 * @return array<int, array{id: int, ip: string, username: string, reason: string, created_at: string}>
	 */
	private function get_lockout_entries_data() {
		$logs   = AppPresser_Limit_Login::get_logs( 100 );
		$result = array();

		foreach ( $logs as $log ) {
			$result[] = array(
				'id'         => (int) $log->id,
				'ip'         => $log->ip,
				'username'   => $log->username,
				'reason'     => $log->reason,
				'created_at' => get_date_from_gmt( $log->created_at ),
			);
		}

		return $result;
	}

	/**
	 * AJAX handler for fetching the current lockout log.
	 */
	public function handle_get_lockouts() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		wp_send_json_success(
			array(
				'entries' => $this->get_lockout_entries_data(),
				'total'   => AppPresser_Limit_Login::get_count(),
			)
		);
	}

	/**
	 * AJAX handler for clearing the lockout log.
	 */
	public function handle_clear_lockouts() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		AppPresser_Limit_Login::clear();

		wp_send_json_success(
			array(
				'entries' => array(),
				'total'   => 0,
			)
		);
	}

	/**
	 * AJAX handler for removing an active lockout for an IP address and
	 * deleting its log entry.
	 */
	public function handle_unlock_lockout() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

		if ( $ip ) {
			AppPresser_Limit_Login::unlock( $ip );
		}

		if ( $id ) {
			AppPresser_Limit_Login::delete( $id );
		}

		wp_send_json_success(
			array(
				'entries' => $this->get_lockout_entries_data(),
				'total'   => AppPresser_Limit_Login::get_count(),
			)
		);
	}

	/**
	 * AJAX handler for fetching the current email log.
	 */
	public function handle_get_emails() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		wp_send_json_success(
			array(
				'entries' => $this->get_email_entries_data(),
				'total'   => AppPresser_Email_Log::get_count(),
			)
		);
	}

	/**
	 * AJAX handler for clearing the email log.
	 */
	public function handle_clear_emails() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		AppPresser_Email_Log::clear();

		wp_send_json_success(
			array(
				'entries' => array(),
				'total'   => 0,
			)
		);
	}

	/**
	 * AJAX handler for deleting a single email log entry.
	 */
	public function handle_delete_email() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id ) {
			AppPresser_Email_Log::delete( $id );
		}

		wp_send_json_success(
			array(
				'entries' => $this->get_email_entries_data(),
				'total'   => AppPresser_Email_Log::get_count(),
			)
		);
	}

	/**
	 * AJAX handler for toggling email logging on/off.
	 */
	public function handle_toggle() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$enabled = isset( $_POST['enabled'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ) : false;

		update_option( AppPresser_Email_Log::ENABLED_OPTION, $enabled );

		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * AJAX handler for sending a test email.
	 */
	public function handle_send_test_email() {
		check_ajax_referer( 'apppresser_logs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$to      = get_option( 'admin_email' );
		$subject = __( 'AppPresser test email', 'apppresser-wp' );
		$message = sprintf(
			/* translators: %s: site name */
			__( 'This is a test email sent from %s to verify outgoing mail is working.', 'apppresser-wp' ),
			get_bloginfo( 'name' )
		);

		$sent = wp_mail( $to, $subject, $message );

		if ( $sent ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: recipient email address */
						__( 'Test email sent to %s.', 'apppresser-wp' ),
						$to
					),
					'entries' => $this->get_email_entries_data(),
					'total'   => AppPresser_Email_Log::get_count(),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'The test email could not be sent. Check your mail configuration.', 'apppresser-wp' ),
			),
			500
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'Logs', 'apppresser-logs-root' );
	}
}
