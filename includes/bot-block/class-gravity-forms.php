<?php
/**
 * Gravity Forms bot-block integration (URL blocking, blocked words/domains, time-trap).
 *
 * @package AppPresser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly.
}

/**
 * Class AppPresser_Bot_Gravity_Forms
 *
 * Only loaded when Gravity Forms is active and at least one of its
 * settings is enabled. See apppresser-wp.php for the conditional loader.
 */
class AppPresser_Bot_Gravity_Forms {

	const TOKEN_FIELD = 'apppresser_bot_ts_token';

	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$settings = AppPresser_Bot_Rate_Limiter::get_settings();

		if ( ! empty( $settings['gf_url_block'] ) ) {
			add_filter( 'gform_field_validation', array( $this, 'block_urls_in_name_fields' ), 10, 4 );
		}

		if ( ! empty( $settings['blocked_words'] ) ) {
			add_filter( 'gform_field_validation', array( $this, 'block_words_in_fields' ), 10, 4 );
		}

		if ( ! empty( $settings['blocked_email_domains'] ) ) {
			add_filter( 'gform_field_validation', array( $this, 'block_email_domains' ), 10, 4 );
		}

		if ( ! empty( $settings['time_trap'] ) ) {
			add_filter( 'gform_get_form_filter', array( $this, 'inject_time_trap_field' ), 10, 2 );
			add_filter( 'gform_validation', array( $this, 'validate_time_trap' ) );
		}
	}

	/**
	 * Reject links/URLs typed into Name or Single Line Text fields.
	 *
	 * @param array           $result Validation result.
	 * @param mixed           $value  Submitted field value.
	 * @param array           $form   The current form.
	 * @param GF_Field         $field  The current field.
	 * @return array
	 */
	public function block_urls_in_name_fields( $result, $value, $form, $field ) {
		if ( 'name' !== $field->type && 'text' !== $field->type ) {
			return $result;
		}

		// Regex pattern to catch http, www, HTML links, BBCode, and common TLDs.
		$pattern = '/(http|https|www\.|<a\s+href|\[url|\.com|\.net|\.org|\.co|\.info)/i';

		// Gravity Forms passes complex fields (like Name) as an array of inputs.
		if ( is_array( $value ) ) {
			foreach ( $value as $input_value ) {
				if ( preg_match( $pattern, $input_value ) ) {
					$result['is_valid'] = false;
					$result['message']  = esc_html__( 'Links and URLs are not permitted in this field.', 'apppresser-wp' );
					break;
				}
			}
		} elseif ( preg_match( $pattern, $value ) ) {
			$result['is_valid'] = false;
			$result['message']  = esc_html__( 'Links and URLs are not permitted in this field.', 'apppresser-wp' );
		}

		return $result;
	}

	/**
	 * Reject submissions containing admin-defined blocked words.
	 *
	 * @param array    $result Validation result.
	 * @param mixed    $value  Submitted field value.
	 * @param array    $form   The current form.
	 * @param GF_Field $field  The current field.
	 * @return array
	 */
	public function block_words_in_fields( $result, $value, $form, $field ) {
		// Target 'name', 'text' (Single Line Text), and 'textarea' (Paragraph) fields.
		if ( ! in_array( $field->type, array( 'name', 'text', 'textarea' ), true ) ) {
			return $result;
		}

		$settings      = AppPresser_Bot_Rate_Limiter::get_settings();
		$blocked_words = $settings['blocked_words'];

		if ( empty( $blocked_words ) ) {
			return $result;
		}

		$values = is_array( $value ) ? $value : array( $value );

		foreach ( $values as $input_value ) {
			if ( ! is_string( $input_value ) || '' === $input_value ) {
				continue;
			}

			foreach ( $blocked_words as $word ) {
				if ( '' !== $word && false !== stripos( $input_value, $word ) ) {
					$result['is_valid'] = false;
					$result['message']  = esc_html__( 'Your submission contains prohibited content.', 'apppresser-wp' );
					return $result;
				}
			}
		}

		return $result;
	}

	/**
	 * Reject submissions using an admin-defined blocked email domain.
	 *
	 * @param array    $result Validation result.
	 * @param mixed    $value  Submitted field value.
	 * @param array    $form   The current form.
	 * @param GF_Field $field  The current field.
	 * @return array
	 */
	public function block_email_domains( $result, $value, $form, $field ) {
		if ( 'email' !== $field->type || empty( $value ) ) {
			return $result;
		}

		$settings = AppPresser_Bot_Rate_Limiter::get_settings();
		$blocked  = $settings['blocked_email_domains'];

		if ( empty( $blocked ) ) {
			return $result;
		}

		// Email fields can be rendered with a confirmation sub-field, which passes $value as an array.
		$email = is_array( $value ) ? reset( $value ) : $value;

		if ( ! is_string( $email ) || false === strpos( $email, '@' ) ) {
			return $result;
		}

		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );

		if ( in_array( $domain, $blocked, true ) ) {
			$result['is_valid'] = false;
			$result['message']  = esc_html__( 'Please use a different email address.', 'apppresser-wp' );
		}

		return $result;
	}

	/**
	 * Inject a signed hidden time-trap token before the form's closing tag.
	 *
	 * @param string $form_string Rendered form HTML.
	 * @param array  $form        The current form.
	 * @return string
	 */
	public function inject_time_trap_field( $form_string, $form ) {
		$token  = $this->generate_time_trap_token( $form['id'] );
		$hidden = '<input type="hidden" name="' . esc_attr( self::TOKEN_FIELD ) . '" value="' . esc_attr( $token ) . '" />';

		return preg_replace( '/<\/form>/', $hidden . '</form>', $form_string, 1 );
	}

	/**
	 * Reject submissions filled out faster than a human could.
	 *
	 * @param array $validation_result Whole-form validation result.
	 * @return array
	 */
	public function validate_time_trap( $validation_result ) {
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return $validation_result;
		}

		$settings    = AppPresser_Bot_Rate_Limiter::get_settings();
		$min_seconds = max( 0, (int) $settings['time_trap_seconds'] );
		$form        = $validation_result['form'];

		$token = isset( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : '';

		if ( $this->is_valid_time_trap_token( $token, $form['id'], $min_seconds ) ) {
			return $validation_result;
		}

		$validation_result['is_valid'] = false;

		foreach ( $form['fields'] as $field ) {
			$field->failed_validation  = true;
			$field->validation_message = esc_html__( 'Please try submitting the form again.', 'apppresser-wp' );
			break;
		}

		$validation_result['form'] = $form;

		return $validation_result;
	}

	/**
	 * Generate an HMAC-signed time-trap token for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	private function generate_time_trap_token( $form_id ) {
		$time = time();
		$hash = hash_hmac( 'sha256', $form_id . '|' . $time, wp_salt( 'auth' ) );

		return $time . '.' . $hash;
	}

	/**
	 * Verify a time-trap token's signature and elapsed time.
	 *
	 * @param string $token       Submitted token.
	 * @param int    $form_id     Form ID.
	 * @param int    $min_seconds Minimum required elapsed seconds.
	 * @return bool
	 */
	private function is_valid_time_trap_token( $token, $form_id, $min_seconds ) {
		if ( empty( $token ) || false === strpos( $token, '.' ) ) {
			return false;
		}

		list( $time, $hash ) = explode( '.', $token, 2 );

		if ( ! ctype_digit( $time ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $form_id . '|' . $time, wp_salt( 'auth' ) );

		if ( ! hash_equals( $expected, $hash ) ) {
			return false;
		}

		return ( time() - (int) $time ) >= $min_seconds;
	}
}
