/**
 * Options settings page.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl } from '@wordpress/components';

const { settings, options, ajaxUrl, nonce } = window.apppresserOptions || {};

const OptionsApp = () => {
	const [ toggles, setToggles ] = useState( () => {
		const initial = {};
		if ( settings ) {
			Object.keys( settings ).forEach( ( key ) => {
				initial[ key ] = Boolean( settings[ key ] );
			} );
		}
		return initial;
	} );

	const handleToggle = ( key, value ) => {
		setToggles( ( prev ) => ( { ...prev, [ key ]: value } ) );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_toggle' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'enabled', value ? '1' : '0' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	if ( ! options ) {
		return null;
	}

	return (
		<Panel>
			<PanelBody
				title={ __( 'General Options', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ Object.entries( options ).map( ( [ key, data ] ) => (
					<PanelRow key={ key }>
						<ToggleControl
							label={ data.label }
							help={ data.help }
							checked={ toggles[ key ] || false }
							onChange={ ( value ) => handleToggle( key, value ) }
						/>
					</PanelRow>
				) ) }
			</PanelBody>
		</Panel>
	);
};

export default OptionsApp;
