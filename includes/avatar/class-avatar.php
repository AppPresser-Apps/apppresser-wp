<?php
/**
 * Custom avatar support.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Avatar
 *
 * Lets users upload a custom avatar from their profile. The avatar is used in
 * place of Gravatar when set, otherwise Gravatar is used, and finally a bundled
 * default image is used when neither is available.
 *
 * Uploaded avatars are stored in their own `avatars` folder inside
 * `wp-content/uploads` rather than in the media library, so they don't clutter
 * it with images that aren't meant to be reused elsewhere on the site.
 */
class AppPresser_Avatar {

	/**
	 * User meta key that stores the custom avatar filename.
	 *
	 * @var string
	 */
	private $meta_key = 'apppresser_custom_avatar';

	/**
	 * Name of the subdirectory (inside the uploads directory) avatars are stored in.
	 *
	 * @var string
	 */
	private $subdir = 'avatars';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_profile_assets' ) );
		add_action( 'wp_ajax_apppresser_upload_avatar', array( $this, 'ajax_upload_avatar' ) );

		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
		add_filter( 'avatar_defaults', array( $this, 'register_default_avatar' ) );
	}

	/**
	 * Resolve a user ID from the various values `get_avatar()` accepts.
	 *
	 * @param mixed $id_or_email User ID, email address, or a WP_User/WP_Comment/WP_Post object.
	 * @return int User ID, or 0 when it cannot be determined.
	 */
	private function get_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( is_object( $id_or_email ) ) {
			if ( $id_or_email instanceof WP_User ) {
				return (int) $id_or_email->ID;
			}

			if ( $id_or_email instanceof WP_Comment ) {
				return (int) $id_or_email->user_id;
			}

			if ( $id_or_email instanceof WP_Post ) {
				return (int) $id_or_email->post_author;
			}

			return 0;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );

			return $user ? (int) $user->ID : 0;
		}

		return 0;
	}

	/**
	 * Return the URL of the bundled default avatar image.
	 *
	 * @return string
	 */
	private function get_default_avatar_url() {
		return APPRESSER_WP_URL . '/includes/avatar/default-avatar.jpg';
	}

	/**
	 * Get the absolute path to the avatars directory, creating it (and its
	 * protective `index.php`) if it doesn't exist yet.
	 *
	 * @return string
	 */
	private function get_avatars_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . $this->subdir;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index_file = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Get the base URL for the avatars directory.
	 *
	 * @return string
	 */
	private function get_avatars_url() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['baseurl'] ) . $this->subdir;
	}

	/**
	 * Get the URL for a stored avatar filename.
	 *
	 * @param string $filename Filename stored in user meta.
	 * @return string|false URL, or false if the file no longer exists.
	 */
	private function get_avatar_url( $filename ) {
		if ( ! $filename || ! file_exists( trailingslashit( $this->get_avatars_dir() ) . $filename ) ) {
			return false;
		}

		return trailingslashit( $this->get_avatars_url() ) . $filename;
	}

	/**
	 * Delete a previously stored avatar file, if present.
	 *
	 * @param string $filename Filename stored in user meta.
	 */
	private function delete_avatar_file( $filename ) {
		if ( ! $filename ) {
			return;
		}

		$path = trailingslashit( $this->get_avatars_dir() ) . $filename;

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Register the bundled image as a selectable default avatar.
	 *
	 * @param array $defaults Existing default avatars.
	 * @return array
	 */
	public function register_default_avatar( $defaults ) {
		$defaults[ $this->get_default_avatar_url() ] = __( 'AppPresser Default', 'apppresser-wp' );

		return $defaults;
	}

	/**
	 * Filter avatar data to prefer a custom avatar, then Gravatar, then the
	 * bundled default image.
	 *
	 * Rather than making our own network request to ask Gravatar whether an
	 * image exists for the email (slow, and unreliable when that request
	 * itself can't reach Gravatar), we let Gravatar resolve that in the same
	 * request the browser makes for the `<img>` itself: we pass our bundled
	 * default image as Gravatar's `d` (default) parameter, so Gravatar's own
	 * server serves the real avatar when one exists and falls back to our
	 * default image when it doesn't.
	 *
	 * @param array $args        Avatar arguments.
	 * @param mixed $id_or_email User ID, email address, or object.
	 * @return array
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		$user_id = $this->get_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $args;
		}

		$filename = get_user_meta( $user_id, $this->meta_key, true );

		if ( ! empty( $filename ) ) {
			$custom_url = $this->get_avatar_url( $filename );

			if ( $custom_url ) {
				$args['url']          = $custom_url;
				$args['found_avatar'] = true;

				return $args;
			}
		}

		// No custom avatar: let Gravatar serve the real avatar if the user
		// has one, falling back to our bundled default image otherwise.
		$args['default'] = $this->get_default_avatar_url();

		return $args;
	}

	/**
	 * Render the custom avatar field on the user profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render_profile_field( $user ) {
		$filename   = get_user_meta( $user->ID, $this->meta_key, true );
		$avatar_url = $filename ? $this->get_avatar_url( $filename ) : false;

		if ( ! $avatar_url ) {
			$avatar_url = $this->get_default_avatar_url();
		}
		?>
		<h2><?php esc_html_e( 'Custom Avatar', 'apppresser-wp' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="apppresser-custom-avatar-row">
				<th scope="row">
					<label for="apppresser_custom_avatar_file"><?php esc_html_e( 'Avatar', 'apppresser-wp' ); ?></label>
				</th>
				<td>
					<input type="hidden" name="apppresser_custom_avatar" id="apppresser_custom_avatar" value="<?php echo esc_attr( $filename ); ?>" />
					<div id="apppresser-custom-avatar-preview">
						<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" style="max-width:96px; height:auto; display:block; margin-bottom:8px;" />
					</div>
					<input type="file" id="apppresser_custom_avatar_file" accept="image/png,image/jpeg,image/gif,image/webp" />
					<button type="button" class="button" id="apppresser-custom-avatar-remove" <?php echo $filename ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'apppresser-wp' ); ?></button>
					<span id="apppresser-custom-avatar-status"></span>
					<p class="description"><?php esc_html_e( 'Upload a custom avatar. If none is set, your Gravatar (or the site default) will be used.', 'apppresser-wp' ); ?></p>
					<?php wp_nonce_field( 'apppresser_upload_avatar', 'apppresser_avatar_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the custom avatar field.
	 *
	 * The file itself is uploaded out-of-band via AJAX (see ajax_upload_avatar());
	 * this just persists (or clears) the filename that was left in the hidden
	 * input, and cleans up the previous file when it changes.
	 *
	 * @param int $user_id The user ID being saved.
	 */
	public function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['apppresser_custom_avatar'] ) ) {
			return;
		}

		$previous = get_user_meta( $user_id, $this->meta_key, true );
		$filename = sanitize_file_name( wp_unslash( $_POST['apppresser_custom_avatar'] ) );

		if ( $filename ) {
			update_user_meta( $user_id, $this->meta_key, $filename );
		} else {
			delete_user_meta( $user_id, $this->meta_key );
		}

		if ( $previous && $previous !== $filename ) {
			$this->delete_avatar_file( $previous );
		}
	}

	/**
	 * Handle the AJAX avatar upload, saving the file into the avatars
	 * directory and returning its filename and URL.
	 */
	public function ajax_upload_avatar() {
		check_ajax_referer( 'apppresser_upload_avatar', 'nonce' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'apppresser-wp' ) ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'apppresser-wp' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->get_avatars_dir();

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			),
		);

		$uploaded = wp_handle_upload( $_FILES['file'], $overrides );

		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		if ( isset( $uploaded['error'] ) ) {
			wp_send_json_error( array( 'message' => $uploaded['error'] ) );
		}

		if ( ! wp_getimagesize( $uploaded['file'] ) ) {
			wp_delete_file( $uploaded['file'] );
			wp_send_json_error( array( 'message' => __( 'The uploaded file is not a valid image.', 'apppresser-wp' ) ) );
		}

		$filename = basename( $uploaded['file'] );
		$previous = get_user_meta( $user_id, $this->meta_key, true );

		if ( $previous && $previous !== $filename ) {
			$this->delete_avatar_file( $previous );
		}

		wp_send_json_success(
			array(
				'filename' => $filename,
				'url'      => $uploaded['url'],
			)
		);
	}

	/**
	 * Redirect `wp_handle_upload()` into the avatars directory instead of the
	 * default (year/month) media library directory.
	 *
	 * @param array $dirs Upload directory info.
	 * @return array
	 */
	public function filter_upload_dir( $dirs ) {
		$dirs['path']   = trailingslashit( $dirs['basedir'] ) . $this->subdir;
		$dirs['url']    = trailingslashit( $dirs['baseurl'] ) . $this->subdir;
		$dirs['subdir'] = '/' . $this->subdir;

		if ( ! file_exists( $dirs['path'] ) ) {
			wp_mkdir_p( $dirs['path'] );
		}

		return $dirs;
	}

	/**
	 * Enqueue the upload script on profile screens.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_profile_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : get_current_user_id();

		wp_register_script( 'apppresser-avatar', false, array( 'jquery' ), false, true );
		wp_enqueue_script( 'apppresser-avatar' );

		wp_add_inline_script(
			'apppresser-avatar',
			"(function ($) {
				var input = $('#apppresser_custom_avatar');
				var fileInput = $('#apppresser_custom_avatar_file');
				var preview = $('#apppresser-custom-avatar-preview');
				var removeBtn = $('#apppresser-custom-avatar-remove');
				var status = $('#apppresser-custom-avatar-status');
				var userId = " . (int) $user_id . ";

				fileInput.on('change', function (e) {
					var file = e.target.files[0];

					if (!file) {
						return;
					}

					var data = new FormData();
					data.append('action', 'apppresser_upload_avatar');
					data.append('nonce', $('#apppresser_avatar_nonce').val());
					data.append('user_id', userId);
					data.append('file', file);

					status.text('" . esc_js( __( 'Uploading…', 'apppresser-wp' ) ) . "');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: data,
						processData: false,
						contentType: false
					}).done(function (response) {
						if (response && response.success) {
							input.val(response.data.filename);
							preview.html('<img src=\"' + response.data.url + '\" alt=\"\" style=\"max-width:96px; height:auto; display:block; margin-bottom:8px;\" />');
							removeBtn.show();
							status.text('');
						} else {
							status.text((response && response.data && response.data.message) || '" . esc_js( __( 'Upload failed.', 'apppresser-wp' ) ) . "');
						}
					}).fail(function () {
						status.text('" . esc_js( __( 'Upload failed.', 'apppresser-wp' ) ) . "');
					});

					fileInput.val('');
				});

				removeBtn.on('click', function (e) {
					e.preventDefault();
					input.val('');
					preview.html('');
					removeBtn.hide();
				});
			})(jQuery);"
		);
	}
}
