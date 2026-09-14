<?php
/**
 * Media library duplicate image detection and merging.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Merge_Duplicates
 *
 * Detects image attachments whose underlying files are byte-for-byte
 * identical (same MD5 hash), lets the user filter the media library
 * down to those duplicate groups, and merges each group into one
 * user-checked "keeper" image:
 *
 * - References to a duplicate (featured images, content URLs and
 *   srcsets, block and shortcode IDs, site icon, product galleries)
 *   are rewritten to point at the keeper.
 * - The duplicate attachment and its files are then deleted.
 *
 * works from the media library list view: filter by "Duplicates",
 * check the image in each group you want to keep, then run the
 * "Merge duplicates (keep checked)" bulk action.
 *
 * Uploads are also pre-screened: when an incoming file matches an
 * existing attachment (identical content hash, or a file name that is
 * already in the library) the upload is stopped with a message
 * instead of being saved again with a "-1" suffix.
 */
class AppPresser_Merge_Duplicates {

	/**
	 * Post meta key holding the MD5 hash of an attachment's file.
	 *
	 * @var string
	 */
	const HASH_META = '_apppresser_file_md5';

	/**
	 * Query var name for the duplicates list filter.
	 *
	 * @var string
	 */
	const FILTER_KEY = 'apppresser_duplicate_filter';

	/**
	 * Bulk action name for merging duplicate groups.
	 *
	 * @var string
	 */
	const BULK_ACTION = 'apppresser_merge_duplicate_group';

	/**
	 * How many un-indexed attachments to hash per page load.
	 *
	 * @var int
	 */
	const BACKFILL_LIMIT = 300;

	/**
	 * Whether the media library still has un-indexed attachments.
	 *
	 * @var bool
	 */
	protected $indexing = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Stop uploads of files that already exist in the media library.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'block_existing_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'block_existing_upload' ) );

		// Hash new uploads so they can be matched against existing files.
		add_action( 'add_attachment', array( $this, 'hash_on_upload' ) );

