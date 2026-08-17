<?php
/**
 * Duplicate Post/Page functionality.
 *
 * Adds a duplicate link to each item in the post and page admin lists.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Duplicate
 *
 * Adds duplicate functionality for posts and pages in the admin.
 */
class AppPresser_Duplicate {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Add duplicate action link to post rows.
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );

		// Handle the duplicate action.
		add_action( 'admin_init', array( $this, 'handle_duplicate' ) );

		// Add admin notice for duplicate success/error.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add duplicate link to post/page row actions.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Current post object.
	 * @return array Modified row actions.
	 */
	public function add_duplicate_link( $actions, $post ) {
		// Only add to users who can edit posts.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		// Build the duplicate action URL.
		$duplicate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'apppresser_duplicate',
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
				),
				admin_url( 'edit.php' )
			),
			'apppresser_duplicate_' . $post->ID
		);

		// Add the duplicate action to the row actions.
		$actions['duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $duplicate_url ),
			/* translators: %s: Post title. */
			esc_attr( sprintf( __( 'Duplicate "%s"', 'apppresser-wp' ), _draft_or_post_title( $post->ID ) ) ),
			__( 'Duplicate', 'apppresser-wp' )
		);

		return $actions;
	}

	/**
	 * Handle the duplicate action.
	 */
	public function handle_duplicate() {
		// Check if this is a duplicate action.
		if ( ! isset( $_GET['action'] ) || 'apppresser_duplicate' !== $_GET['action'] ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'apppresser_duplicate_' . absint( $_GET['post_id'] ) ) ) {
			set_transient( 'apppresser_duplicate_error', __( 'Security check failed.', 'apppresser-wp' ) );
			wp_redirect( admin_url( 'edit.php' ) );
			exit;
		}

		// Check user capabilities.
		$post_id = absint( $_GET['post_id'] );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			set_transient( 'apppresser_duplicate_error', __( 'You do not have permission to duplicate this post.', 'apppresser-wp' ) );
			wp_redirect( admin_url( 'edit.php' ) );
			exit;
		}

		// Get the original post.
		$post = get_post( $post_id );
		if ( ! $post ) {
			set_transient( 'apppresser_duplicate_error', __( 'Post not found.', 'apppresser-wp' ) );
			wp_redirect( admin_url( 'edit.php' ) );
			exit;
		}

		// Create the duplicate.
		$new_post_id = $this->create_duplicate( $post );

		if ( is_wp_error( $new_post_id ) ) {
			set_transient( 'apppresser_duplicate_error', $new_post_id->get_error_message() );
			wp_redirect( admin_url( 'edit.php' ) );
			exit;
		}

		// Set success transient with the new post ID.
		set_transient( 'apppresser_duplicate_success', $new_post_id );

		// Redirect to the edit screen for the post type.
		$redirect_url = add_query_arg(
			array(
				'post_type' => $post->post_type,
			),
			admin_url( 'edit.php' )
		);

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Create a duplicate of a post.
	 *
	 * @param WP_Post $post The original post object.
	 * @return int|WP_Error The new post ID on success, WP_Error on failure.
	 */
	private function create_duplicate( $post ) {
		global $wpdb;

		// Prepare post data for duplication.
		$new_post = array(
			'post_title'    => sprintf(
				/* translators: %s: Original post title. */
				__( '%s (Copy)', 'apppresser-wp' ),
				$post->post_title
			),
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => 'draft',
			'post_type'     => $post->post_type,
			'post_author'   => get_current_user_id(),
			'post_parent'   => $post->post_parent,
			'menu_order'    => $post->menu_order,
			'post_password' => $post->post_password,
		);

		// Insert the new post.
		$new_post_id = wp_insert_post( $new_post, true );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Copy post meta.
		$post_meta = get_post_meta( $post->ID );
		foreach ( $post_meta as $meta_key => $meta_values ) {
			// Skip internal WordPress meta keys.
			if ( '_' === substr( $meta_key, 0, 1 ) ) {
				continue;
			}

			foreach ( $meta_values as $meta_value ) {
				add_post_meta( $new_post_id, $meta_key, maybe_unserialize( $meta_value ) );
			}
		}

		// Copy terms (categories, tags, custom taxonomies).
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
				wp_set_object_terms( $new_post_id, $post_terms, $taxonomy );
			}
		}

		// Copy featured image.
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_post_id, $thumbnail_id );
		}

		/**
		 * Fires after a post has been duplicated.
		 *
		 * @param int     $new_post_id The ID of the new duplicated post.
		 * @param WP_Post $post        The original post object.
		 */
		do_action( 'apppresser_duplicate_post', $new_post_id, $post );

		return $new_post_id;
	}

	/**
	 * Display admin notices for duplicate success/error.
	 */
	public function admin_notices() {
		// Check for success transient.
		$new_post_id = get_transient( 'apppresser_duplicate_success' );
		if ( $new_post_id ) {
			$post = get_post( $new_post_id );
			if ( $post ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Post duplicated successfully!', 'apppresser-wp' ),
					esc_url( get_edit_post_link( $new_post_id ) ),
					esc_html__( 'Edit the duplicated post', 'apppresser-wp' )
				);
			}
			delete_transient( 'apppresser_duplicate_success' );
		}

		// Check for error transient.
		$error = get_transient( 'apppresser_duplicate_error' );
		if ( $error ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $error )
			);
			delete_transient( 'apppresser_duplicate_error' );
		}
	}
}
