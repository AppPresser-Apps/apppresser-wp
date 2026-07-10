<?php
/**
 * Social Share admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Social_Share
 *
 * Registers an admin submenu page under Settings for social sharing buttons.
 */
class AppPresser_Social_Share {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-social-share';

	/**
	 * Registered social share buttons.
	 *
	 * @var array<string, array{label: string, help: string, bg_color: string, text_color: string}>
	 */
	private $buttons = array(
		'print'    => array(
			'label'      => 'Print',
			'help'       => 'Show a print button that opens the browser print dialog.',
			'bg_color'   => '#333333',
			'text_color' => '#ffffff',
		),
		'email'    => array(
			'label'      => 'Email',
			'help'       => 'Show an email sharing button.',
			'bg_color'   => '#6c757d',
			'text_color' => '#ffffff',
		),
		'facebook' => array(
			'label'      => 'Facebook',
			'help'       => 'Show a Facebook sharing button.',
			'bg_color'   => '#1877F2',
			'text_color' => '#ffffff',
		),
		'twitter'  => array(
			'label'      => 'X (Twitter)',
			'help'       => 'Show an X (Twitter) sharing button.',
			'bg_color'   => '#000000',
			'text_color' => '#ffffff',
		),
		'linkedin' => array(
			'label'      => 'LinkedIn',
			'help'       => 'Show a LinkedIn sharing button.',
			'bg_color'   => '#0A66C2',
			'text_color' => '#ffffff',
		),
		'bluesky'  => array(
			'label'      => 'Bluesky',
			'help'       => 'Show a Bluesky sharing button.',
			'bg_color'   => '#1185FE',
			'text_color' => '#ffffff',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_social_share_toggle', array( $this, 'handle_toggle' ) );
		add_shortcode( 'social_share', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Social Share', 'apppresser-wp' ),
			__( 'Social Share', 'apppresser-wp' ),
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
				'apppresser-social-share',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			$settings = array();
			$colors   = array();
			foreach ( $this->buttons as $key => $data ) {
				$settings[ $key ] = get_option( 'apppresser_social_' . $key . '_enabled', true );
				$colors[ $key ]   = array(
					'bg'   => get_option( 'apppresser_social_' . $key . '_bg_color', $data['bg_color'] ),
					'text' => get_option( 'apppresser_social_' . $key . '_text_color', $data['text_color'] ),
				);
			}

			wp_localize_script(
				'apppresser-social-share',
				'apppresserSocialShare',
				array(
					'settings' => $settings,
					'buttons'  => $this->buttons,
					'colors'   => $colors,
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'apppresser_social_share_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-social-share',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for updating social share button settings.
	 */
	public function handle_toggle() {
		check_ajax_referer( 'apppresser_social_share_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$key   = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
		$field = isset( $_POST['field'] ) ? sanitize_key( $_POST['field'] ) : 'enabled';

		if ( ! array_key_exists( $key, $this->buttons ) ) {
			wp_send_json_error( array( 'message' => 'Invalid button key.' ), 400 );
		}

		if ( 'enabled' === $field ) {
			$enabled = isset( $_POST['enabled'] ) ? rest_sanitize_boolean( $_POST['enabled'] ) : false;
			update_option( 'apppresser_social_' . $key . '_enabled', $enabled );
			wp_send_json_success(
				array(
					'key'   => $key,
					'field' => $field,
					'value' => $enabled,
				)
			);
		} elseif ( in_array( $field, array( 'bg_color', 'text_color' ), true ) ) {
			$value = isset( $_POST['value'] ) ? sanitize_hex_color( wp_unslash( $_POST['value'] ) ) : '';
			if ( empty( $value ) ) {
				wp_send_json_error( array( 'message' => 'Invalid color value.' ), 400 );
			}
			update_option( 'apppresser_social_' . $key . '_' . $field, $value );
			wp_send_json_success(
				array(
					'key'   => $key,
					'field' => $field,
					'value' => $value,
				)
			);
		}

		wp_send_json_error( array( 'message' => 'Invalid field.' ), 400 );
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		?>
		<div class="plugin-header">
			<img src="<?php echo esc_url( APPRESSER_WP_URL . '/apppresser.jpg' ); ?>" alt="AppPresser" class="plugin-header-logo" />
			<h1>Social Share</h1>
		</div>
		<div id="apppresser-social-share-root"></div>
		<?php
	}

	/**
	 * Shortcode to display social sharing buttons.
	 * Usage: [social_share]
	 *
	 * @return string The social share buttons HTML.
	 */
	public function render_shortcode() {
		$post_url   = urlencode( get_permalink() );
		$post_title = urlencode( html_entity_decode( get_the_title(), ENT_QUOTES, 'UTF-8' ) );

		$facebook_url = "https://www.facebook.com/sharer/sharer.php?u={$post_url}";
		$twitter_url  = "https://twitter.com/intent/tweet?url={$post_url}&text={$post_title}";
		$linkedin_url = "https://www.linkedin.com/sharing/share-offsite/?url={$post_url}";
		$bluesky_url  = "https://bsky.app/intent/compose?text={$post_title}%20{$post_url}";
		$email_url    = "mailto:?subject={$post_title}&body=Check out this post: {$post_url}";

		$html = '<div class="custom-social-share" style="display: flex; gap: 10px; flex-wrap: wrap; margin: 20px 0; font-size: 0.8rem">';

		// Print.
		if ( get_option( 'apppresser_social_print_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_print_bg_color', '#333333' );
			$text  = get_option( 'apppresser_social_print_text_color', '#ffffff' );
			$html .= '<a href="javascript:window.print()" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px; cursor: pointer;">Print</a>';
		}

		// Email.
		if ( get_option( 'apppresser_social_email_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_email_bg_color', '#6c757d' );
			$text  = get_option( 'apppresser_social_email_text_color', '#ffffff' );
			$html .= '<a href="' . esc_url( $email_url ) . '" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px;">Email</a>';
		}

		// Facebook.
		if ( get_option( 'apppresser_social_facebook_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_facebook_bg_color', '#1877F2' );
			$text  = get_option( 'apppresser_social_facebook_text_color', '#ffffff' );
			$html .= '<a href="' . esc_url( $facebook_url ) . '" target="_blank" rel="noopener noreferrer" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px;">Facebook</a>';
		}

		// Twitter.
		if ( get_option( 'apppresser_social_twitter_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_twitter_bg_color', '#000000' );
			$text  = get_option( 'apppresser_social_twitter_text_color', '#ffffff' );
			$html .= '<a href="' . esc_url( $twitter_url ) . '" target="_blank" rel="noopener noreferrer" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px;">X (Twitter)</a>';
		}

		// LinkedIn.
		if ( get_option( 'apppresser_social_linkedin_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_linkedin_bg_color', '#0A66C2' );
			$text  = get_option( 'apppresser_social_linkedin_text_color', '#ffffff' );
			$html .= '<a href="' . esc_url( $linkedin_url ) . '" target="_blank" rel="noopener noreferrer" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px;">LinkedIn</a>';
		}

		// Bluesky.
		if ( get_option( 'apppresser_social_bluesky_enabled', true ) ) {
			$bg    = get_option( 'apppresser_social_bluesky_bg_color', '#1185FE' );
			$text  = get_option( 'apppresser_social_bluesky_text_color', '#ffffff' );
			$html .= '<a href="' . esc_url( $bluesky_url ) . '" target="_blank" rel="noopener noreferrer" style="padding: 8px 16px; background-color: ' . esc_attr( $bg ) . '; color: ' . esc_attr( $text ) . '; text-decoration: none; border-radius: 4px;">Bluesky</a>';
		}

		$html .= '</div>';

		return $html;
	}
}
