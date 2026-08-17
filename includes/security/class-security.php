<?php
/**
 * Security admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Security
 *
 * Registers an admin submenu page under Settings for security options.
 */
class AppPresser_Security {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-security';

	/**
	 * XML-RPC mode options.
	 *
	 * @var array<int, array{label: string, value: string}>
	 */
	private $xmlrpc_modes = array(
		array(
			'label' => 'Disable XML-RPC',
			'value' => 'disable',
		),
		array(
			'label' => 'Disable Pingbacks',
			'value' => 'disable_pingbacks',
		),
		array(
			'label' => 'Enable XML-RPC',
			'value' => 'enable',
		),
	);

	/**
	 * REST API access options.
	 *
	 * @var array<int, array{label: string, value: string}>
	 */
	private $rest_api_modes = array(
		array(
			'label' => 'Default Access',
			'value' => 'default',
		),
		array(
			'label' => 'Restricted Access',
			'value' => 'restricted',
		),
	);

	/**
	 * Login identifier options.
	 *
	 * @var array<int, array{label: string, value: string}>
	 */
	private $login_id_modes = array(
		array(
			'label' => 'Email Address and Username',
			'value' => 'default',
		),
		array(
			'label' => 'Email Address Only',
			'value' => 'email',
		),
		array(
			'label' => 'Username Only',
			'value' => 'username',
		),
	);

