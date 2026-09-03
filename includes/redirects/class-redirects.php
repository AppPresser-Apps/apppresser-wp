<?php
/**
 * URL redirects admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Redirects
 *
 * Registers an admin submenu page under Settings for URL redirects.
 * An admin can map a source path to a destination URL; when a visitor
 * requests the source path, they are redirected to the destination.
 */
class AppPresser_Redirects {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-redirects';

	/**
	 * Option key that stores the redirect map.
	 *
	 * @var string
	 */
	private $option_key = 'apppresser_redirects';

	/**
	 * Redirect status codes the user may choose from.
	 *
	 * @var int[]
	 */
	private $allowed_statuses = array( 301, 302, 303, 307, 308 );

	/**
	 * Default redirect status code.
	 *
	 * @var int
	 */
	private $default_status = 301;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_apppresser_redirects_get', array( $this, 'handle_get' ) );
		add_action( 'wp_ajax_apppresser_redirects_add', array( $this, 'handle_add' ) );
		add_action( 'wp_ajax_apppresser_redirects_delete', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_apppresser_redirects_get_404s', array( $this, 'handle_get_404s' ) );
		add_action( 'wp_ajax_apppresser_redirects_delete_404', array( $this, 'handle_delete_404' ) );
		add_action( 'wp_ajax_apppresser_redirects_clear_404s', array( $this, 'handle_clear_404s' ) );

		// Apply redirects on the frontend. Priority 1 so it runs before
		// WordPress's own canonical redirect (priority 10).
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );

		// Record 404 hits. Runs after maybe_redirect() so a matched redirect
		// (which exits) never reaches this point.
		add_action( 'template_redirect', array( $this, 'log_404' ), 20 );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Redirects', 'apppresser-wp' ),
			__( 'Redirects', 'apppresser-wp' ),
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
				'apppresser-redirects',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				'apppresser-redirects',
				'apppresserRedirects',
				array(
					'redirects' => $this->get_redirects_data(),
					'notFound'  => $this->get_404s_data(),
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'apppresser_redirects_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-redirects',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * Get the raw redirect map from the option.
	 *
	 * @return array<int, array{from: string, to: string}>
	 */
	private function get_redirects() {
		$redirects = get_option( $this->option_key, array() );

		if ( ! is_array( $redirects ) ) {
			return array();
		}

		return array_values( $redirects );
	}

	/**
	 * Persist the redirect map.
	 *
	 * @param array<int, array{from: string, to: string}> $redirects Redirect map.
	 */
	private function save_redirects( $redirects ) {
		update_option( $this->option_key, $redirects );
	}

	/**
	 * Format redirects for the JS panel.
	 *
	 * @return array<int, array{from: string, to: string}>
	 */
	private function get_redirects_data() {
		$redirects = $this->get_redirects();
		$result    = array();

		foreach ( $redirects as $redirect ) {
			$result[] = array(
				'from'   => isset( $redirect['from'] ) ? $redirect['from'] : '',
				'to'     => isset( $redirect['to'] ) ? $redirect['to'] : '',
				'status' => isset( $redirect['status'] ) ? (int) $redirect['status'] : $this->default_status,
			);
		}

		return $result;
	}

	/**
	 * Format 404 log rows for the JS panel.
	 *
	 * @return array<int, array{id: int, url: string, hits: int, last_seen: string}>
	 */
	private function get_404s_data() {
		$logs   = AppPresser_Redirect_404_Log::get_logs( 100 );
		$result = array();

		foreach ( $logs as $log ) {
			$result[] = array(
				'id'        => (int) $log->id,
				'url'       => $log->url,
				'hits'      => (int) $log->hits,
				'last_seen' => get_date_from_gmt( $log->last_seen ),
			);
		}

		return $result;
	}

	/**
	 * Normalize a source path for matching.
	 *
	 * @param string $path Raw source path.
	 * @return string
	 */
	private function normalize_source( $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			return '';
		}

		// Strip scheme/host so only the path remains.
		if ( preg_match( '#^https?://[^/]+(/.*)?$#i', $path, $matches ) ) {
			$path = isset( $matches[1] ) ? $matches[1] : '/';
		}

		// Ensure a leading slash.
		if ( '/' !== substr( $path, 0, 1 ) ) {
			$path = '/' . $path;
		}

		// Strip trailing slashes (except the root) so "/old-page" and
		// "/old-page/" match the same redirect.
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $path;
	}

