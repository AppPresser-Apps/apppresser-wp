<?php
/**
 * Accessibility admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Accessibility
 *
 * Registers an admin submenu page under Settings.
 */
class AppPresser_Accessibility {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-accessibility';

	/**
	 * Registered accessibility options.
	 *
	 * @var array<string, array{label: string, help: string, panel: string}>
	 */
	private $options = array(
		'font_size'      => array(
			'label' => 'Enable large font size',
			'help'  => 'Increases the base font size across the site for better readability.',
			'panel' => 'content',
		),
		'readable_font'  => array(
			'label' => 'Readable Font',
			'help'  => 'Switches to a highly legible, sans-serif font for improved readability.',
			'panel' => 'content',
		),
		'line_height'    => array(
			'label' => 'Line Height',
			'help'  => 'Increases line spacing to make text easier to follow.',
			'panel' => 'content',
		),
		'big_cursor'     => array(
			'label' => 'Big Cursor',
			'help'  => 'Enlarges the mouse cursor for better visibility.',
			'panel' => 'content',
		),
		'text_magnifier' => array(
			'label' => 'Text Magnifier',
			'help'  => 'Adds a text magnifier tool that enlarges text on hover.',
			'panel' => 'content',
		),
		'dyslexic_font'  => array(
			'label' => 'Dyslexic Font',
			'help'  => 'Switches to a dyslexia-friendly font designed for easier reading.',
			'panel' => 'content',
		),
		'align_text'     => array(
			'label' => 'Align Text',
			'help'  => 'Left-aligns all text for a consistent reading experience.',
			'panel' => 'content',
		),
		'font_weight'    => array(
			'label' => 'Font Weight',
			'help'  => 'Increases font weight (boldness) for better contrast and readability.',
			'panel' => 'content',
		),
		'dark_contrast'  => array(
			'label' => 'Dark Contrast',
			'help'  => 'Applies a dark background with light text for reduced eye strain.',
			'panel' => 'color',
		),
		'light_contrast' => array(
			'label' => 'Light Contrast',
			'help'  => 'Applies a light background with dark text for a clean reading experience.',
			'panel' => 'color',
		),
		'high_contrast'  => array(
			'label' => 'High Contrast',
			'help'  => 'Maximizes contrast between text and background for maximum readability.',
			'panel' => 'color',
		),
		'monochrome'     => array(
			'label' => 'Monochrome',
			'help'  => 'Converts the site to black and white, removing all color.',
			'panel' => 'color',
		),
		'saturation'     => array(
			'label' => 'Saturation',
			'help'  => 'Adjusts color saturation levels for better visual comfort.',
			'panel' => 'color',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_accessibility_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Accessibility', 'apppresser-wp' ),
			__( 'Accessibility', 'apppresser-wp' ),
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
				'apppresser-accessibility',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			$settings = array();
			foreach ( $this->options as $key => $data ) {
				$settings[ $key ] = get_option( 'apppresser_' . $key . '_enabled', false );
			}

			wp_localize_script(
				'apppresser-accessibility',
				'apppresserAccessibility',
				array(
					'settings' => $settings,
					'options'  => $this->options,
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'apppresser_accessibility_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-accessibility',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for toggling any accessibility setting.
	 */
	public function handle_toggle() {
		check_ajax_referer( 'apppresser_accessibility_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$key     = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
		$enabled = isset( $_POST['enabled'] ) ? rest_sanitize_boolean( $_POST['enabled'] ) : false;

		if ( ! array_key_exists( $key, $this->options ) ) {
			wp_send_json_error( array( 'message' => 'Invalid option key.' ), 400 );
		}

		update_option( 'apppresser_' . $key . '_enabled', $enabled );

		wp_send_json_success(
			array(
				'key'     => $key,
				'enabled' => $enabled,
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
			<H1>Accessibility</H1>
		</div>
		<div id="apppresser-accessibility-root"></div>
		<?php
	}

	/**
	 * Register the Accessibility Button block.
	 */
	public function register_block() {
		register_block_type( APPRESSER_WP_DIR . 'blocks/accessibility-button' );
	}

	/**
	 * Enqueue the block editor script for the Accessibility Button block.
	 */
	public function enqueue_block_editor_assets() {
		// Editor script for the block.
		wp_enqueue_script(
			'apppresser-accessibility-button-editor',
			APPRESSER_WP_URL . '/blocks/accessibility-button/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			'1.0.0',
			true
		);

		// Editor styles for the block preview.
		wp_enqueue_style(
			'apppresser-accessibility-button-editor',
			APPRESSER_WP_URL . '/blocks/accessibility-button/editor.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Enqueue frontend scripts and styles for the accessibility modal.
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'apppresser-accessibility-modal',
			APPRESSER_WP_URL . '/assets/css/accessibility-modal.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_style(
			'apppresser-accessibility-styles',
			APPRESSER_WP_URL . '/assets/css/accessibility-styles.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'apppresser-accessibility-modal',
			APPRESSER_WP_URL . '/assets/js/accessibility-modal.js',
			array(),
			'1.0.0',
			true
		);

		$settings = array();
		foreach ( $this->options as $key => $data ) {
			$settings[ $key ] = get_option( 'apppresser_' . $key . '_enabled', false );
		}

		wp_localize_script(
			'apppresser-accessibility-modal',
			'apppresserAccessibilityFrontend',
			array(
				'options'  => $this->options,
				'settings' => $settings,
			)
		);
	}
}
