<?php

/**
 * AppPresser
 *
 * @package AppPresser
 * @author  AppPresser
 *
 * @wordpress-plugin
 * Plugin Name:       AppPresser WP
 * Plugin URI:        https://apppresser.com
 * Description:       A compilation of functionality for running a WordPress site.
 * Version:           1.0.0
 * Author:            AppPresser
 * Author URI:        https://apppresser.com
 * Text Domain:       apppresser-wp
 * Domain Path:       /lang
 * Requires PHP:      8.1
 * Requires at least: 6.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

define( 'APPRESSER_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'APPRESSER_WP_URL', plugins_url( basename( __DIR__ ) ) );
define( 'APPRESSER_WP_SLUG', plugin_basename( __FILE__ ) );
define( 'APPRESSER_WP_FILE', __FILE__ );

// Load accessibility module.
require_once APPRESSER_WP_DIR . 'includes/accessibility/class-accessibility.php';
new AppPresser_Accessibility();

// Load cookies module.
require_once APPRESSER_WP_DIR . 'includes/cookies/class-cookies.php';
new AppPresser_Cookies();

// Load cookie consent banner.
require_once APPRESSER_WP_DIR . 'includes/cookies/class-cookie-consent.php';
new AppPresser_Cookie_Consent();

// Load cookie scanner & shortcodes.
require_once APPRESSER_WP_DIR . 'includes/cookies/class-cookie-search.php';
new AppPresser_Cookie_Search();

// Load maintenance module.
require_once APPRESSER_WP_DIR . 'includes/maintenance/class-maintenance.php';
new AppPresser_Maintenance();

// Load social share module.
require_once APPRESSER_WP_DIR . 'includes/social-share/class-social-share.php';
new AppPresser_Social_Share();

// Load options module.
require_once APPRESSER_WP_DIR . 'includes/options/class-options.php';
new AppPresser_Options();