	/**
	 * Strip the current site's base path from a path.
	 *
	 * In a multisite subdirectory install, the request path includes the
	 * subsite prefix (e.g. "/visit/dsdsds"). Redirects are entered relative
	 * to the subsite (e.g. "/dsdsds"), so the prefix must be removed before
	 * matching. On a single site (or the network root) this is a no-op.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private function strip_site_path( $path ) {
		$home_path = $this->normalize_source( wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( $home_path && '/' !== $home_path && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		return $path;
	}

	/**
	 * AJAX handler for fetching the current redirects.
	 */
	public function handle_get() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		wp_send_json_success(
			array(
				'redirects' => $this->get_redirects_data(),
			)
		);
	}

	/**
	 * AJAX handler for adding or updating a redirect.
	 */
	public function handle_add() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$from   = isset( $_POST['from'] ) ? $this->strip_site_path( $this->normalize_source( wp_unslash( $_POST['from'] ) ) ) : '';
		$to     = isset( $_POST['to'] ) ? esc_url_raw( wp_unslash( $_POST['to'] ) ) : '';
		$status = isset( $_POST['status'] ) ? (int) $_POST['status'] : $this->default_status;

		if ( ! in_array( $status, $this->allowed_statuses, true ) ) {
			$status = $this->default_status;
		}

		if ( '' === $from ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a source URL.', 'apppresser-wp' ) ), 400 );
		}

		if ( '' === $to ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a destination URL.', 'apppresser-wp' ) ), 400 );
		}

		$redirects = $this->get_redirects();

		// Replace any existing redirect for this source path.
		$updated = false;
		foreach ( $redirects as $key => $redirect ) {
			if ( isset( $redirect['from'] ) && $redirect['from'] === $from ) {
				$redirects[ $key ]['to']     = $to;
				$redirects[ $key ]['status'] = $status;
				$updated                     = true;
				break;
			}
		}

		if ( ! $updated ) {
			$redirects[] = array(
				'from'   => $from,
				'to'     => $to,
				'status' => $status,
			);
		}

		$this->save_redirects( $redirects );

		wp_send_json_success(
			array(
				'redirects' => $this->get_redirects_data(),
			)
		);
	}

	/**
	 * AJAX handler for removing a redirect.
	 */
	public function handle_delete() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$from = isset( $_POST['from'] ) ? $this->normalize_source( wp_unslash( $_POST['from'] ) ) : '';

		$redirects = $this->get_redirects();

		foreach ( $redirects as $key => $redirect ) {
			if ( isset( $redirect['from'] ) && $redirect['from'] === $from ) {
				unset( $redirects[ $key ] );
				break;
			}
		}

		$this->save_redirects( array_values( $redirects ) );

		wp_send_json_success(
			array(
				'redirects' => $this->get_redirects_data(),
			)
		);
	}

	/**
	 * AJAX handler for fetching the current 404 log.
	 */
	public function handle_get_404s() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		wp_send_json_success(
			array(
				'notFound' => $this->get_404s_data(),
			)
		);
	}

	/**
	 * AJAX handler for deleting a single 404 log entry.
	 */
	public function handle_delete_404() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id ) {
			AppPresser_Redirect_404_Log::delete( $id );
		}

		wp_send_json_success(
			array(
				'notFound' => $this->get_404s_data(),
			)
		);
	}

	/**
	 * AJAX handler for clearing the entire 404 log.
	 */
	public function handle_clear_404s() {
		check_ajax_referer( 'apppresser_redirects_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		AppPresser_Redirect_404_Log::clear();

		wp_send_json_success(
			array(
				'notFound' => array(),
			)
		);
	}

	/**
	 * Redirect the visitor if the current request matches a source path.
	 */
	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}

		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$request_path = $this->strip_site_path( $this->normalize_source( $request_path ) );

		if ( '' === $request_path ) {
			return;
		}

		$redirects = $this->get_redirects();

		foreach ( $redirects as $redirect ) {
			if ( isset( $redirect['from'] ) && $redirect['from'] === $request_path && ! empty( $redirect['to'] ) ) {
				$to = $redirect['to'];

				// Resolve relative destinations (e.g. "/sdsdsdsd") against the
				// current site's home URL so they stay within the subsite.
				if ( strpos( $to, '/' ) === 0 && strpos( $to, '//' ) !== 0 ) {
					$to = home_url( $to );
				}

				$status = isset( $redirect['status'] ) ? (int) $redirect['status'] : $this->default_status;
				if ( ! in_array( $status, $this->allowed_statuses, true ) ) {
					$status = $this->default_status;
				}

				// wp_redirect (not wp_safe_redirect) so external destinations work.
				// The destination is already sanitized with esc_url_raw() on save.
				wp_redirect( $to, $status );
				exit;
			}
		}
	}

	/**
	 * Record a 404 hit for the current request.
	 */
	public function log_404() {
		if ( is_admin() || ! is_404() ) {
			return;
		}

		// Don't log 404s for logged-in users (admins/editors previewing pages).
		if ( is_user_logged_in() ) {
			return;
		}

		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$request_path = $this->strip_site_path( $this->normalize_source( $request_path ) );

		if ( '' === $request_path ) {
			return;
		}

		AppPresser_Redirect_404_Log::log_404( $request_path );
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'Redirects', 'apppresser-redirects-root' );
	}
}
