<?php
/**
 * SEO admin page.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Seo
 *
 * Registers an admin submenu page under Settings for SEO options and
 * outputs the corresponding SEO and Open Graph tags on the frontend.
 */
class AppPresser_Seo {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'apppresser-seo';

	/**
	 * Option key for the Open Graph fallback image attachment ID.
	 *
	 * @var string
	 */
	private $og_fallback_image_option = 'apppresser_og_fallback_image_id';

	/**
	 * Registered SEO settings.
	 *
	 * @var array<string, array{label: string, help: string, type: string, default: mixed}>
	 */
	private $settings = array(
		'twitter_handle'      => array(
			'label'   => 'Twitter/X Handle',
			'help'    => 'Your Twitter/X username, without the @ symbol. Used for the twitter:site tag.',
			'type'    => 'text',
			'default' => '',
		),
		'default_description' => array(
			'label'   => 'Default Meta Description',
			'help'    => 'Fallback description used when a page has no excerpt or content.',
			'type'    => 'textarea',
			'default' => '',
		),
		'title_separator'     => array(
			'label'   => 'Title Separator',
			'help'    => 'Separator used between the page title and site name.',
			'type'    => 'text',
			'default' => ' - ',
		),
		'facebook_app_id'     => array(
			'label'   => 'Facebook App ID',
			'help'    => 'Your Facebook App ID, used for the fb:app_id tag.',
			'type'    => 'text',
			'default' => '',
		),
		'facebook_admins'     => array(
			'label'   => 'Facebook Admins',
			'help'    => 'Comma-separated Facebook user IDs, used for the fb:admins tag.',
			'type'    => 'text',
			'default' => '',
		),
		'noindex_search'      => array(
			'label'   => 'Noindex Search Results',
			'help'    => 'Add noindex to search result pages.',
			'type'    => 'toggle',
			'default' => false,
		),
		'noindex_404'         => array(
			'label'   => 'Noindex 404 Pages',
			'help'    => 'Add noindex to 404 pages.',
			'type'    => 'toggle',
			'default' => false,
		),
		'noindex_archives'    => array(
			'label'   => 'Noindex Archives',
			'help'    => 'Add noindex to category, tag, taxonomy, author, and date archives.',
			'type'    => 'toggle',
			'default' => false,
		),
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_apppresser_seo_save_og_fallback_image', array( $this, 'handle_save_og_fallback_image' ) );
		add_action( 'wp_ajax_apppresser_seo_save_setting', array( $this, 'handle_save_setting' ) );

		// Customize the document title.
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 10 );

		// Output SEO and Open Graph tags on the frontend.
		add_action( 'wp_head', array( $this, 'output_seo_tags' ), 5 );

