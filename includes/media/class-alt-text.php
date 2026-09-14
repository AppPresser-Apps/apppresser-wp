<?php
/**
 * Media library alt text column and filters.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Alt_Text
 *
 * Adds an editable alt text column to the media library list view,
 * a "Save Alt Text" bulk action, a filter to show only attachments
 * missing alt text, and a modal after image uploads that requires
 * the user to enter alt text immediately.
 */
class AppPresser_Alt_Text {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );

		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_update_notice' ) );

		add_action( 'restrict_manage_posts', array( $this, 'render_missing_alt_filter' ) );
		add_action( 'parse_query', array( $this, 'filter_missing_alt_query' ) );

		add_action( 'admin_footer', array( $this, 'admin_footer_scripts' ) );
		add_action( 'wp_ajax_apppresser_save_alt_text', array( $this, 'ajax_save_alt_text' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_require_alt_text' ) );
	}

	/**
	 * Add the alt text column to the media library list view.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['alt_text'] = __( 'Alt Text', 'apppresser-wp' );
		return $columns;
	}

	/**
	 * Render the alt text column content.
	 *
	 * @param string $column_name Current column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_column( $column_name, $post_id ) {
		if ( 'alt_text' !== $column_name ) {
			return;
		}

		$alt_text = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		?>
		<textarea
			class="apppresser-alt-text-input"
			data-attachment-id="<?php echo esc_attr( $post_id ); ?>"
			style="width: 100%; min-height: 60px; resize: vertical;"
		><?php echo esc_textarea( $alt_text ); ?></textarea>
		<?php
	}

	/**
	 * Register the "Save Alt Text" bulk action.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $bulk_actions ) {
		$bulk_actions['save_alt_text'] = __( 'Save Alt Text', 'apppresser-wp' );
		return $bulk_actions;
	}

	/**
	 * Handle the "Save Alt Text" bulk action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Bulk action name.
	 * @param array  $post_ids     Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( 'save_alt_text' !== $action ) {
			return $redirect_url;
		}

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-media' ) ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_url;
		}

		foreach ( $post_ids as $post_id ) {
			$input_name = 'alt_text_' . $post_id;
			if ( isset( $_REQUEST[ $input_name ] ) ) {
				$alt_text = sanitize_text_field( wp_unslash( $_REQUEST[ $input_name ] ) );
				update_post_meta( $post_id, '_wp_attachment_image_alt', $alt_text );
			}
		}

		return add_query_arg( 'bulk_alt_text_updated', count( $post_ids ), $redirect_url );
	}

	/**
	 * Display an admin notice after a bulk alt text update.
	 */
	public function bulk_update_notice() {
		if ( empty( $_REQUEST['bulk_alt_text_updated'] ) ) {
			return;
		}

		$count = intval( $_REQUEST['bulk_alt_text_updated'] );
		printf(
			'<div class="updated notice is-dismissible"><p>' .
			esc_html(
				_n(
					'Updated alt text for %s item.',
					'Updated alt text for %s items.',
					$count,
					'apppresser-wp'
				)
			) . '</p></div>',
			$count
		);
	}

	/**
	 * Render the alt text status filter dropdown above the media list table.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_missing_alt_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['apppresser_alt_text_filter'] ) ? sanitize_key( wp_unslash( $_GET['apppresser_alt_text_filter'] ) ) : '';
		?>
		<select name="apppresser_alt_text_filter" id="apppresser-alt-text-filter">
			<option value=""><?php esc_html_e( 'All alt text', 'apppresser-wp' ); ?></option>
			<option value="missing" <?php selected( $selected, 'missing' ); ?>><?php esc_html_e( 'Missing alt text', 'apppresser-wp' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Filter the media list query to attachments with no alt text.
	 *
	 * @param WP_Query $query The current query.
	 */
	public function filter_missing_alt_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( empty( $_GET['apppresser_alt_text_filter'] ) || 'missing' !== $_GET['apppresser_alt_text_filter'] ) {
			return;
		}

		// Only images use the alt text meta.
		$query->set( 'post_mime_type', 'image' );
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			)
		);
	}

	/**
	 * Output JavaScript for AJAX saving and the bulk action on the media library screen.
	 */
	public function admin_footer_scripts() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->base ) {
			return;
		}
		?>
		<script>
		jQuery(document).ready(function($) {
			// Store alt text values when a bulk action is triggered.
			$('#doaction, #doaction2').click(function() {
				var action = $(this).prev('select').val();
				if ( 'save_alt_text' === action ) {
					$('.apppresser-alt-text-input').each(function() {
						var attachmentId = $(this).data('attachment-id');
						$('<input>').attr({
							type: 'hidden',
							name: 'alt_text_' + attachmentId,
							value: $(this).val()
						}).appendTo('#posts-filter');
					});
				}
			});

			// Individual save functionality.
			$('.apppresser-alt-text-input').on('change', function() {
				var $input = $(this);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'apppresser_save_alt_text',
						attachment_id: $input.data('attachment-id'),
						alt_text: $input.val(),
						nonce: '<?php echo esc_js( wp_create_nonce( 'apppresser_alt_text_nonce' ) ); ?>'
					},
					success: function(response) {
						if ( response.success ) {
							$input.css('background-color', '#e7f9e7').delay(1000).queue(function(next) {
								$(this).css('background-color', '');
								next();
							});
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler for saving a single attachment's alt text.
	 */
	public function ajax_save_alt_text() {
		check_ajax_referer( 'apppresser_alt_text_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		$alt_text      = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';

		if ( ! $attachment_id ) {
			wp_send_json_error( 'Invalid attachment ID' );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		wp_send_json_success();
	}

	/**
	 * Enqueue the required alt text modal on screens with a media uploader.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_require_alt_text( $hook ) {
		if ( ! in_array( $hook, array( 'upload.php', 'media-new.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$script_path = APPRESSER_WP_DIR . 'includes/media/js/require-alt-text.js';

		wp_enqueue_script(
			'apppresser-require-alt-text',
			APPRESSER_WP_URL . '/includes/media/js/require-alt-text.js',
			array( 'jquery' ),
			filemtime( $script_path ),
			true
		);

		wp_localize_script(
			'apppresser-require-alt-text',
			'AppPresserRequireAltText',
			array(
				'nonce'   => wp_create_nonce( 'apppresser_alt_text_nonce' ),
				'strings' => array(
					'heading'     => __( 'Alt text is required', 'apppresser-wp' ),
					'description' => __( 'Describe this image for screen readers and search engines before continuing.', 'apppresser-wp' ),
					'save'        => __( 'Save alt text', 'apppresser-wp' ),
					'saving'      => __( 'Saving…', 'apppresser-wp' ),
					'error'       => __( 'Could not save the alt text. Please try again.', 'apppresser-wp' ),
				),
			)
		);

		wp_add_inline_style(
			'common',
			'.appr-alt-overlay{position:fixed;inset:0;z-index:300000;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;}' .
			'.appr-alt-modal{background:#fff;padding:20px 24px;max-width:420px;width:90%;box-shadow:0 5px 25px rgba(0,0,0,.4);}' .
			'.appr-alt-modal h2{margin-top:0;}' .
			'.appr-alt-thumb{max-height:120px;max-width:100%;display:block;margin:0 auto 8px;}' .
			'.appr-alt-filename{font-weight:600;overflow-wrap:anywhere;}' .
			'.appr-alt-input{width:100%;}' .
			'.appr-alt-error{color:#d63638;}' .
			'.appr-alt-save{margin-top:8px;}'
		);
	}
}
