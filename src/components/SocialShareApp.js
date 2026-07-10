/**
 * Social Share settings page.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl } from '@wordpress/components';

const { settings, buttons, colors: initialColors, ajaxUrl, nonce } = window.apppresserSocialShare || {};

const SocialShareApp = () => {
	const [ toggles, setToggles ] = useState( () => {
		const initial = {};
		if ( settings ) {
			Object.keys( settings ).forEach( ( key ) => {
				initial[ key ] = Boolean( settings[ key ] );
			} );
		}
		return initial;
	} );

	const [ colors, setColors ] = useState( () => {
		const initial = {};
		if ( buttons && initialColors ) {
			Object.keys( buttons ).forEach( ( key ) => {
				initial[ key ] = {
					bg: initialColors[ key ]?.bg || buttons[ key ]?.bg_color || '#333333',
					text: initialColors[ key ]?.text || buttons[ key ]?.text_color || '#ffffff',
				};
			} );
		}
		return initial;
	} );

	const handleChange = ( key, field, value ) => {
		if ( field === 'enabled' ) {
			setToggles( ( prev ) => ( { ...prev, [ key ]: value } ) );
		} else {
			setColors( ( prev ) => ( {
				...prev,
				[ key ]: { ...prev[ key ], [ field ]: value },
			} ) );
		}

		const formData = new FormData();
		formData.append( 'action', 'apppresser_social_share_toggle' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'field', field );

		if ( field === 'enabled' ) {
			formData.append( 'enabled', value ? '1' : '0' );
		} else {
			formData.append( 'value', value );
		}

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	if ( ! buttons ) {
		return null;
	}

	return (
		<Panel>
			<PanelBody
				title={ __( 'Social Share Buttons', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ Object.entries( buttons ).map( ( [ key, data ] ) => (
					<PanelRow key={ key }>
						<div style={ { width: '100%' } }>
							<ToggleControl
								label={ data.label }
								help={ data.help }
								checked={ toggles[ key ] || false }
								onChange={ ( value ) => handleChange( key, 'enabled', value ) }
							/>
							{ toggles[ key ] && colors[ key ] && (
								<div style={ { display: 'flex', gap: '12px', marginTop: '8px', alignItems: 'center' } }>
									<label style={ { display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px' } }>
										{ __( 'Background', 'apppresser-wp' ) }
										<input
											type="color"
											value={ colors[ key ].bg }
											onChange={ ( e ) => handleChange( key, 'bg', e.target.value ) }
											style={ { width: '32px', height: '32px', padding: '0', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer' } }
										/>
									</label>
									<label style={ { display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px' } }>
										{ __( 'Text', 'apppresser-wp' ) }
										<input
											type="color"
											value={ colors[ key ].text }
											onChange={ ( e ) => handleChange( key, 'text', e.target.value ) }
											style={ { width: '32px', height: '32px', padding: '0', border: '1px solid #ccc', borderRadius: '4px', cursor: 'pointer' } }
										/>
									</label>
								</div>
							) }
						</div>
					</PanelRow>
				) ) }
			</PanelBody>
		</Panel>
	);
};

export default SocialShareApp;