		// Output structured data (JSON-LD) on the frontend.
		add_action( 'wp_head', array( $this, 'output_structured_data' ), 10 );
	}

	/**
	 * Add the submenu page under Settings.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'SEO', 'apppresser-wp' ),
			__( 'SEO', 'apppresser-wp' ),
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
				'apppresser-seo',
				APPRESSER_WP_URL . '/build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Media library is used to pick the Open Graph fallback image.
			wp_enqueue_media();

			$image_id = (int) get_option( $this->og_fallback_image_option, 0 );

			$values = array();
			foreach ( array_keys( $this->settings ) as $key ) {
				$values[ $key ] = $this->get_setting( $key );
			}

			wp_localize_script(
				'apppresser-seo',
				'apppresserSeo',
				array(
					'ogFallbackImageId'  => $image_id,
					'ogFallbackImageUrl' => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
					'fields'             => $this->settings,
					'values'             => $values,
					'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
					'nonce'              => wp_create_nonce( 'apppresser_seo_nonce' ),
				)
			);

			if ( isset( $asset['version'] ) ) {
				wp_enqueue_style(
					'apppresser-seo',
					APPRESSER_WP_URL . '/build/index.css',
					array( 'wp-components' ),
					$asset['version']
				);
			}
		}
	}

	/**
	 * AJAX handler for saving the Open Graph fallback image.
	 */
	public function handle_save_og_fallback_image() {
		check_ajax_referer( 'apppresser_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$image_id = isset( $_POST['imageId'] ) ? absint( $_POST['imageId'] ) : 0;

		if ( 0 === $image_id ) {
			delete_option( $this->og_fallback_image_option );
			wp_send_json_success(
				array(
					'imageId'  => 0,
					'imageUrl' => '',
				)
			);
		}

		$attachment = get_post( $image_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			wp_send_json_error( array( 'message' => 'Invalid image.' ), 400 );
		}

		update_option( $this->og_fallback_image_option, $image_id );

		wp_send_json_success(
			array(
				'imageId'  => $image_id,
				'imageUrl' => wp_get_attachment_image_url( $image_id, 'full' ),
			)
		);
	}

	/**
	 * AJAX handler for saving a single SEO setting.
	 */
	public function handle_save_setting() {
		check_ajax_referer( 'apppresser_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';

		if ( ! array_key_exists( $key, $this->settings ) ) {
			wp_send_json_error( array( 'message' => 'Invalid setting key.' ), 400 );
		}

		$type = $this->settings[ $key ]['type'];

		if ( 'toggle' === $type ) {
			$value = isset( $_POST['value'] ) ? rest_sanitize_boolean( $_POST['value'] ) : false;
		} elseif ( 'textarea' === $type ) {
			$value = isset( $_POST['value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'] ) ) : '';
		} else {
			$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		}

		update_option( 'apppresser_seo_' . $key, $value );

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Filter the document title.
	 *
	 * @param string $title The current title (empty by default).
	 * @return string
	 */
	public function filter_document_title( $title ) {
		$custom = $this->get_seo_title();

		return '' !== $custom ? $custom : $title;
	}

	/**
	 * Output SEO and Open Graph meta tags on the frontend.
	 *
	 * The image uses the current page's featured image when available,
	 * otherwise falls back to the configured fallback image.
	 */
	public function output_seo_tags() {
		if ( is_admin() ) {
			return;
		}

		$title       = wp_get_document_title();
		$description = $this->get_seo_description();
		$url         = $this->get_seo_url();
		$image       = $this->get_seo_image_url();
		$type        = $this->get_seo_type();
		$site_name   = get_bloginfo( 'name' );
		$locale      = get_locale();

		if ( $description ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}

		if ( $url ) {
			printf(
				'<link rel="canonical" href="%s" />' . "\n",
				esc_url( $url )
			);
		}

		$og = array(
			'og:site_name'   => $site_name,
			'og:title'       => $title,
			'og:description' => $description,
			'og:url'         => $url,
			'og:type'        => $type,
			'og:locale'      => $locale,
		);

		if ( $image ) {
			$og['og:image'] = $image;
		}

		foreach ( $og as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}

			printf(
				'<meta property="%s" content="%s" />' . "\n",
				esc_attr( $property ),
				esc_attr( $content )
			);
		}

		if ( $title ) {
			printf(
				'<meta name="twitter:title" content="%s" />' . "\n",
				esc_attr( $title )
			);
		}

		if ( $description ) {
			printf(
				'<meta name="twitter:description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}

		if ( $image ) {
			printf(
				'<meta name="twitter:image" content="%s" />' . "\n",
				esc_url( $image )
			);
			printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );
		} else {
			printf( '<meta name="twitter:card" content="summary" />' . "\n" );
		}

		$twitter_handle = $this->get_setting( 'twitter_handle' );

		if ( $twitter_handle ) {
			printf(
				'<meta name="twitter:site" content="@%s" />' . "\n",
				esc_attr( ltrim( $twitter_handle, '@' ) )
			);
		}

		$facebook_app_id = $this->get_setting( 'facebook_app_id' );

		if ( $facebook_app_id ) {
			printf(
				'<meta property="fb:app_id" content="%s" />' . "\n",
				esc_attr( $facebook_app_id )
			);
		}

		$facebook_admins = $this->get_setting( 'facebook_admins' );

		if ( $facebook_admins ) {
			printf(
				'<meta property="fb:admins" content="%s" />' . "\n",
				esc_attr( $facebook_admins )
			);
		}

		$robots = $this->should_noindex() ? 'noindex, follow' : 'index, follow';

		printf( '<meta name="robots" content="%s, max-image-preview:large, max-snippet:-1, max-video-preview:-1" />' . "\n", esc_attr( $robots ) );
	}

	/**
	 * Build the SEO title for the current page.
	 *
	 * Returns an empty string for contexts that should fall back to the
	 * default WordPress title (e.g. author and date archives).
	 *
	 * @return string
	 */
	private function get_seo_title() {
		$separator = $this->get_title_separator();

		if ( is_front_page() ) {
			return get_bloginfo( 'name' );
		}

		if ( is_singular() ) {
			$title = single_post_title( '', false );

			if ( $title ) {
				return $title . $separator . get_bloginfo( 'name' );
			}
		}

		if ( is_home() && ! is_front_page() ) {
			$page_for_posts = get_option( 'page_for_posts' );

			if ( $page_for_posts ) {
				$title = get_the_title( $page_for_posts );

				if ( $title ) {
					return $title . $separator . get_bloginfo( 'name' );
				}
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$title = single_term_title( '', false );

			if ( $title ) {
				return $title . $separator . get_bloginfo( 'name' );
			}
		}

		if ( is_search() ) {
			/* translators: %s: Search query. */
			return sprintf( __( 'Search Results for: %s', 'apppresser-wp' ), get_search_query() ) . $separator . get_bloginfo( 'name' );
		}

		if ( is_404() ) {
			return __( 'Page Not Found', 'apppresser-wp' ) . $separator . get_bloginfo( 'name' );
		}

		return '';
	}

	/**
	 * Build the SEO description for the current page.
	 *
	 * @return string
	 */
	private function get_seo_description() {
		if ( is_singular() ) {
			$post = get_queried_object();

			if ( $post && ! empty( $post->post_excerpt ) ) {
				return $this->trim_description( $post->post_excerpt );
			}

			if ( $post && ! empty( $post->post_content ) ) {
				$content = wp_strip_all_tags( $post->post_content );

				if ( '' !== $content ) {
					return $this->trim_description( $content );
				}
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term && ! empty( $term->description ) ) {
				return $this->trim_description( $term->description );
			}
		}

		if ( is_home() && ! is_front_page() ) {
			$page_for_posts = get_option( 'page_for_posts' );

			if ( $page_for_posts ) {
				$post = get_post( $page_for_posts );

				if ( $post && ! empty( $post->post_excerpt ) ) {
					return $this->trim_description( $post->post_excerpt );
				}
			}
		}

		$default = $this->get_setting( 'default_description' );

		if ( '' !== $default ) {
			return $this->trim_description( $default );
		}

		return $this->trim_description( get_bloginfo( 'description' ) );
	}

	/**
	 * Trim a description to a maximum character length without cutting words.
	 *
	 * @param string $text   The text to trim.
	 * @param int    $length Maximum character length.
	 * @return string
	 */
	private function trim_description( $text, $length = 155 ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$text       = mb_substr( $text, 0, $length );
		$last_space = mb_strrpos( $text, ' ' );

		if ( false !== $last_space ) {
			$text = mb_substr( $text, 0, $last_space );
		}

		return rtrim( $text, " \t\n\r\0\x0B,.;:-" ) . '…';
	}

	/**
	 * Build the canonical URL for the current page.
	 *
	 * @return string
	 */
	private function get_seo_url() {
		if ( is_singular() ) {
			return get_permalink();
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term ) {
				return get_term_link( $term );
			}
		}

		if ( is_search() ) {
			return get_search_link();
		}

		if ( is_home() && ! is_front_page() ) {
			return get_permalink( get_option( 'page_for_posts' ) );
		}

		if ( is_front_page() || is_home() || is_404() ) {
			return home_url( '/' );
		}

		return home_url( add_query_arg( array() ) );
	}

	/**
	 * Determine the Open Graph type for the current page.
	 *
	 * @return string
	 */
	private function get_seo_type() {
		return is_singular( 'post' ) ? 'article' : 'website';
	}

	/**
	 * Resolve the Open Graph image URL for the current page.
	 *
	 * @return string
	 */
	private function get_seo_image_url() {
		if ( is_singular() && ! is_front_page() ) {
			$post_id = get_queried_object_id();

			if ( has_post_thumbnail( $post_id ) ) {
				return get_the_post_thumbnail_url( $post_id, 'full' );
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term ) {
				$image_id = get_term_meta( $term->term_id, 'thumbnail_id', true );

				if ( $image_id ) {
					$image_url = wp_get_attachment_image_url( $image_id, 'full' );

					if ( $image_url ) {
						return $image_url;
					}
				}
			}
		}

		$image_id = (int) get_option( $this->og_fallback_image_option, 0 );

		if ( $image_id ) {
			return wp_get_attachment_image_url( $image_id, 'full' );
		}

		return '';
	}

	/**
	 * Get a saved SEO setting value.
	 *
	 * @param string $key The setting key.
	 * @return mixed
	 */
	private function get_setting( $key ) {
		$default = isset( $this->settings[ $key ]['default'] ) ? $this->settings[ $key ]['default'] : '';

		return get_option( 'apppresser_seo_' . $key, $default );
	}

	/**
	 * Get the configured title separator.
	 *
	 * @return string
	 */
	private function get_title_separator() {
		$separator = $this->get_setting( 'title_separator' );

		return '' !== $separator ? $separator : ' - ';
	}

	/**
	 * Whether the current page should be marked noindex.
	 *
	 * @return bool
	 */
	private function should_noindex() {
		if ( is_search() && $this->get_setting( 'noindex_search' ) ) {
			return true;
		}

		if ( is_404() && $this->get_setting( 'noindex_404' ) ) {
			return true;
		}

		if ( $this->get_setting( 'noindex_archives' ) && ( is_category() || is_tag() || is_tax() || is_author() || is_date() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Output structured data (JSON-LD) on the frontend.
	 */
	public function output_structured_data() {
		if ( is_admin() ) {
			return;
		}

		$schemas = array();

		if ( is_front_page() ) {
			$schemas[] = $this->get_organization_schema();
		}

		if ( is_singular( 'post' ) ) {
			$schemas[] = $this->get_article_schema();
		}

		$breadcrumb = $this->get_breadcrumb_schema();

		if ( $breadcrumb ) {
			$schemas[] = $breadcrumb;
		}

		foreach ( $schemas as $schema ) {
			if ( empty( $schema ) ) {
				continue;
			}

			printf(
				'<script type="application/ld+json">%s</script>' . "\n",
				wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
			);
		}
	}

	/**
	 * Build the Organization schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_organization_schema() {
		$logo_id  = get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		if ( ! $logo_url ) {
			$fallback_id = (int) get_option( $this->og_fallback_image_option, 0 );

			if ( $fallback_id ) {
				$logo_url = wp_get_attachment_image_url( $fallback_id, 'full' );
			}
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);

		if ( $logo_url ) {
			$schema['logo'] = $logo_url;
		}

		$same_as = apply_filters( 'apppresser_seo_organization_same_as', array() );

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		return $schema;
	}

	/**
	 * Build the Article schema for a single post.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_article_schema() {
		$post = get_queried_object();

		if ( ! $post ) {
			return null;
		}

		$author_id   = (int) $post->post_author;
		$author_name = get_the_author_meta( 'display_name', $author_id );
		$image_url   = $this->get_seo_image_url();

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title( $post ),
			'datePublished' => get_the_date( 'c', $post->ID ),
			'dateModified'  => get_the_modified_date( 'c', $post->ID ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => $author_name,
				'url'   => get_author_posts_url( $author_id ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
		);

		if ( $image_url ) {
			$schema['image'] = $image_url;
		}

		return $schema;
	}

	/**
	 * Build the BreadcrumbList schema for the current page.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_breadcrumb_schema() {
		if ( is_front_page() ) {
			return null;
		}

		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => __( 'Home', 'apppresser-wp' ),
				'item'     => home_url( '/' ),
			),
		);

		$position = 2;

		if ( is_singular() ) {
			$post = get_queried_object();

			if ( is_singular( 'post' ) ) {
				$categories = get_the_category( $post->ID );

				if ( ! empty( $categories ) ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $position,
						'name'     => $categories[0]->name,
						'item'     => get_category_link( $categories[0] ),
					);
					++$position;
				}
			} elseif ( is_page() ) {
				$ancestors = array_reverse( get_post_ancestors( $post->ID ) );

				foreach ( $ancestors as $ancestor_id ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $position,
						'name'     => get_the_title( $ancestor_id ),
						'item'     => get_permalink( $ancestor_id ),
					);
					++$position;
				}
			}

			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => get_the_title( $post ),
			);
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $term->name,
			);
		} elseif ( is_search() ) {
			/* translators: %s: Search query. */
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => sprintf( __( 'Search Results for: %s', 'apppresser-wp' ), get_search_query() ),
			);
		} elseif ( is_404() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => __( 'Page Not Found', 'apppresser-wp' ),
			);
		} elseif ( is_author() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => get_the_author(),
			);
		} elseif ( is_date() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => get_the_archive_title(),
			);
		} else {
			return null;
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_page() {
		AppPresser_Settings_Page::render( 'SEO', 'apppresser-seo-root' );
	}
}
