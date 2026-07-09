/**
 * Cookies settings page.
 */
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	SelectControl,
	TextControl,
	TextareaControl,
	Notice,
} from '@wordpress/components';

const { settings, pages, positions, ajaxUrl, nonce } =
	window.apppresserCookies || {};

const CookiesApp = () => {
	const [ values, setValues ] = useState( () => ( {
		apppresser_cookie_consent_enabled:
			Boolean( settings?.apppresser_cookie_consent_enabled ) || false,
		apppresser_cookie_policy_page:
			parseInt( settings?.apppresser_cookie_policy_page, 10 ) || 0,
		apppresser_cookie_consent_duration:
			parseInt( settings?.apppresser_cookie_consent_duration, 10 ) || 30,
		apppresser_cookie_banner_position:
			settings?.apppresser_cookie_banner_position || 'left_bottom',
		apppresser_cookie_message:
			settings?.apppresser_cookie_message || '',
		apppresser_cookie_button_settings:
			settings?.apppresser_cookie_button_settings || '',
		apppresser_cookie_button_reject:
			settings?.apppresser_cookie_button_reject || '',
		apppresser_cookie_button_accept:
			settings?.apppresser_cookie_button_accept || '',
	} ) );

	const [ saving, setSaving ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const saveSetting = useCallback(
		( key, value ) => {
			setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );

			const formData = new FormData();
			formData.append( 'action', 'apppresser_cookies_save' );
			formData.append( 'nonce', nonce );
			formData.append( 'key', key );
			formData.append( 'value', value );

			setSaving( key );

			fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
			} )
				.then( ( response ) => response.json() )
				.then( ( data ) => {
					if ( data.success ) {
						setNotice( {
							type: 'success',
							message: __( 'Setting saved.', 'apppresser-wp' ),
						} );
					} else {
						setNotice( {
							type: 'error',
							message:
								data.data?.message ||
								__( 'Failed to save setting.', 'apppresser-wp' ),
						} );
					}
				} )
				.catch( () => {
					setNotice( {
						type: 'error',
						message: __(
							'Network error. Please try again.',
							'apppresser-wp'
						),
					} );
				} )
				.finally( () => {
					setSaving( null );
					// Auto-dismiss success notices after 3 seconds.
					setTimeout( () => setNotice( null ), 3000 );
				} );
		},
		[ ajaxUrl, nonce ]
	);

	return (
		<Panel>
			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<PanelBody
				title={ __( 'General', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<ToggleControl
						label={ __(
							'Enable Cookie Consent Banner',
							'apppresser-wp'
						) }
						help={ __(
							'Show a cookie consent banner to visitors.',
							'apppresser-wp'
						) }
						checked={
							values.apppresser_cookie_consent_enabled
						}
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_consent_enabled',
								value
							)
						}
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						label={ __( 'Cookie Policy Page', 'apppresser-wp' ) }
						help={ __(
							'Select the page that contains your cookie policy.',
							'apppresser-wp'
						) }
						value={ values.apppresser_cookie_policy_page }
						options={ pages || [] }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_policy_page',
								parseInt( value, 10 )
							)
						}
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						type="number"
						label={ __(
							'Consent Duration (days)',
							'apppresser-wp'
						) }
						help={ __(
							'Number of days before the consent banner reappears.',
							'apppresser-wp'
						) }
						value={ values.apppresser_cookie_consent_duration }
						min={ 1 }
						max={ 365 }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_consent_duration',
								parseInt( value, 10 ) || 30
							)
						}
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Appearance', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<SelectControl
						label={ __( 'Banner Position', 'apppresser-wp' ) }
						value={ values.apppresser_cookie_banner_position }
						options={ positions || [] }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_banner_position',
								value
							)
						}
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Content', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<TextareaControl
						label={ __( 'Cookie Message', 'apppresser-wp' ) }
						help={ __(
							'The message displayed in the cookie consent banner. HTML is allowed.',
							'apppresser-wp'
						) }
						value={ values.apppresser_cookie_message }
						rows={ 4 }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_message',
								value
							)
						}
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Button Labels', 'apppresser-wp' ) }
				initialOpen={ false }
			>
				<PanelRow>
					<TextControl
						label={ __( 'Settings Button', 'apppresser-wp' ) }
						help={ __(
							'Label for the preferences/settings button.',
							'apppresser-wp'
						) }
						value={
							values.apppresser_cookie_button_settings
						}
						placeholder={ __(
							'Preferences',
							'apppresser-wp'
						) }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_button_settings',
								value
							)
						}
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'Reject Button', 'apppresser-wp' ) }
						help={ __(
							'Label for the reject button.',
							'apppresser-wp'
						) }
						value={
							values.apppresser_cookie_button_reject
						}
						placeholder={ __( 'Reject', 'apppresser-wp' ) }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_button_reject',
								value
							)
						}
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						label={ __( 'Accept Button', 'apppresser-wp' ) }
						help={ __(
							'Label for the accept all button.',
							'apppresser-wp'
						) }
						value={
							values.apppresser_cookie_button_accept
						}
						placeholder={ __(
							'Accept All',
							'apppresser-wp'
						) }
						onChange={ ( value ) =>
							saveSetting(
								'apppresser_cookie_button_accept',
								value
							)
						}
					/>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default CookiesApp;
