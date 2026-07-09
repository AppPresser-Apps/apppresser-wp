<?php
/**
 * Cookie Consent Banner
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AppPresser_Cookie_Consent class.
 *
 * Renders a cookie consent banner using WordPress options.
 */
class AppPresser_Cookie_Consent {

	/**
	 * Settings from WordPress options.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_banner' ) );
	}

	/**
	 * Load settings from WordPress options once.
	 *
	 * @return array
	 */
	private function get_settings() {
		if ( null === $this->settings ) {
			$this->settings = array(
				'enabled'         => get_option( 'apppresser_cookie_consent_enabled', false ),
				'policy_page'     => get_option( 'apppresser_cookie_policy_page', 0 ),
				'duration'        => get_option( 'apppresser_cookie_consent_duration', 30 ),
				'position'        => get_option( 'apppresser_cookie_banner_position', 'left_bottom' ),
				'message'         => get_option( 'apppresser_cookie_message', '' ),
				'settings_button' => get_option( 'apppresser_cookie_button_settings', '' ) ?: __( 'Preferences', 'apppresser-wp' ),
				'reject_button'   => get_option( 'apppresser_cookie_button_reject', '' ) ?: __( 'Reject', 'apppresser-wp' ),
				'accept_button'   => get_option( 'apppresser_cookie_button_accept', '' ) ?: __( 'Accept All', 'apppresser-wp' ),
			);
		}
		return $this->settings;
	}

	/**
	 * Enqueue frontend CSS and JS.
	 */
	public function enqueue_assets() {
		$settings = $this->get_settings();

		if ( ! $settings['enabled'] ) {
			return;
		}

		wp_enqueue_style(
			'apppresser-cookie-consent',
			APPRESSER_WP_URL . '/inludes/cookies/css/cookie-consent.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'apppresser-cookie-consent',
			APPRESSER_WP_URL . '/inludes/cookies/js/cookie-consent.js',
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'apppresser-cookie-consent',
			'apppresserCookieConsent',
			array(
				'duration'        => absint( $settings['duration'] ),
				'cookieName'      => 'apppresser_cookie_consent',
				'prefsCookieName' => 'apppresser_cookie_prefs',
			)
		);
	}

	/**
	 * Render the banner in the footer.
	 */
	public function render_banner() {
		$settings = $this->get_settings();

		if ( ! $settings['enabled'] ) {
			return;
		}

		$policy_url = $settings['policy_page'] ? get_permalink( $settings['policy_page'] ) : '';

		$position_class = 'apppresser-cookie-banner--' . esc_attr( $settings['position'] );
		?>
		<div class="apppresser-cookie-banner <?php echo $position_class; ?>" id="apppresser-cookie-banner" role="dialog" aria-labelledby="apppresser-cookie-title" aria-hidden="true">
			<div class="apppresser-cookie-banner__inner">
				<div class="apppresser-cookie-banner__content">
					<?php if ( ! empty( $settings['message'] ) ) : ?>
						<div class="apppresser-cookie-banner__message">
							<?php echo wp_kses_post( $settings['message'] ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $policy_url ) : ?>
						<p class="apppresser-cookie-banner__policy-link">
							<a href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Learn more in our Cookie Policy', 'apppresser-wp' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<div class="apppresser-cookie-banner__actions">
					<button type="button" class="apppresser-cookie-banner__btn apppresser-cookie-banner__btn--settings" id="apppresser-cookie-settings-btn">
						<?php echo esc_html( $settings['settings_button'] ); ?>
					</button>
					<button type="button" class="apppresser-cookie-banner__btn apppresser-cookie-banner__btn--reject" id="apppresser-cookie-reject-btn">
						<?php echo esc_html( $settings['reject_button'] ); ?>
					</button>
					<button type="button" class="apppresser-cookie-banner__btn apppresser-cookie-banner__btn--accept" id="apppresser-cookie-accept-btn">
						<?php echo esc_html( $settings['accept_button'] ); ?>
					</button>
				</div>

				<button type="button" class="apppresser-cookie-banner__close" id="apppresser-cookie-close-btn" aria-label="<?php esc_attr_e( 'Close banner', 'apppresser-wp' ); ?>">
					&times;
				</button>
			</div>

			<?php $this->render_preferences_panel( $settings, $policy_url ); ?>
		</div>
		<?php
	}

