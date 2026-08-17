<?php
/**
 * Shared admin settings page layout.
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Settings_Page
 *
 * Renders the common header and root container markup used by every
 * AppPresser settings page, so each feature only needs to supply a
 * title and its React root element id(s).
 */
class AppPresser_Settings_Page {

	/**
	 * Render the page header and root container(s).
	 *
	 * @param string          $title    Page heading shown next to the logo.
	 * @param string|string[] $root_ids One or more element ids to mount React roots into.
	 */
	public static function render( $title, $root_ids ) {
		?>
		<div class="plugin-header">
			<img src="<?php echo esc_url( APPRESSER_WP_URL . '/apppresser.jpg' ); ?>" alt="AppPresser" class="plugin-header-logo" />
			<h1><?php echo esc_html( $title ); ?></h1>
		</div>
		<?php
		foreach ( (array) $root_ids as $root_id ) {
			?>
			<div id="<?php echo esc_attr( $root_id ); ?>"></div>
			<?php
		}
	}
}
