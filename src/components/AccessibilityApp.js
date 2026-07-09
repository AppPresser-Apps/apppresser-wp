/**
 * Accessibility settings page.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl } from '@wordpress/components';

const { settings, options, ajaxUrl, nonce } = window.apppresserAccessibility || {};

const AccessibilityApp = () => {
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
		formData.append( 'action', 'apppresser_accessibility_toggle' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'enabled', value ? '1' : '0' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const renderToggles = ( panel ) => {
		if ( ! options ) {
			return null;
		}

		return Object.entries( options )
			.filter( ( [ , data ] ) => data.panel === panel )
			.map( ( [ key, data ] ) => (
				<PanelRow key={ key }>
					<ToggleControl
						label={ data.label }
						help={ data.help }
						checked={ toggles[ key ] || false }
						onChange={ ( value ) => handleToggle( key, value ) }
					/>
				</PanelRow>
			) );
	};

	return (
		<Panel>
			<PanelBody
				title={ __( 'Content Modules', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ renderToggles( 'content' ) }
			</PanelBody>

			<PanelBody
				title={ __( 'Color Modules', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ renderToggles( 'color' ) }
			</PanelBody>
		</Panel>
	);
};

export default AccessibilityApp;
