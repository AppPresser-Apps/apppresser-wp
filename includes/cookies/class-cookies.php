<?php
/**
 * Cookies admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Cookies
 *
 * Registers an admin submenu page under Settings and handles
 * cookie consent settings via WordPress options.
 */
class AppPresser_Cookies {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-cookies';

	/**
	 * Option keys and their defaults.
	 *
	 * @var array<string, array{default: mixed, sanitize: string}>
	 */
	private $option_defaults = array(
		'apppresser_cookie_consent_enabled'  => array(
			'default'  => false,
			'sanitize' => 'bool',
		),
		'apppresser_cookie_policy_page'      => array(
			'default'  => 0,
			'sanitize' => 'int',
		),
		'apppresser_cookie_consent_duration' => array(
			'default'  => 30,
			'sanitize' => 'int',
		),
		'apppresser_cookie_banner_position'  => array(
			'default'  => 'left_bottom',
			'sanitize' => 'key',
		),
		'apppresser_cookie_message'          => array(
			'default'  => '',
			'sanitize' => 'html',
		),
		'apppresser_cookie_button_settings'  => array(
			'default'  => '',
			'sanitize' => 'text',
		),
		'apppresser_cookie_button_reject'    => array(
			'default'  => '',
			'sanitize' => 'text',
		),
		'apppresser_cookie_button_accept'    => array(
			'default'  => '',
			'sanitize' => 'text',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_cookies_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Cookies', 'apppresser-wp' ),
			__( 'Cookies', 'apppresser-wp' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Get all cookie consent settings from options.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = array();
		foreach ( $this->option_defaults as $key => $data ) {
			$settings[ $key ] = get_option( $key, $data['default'] );
		}
		return $settings;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	public function get_setting( $key ) {
		if ( isset( $this->option_defaults[ $key ] ) ) {
			return get_option( $key, $this->option_defaults[ $key ]['default'] );
		}
		return null;
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
				'apppresser-cookies',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Build pages list for the policy page selector.
			$pages        = get_pages();
			$page_options = array(
				array(
					'value' => 0,
					'label' => __( '— None —', 'apppresser-wp' ),
				),
			);
			foreach ( $pages as $page ) {
				$page_options[] = array(
					'value' => $page->ID,
					'label' => $page->post_title,
				);
			}

			// Banner position options.
			$position_options = array(
				array(
					'value' => 'left_bottom',
					'label' => __( 'Bottom Left', 'apppresser-wp' ),
				),
				array(
					'value' => 'right_bottom',
					'label' => __( 'Bottom Right', 'apppresser-wp' ),
				),
				array(
					'value' => 'left_top',
					'label' => __( 'Top Left', 'apppresser-wp' ),
				),
				array(
					'value' => 'right_top',
					'label' => __( 'Top Right', 'apppresser-wp' ),
				),
			);

			wp_localize_script(
				'apppresser-cookies',
				'apppresserCookies',
				array(
					'settings'        => $this->get_settings(),
					'pages'           => $page_options,
					'positions'       => $position_options,
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'apppresser_cookies_nonce' ),
					'scannerNonce'    => wp_create_nonce( 'apppresser_scan_cookies' ),
					'detectedCookies' => get_option( 'apppresser_detected_cookies', array() ),
					'scanAction'      => 'scan_cookies',
					'scanField'       => 'apppresser_action',
					'scanNonceField'  => 'apppresser_scan_nonce',
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-cookies',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for saving cookie consent settings.
	 */
	public function handle_save() {
		check_ajax_referer( 'apppresser_cookies_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! array_key_exists( $key, $this->option_defaults ) ) {
			wp_send_json_error( array( 'message' => 'Invalid option key.' ), 400 );
		}

		$sanitize = $this->option_defaults[ $key ]['sanitize'];

		switch ( $sanitize ) {
			case 'bool':
				$value = rest_sanitize_boolean( $value );
				break;
			case 'int':
				$value = absint( $value );
				break;
			case 'key':
				$value = sanitize_key( $value );
				break;
			case 'html':
				$value = wp_kses_post( $value );
				break;
			case 'text':
			default:
				$value = sanitize_text_field( $value );
				break;
		}

		update_option( $key, $value );

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'Cookies', array( 'apppresser-cookies-root', 'apppresser-cookie-scanner-root' ) );
	}
}
