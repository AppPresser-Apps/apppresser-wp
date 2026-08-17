<?php
/**
 * Pop-ups admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Pop_Ups
 *
 * Registers an admin submenu page under Settings for pop-ups.
 */
class AppPresser_Pop_Ups {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-pop-ups';

	/**
	 * Option key for storing pop-ups.
	 *
	 * @var string
	 */
	private $option_key = 'apppresser_pop_ups';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_pop_ups_save', array( $this, 'handle_save' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Pop Ups', 'apppresser-wp' ),
			__( 'Pop Ups', 'apppresser-wp' ),
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
				'apppresser-pop-ups',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Enqueue the classic editor and media library for TinyMCE WYSIWYG.
			wp_enqueue_editor();
			wp_enqueue_media();

			wp_localize_script(
				'apppresser-pop-ups',
				'apppresserPopUps',
				array(
					'popUps'  => $this->get_pop_ups(),
					'pages'   => $this->get_pages_for_select(),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'apppresser_pop_ups_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-pop-ups',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * Get saved pop-ups.
	 *
	 * @return array<int, array{id: string, enabled: bool, content: string, trigger: string}>
	 */
	private function get_pop_ups() {
		$pop_ups = get_option( $this->option_key, array() );

		if ( ! is_array( $pop_ups ) ) {
			return array();
		}

		return array_values( $pop_ups );
	}

	/**
	 * AJAX handler for saving pop-ups.
	 */
	public function handle_save() {
		check_ajax_referer( 'apppresser_pop_ups_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$raw = isset( $_POST['popUps'] ) ? wp_unslash( $_POST['popUps'] ) : '';

		$pop_ups = json_decode( $raw, true );

		if ( ! is_array( $pop_ups ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data.' ), 400 );
		}

		$sanitized = array();

		foreach ( $pop_ups as $pop_up ) {
			$sanitized[] = array(
				'id'        => isset( $pop_up['id'] ) ? sanitize_text_field( $pop_up['id'] ) : wp_generate_uuid4(),
				'enabled'   => isset( $pop_up['enabled'] ) ? rest_sanitize_boolean( $pop_up['enabled'] ) : false,
				'content'   => isset( $pop_up['content'] ) ? wp_kses_post( $pop_up['content'] ) : '',
				'trigger'   => isset( $pop_up['trigger'] ) ? sanitize_text_field( $pop_up['trigger'] ) : 'page_load',
				'dismissal' => isset( $pop_up['dismissal'] ) ? sanitize_text_field( $pop_up['dismissal'] ) : 'every_page_load',
				'pages'     => isset( $pop_up['pages'] ) ? array_map( 'absint', $pop_up['pages'] ) : array(),
			);
		}

		update_option( $this->option_key, $sanitized );

		wp_send_json_success(
			array(
				'popUps' => $sanitized,
			)
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'Pop Ups', 'apppresser-pop-ups-root' );
	}

	/**
	 * Get published pages formatted for a SelectControl.
	 *
	 * @return array<int, array{value: int, label: string}>
	 */
	private function get_pages_for_select() {
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);

		$options = array();

		foreach ( $pages as $page ) {
			$options[] = array(
				'value' => $page->ID,
				'label' => $page->post_title,
			);
		}

		return $options;
	}

	/**
	 * Enqueue frontend scripts and styles for pop-ups.
	 */
	public function enqueue_frontend_assets() {
		$pop_ups = $this->get_pop_ups();

		// Only enqueue if there are enabled pop-ups.
		$enabled = array_filter(
			$pop_ups,
			function ( $p ) {
				return ! empty( $p['enabled'] );
			}
		);

		if ( empty( $enabled ) ) {
			return;
		}

		wp_enqueue_style(
			'apppresser-pop-ups',
			APPRESSER_WP_URL . '/assets/css/pop-ups.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'apppresser-pop-ups',
			APPRESSER_WP_URL . '/assets/js/pop-ups.js',
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'apppresser-pop-ups',
			'apppresserPopUpsFrontend',
			array(
				'popUps'      => $pop_ups,
				'currentPage' => is_singular( 'page' ) ? get_the_ID() : 0,
			)
		);
	}
}
