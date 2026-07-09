<?php
/**
 * Maintenance mode admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Maintenance
 *
 * Registers an admin submenu page under Settings for maintenance mode.
 */
class AppPresser_Maintenance {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-maintenance';

	/**
	 * Option key for maintenance mode enabled state.
	 *
	 * @var string
	 */
	private $option_enabled = 'apppresser_maintenance_enabled';

	/**
	 * Option key for the maintenance page title.
	 *
	 * @var string
	 */
	private $option_title = 'apppresser_maintenance_title';

	/**
	 * Option key for the maintenance page content.
	 *
	 * @var string
	 */
	private $option_content = 'apppresser_maintenance_content';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_maintenance_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'wp_ajax_apppresser_maintenance_save_settings', array( $this, 'handle_save_settings' ) );

		// Only activate the coming-soon page if the option is enabled.
		if ( get_option( $this->option_enabled, false ) ) {
			add_action( 'template_redirect', array( $this, 'coming_soon_mode' ) );
		}
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Maintenance', 'apppresser-wp' ),
			__( 'Maintenance', 'apppresser-wp' ),
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
				'apppresser-maintenance',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				'apppresser-maintenance',
				'apppresserMaintenance',
				array(
					'enabled' => get_option( $this->option_enabled, false ),
					'title'   => get_option( $this->option_title, 'Under Maintenance' ),
					'content' => get_option( $this->option_content, 'Our website is currently under construction. Please check back later!' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'apppresser_maintenance_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-maintenance',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for toggling maintenance mode.
	 */
	public function handle_toggle() {
		check_ajax_referer( 'apppresser_maintenance_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$enabled = isset( $_POST['enabled'] ) ? rest_sanitize_boolean( $_POST['enabled'] ) : false;

		update_option( $this->option_enabled, $enabled );

		wp_send_json_success(
			array(
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * AJAX handler for saving title and content settings.
	 */
	public function handle_save_settings() {
		check_ajax_referer( 'apppresser_maintenance_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

		update_option( $this->option_title, $title );
		update_option( $this->option_content, $content );

		wp_send_json_success(
			array(
				'title'   => $title,
				'content' => $content,
			)
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		?>
		<div class="plugin-header">
			<img src="<?php echo esc_url( APPRESSER_WP_URL . '/apppresser.jpg' ); ?>" alt="AppPresser" class="plugin-header-logo" />
			<h1>Maintenance</h1>
		</div>
		<div id="apppresser-maintenance-root"></div>
		<?php
	}

	/**
	 * Display a coming-soon / maintenance page for non-logged-in users.
	 */
	public function coming_soon_mode() {
		// Check if the user is NOT logged in and NOT on the login page.
		if ( ! is_user_logged_in() && $GLOBALS['pagenow'] !== 'wp-login.php' ) {

			$title   = get_option( $this->option_title, 'Under Maintenance' );
			$content = get_option( $this->option_content, 'Our website is currently under construction. Please check back later!' );

			// Set a 503 Service Unavailable header so SEO isn't negatively impacted.
			header( 'HTTP/1.1 503 Service Unavailable' );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Retry-After: 3600' ); // Suggest retry in 1 hour.

			?>
			<!DOCTYPE html>
			<html>
			<head>
				<title><?php echo esc_html( $title ); ?></title>
				<style>
					body { text-align: center; padding: 150px; font-family: sans-serif; }
					h1 { font-size: 50px; color: #333; }
					p { font-size: 20px; color: #666; }
					.container { max-width: 760px; margin: 0 auto; padding: 50px; }
				</style>
			</head>
			<body>
				<div class="container">
					<h1><?php echo esc_html( $title ); ?></h1>
					<p><?php echo wp_kses_post( nl2br( $content ) ); ?></p>
				</div>
			</body>
			</html>
			<?php
			die(); // Stop further execution.
		}
	}
}