	/**
	 * Simple boolean settings, mapping setting key to option name.
	 *
	 * @var array<string, string>
	 */
	private $boolean_settings = array(
		'xmlrpc_multiauth'            => 'apppresser_xmlrpc_multiauth_enabled',
		'force_unique_nickname'       => 'apppresser_force_unique_nickname_enabled',
		'disable_extra_user_archives' => 'apppresser_disable_extra_user_archives_enabled',
		'disable_generator_tag'       => 'apppresser_disable_generator_tag_enabled',
		'disable_rss_generator'       => 'apppresser_disable_rss_generator_enabled',
		'disable_resource_versions'   => 'apppresser_disable_resource_versions_enabled',
		'disable_shortlink'           => 'apppresser_disable_shortlink_enabled',
		'disable_emojis'              => 'apppresser_disable_emojis_enabled',
		'disable_wlw_manifest'        => 'apppresser_disable_wlw_manifest_enabled',
		'disable_rsd'                 => 'apppresser_disable_rsd_enabled',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_security_save_setting', array( $this, 'handle_save_setting' ) );

		$xmlrpc_mode = $this->get_xmlrpc_mode();

		if ( 'disable' === $xmlrpc_mode ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		} elseif ( 'disable_pingbacks' === $xmlrpc_mode ) {
			add_filter( 'xmlrpc_methods', array( $this, 'remove_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		}

		if ( ! get_option( 'apppresser_xmlrpc_multiauth_enabled', false ) ) {
			add_filter( 'xmlrpc_methods', array( $this, 'remove_multicall_method' ) );
		}

		if ( 'restricted' === $this->get_rest_api_access() ) {
			add_filter( 'rest_authentication_errors', array( $this, 'restrict_rest_api' ) );
		}

		$login_id_mode = $this->get_login_id_mode();

		if ( 'email' === $login_id_mode ) {
			remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
		} elseif ( 'username' === $login_id_mode ) {
			remove_filter( 'authenticate', 'wp_authenticate_email_password', 20 );
		}

		if ( get_option( 'apppresser_force_unique_nickname_enabled', false ) ) {
			add_action( 'user_profile_update_errors', array( $this, 'enforce_unique_nickname' ), 10, 3 );
		}

		if ( get_option( 'apppresser_disable_extra_user_archives_enabled', true ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_404_empty_author_archive' ) );
		}

		if ( get_option( 'apppresser_disable_generator_tag_enabled', false ) ) {
			remove_action( 'wp_head', 'wp_generator' );
		}

		if ( get_option( 'apppresser_disable_rss_generator_enabled', false ) ) {
			add_filter( 'get_the_generator_rss2', '__return_empty_string' );
			add_filter( 'get_the_generator_rss', '__return_empty_string' );
			add_filter( 'get_the_generator_rdf', '__return_empty_string' );
			add_filter( 'get_the_generator_atom', '__return_empty_string' );
			add_filter( 'get_the_generator_comment', '__return_empty_string' );
		}

		if ( get_option( 'apppresser_disable_resource_versions_enabled', false ) ) {
			add_action( 'init', array( $this, 'maybe_strip_resource_versions' ) );
		}

		if ( get_option( 'apppresser_disable_shortlink_enabled', false ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		}

		if ( get_option( 'apppresser_disable_emojis_enabled', false ) ) {
			$this->disable_emojis();
		}

		if ( get_option( 'apppresser_disable_wlw_manifest_enabled', false ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( get_option( 'apppresser_disable_rsd_enabled', false ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
	}

	/**
	 * Get the saved XML-RPC mode.
	 *
	 * @return string
	 */
	private function get_xmlrpc_mode() {
		return get_option( 'apppresser_xmlrpc_mode', 'disable_pingbacks' );
	}

	/**
	 * Get the saved REST API access mode.
	 *
	 * @return string
	 */
	private function get_rest_api_access() {
		return get_option( 'apppresser_rest_api_access', 'default' );
	}

	/**
	 * Get the saved login identifier mode.
	 *
	 * @return string
	 */
	private function get_login_id_mode() {
		return get_option( 'apppresser_login_id_mode', 'default' );
	}

	/**
	 * Remove the pingback XML-RPC methods.
	 *
	 * @param array $methods Registered XML-RPC methods.
	 * @return array
	 */
	public function remove_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	/**
	 * Remove the system.multicall XML-RPC method, which allows many
	 * authentication attempts to be bundled into a single request.
	 *
	 * @param array $methods Registered XML-RPC methods.
	 * @return array
	 */
	public function remove_multicall_method( $methods ) {
		unset( $methods['system.multicall'] );
		return $methods;
	}

	/**
	 * Remove the X-Pingback response header.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/**
	 * Restrict REST API access to authenticated users.
	 *
	 * @param WP_Error|null|bool $result Current authentication error result.
	 * @return WP_Error|null|bool
	 */
	public function restrict_rest_api( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'apppresser_rest_restricted',
				__( 'The REST API is restricted to authenticated users on this site.', 'apppresser-wp' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $result;
	}

	/**
	 * Reject profile updates that would result in a duplicate nickname.
	 *
	 * @param WP_Error $errors Validation error collection, passed by reference by WordPress.
	 * @param bool     $update Whether this is an existing user being updated.
	 * @param stdClass $user   The user object being saved, passed by reference by WordPress.
	 */
	public function enforce_unique_nickname( $errors, $update, $user ) {
		if ( empty( $user->nickname ) ) {
			return;
		}

		$existing = get_users(
			array(
				'meta_key'   => 'nickname', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $user->nickname, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'exclude'    => $update ? array( $user->ID ) : array(),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( ! empty( $existing ) ) {
			$errors->add( 'apppresser_duplicate_nickname', __( 'That nickname is already in use by another user. Please choose a unique nickname.', 'apppresser-wp' ) );
		}
	}

	/**
	 * Return a 404 for author archives belonging to users with no published posts.
	 */
	public function maybe_404_empty_author_archive() {
		if ( ! is_author() ) {
			return;
		}

		$author = get_queried_object();

		if ( ! ( $author instanceof WP_User ) ) {
			return;
		}

		if ( count_user_posts( $author->ID, 'post', true ) > 0 ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Strip the "ver" query arg from enqueued style/script URLs for
	 * logged-out frontend requests, so no WordPress version number leaks.
	 */
	public function maybe_strip_resource_versions() {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		add_filter( 'style_loader_src', array( $this, 'remove_version_query_arg' ), 9999 );
		add_filter( 'script_loader_src', array( $this, 'remove_version_query_arg' ), 9999 );
	}

	/**
	 * Remove the "ver" query arg from a resource URL.
	 *
	 * @param string $src Resource URL.
	 * @return string
	 */
	public function remove_version_query_arg( $src ) {
		if ( strpos( $src, 'ver=' ) === false ) {
			return $src;
		}

		return remove_query_arg( 'ver', $src );
	}

	/**
	 * Disable emoji support on the admin and frontend.
	 */
	private function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', array( $this, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( $this, 'disable_emojis_remove_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove the emoji plugin from TinyMCE.
	 *
	 * @param array $plugins TinyMCE plugins.
	 * @return array
	 */
	public function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}

		return array();
	}

	/**
	 * Remove the emoji CDN from the DNS prefetch resource hints.
	 *
	 * @param array  $urls          Resource hint URLs.
	 * @param string $relation_type Relation type of the resource hints.
	 * @return array
	 */
	public function disable_emojis_remove_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$emoji_svg_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/' );
			$urls          = array_filter(
				$urls,
				function ( $url ) use ( $emoji_svg_url ) {
					return strpos( $url, $emoji_svg_url ) !== 0;
				}
			);
		}

		return $urls;
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Security', 'apppresser-wp' ),
			__( 'Security', 'apppresser-wp' ),
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
				'apppresser-security',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				'apppresser-security',
				'apppresserSecurity',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'apppresser_security_nonce' ),
					'xmlrpcModes'  => $this->xmlrpc_modes,
					'restApiModes' => $this->rest_api_modes,
					'loginIdModes' => $this->login_id_modes,
					'settings'     => array(
						'xmlrpc_mode'                 => $this->get_xmlrpc_mode(),
						'xmlrpc_multiauth'            => (bool) get_option( 'apppresser_xmlrpc_multiauth_enabled', false ),
						'rest_api_access'              => $this->get_rest_api_access(),
						'login_id_mode'                => $this->get_login_id_mode(),
						'force_unique_nickname'        => (bool) get_option( 'apppresser_force_unique_nickname_enabled', false ),
						'disable_extra_user_archives'  => (bool) get_option( 'apppresser_disable_extra_user_archives_enabled', true ),
						'disable_generator_tag'        => (bool) get_option( 'apppresser_disable_generator_tag_enabled', false ),
						'disable_rss_generator'        => (bool) get_option( 'apppresser_disable_rss_generator_enabled', false ),
						'disable_resource_versions'    => (bool) get_option( 'apppresser_disable_resource_versions_enabled', false ),
						'disable_shortlink'            => (bool) get_option( 'apppresser_disable_shortlink_enabled', false ),
						'disable_emojis'               => (bool) get_option( 'apppresser_disable_emojis_enabled', false ),
						'disable_wlw_manifest'         => (bool) get_option( 'apppresser_disable_wlw_manifest_enabled', false ),
						'disable_rsd'                  => (bool) get_option( 'apppresser_disable_rsd_enabled', false ),
					),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-security',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for saving a single security setting.
	 */
	public function handle_save_setting() {
		check_ajax_referer( 'apppresser_security_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';

		switch ( $key ) {
			case 'xmlrpc_mode':
				$value         = isset( $_POST['value'] ) ? sanitize_key( wp_unslash( $_POST['value'] ) ) : '';
				$allowed_modes = wp_list_pluck( $this->xmlrpc_modes, 'value' );

				if ( ! in_array( $value, $allowed_modes, true ) ) {
					wp_send_json_error( array( 'message' => 'Invalid XML-RPC mode.' ), 400 );
				}

				update_option( 'apppresser_xmlrpc_mode', $value );
				break;

			case 'rest_api_access':
				$value         = isset( $_POST['value'] ) ? sanitize_key( wp_unslash( $_POST['value'] ) ) : '';
				$allowed_modes = wp_list_pluck( $this->rest_api_modes, 'value' );

				if ( ! in_array( $value, $allowed_modes, true ) ) {
					wp_send_json_error( array( 'message' => 'Invalid REST API access mode.' ), 400 );
				}

				update_option( 'apppresser_rest_api_access', $value );
				break;

			case 'login_id_mode':
				$value         = isset( $_POST['value'] ) ? sanitize_key( wp_unslash( $_POST['value'] ) ) : '';
				$allowed_modes = wp_list_pluck( $this->login_id_modes, 'value' );

				if ( ! in_array( $value, $allowed_modes, true ) ) {
					wp_send_json_error( array( 'message' => 'Invalid login identifier mode.' ), 400 );
				}

				update_option( 'apppresser_login_id_mode', $value );
				break;

			default:
				if ( ! array_key_exists( $key, $this->boolean_settings ) ) {
					wp_send_json_error( array( 'message' => 'Invalid setting key.' ), 400 );
				}

				$value = isset( $_POST['value'] ) ? rest_sanitize_boolean( $_POST['value'] ) : false;
				update_option( $this->boolean_settings[ $key ], $value );
		}

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Render the admin page markup.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'Security', 'apppresser-security-root' );
	}
}
