<?php
/**
 * Options admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Options
 *
 * Registers an admin submenu page under Settings for general site options.
 */
class AppPresser_Options {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-options';

	/**
	 * Registered options.
	 *
	 * @var array<string, array{label: string, help: string}>
	 */
	private $options = array(
		'disable_comments'              => array(
			'label' => 'Disable Comments',
			'help'  => 'Completely disable comments across the entire site. Existing comments will be hidden from the frontend.',
		),
		'disable_application_passwords' => array(
			'label' => 'Disable Application Passwords',
			'help'  => 'Disable the WordPress Application Passwords feature for all users.',
		),
		'enable_markdown_urls'          => array(
			'label' => 'Enable LLM .md URLs',
			'help'  => 'Allow posts and pages to be accessed as Markdown by appending .md to the URL (e.g. /hello-world.md). After enabling, go to Settings > Permalinks and click Save Changes.',
		),
		'header_banner'                 => array(
			'label' => 'Enable Header Banner',
			'help'  => 'Display a thin banner above the site header. Use the Banner Content panel below to edit the banner text.',
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_options_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'wp_ajax_apppresser_options_save_banner', array( $this, 'handle_save_banner' ) );
		add_action( 'wp_ajax_apppresser_options_save_banner_color', array( $this, 'handle_save_banner_color' ) );
		add_action( 'wp_ajax_apppresser_options_save_banner_text_color', array( $this, 'handle_save_banner_text_color' ) );

		// Apply comment disabling if enabled.
		if ( get_option( 'apppresser_disable_comments_enabled', false ) ) {
			add_action( 'init', array( $this, 'disable_comments' ) );
		}

		// Apply application passwords disabling if enabled.
		if ( get_option( 'apppresser_disable_application_passwords_enabled', false ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		// Apply markdown URLs if enabled.
		if ( get_option( 'apppresser_enable_markdown_urls_enabled', false ) ) {
			add_action( 'init', array( $this, 'add_markdown_rewrite_rule' ) );
			add_filter( 'query_vars', array( $this, 'add_markdown_query_var' ) );
			add_action( 'template_redirect', array( $this, 'handle_markdown_request' ) );
		}

		// Apply header banner if enabled.
		if ( get_option( 'apppresser_header_banner_enabled', false ) ) {
			add_action( 'wp_body_open', array( $this, 'render_header_banner' ) );
		}
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Options', 'apppresser-wp' ),
			__( 'Options', 'apppresser-wp' ),
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
				'apppresser-options',
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
				'apppresser-options',
				'apppresserOptions',
				array(
					'settings'        => $settings,
					'options'         => $this->options,
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'apppresser_options_nonce' ),
					'bannerContent'   => get_option( 'apppresser_header_banner_content', '' ),
					'bannerColor'     => get_option( 'apppresser_header_banner_color', '#1e1e1e' ),
					'bannerTextColor' => get_option( 'apppresser_header_banner_text_color', '#ffffff' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-options',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for toggling any option.
	 */
	public function handle_toggle() {
		check_ajax_referer( 'apppresser_options_nonce', 'nonce' );

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
	 * AJAX handler for saving banner content.
	 */
	public function handle_save_banner() {
		check_ajax_referer( 'apppresser_options_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

		update_option( 'apppresser_header_banner_content', $content );

		wp_send_json_success(
			array(
				'content' => $content,
			)
		);
	}

	/**
	 * AJAX handler for saving banner background color.
	 */
	public function handle_save_banner_color() {
		check_ajax_referer( 'apppresser_options_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$color = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '#1e1e1e';

		if ( ! $color ) {
			$color = '#1e1e1e';
		}

		update_option( 'apppresser_header_banner_color', $color );

		wp_send_json_success(
			array(
				'color' => $color,
			)
		);
	}

	/**
	 * AJAX handler for saving banner text color.
	 */
	public function handle_save_banner_text_color() {
		check_ajax_referer( 'apppresser_options_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$color = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '#ffffff';

		if ( ! $color ) {
			$color = '#ffffff';
		}

		update_option( 'apppresser_header_banner_text_color', $color );

		wp_send_json_success(
			array(
				'color' => $color,
			)
		);
	}

	/**
	 * Render the header banner on the frontend.
	 */
	public function render_header_banner() {
		$content = get_option( 'apppresser_header_banner_content', '' );

		if ( empty( $content ) ) {
			return;
		}

		$bg_color   = get_option( 'apppresser_header_banner_color', '#1e1e1e' );
		$text_color = get_option( 'apppresser_header_banner_text_color', '#ffffff' );
		?>
		<style>
			.apppresser-header-banner a,
			.apppresser-header-banner a:link,
			.apppresser-header-banner a:visited,
			.apppresser-header-banner a:hover,
			.apppresser-header-banner a:active {
				color: <?php echo esc_attr( $text_color ); ?>;
				text-decoration: underline;
			}
		</style>
		<div class="apppresser-header-banner" style="background:<?php echo esc_attr( $bg_color ); ?>;color:<?php echo esc_attr( $text_color ); ?>;text-align:center;padding:6px 16px;font-size:14px;line-height:1.5;position:relative;z-index:9999;">
			<div class="apppresser-header-banner__content">
				<?php echo wp_kses_post( $content ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		?>
		<div class="plugin-header">
			<img src="<?php echo esc_url( APPRESSER_WP_URL . '/apppresser.jpg' ); ?>" alt="AppPresser" class="plugin-header-logo" />
			<h1>Options</h1>
		</div>
		<div id="apppresser-options-root"></div>
		<?php
	}

	/**
	 * Disable comments site-wide.
	 */
	public function disable_comments() {
		// Close comments on the frontend.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );

		// Hide existing comments from display.
		add_filter( 'comments_array', '__return_empty_array', 20 );

		// Remove comments from admin bar.
		add_action( 'admin_bar_menu', array( $this, 'remove_comments_admin_bar' ), 999 );

		// Remove comments from admin menu.
		add_action( 'admin_menu', array( $this, 'remove_comments_admin_menu' ), 999 );
	}

	/**
	 * Remove comments from the admin bar.
	 */
	public function remove_comments_admin_bar() {
		global $wp_admin_bar;
		$wp_admin_bar->remove_menu( 'comments' );
	}

	/**
	 * Remove comments from the admin menu.
	 */
	public function remove_comments_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/**
	 * Add rewrite rule for .md URLs.
	 */
	public function add_markdown_rewrite_rule() {
		add_rewrite_rule(
			'(.+?)\.md$',
			'index.php?markdown_url=$matches[1]',
			'top'
		);
	}

	/**
	 * Add custom query variable for markdown URLs.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_markdown_query_var( $vars ) {
		$vars[] = 'markdown_url';
		return $vars;
	}

	/**
	 * Handle markdown requests and output post content as Markdown.
	 */
	public function handle_markdown_request() {
		$markdown_url = get_query_var( 'markdown_url' );

		if ( ! $markdown_url ) {
			return;
		}

		$enabled_post_types = array( 'post', 'page' );

		// Try to find the post by URL.
		$url = ltrim( $markdown_url, '/' );

		// Try to get post by URL path.
		$post_id = url_to_postid( '/' . $url );
		$post    = $post_id ? get_post( $post_id ) : null;

		// If that doesn't work, try by post name/slug.
		if ( ! $post ) {
			$slug  = basename( $url );
			$posts = get_posts(
				array(
					'name'        => $slug,
					'post_type'   => $enabled_post_types,
					'post_status' => 'publish',
					'numberposts' => 1,
				)
			);

			if ( ! empty( $posts ) ) {
				$post = $posts[0];
			}
		}

		if ( ! $post || ! in_array( $post->post_type, $enabled_post_types, true ) ) {
			status_header( 404 );
			nocache_headers();
			echo 'Post not found or markdown not enabled for this post type.';
			exit;
		}

		// Generate markdown content.
		$markdown_content = '# ' . get_the_title( $post ) . "\n\n";

		// Add metadata.
		$markdown_content .= '**Published:** ' . get_the_date( 'F j, Y', $post ) . "\n";

		// Add categories for posts.
		if ( 'post' === $post->post_type ) {
			$categories = get_the_category( $post->ID );
			if ( ! empty( $categories ) ) {
				$cat_names         = array_map(
					function ( $cat ) {
						return $cat->name;
					},
					$categories
				);
				$markdown_content .= '**Categories:** ' . implode( ', ', $cat_names ) . "\n";
			}

			// Add tags for posts.
			$tags = get_the_tags( $post->ID );
			if ( ! empty( $tags ) ) {
				$tag_names         = array_map(
					function ( $tag ) {
						return $tag->name;
					},
					$tags
				);
				$markdown_content .= '**Tags:** ' . implode( ', ', $tag_names ) . "\n";
			}
		}

		$markdown_content .= "\n---\n\n";

		// Convert HTML content to markdown-friendly format.
		$post_content      = apply_filters( 'the_content', $post->post_content );
		$post_content      = $this->html_to_markdown( $post_content );
		$markdown_content .= $post_content;

		// Add permalink at the end.
		$markdown_content .= "\n\n---\n\n";
		$markdown_content .= '**Original URL:** ' . get_permalink( $post ) . "\n";

		// Set proper headers.
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $post->post_name ) . '.md"' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $markdown_content;
		exit;
	}

	/**
	 * Convert HTML to Markdown.
	 *
	 * @param string $html The HTML content.
	 * @return string
	 */
	private function html_to_markdown( $html ) {
		$html = trim( preg_replace( '/\s+/', ' ', $html ) );

		// Headings.
		$html = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/i', "\n# $1\n", $html );
		$html = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/i', "\n## $1\n", $html );
		$html = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/i', "\n### $1\n", $html );
		$html = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/i', "\n#### $1\n", $html );
		$html = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/i', "\n##### $1\n", $html );
		$html = preg_replace( '/<h6[^>]*>(.*?)<\/h6>/i', "\n###### $1\n", $html );

		// Paragraphs.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/i', "$1\n\n", $html );

		// Line breaks.
		$html = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $html );

		// Bold and italic.
		$html = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/i', '**$2**', $html );
		$html = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/i', '*$2*', $html );

		// Links.
		$html = preg_replace( '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/i', '[$2]($1)', $html );

		// Images.
		$html = preg_replace( '/<img[^>]*src=["\']([^"\']*)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/i', '![$2]($1)', $html );
		$html = preg_replace( '/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']*)["\'][^>]*\/?>/i', '![$1]($2)', $html );
		$html = preg_replace( '/<img[^>]*src=["\']([^"\']*)["\'][^>]*\/?>/i', '![]($1)', $html );

		// Lists.
		$html = preg_replace( '/<ul[^>]*>/i', '', $html );
		$html = preg_replace( '/<\/ul>/i', "\n", $html );
		$html = preg_replace( '/<ol[^>]*>/i', '', $html );
		$html = preg_replace( '/<\/ol>/i', "\n", $html );
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/i', "- $1\n", $html );

		// Blockquotes.
		$html = preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			function ( $matches ) {
				$lines  = explode( "\n", trim( $matches[1] ) );
				$quoted = array_map(
					function ( $line ) {
						return '> ' . trim( $line );
					},
					$lines
				);
				return "\n" . implode( "\n", $quoted ) . "\n\n";
			},
			$html
		);

		// Code blocks.
		$html = preg_replace_callback(
			'/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is',
			function ( $matches ) {
				return "\n```\n" . html_entity_decode( wp_strip_all_tags( $matches[1] ) ) . "\n```\n\n";
			},
			$html
		);

		// Inline code.
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/i', '`$1`', $html );

		// Strip remaining tags and decode entities.
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

		// Collapse excessive newlines.
		$html = preg_replace( '/\n\s*\n\s*\n/', "\n\n", $html );

		return trim( $html );
	}
}
