/**
 * Maintenance mode settings page.
 */
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

const { enabled, title, content, ajaxUrl, nonce } = window.apppresserMaintenance || {};

const MaintenanceApp = () => {
	const [ isEnabled, setIsEnabled ] = useState( () => Boolean( enabled ) );
	const [ pageTitle, setPageTitle ] = useState( title || '' );
	const [ pageContent, setPageContent ] = useState( content || '' );

	const handleToggle = ( value ) => {
		setIsEnabled( value );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_maintenance_toggle' );
		formData.append( 'nonce', nonce );
		formData.append( 'enabled', value ? '1' : '0' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const saveSettings = useCallback(
		( newTitle, newContent ) => {
			const formData = new FormData();
			formData.append( 'action', 'apppresser_maintenance_save_settings' );
			formData.append( 'nonce', nonce );
			formData.append( 'title', newTitle );
			formData.append( 'content', newContent );

			fetch( ajaxUrl, {
				method: 'POST',
				body: formData,
			} );
		},
		[ ajaxUrl, nonce ]
	);

	const handleTitleBlur = () => {
		saveSettings( pageTitle, pageContent );
	};

	const handleContentBlur = () => {
		saveSettings( pageTitle, pageContent );
	};

	return (
		<Panel>
			<PanelBody
				title={ __( 'Maintenance Mode', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<ToggleControl
						label={ __( 'Enable Maintenance Mode', 'apppresser-wp' ) }
						help={ __(
							'When enabled, visitors who are not logged in will see a "Coming Soon" page. Logged-in users and the WordPress login page remain accessible.',
							'apppresser-wp'
						) }
						checked={ isEnabled }
						onChange={ handleToggle }
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Page Content', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<TextControl
						label={ __( 'Title', 'apppresser-wp' ) }
						help={ __(
							'The heading displayed on the maintenance page.',
							'apppresser-wp'
						) }
						value={ pageTitle }
						onChange={ setPageTitle }
						onBlur={ handleTitleBlur }
					/>
				</PanelRow>
				<PanelRow>
					<TextareaControl
						label={ __( 'Content', 'apppresser-wp' ) }
						help={ __(
							'The message displayed below the title on the maintenance page.',
							'apppresser-wp'
						) }
						value={ pageContent }
						onChange={ setPageContent }
						onBlur={ handleContentBlur }
					/>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default MaintenanceApp;