	/**
	 * Render the preferences/settings panel (hidden by default).
	 *
	 * @param array  $settings   ACF settings.
	 * @param string $policy_url Cookie policy URL.
	 */
	private function render_preferences_panel( $settings, $policy_url ) {
		?>
		<div class="apppresser-cookie-preferences" id="apppresser-cookie-preferences" aria-hidden="true">
			<div class="apppresser-cookie-preferences__inner">
				<h3 class="apppresser-cookie-preferences__title">
					<?php esc_html_e( 'Cookie Preferences', 'apppresser-wp' ); ?>
				</h3>

				<p class="apppresser-cookie-preferences__intro">
					<?php esc_html_e( 'Manage your cookie preferences below. Essential cookies are always enabled as they are required for the website to function properly.', 'apppresser-wp' ); ?>
				</p>

				<div class="apppresser-cookie-preferences__categories">
					<div class="apppresser-cookie-pref-category">
						<div class="apppresser-cookie-pref-category__header">
							<h4><?php esc_html_e( 'Essential Cookies', 'apppresser-wp' ); ?></h4>
							<label class="apppresser-cookie-toggle apppresser-cookie-toggle--disabled">
								<input type="checkbox" checked disabled>
								<span class="apppresser-cookie-toggle__slider"></span>
								<span class="apppresser-cookie-toggle__label"><?php esc_html_e( 'Always Active', 'apppresser-wp' ); ?></span>
							</label>
						</div>
						<p class="apppresser-cookie-pref-category__desc">
							<?php esc_html_e( 'These cookies are necessary for the website to function and cannot be switched off in our systems.', 'apppresser-wp' ); ?>
						</p>
					</div>

					<div class="apppresser-cookie-pref-category">
						<div class="apppresser-cookie-pref-category__header">
							<h4><?php esc_html_e( 'Analytics Cookies', 'apppresser-wp' ); ?></h4>
							<label class="apppresser-cookie-toggle">
								<input type="checkbox" id="apppresser-cookie-analytics" value="analytics" checked>
								<span class="apppresser-cookie-toggle__slider"></span>
							</label>
						</div>
						<p class="apppresser-cookie-pref-category__desc">
							<?php esc_html_e( 'These cookies allow us to count visits and traffic sources so we can measure and improve the performance of our site.', 'apppresser-wp' ); ?>
						</p>
					</div>

					<div class="apppresser-cookie-pref-category">
						<div class="apppresser-cookie-pref-category__header">
							<h4><?php esc_html_e( 'Marketing Cookies', 'apppresser-wp' ); ?></h4>
							<label class="apppresser-cookie-toggle">
								<input type="checkbox" id="apppresser-cookie-marketing" value="marketing" checked>
								<span class="apppresser-cookie-toggle__slider"></span>
							</label>
						</div>
						<p class="apppresser-cookie-pref-category__desc">
							<?php esc_html_e( 'These cookies may be set through our site by our advertising partners to build a profile of your interests.', 'apppresser-wp' ); ?>
						</p>
					</div>
				</div>

				<div class="apppresser-cookie-preferences__actions">
					<button type="button" class="apppresser-cookie-banner__btn apppresser-cookie-banner__btn--reject" id="apppresser-cookie-reject-all-btn">
						<?php esc_html_e( 'Reject All', 'apppresser-wp' ); ?>
					</button>
					<button type="button" class="apppresser-cookie-banner__btn apppresser-cookie-banner__btn--accept" id="apppresser-cookie-save-prefs-btn">
						<?php esc_html_e( 'Save Preferences', 'apppresser-wp' ); ?>
					</button>
				</div>

				<?php if ( $policy_url ) : ?>
					<p class="apppresser-cookie-preferences__policy-link">
						<a href="<?php echo esc_url( $policy_url ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'View our full Cookie Policy', 'apppresser-wp' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
