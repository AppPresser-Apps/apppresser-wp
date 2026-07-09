<?php
/**
 * Cookie Scanner & Policy Shortcode
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AppPresser_Cookie_Search class.
 *
 * - Handles the scan form submission.
 * - Scans the site for cookies via HTTP headers and inline scripts.
 * - Saves discovered cookies as a WordPress option.
 * - Registers the [cookie_policy] shortcode to display the cookie list.
 * - Registers the [cookie_manage] shortcode for a manage-preferences button.
 * - Provides AJAX handlers for the React scanner UI.
 */
class AppPresser_Cookie_Search {

	/**
	 * Option key for stored cookies.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'apppresser_detected_cookies';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Handle scan action.
		add_action( 'admin_init', array( $this, 'handle_scan' ) );

		// Register shortcodes.
		add_shortcode( 'cookie_policy', array( $this, 'render_shortcode' ) );
		add_shortcode( 'cookie_manage', array( $this, 'render_manage_button' ) );

		// Enqueue frontend styles when shortcode is used.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// AJAX handlers for the React scanner UI.
		add_action( 'wp_ajax_apppresser_save_cookie', array( $this, 'ajax_save_cookie' ) );
		add_action( 'wp_ajax_apppresser_delete_cookie', array( $this, 'ajax_delete_cookie' ) );
		add_action( 'wp_ajax_apppresser_add_cookie', array( $this, 'ajax_add_cookie' ) );
	}

	/**
	 * Handle the scan form submission.
	 */
	public function handle_scan() {
		if ( ! isset( $_POST['apppresser_action'] ) || 'scan_cookies' !== $_POST['apppresser_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'apppresser_scan_cookies', 'apppresser_scan_nonce' );

		$cookies = $this->scan_site();

		update_option( self::OPTION_KEY, $cookies );

		wp_safe_redirect( add_query_arg( 'scanned', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Scan the site for cookies.
	 *
	 * @return array
	 */
	private function scan_site() {
		$found = array();

		// 1. Request the homepage and capture Set-Cookie headers.
		$home_url = home_url( '/' );
		$response = wp_remote_get(
			$home_url,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$headers = wp_remote_retrieve_headers( $response );

			// Collect all Set-Cookie headers.
			$set_cookie_headers = array();
			if ( isset( $headers['set-cookie'] ) ) {
				$raw = $headers['set-cookie'];
				if ( is_array( $raw ) ) {
					$set_cookie_headers = $raw;
				} else {
					$set_cookie_headers = array( $raw );
				}
			}

			// Also check for lowercase variant.
			if ( isset( $headers['Set-Cookie'] ) ) {
				$raw = $headers['Set-Cookie'];
				if ( is_array( $raw ) ) {
					$set_cookie_headers = array_merge( $set_cookie_headers, $raw );
				} else {
					$set_cookie_headers[] = $raw;
				}
			}

			foreach ( $set_cookie_headers as $cookie_header ) {
				$parsed = $this->parse_cookie_header( $cookie_header );
				if ( $parsed ) {
					$found[ $parsed['name'] ] = $parsed;
				}
			}

			// 2. Scan the response body for common cookie-setting scripts.
			$body           = wp_remote_retrieve_body( $response );
			$script_cookies = $this->scan_body_for_cookies( $body );
			foreach ( $script_cookies as $name => $data ) {
				if ( ! isset( $found[ $name ] ) ) {
					$found[ $name ] = $data;
				}
			}
		}

		// 3. Scan for known WordPress cookies.
		$wp_cookies = $this->get_wordpress_cookies();
		foreach ( $wp_cookies as $name => $data ) {
			if ( ! isset( $found[ $name ] ) ) {
				$found[ $name ] = $data;
			}
		}

		return array_values( $found );
	}

	/**
	 * Parse a Set-Cookie header string.
	 *
	 * @param string $header Raw Set-Cookie header.
	 * @return array|null
	 */
	private function parse_cookie_header( $header ) {
		$parts = explode( ';', $header );
		$first = trim( array_shift( $parts ) );

		// Extract name=value.
		$eq_pos = strpos( $first, '=' );
		if ( false === $eq_pos ) {
			return null;
		}

		$name  = trim( substr( $first, 0, $eq_pos ) );
		$value = trim( substr( $first, $eq_pos + 1 ) );

		if ( empty( $name ) ) {
			return null;
		}

		// Parse attributes.
		$domain  = '';
		$max_age = '';

		foreach ( $parts as $attr ) {
			$attr = trim( $attr );
			if ( stripos( $attr, 'domain=' ) === 0 ) {
				$domain = trim( substr( $attr, 7 ) );
			} elseif ( stripos( $attr, 'max-age=' ) === 0 ) {
				$max_age = trim( substr( $attr, 8 ) );
			} elseif ( stripos( $attr, 'expires=' ) === 0 ) {
				$expires = trim( substr( $attr, 8 ) );
				if ( ! $max_age && $expires ) {
					$expires_time = strtotime( $expires );
					if ( $expires_time ) {
						$diff = $expires_time - time();
						if ( $diff > 0 ) {
							$max_age = $diff;
						}
					}
				}
			}
		}

		if ( empty( $domain ) ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$duration = $this->format_duration( $max_age );

		return array(
			'name'        => $name,
			'domain'      => $domain,
			'duration'    => $duration,
			'category'    => $this->guess_category( $name, $domain ),
			'description' => '',
		);
	}

	/**
	 * Scan HTML body for common cookie-setting patterns.
	 *
	 * @param string $body HTML body.
	 * @return array
	 */
	private function scan_body_for_cookies( $body ) {
		$found  = array();
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$patterns = array(
			'/_ga\b/'            => array(
				'name'        => '_ga',
				'domain'      => $domain,
				'duration'    => '2 years',
				'category'    => 'analytics',
				'description' => 'Google Analytics: used to distinguish users.',
			),
			'/_gid\b/'           => array(
				'name'        => '_gid',
				'domain'      => $domain,
				'duration'    => '24 hours',
				'category'    => 'analytics',
				'description' => 'Google Analytics: used to distinguish users.',
			),
			'/_gat\b/'           => array(
				'name'        => '_gat',
				'domain'      => $domain,
				'duration'    => '1 minute',
				'category'    => 'analytics',
				'description' => 'Google Analytics: used to throttle request rate.',
			),
			'/_fbp\b/'           => array(
				'name'        => '_fbp',
				'domain'      => $domain,
				'duration'    => '3 months',
				'category'    => 'marketing',
				'description' => 'Facebook Pixel: used to store and track visits across websites.',
			),
			'/googletagmanager/' => array(
				'name'        => '_dc_gtm_UA-*',
				'domain'      => $domain,
				'duration'    => '1 minute',
				'category'    => 'analytics',
				'description' => 'Google Tag Manager: used to throttle request rate.',
			),
		);

		foreach ( $patterns as $pattern => $cookie_data ) {
			if ( preg_match( $pattern, $body ) ) {
				$found[ $cookie_data['name'] ] = $cookie_data;
			}
		}

		return $found;
	}

	/**
	 * Get known WordPress cookies.
	 *
	 * @return array
	 */
	private function get_wordpress_cookies() {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'wordpress_test_cookie' => array(
				'name'        => 'wordpress_test_cookie',
				'domain'      => $domain,
				'duration'    => 'Session',
				'category'    => 'essential',
				'description' => 'WordPress: tests whether cookies are enabled in the browser.',
			),
			'wordpress_logged_in_*' => array(
				'name'        => 'wordpress_logged_in_*',
				'domain'      => $domain,
				'duration'    => 'Session',
				'category'    => 'essential',
				'description' => 'WordPress: indicates when you are logged in and who you are.',
			),
			'wordpress_sec_*'       => array(
				'name'        => 'wordpress_sec_*',
				'domain'      => $domain,
				'duration'    => 'Session',
				'category'    => 'essential',
				'description' => 'WordPress: used for secure account access.',
			),
			'wp-settings-*'         => array(
				'name'        => 'wp-settings-*',
				'domain'      => $domain,
				'duration'    => '1 year',
				'category'    => 'essential',
				'description' => 'WordPress: stores user interface preferences.',
			),
			'wp-settings-time-*'    => array(
				'name'        => 'wp-settings-time-*',
				'domain'      => $domain,
				'duration'    => '1 year',
				'category'    => 'essential',
				'description' => 'WordPress: stores the time of the wp-settings cookie.',
			),
		);
	}

	/**
	 * Format a duration in seconds to a human-readable string.
	 *
	 * @param string|int $seconds Duration in seconds.
	 * @return string
	 */
	private function format_duration( $seconds ) {
		$seconds = absint( $seconds );

		if ( 0 === $seconds ) {
			return 'Session';
		}

		$minute = 60;
		$hour   = 3600;
		$day    = 86400;
		$year   = 31536000;

		if ( $seconds >= $year ) {
			$years = round( $seconds / $year, 1 );
			/* translators: %s: number of years */
			return sprintf( _n( '%s year', '%s years', (int) $years, 'apppresser-wp' ), $years );
		} elseif ( $seconds >= $day ) {
			$days = round( $seconds / $day );
			/* translators: %d: number of days */
			return sprintf( _n( '%d day', '%d days', (int) $days, 'apppresser-wp' ), (int) $days );
		} elseif ( $seconds >= $hour ) {
			$hours = round( $seconds / $hour, 1 );
			/* translators: %s: number of hours */
			return sprintf( _n( '%s hour', '%s hours', (int) $hours, 'apppresser-wp' ), $hours );
		} elseif ( $seconds >= $minute ) {
			$minutes = round( $seconds / $minute );
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', (int) $minutes, 'apppresser-wp' ), (int) $minutes );
		}

		/* translators: %d: number of seconds */
		return sprintf( _n( '%d second', '%d seconds', $seconds, 'apppresser-wp' ), $seconds );
	}

	/**
	 * Guess the cookie category based on name and domain.
	 *
	 * @param string $name   Cookie name.
	 * @param string $domain Cookie domain.
	 * @return string
	 */
	private function guess_category( $name, $domain ) {
		$analytics_patterns = array( '_ga', '_gid', '_gat', 'utm_', '__utm', 'ga_', 'analytics' );
		$marketing_patterns = array( '_fbp', 'fr', 'tr', 'ads', 'pixel', 'ad_' );

		$name_lower = strtolower( $name );

		foreach ( $analytics_patterns as $pattern ) {
			if ( false !== strpos( $name_lower, $pattern ) ) {
				return 'analytics';
			}
		}

		foreach ( $marketing_patterns as $pattern ) {
			if ( false !== strpos( $name_lower, $pattern ) ) {
				return 'marketing';
			}
		}

		return 'essential';
	}

	/**
	 * AJAX handler to save a cookie edit.
	 */
	public function ajax_save_cookie() {
		check_ajax_referer( 'apppresser_cookie_scanner', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : null;
		$data  = isset( $_POST['cookie'] ) ? (array) $_POST['cookie'] : array();

		if ( null === $index || empty( $data ) ) {
			wp_send_json_error( array( 'message' => 'Invalid data.' ) );
		}

		$cookies = get_option( self::OPTION_KEY, array() );

		if ( ! isset( $cookies[ $index ] ) ) {
			wp_send_json_error( array( 'message' => 'Cookie not found.' ) );
		}

		$cookies[ $index ] = array(
			'name'        => sanitize_text_field( $data['name'] ),
			'domain'      => sanitize_text_field( $data['domain'] ),
			'duration'    => sanitize_text_field( $data['duration'] ),
			'category'    => sanitize_text_field( $data['category'] ),
			'description' => sanitize_textarea_field( $data['description'] ),
		);

		update_option( self::OPTION_KEY, $cookies );

		wp_send_json_success(
			array(
				'message' => 'Cookie saved.',
				'cookie'  => $cookies[ $index ],
			)
		);
	}

	/**
	 * AJAX handler to delete a cookie.
	 */
	public function ajax_delete_cookie() {
		check_ajax_referer( 'apppresser_cookie_scanner', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : null;

		if ( null === $index ) {
			wp_send_json_error( array( 'message' => 'Invalid index.' ) );
		}

		$cookies = get_option( self::OPTION_KEY, array() );

		if ( ! isset( $cookies[ $index ] ) ) {
			wp_send_json_error( array( 'message' => 'Cookie not found.' ) );
		}

		array_splice( $cookies, $index, 1 );
		$cookies = array_values( $cookies );

		update_option( self::OPTION_KEY, $cookies );

		wp_send_json_success( array( 'message' => 'Cookie deleted.' ) );
	}

	/**
	 * AJAX handler to add a new cookie manually.
	 */
	public function ajax_add_cookie() {
		check_ajax_referer( 'apppresser_cookie_scanner', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$data = isset( $_POST['cookie'] ) ? (array) $_POST['cookie'] : array();

		$cookies   = get_option( self::OPTION_KEY, array() );
		$cookies[] = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'domain'      => sanitize_text_field( $data['domain'] ?? '' ),
			'duration'    => sanitize_text_field( $data['duration'] ?? 'Session' ),
			'category'    => sanitize_text_field( $data['category'] ?? 'essential' ),
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
		);

		update_option( self::OPTION_KEY, $cookies );

		wp_send_json_success( array( 'message' => 'Cookie added.' ) );
	}

	/**
	 * Enqueue frontend assets when cookie policy is displayed.
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'apppresser-cookie-policy',
			APPRESSER_WP_URL . '/inludes/cookies/css/cookie-scanner.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Render the [cookie_policy] shortcode.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$cookies = get_option( self::OPTION_KEY, array() );

		if ( empty( $cookies ) ) {
			return '<p style="text-align: center; padding: 30px 20px;">' . esc_html__( 'No cookie information is currently available.', 'apppresser-wp' ) . '</p>';
		}

		// Group by category.
		$grouped = array(
			'essential' => array(),
			'analytics' => array(),
			'marketing' => array(),
		);

		foreach ( $cookies as $cookie ) {
			$cat = isset( $cookie['category'] ) ? $cookie['category'] : 'essential';
			if ( ! isset( $grouped[ $cat ] ) ) {
				$grouped[ $cat ] = array();
			}
			$grouped[ $cat ][] = $cookie;
		}

		$category_labels = array(
			'essential' => __( 'Essential Cookies', 'apppresser-wp' ),
			'analytics' => __( 'Analytics Cookies', 'apppresser-wp' ),
			'marketing' => __( 'Marketing Cookies', 'apppresser-wp' ),
		);

		$category_descriptions = array(
			'essential' => __( 'These cookies are necessary for the website to function properly and cannot be switched off in our systems.', 'apppresser-wp' ),
			'analytics' => __( 'These cookies allow us to count visits and traffic sources so we can measure and improve the performance of our site.', 'apppresser-wp' ),
			'marketing' => __( 'These cookies may be set through our site by our advertising partners to build a profile of your interests and show you relevant adverts on other sites.', 'apppresser-wp' ),
		);

		ob_start();
		?>
		<div class="apppresser-cookie-policy">
			<?php foreach ( $grouped as $cat => $cat_cookies ) : ?>
				<?php if ( ! empty( $cat_cookies ) ) : ?>
					<div class="apppresser-cookie-policy__category">
						<h3 class="apppresser-cookie-policy__cat-title">
							<?php echo esc_html( $category_labels[ $cat ] ?? ucfirst( $cat ) ); ?>
						</h3>
						<p class="apppresser-cookie-policy__cat-desc">
							<?php echo esc_html( $category_descriptions[ $cat ] ?? '' ); ?>
						</p>

						<table class="apppresser-cookie-policy__table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Cookie', 'apppresser-wp' ); ?></th>
									<th><?php esc_html_e( 'Domain', 'apppresser-wp' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'apppresser-wp' ); ?></th>
									<th><?php esc_html_e( 'Description', 'apppresser-wp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cat_cookies as $cookie ) : ?>
									<tr>
										<td data-label="<?php esc_attr_e( 'Cookie', 'apppresser-wp' ); ?>"><code><?php echo esc_html( $cookie['name'] ); ?></code></td>
										<td data-label="<?php esc_attr_e( 'Domain', 'apppresser-wp' ); ?>"><?php echo esc_html( $cookie['domain'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Duration', 'apppresser-wp' ); ?>"><?php echo esc_html( $cookie['duration'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Description', 'apppresser-wp' ); ?>"><?php echo esc_html( $cookie['description'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<script>
		(function(){
			var btn = document.getElementById('apppresser-cookie-policy-manage-btn');
			if (!btn) return;
			btn.addEventListener('click', function(){
				var banner = document.getElementById('apppresser-cookie-banner');
				var prefs  = document.getElementById('apppresser-cookie-preferences');
				if (banner) {
					banner.classList.add('is-visible');
					banner.setAttribute('aria-hidden', 'false');
				}
				if (prefs) {
					prefs.classList.add('is-visible');
					prefs.setAttribute('aria-hidden', 'false');
				}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the [cookie_manage] shortcode — a standalone button
	 * to re-open the cookie consent banner and preferences panel.
	 *
	 * @return string
	 */
	public function render_manage_button() {
		ob_start();
		?>
		<span class="apppresser-cookie-manage-wrap">
			<button type="button" class="apppresser-cookie-manage-btn" id="apppresser-cookie-manage-btn">
				<?php esc_html_e( 'Manage Cookie Preferences', 'apppresser-wp' ); ?>
			</button>
		</span>
		<script>
		(function(){
			var btn = document.getElementById('apppresser-cookie-manage-btn');
			if (!btn) return;
			btn.addEventListener('click', function(){
				var banner = document.getElementById('apppresser-cookie-banner');
				var prefs  = document.getElementById('apppresser-cookie-preferences');
				if (banner) {
					banner.classList.add('is-visible');
					banner.setAttribute('aria-hidden', 'false');
				}
				if (prefs) {
					prefs.classList.add('is-visible');
					prefs.setAttribute('aria-hidden', 'false');
				}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}
}