		// Duplicate group column on the media library list view.
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );

		// "Duplicates" filter above the media list table.
		add_action( 'restrict_manage_posts', array( $this, 'render_filter' ) );
		add_action( 'parse_query', array( $this, 'filter_query' ) );

		// Bulk merge action.
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Hash an image attachment's file and store it in post meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string The file hash, or empty string when unavailable.
	 */
	public static function hash_image( $attachment_id ) {
		$attachment_id = intval( $attachment_id );

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return '';
		}

		$existing = get_post_meta( $attachment_id, self::HASH_META, true );
		if ( $existing ) {
			return $existing;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}

		$hash = md5_file( $file );
		if ( $hash ) {
			update_post_meta( $attachment_id, self::HASH_META, $hash );
		}

		return $hash ? $hash : '';
	}

	/**
	 * Hash new uploads as they are added.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function hash_on_upload( $post_id ) {
		self::hash_image( $post_id );
	}

	/**
	 * Stop an upload when the file already exists in the media library.
	 *
	 * Compares the incoming file against existing attachments: first by
	 * content hash, then by file name. When a match is found the upload
	 * is aborted with an explanatory message so the user can pick the
	 * existing file instead of adding another copy with a "-1" suffix.
	 *
	 * @param array $file Uploaded file data (single entry from $_FILES).
	 * @return array
	 */
	public function block_existing_upload( $file ) {
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		// Same file contents (hashes are indexed for images).
		if ( ! empty( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
			$hash     = md5_file( $file['tmp_name'] );
			$existing = $hash ? self::find_by_hash( $hash ) : 0;

			if ( $existing ) {
				$file['error'] = sprintf(
					/* translators: %s: title of the existing media library item. */
					__( 'This file was not uploaded because an identical file already exists in the media library: "%s".', 'apppresser-wp' ),
					get_the_title( $existing )
				);
				return $file;
			}
		}

		// Same file name.
		if ( ! empty( $file['name'] ) ) {
			$name     = sanitize_file_name( $file['name'] );
			$existing = self::find_by_filename( $name );

			if ( $existing ) {
				$file['error'] = sprintf(
					/* translators: 1: uploaded file name, 2: title of the existing media library item. */
					__( 'A file named "%1$s" already exists in the media library: "%2$s". The upload was stopped to avoid a duplicate.', 'apppresser-wp' ),
					$name,
					get_the_title( $existing )
				);
				return $file;
			}
		}

		return $file;
	}

	/**
	 * Find an attachment with a matching content hash.
	 *
	 * Only attachments indexed by this class (images) carry hash meta.
	 *
	 * @param string $hash MD5 hash of the file contents.
	 * @return int Attachment ID, or 0 when no match.
	 */
	protected static function find_by_hash( $hash ) {
		global $wpdb;

		if ( empty( $hash ) || ! ctype_xdigit( $hash ) ) {
			return 0;
		}

		$sql = $wpdb->prepare(
			"SELECT meta.post_id
			FROM {$wpdb->postmeta} AS meta
			INNER JOIN {$wpdb->posts} AS p ON p.ID = meta.post_id
			WHERE meta.meta_key = %s
				AND meta.meta_value = %s
				AND p.post_type = 'attachment'
			LIMIT 1",
			self::HASH_META,
			$hash
		);

		return intval( $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
	}

	/**
	 * Find an attachment by file name, anywhere in the uploads folders.
	 *
	 * @param string $name Sanitized uploaded file name.
	 * @return int Attachment ID, or 0 when no match.
	 */
	protected static function find_by_filename( $name ) {
		global $wpdb;

		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			return 0;
		}

		$names = array( $name );

		// Big images are stored as name-scaled.ext after upload scaling.
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		if ( $extension ) {
			$names[] = pathinfo( $name, PATHINFO_FILENAME ) . '-scaled.' . $extension;
		}

		$wheres = array();
		$args   = array();
		foreach ( $names as $candidate ) {
			$wheres[] = '( meta.meta_value = %s OR meta.meta_value LIKE %s )';
			$args[]   = $candidate;
			$args[]   = '%' . $wpdb->esc_like( '/' . $candidate );
		}
		$where = implode( ' OR ', $wheres );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built from a fixed string.
		$sql = $wpdb->prepare(
			"SELECT meta.post_id
			FROM {$wpdb->postmeta} AS meta
			INNER JOIN {$wpdb->posts} AS p ON p.ID = meta.post_id
			WHERE meta.meta_key = '_wp_attached_file'
				AND p.post_type = 'attachment'
				AND ( {$where} )
			ORDER BY meta.post_id ASC
			LIMIT 1",
			$args
		);

		return intval( $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
	}

	/**
	 * Add the duplicate group column to the media library list view.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( self::is_duplicate_filter_active() ) {
			$columns['apppresser_duplicates'] = __( 'Duplicate Group', 'apppresser-wp' );
		}
		return $columns;
	}

	/**
	 * Render the duplicate group column content.
	 *
	 * @param string $column_name Current column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_column( $column_name, $post_id ) {
		if ( 'apppresser_duplicates' !== $column_name ) {
			return;
		}

		$hash = self::hash_image( $post_id );
		if ( ! $hash ) {
			echo '&mdash;';
			return;
		}

		$group = self::get_group_ids( $hash, $post_id );
		if ( empty( $group ) ) {
			esc_html_e( 'Unique', 'apppresser-wp' );
			return;
		}

		printf(
			/* translators: %d: number of other identical images. */
			esc_html( _n( '%d other copy', '%d other copies', count( $group ), 'apppresser-wp' ) ),
			count( $group )
		);
		echo '<div class="apppresser-duplicate-group" style="margin-top:4px;">';
		foreach ( $group as $dup_id ) {
			$edit_link = get_edit_post_link( $dup_id );
			$thumb     = wp_get_attachment_image(
				$dup_id,
				array( 48, 48 ),
				false,
				array(
					'style' => 'width:40px;height:40px;object-fit:cover;border:1px solid #c3c4c7;margin:0 4px 4px 0;',
					'title' => get_the_title( $dup_id ),
				)
			);
			echo $edit_link ? '<a href="' . esc_url( $edit_link ) . '">' . $thumb . '</a>' : $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its own attributes.
		}
		echo '</div>';
	}

	/**
	 * Render the duplicates filter dropdown above the media list table.
	 *
	 * @param string $post_type Current post type.
	 */
	public function render_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET[ self::FILTER_KEY ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_KEY ] ) ) : '';
		?>
		<select name="<?php echo esc_attr( self::FILTER_KEY ); ?>" id="apppresser-duplicate-filter">
			<option value=""><?php esc_html_e( 'All files', 'apppresser-wp' ); ?></option>
			<option value="duplicates" <?php selected( $selected, 'duplicates' ); ?>><?php esc_html_e( 'Duplicates', 'apppresser-wp' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Whether the duplicates filter is currently active.
	 *
	 * @return bool
	 */
	protected static function is_duplicate_filter_active() {
		return isset( $_GET[ self::FILTER_KEY ] ) && 'duplicates' === sanitize_key( wp_unslash( $_GET[ self::FILTER_KEY ] ) );
	}

	/**
	 * Filter the media list query to attachments that belong to a duplicate group.
	 *
	 * @param WP_Query $query The current query.
	 */
	public function filter_query( $query ) {
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

		if ( ! self::is_duplicate_filter_active() ) {
			return;
		}

		// Only images are compared.
		$query->set( 'post_mime_type', 'image' );

		$this->backfill_hashes();

		$duplicate_ids = self::get_duplicate_attachment_ids();

		// No duplicates: force an empty result set.
		$query->set( 'post__in', empty( $duplicate_ids ) ? array( 0 ) : $duplicate_ids );

		// Order by hash so members of the same group sit next to each other.
		$query->set( 'meta_key', self::HASH_META );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', 'ASC' );
	}

	/**
	 * Register the merge bulk action.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $bulk_actions ) {
		$bulk_actions[ self::BULK_ACTION ] = __( 'Merge duplicates (keep checked)', 'apppresser-wp' );
		return $bulk_actions;
	}

	/**
	 * Handle the merge bulk action.
	 *
	 * Every checked attachment becomes the keeper for its duplicate
	 * group. All other group members have their usages rewritten to
	 * the keeper and are then deleted.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Bulk action name.
	 * @param array  $post_ids     Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_url;
		}

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-media' ) ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_url;
		}

		$post_ids = array_map( 'intval', (array) $post_ids );
		sort( $post_ids );

		$processed = array();
		$deleted   = 0;
		$keepers   = 0;
		$skipped   = 0;

		foreach ( $post_ids as $keeper_id ) {
			$hash = self::hash_image( $keeper_id );

			// Already merged as part of another checked image's group.
			if ( ! $hash || isset( $processed[ $hash ] ) ) {
				continue;
			}

			$processed[ $hash ] = true;

			$duplicates = self::get_group_ids( $hash, $keeper_id );
			if ( empty( $duplicates ) ) {
				++$skipped;
				continue;
			}

			foreach ( $duplicates as $duplicate_id ) {
				$this->replace_usage( $duplicate_id, $keeper_id );

				// Skip trash: remove the duplicate and its files for real.
				wp_delete_attachment( $duplicate_id, true );
				++$deleted;
			}

			++$keepers;
		}

		return add_query_arg(
			array(
				'apppresser_merged_deleted' => $deleted,
				'apppresser_merged_keepers' => $keepers,
				'apppresser_merged_skipped' => $skipped,
			),
			$redirect_url
		);
	}

	/**
	 * Display admin notices after a merge or while indexing.
	 */
	public function admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		if ( isset( $_GET['apppresser_merged_deleted'] ) ) {
			$deleted = intval( $_GET['apppresser_merged_deleted'] );
			$keepers = isset( $_GET['apppresser_merged_keepers'] ) ? intval( $_GET['apppresser_merged_keepers'] ) : 0;
			$skipped = isset( $_GET['apppresser_merged_skipped'] ) ? intval( $_GET['apppresser_merged_skipped'] ) : 0;

			$message = sprintf(
				/* translators: 1: number of kept images, 2: number of deleted duplicates. */
				_n(
					'Merged %1$d checked image: deleted %2$d duplicate and updated its usages.',
					'Merged %1$d checked images: deleted %2$d duplicates and updated their usages.',
					$keepers,
					'apppresser-wp'
				),
				$keepers,
				$deleted
			);

			if ( $skipped ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of checked images that had no duplicates. */
					_n( '%d checked image had no duplicates.', '%d checked images had no duplicates.', $skipped, 'apppresser-wp' ),
					$skipped
				);
			}

			printf( '<div class="updated notice is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( $this->indexing && self::is_duplicate_filter_active() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Indexing media files for duplicate detection. Reload this page to see more results.', 'apppresser-wp' )
			);
		}
	}

	/**
	 * Hash attachments that have not been indexed yet, in batches.
	 */
	protected function backfill_hashes() {
		$missing = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => self::BACKFILL_LIMIT,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::HASH_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $missing->posts as $attachment_id ) {
			self::hash_image( $attachment_id );
		}

		// If we hit the limit there are likely more attachments to index.
		$this->indexing = count( $missing->posts ) >= self::BACKFILL_LIMIT;
	}

	/**
	 * Get hashes shared by more than one image attachment.
	 *
	 * @return array List of duplicate file hashes.
	 */
	protected static function get_duplicate_hashes() {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT meta.meta_value
			FROM {$wpdb->postmeta} AS meta
			INNER JOIN {$wpdb->posts} AS p ON p.ID = meta.post_id
			WHERE meta.meta_key = %s
				AND p.post_type = 'attachment'
				AND p.post_status = 'inherit'
				AND p.post_mime_type LIKE %s
			GROUP BY meta.meta_value
			HAVING COUNT(meta.post_id) > 1",
			self::HASH_META,
			'image/%'
		);

		return $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
	}

	/**
	 * Get all attachment IDs that belong to any duplicate group.
	 *
	 * @return array Attachment IDs.
	 */
	protected static function get_duplicate_attachment_ids() {
		global $wpdb;

		$hashes = self::get_duplicate_hashes();
		if ( empty( $hashes ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$sql          = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One placeholder per known hash.
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$placeholders})",
			array_merge( array( self::HASH_META ), $hashes )
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
	}

	/**
	 * Get image attachment IDs sharing a hash.
	 *
	 * @param string $hash       File hash.
	 * @param int    $exclude_id Optional attachment ID to exclude.
	 * @return array Attachment IDs.
	 */
	protected static function get_group_ids( $hash, $exclude_id = 0 ) {
		global $wpdb;

		if ( empty( $hash ) || ! ctype_xdigit( $hash ) ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT meta.post_id
			FROM {$wpdb->postmeta} AS meta
			INNER JOIN {$wpdb->posts} AS p ON p.ID = meta.post_id
			WHERE meta.meta_key = %s
				AND meta.meta_value = %s
				AND p.post_type = 'attachment'
				AND p.post_status = 'inherit'
				AND p.post_mime_type LIKE %s
				AND meta.post_id != %d",
			self::HASH_META,
			$hash,
			'image/%',
			intval( $exclude_id )
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
	}

	/**
	 * Rewrite all usages of a duplicate attachment to the keeper.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 */
	protected function replace_usage( $duplicate_id, $keeper_id ) {
		$this->replace_featured_images( $duplicate_id, $keeper_id );
		$this->replace_option_references( $duplicate_id, $keeper_id );
		$this->replace_comma_id_meta( $duplicate_id, $keeper_id );
		$this->replace_in_content( $duplicate_id, $keeper_id );
	}

	/**
	 * Point featured images (posts and terms) at the keeper.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 */
	protected function replace_featured_images( $duplicate_id, $keeper_id ) {
		global $wpdb;

		// Post featured images.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
				(string) $keeper_id,
				(string) $duplicate_id
			)
		);

		// Term thumbnails (e.g. WooCommerce categories).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->termmeta} SET meta_value = %s WHERE meta_key IN ( 'thumbnail_id', '_thumbnail_id' ) AND meta_value = %s",
				(string) $keeper_id,
				(string) $duplicate_id
			)
		);
	}

	/**
	 * Point the site icon and custom logo at the keeper.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 */
	protected function replace_option_references( $duplicate_id, $keeper_id ) {
		if ( intval( get_option( 'site_icon' ) ) === $duplicate_id ) {
			update_option( 'site_icon', $keeper_id );
		}

		if ( intval( get_theme_mod( 'custom_logo' ) ) === $duplicate_id ) {
			set_theme_mod( 'custom_logo', $keeper_id );
		}
	}

	/**
	 * Replace the duplicate ID inside comma-separated ID lists in post meta.
	 *
	 * Currently covers the WooCommerce product gallery.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 */
	protected function replace_comma_id_meta( $duplicate_id, $keeper_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( (string) $duplicate_id ) . '%'
			)
		);

		foreach ( $rows as $row ) {
			$ids     = array_map( 'trim', explode( ',', $row->meta_value ) );
			$changed = false;

			foreach ( $ids as $index => $id ) {
				if ( intval( $id ) === $duplicate_id ) {
					$ids[ $index ] = $keeper_id;
					$changed       = true;
				}
			}

			if ( $changed ) {
				update_metadata_by_mid( 'post', $row->meta_id, implode( ',', array_unique( $ids ) ) );
			}
		}
	}

	/**
	 * Replace duplicate references inside post content.
	 *
	 * Swaps the duplicate's file URLs (including every registered image
	 * size, which also covers srcset attributes) for the keeper's, and
	 * rewrites attachment ID references used by image CSS classes,
	 * image blocks, and galleries.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 */
	protected function replace_in_content( $duplicate_id, $keeper_id ) {
		global $wpdb;

		foreach ( self::build_url_map( $duplicate_id, $keeper_id ) as $from => $to ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s",
					$from,
					$to,
					'%' . $wpdb->esc_like( $from ) . '%'
				)
			);
		}

		// Find posts that may hold attachment ID references to rewrite.
		$sql = $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_status NOT IN ( 'inherit', 'trash' )
			AND (
				post_content LIKE %s
				OR post_content LIKE %s
				OR post_content LIKE %s
				OR post_content LIKE %s
			)",
			'%' . $wpdb->esc_like( 'wp-image-' . $duplicate_id ) . '%',
			'%' . $wpdb->esc_like( '"id":' . $duplicate_id ) . '%',
			'%' . $wpdb->esc_like( '<!-- wp:gallery' ) . '%',
			'%' . $wpdb->esc_like( '[gallery' ) . '%'
		);

		$posts = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.

		foreach ( (array) $posts as $post ) {
			$updated = self::rewrite_content_ids( $post->post_content, $duplicate_id, $keeper_id );

			if ( $updated !== $post->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $updated ), array( 'ID' => $post->ID ) );
				clean_post_cache( $post->ID );
			}
		}
	}

	/**
	 * Rewrite attachment ID references in a post's content.
	 *
	 * @param string $content      Post content.
	 * @param int    $duplicate_id Duplicate attachment ID.
	 * @param int    $keeper_id    Keeper attachment ID.
	 * @return string Updated content.
	 */
	protected static function rewrite_content_ids( $content, $duplicate_id, $keeper_id ) {
		// Image CSS classes: wp-image-123 (the digit lookahead avoids matching wp-image-1234).
		$content = preg_replace(
			'#wp-image-' . $duplicate_id . '(?![0-9])#',
			'wp-image-' . $keeper_id,
			$content
		);

		// Image block comment: <!-- wp:image {"id":123,...} -->.
		$content = preg_replace(
			'#(<!--\s+wp:image\s+\{[^{}]*?"id"\s*:\s*)' . $duplicate_id . '(?![0-9])#',
			'${1}' . $keeper_id,
			$content
		);

		// Legacy gallery block: <!-- wp:gallery {"ids":[123,456]} -->.
		$content = preg_replace_callback(
			'#(<!--\s+wp:gallery\s+\{[^{}]*?"ids"\s*:\s*\[)([^\]]*)(\])#',
			function ( $matches ) use ( $duplicate_id, $keeper_id ) {
				$ids = preg_replace( '#\b' . $duplicate_id . '(?![0-9])#', (string) $keeper_id, $matches[2] );
				return $matches[1] . $ids . $matches[3];
			},
			$content
		);

		// Gallery shortcode: [gallery ids="123,456"].
		$content = preg_replace_callback(
			'#(\[gallery[^\]]*\bids=")([^"]*)(")#',
			function ( $matches ) use ( $duplicate_id, $keeper_id ) {
				$ids = array_map( 'trim', explode( ',', $matches[2] ) );
				foreach ( $ids as $index => $id ) {
					if ( intval( $id ) === $duplicate_id ) {
						$ids[ $index ] = $keeper_id;
					}
				}
				return $matches[1] . implode( ',', array_unique( $ids ) ) . $matches[3];
			},
			$content
		);

		return $content;
	}

	/**
	 * Build a map of the duplicate's file URLs to the keeper's.
	 *
	 * Includes the full-size file and every registered intermediate
	 * size that exists on both attachments.
	 *
	 * @param int $duplicate_id Duplicate attachment ID.
	 * @param int $keeper_id    Keeper attachment ID.
	 * @return array Map of duplicate URL => keeper URL.
	 */
	protected static function build_url_map( $duplicate_id, $keeper_id ) {
		$map = array();

		$duplicate_url = wp_get_attachment_url( $duplicate_id );
		$keeper_url    = wp_get_attachment_url( $keeper_id );

		if ( ! $duplicate_url || ! $keeper_url || $duplicate_url === $keeper_url ) {
			return $map;
		}

		$map[ $duplicate_url ] = $keeper_url;

		$duplicate_meta = wp_get_attachment_metadata( $duplicate_id );
		$keeper_meta    = wp_get_attachment_metadata( $keeper_id );

		if ( ! is_array( $duplicate_meta ) || ! is_array( $keeper_meta ) || empty( $duplicate_meta['sizes'] ) || empty( $keeper_meta['sizes'] ) ) {
			return $map;
		}

		$duplicate_base = trailingslashit( dirname( $duplicate_url ) );
		$keeper_base    = trailingslashit( dirname( $keeper_url ) );

		foreach ( $duplicate_meta['sizes'] as $size => $data ) {
			if ( ! empty( $data['file'] ) && ! empty( $keeper_meta['sizes'][ $size ]['file'] ) ) {
				$map[ $duplicate_base . $data['file'] ] = $keeper_base . $keeper_meta['sizes'][ $size ]['file'];
			}
		}

		return $map;
	}
}
