/**
 * Security settings page.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, SelectControl, CheckboxControl, RadioControl, Button } from '@wordpress/components';

const { settings, xmlrpcModes, restApiModes, restRoutes, loginIdModes, ajaxUrl, nonce } = window.apppresserSecurity || {};

const SecurityApp = () => {
	const [ values, setValues ] = useState( () => ( { ...( settings || {} ) } ) );

	const saveSetting = ( key, value ) => {
		const previous = values[ key ];
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_security_save_setting' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append(
			'value',
			typeof value === 'boolean' ? ( value ? '1' : '0' ) : Array.isArray( value ) ? JSON.stringify( value ) : value
		);

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( ! data || ! data.success ) {
					setValues( ( prev ) => ( { ...prev, [ key ]: previous } ) );
				}
			} )
			.catch( () => {
				setValues( ( prev ) => ( { ...prev, [ key ]: previous } ) );
			} );
	};

	const blockedEndpoints = Array.isArray( values.rest_blocked_endpoints ) ? values.rest_blocked_endpoints : [];

	const isEndpointAllowed = ( route ) => ! blockedEndpoints.includes( route );

	const toggleEndpoint = ( route ) => {
		const next = blockedEndpoints.includes( route )
			? blockedEndpoints.filter( ( item ) => item !== route )
			: [ ...blockedEndpoints, route ];

		saveSetting( 'rest_blocked_endpoints', next );
	};

	const publicRoutes = ( restRoutes || [] ).filter( ( endpoint ) => endpoint.group === 'public' );
	const authenticatedRoutes = ( restRoutes || [] ).filter( ( endpoint ) => endpoint.group === 'authenticated' );

	const setGroupChecked = ( group, checked ) => {
		const groupRoutes = ( restRoutes || [] )
			.filter( ( endpoint ) => endpoint.group === group )
			.map( ( endpoint ) => endpoint.route );

		const next = checked
			? blockedEndpoints.filter( ( route ) => ! groupRoutes.includes( route ) )
			: [ ...new Set( [ ...blockedEndpoints, ...groupRoutes ] ) ];

		saveSetting( 'rest_blocked_endpoints', next );
	};

	const renderEndpoints = ( endpoints ) =>
		endpoints.map( ( endpoint ) => (
			<div key={ endpoint.route } style={ { marginBottom: '8px' } }>
				<CheckboxControl
					label={ endpoint.label }
					checked={ isEndpointAllowed( endpoint.route ) }
					onChange={ () => toggleEndpoint( endpoint.route ) }
				/>
			</div>
		) );

	if ( ! settings ) {
		return null;
	}

	return (
		<Panel>
			<PanelBody
				title={ __( 'XML-RPC', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<SelectControl
							label={ __( 'XML-RPC', 'apppresser-wp' ) }
							value={ values.xmlrpc_mode }
							options={ ( xmlrpcModes || [] ).map( ( mode ) => ( {
								label: mode.label,
								value: mode.value,
							} ) ) }
							onChange={ ( value ) => saveSetting( 'xmlrpc_mode', value ) }
							help={ __(
								'The WordPress XML-RPC API allows external services to access and modify content on the site. Common examples of services that use XML-RPC are the Jetpack plugin, the WordPress mobile apps, and pingbacks. If the site does not use a service that requires XML-RPC, select "Disable XML-RPC" as disabling XML-RPC prevents attackers from using the feature to attack the site.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<CheckboxControl
							label={ __( 'Allow Multiple Authentication Attempts per XML-RPC Request', 'apppresser-wp' ) }
							checked={ Boolean( values.xmlrpc_multiauth ) }
							onChange={ ( value ) => saveSetting( 'xmlrpc_multiauth', value ) }
							help={ __(
								'By default, the WordPress XML-RPC API allows hundreds of username and password guesses per request. Leave this unchecked to prevent attackers from exploiting this feature.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'REST API', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<RadioControl
							label={ __( 'REST API', 'apppresser-wp' ) }
							selected={ values.rest_api_access }
							options={ ( restApiModes || [] ).map( ( mode ) => ( {
								label: mode.label,
								value: mode.value,
							} ) ) }
							onChange={ ( value ) => saveSetting( 'rest_api_access', value ) }
							help={ __(
								'Default Access leaves the API as WordPress configures it. Restrict All Access turns the REST API off entirely. Allowed Endpoints lets you choose which endpoints are available using the list below: unchecking a public endpoint requires authentication to access it, while unchecking an authenticated endpoint disables it completely.',
								'apppresser-wp'
							) }
						/>
						<details style={ { marginTop: '8px' } }>
							<summary>{ __( 'Available REST Endpoints', 'apppresser-wp' ) }</summary>
							<div style={ { maxHeight: '400px', overflowY: 'auto', border: '1px solid #dcdcde', borderRadius: '2px', padding: '12px', marginTop: '8px' } }>
								<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '0 0 8px' } }>
									<h4 style={ { margin: '0' } }>{ __( 'Public', 'apppresser-wp' ) }</h4>
									<span style={ { fontSize: '11px' } }>
										<Button isLink style={ { marginRight: '8px' } } onClick={ () => setGroupChecked( 'public', true ) }>
											{ __( 'Check all', 'apppresser-wp' ) }
										</Button>
										<Button isLink onClick={ () => setGroupChecked( 'public', false ) }>
											{ __( 'Uncheck all', 'apppresser-wp' ) }
										</Button>
									</span>
								</div>
								{ renderEndpoints( publicRoutes ) }
								<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '16px 0 8px' } }>
									<h4 style={ { margin: '0' } }>{ __( 'Authenticated', 'apppresser-wp' ) }</h4>
									<span>
										<Button isLink style={ { marginRight: '8px' } } onClick={ () => setGroupChecked( 'authenticated', true ) }>
											{ __( 'Check all', 'apppresser-wp' ) }
										</Button>
										<Button isLink onClick={ () => setGroupChecked( 'authenticated', false ) }>
											{ __( 'Uncheck all', 'apppresser-wp' ) }
										</Button>
									</span>
								</div>
								{ renderEndpoints( authenticatedRoutes ) }
							</div>
						</details>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Users', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<SelectControl
							label={ __( 'Login with Email Address or Username', 'apppresser-wp' ) }
							value={ values.login_id_mode }
							options={ ( loginIdModes || [] ).map( ( mode ) => ( {
								label: mode.label,
								value: mode.value,
							} ) ) }
							onChange={ ( value ) => saveSetting( 'login_id_mode', value ) }
							help={ __(
								'By default, WordPress allows users to log in using either an email address or username. This setting allows you to restrict logins to only accept email addresses or usernames.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<CheckboxControl
							label={ __( 'Force Unique Nickname', 'apppresser-wp' ) }
							checked={ Boolean( values.force_unique_nickname ) }
							onChange={ ( value ) => saveSetting( 'force_unique_nickname', value ) }
							help={ __(
								'This forces users to choose a unique nickname when updating their profile or creating a new account which prevents bots and attackers from easily harvesting user\'s login usernames from the code on author pages. Note this does not automatically update existing users as it will affect author feed urls if used.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<CheckboxControl
							label={ __( 'Disable Extra User Archives', 'apppresser-wp' ) }
							checked={ Boolean( values.disable_extra_user_archives ) }
							onChange={ ( value ) => saveSetting( 'disable_extra_user_archives', value ) }
							help={ __(
								'Disables a user\'s author page if their post count is 0. This makes it harder for bots to determine usernames by disabling post archives for users that don\'t write content for your site.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'WordPress', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Version in Generator Tag', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_generator_tag ) }
						onChange={ ( value ) => saveSetting( 'disable_generator_tag', value ) }
						help={ __(
							'Disable the generator <meta> tag in <head>, which discloses the WordPress version number. Older version(s) might contain unpatched security loophole(s).',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Version in RSS Generator Tag', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_rss_generator ) }
						onChange={ ( value ) => saveSetting( 'disable_rss_generator', value ) }
						help={ __(
							'Disable the <generator> tag in RSS feed <channel>, which discloses the WordPress version number. Older version(s) might contain unpatched security loophole(s).',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Version in Resource URLs', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_resource_versions ) }
						onChange={ ( value ) => saveSetting( 'disable_resource_versions', value ) }
						help={ __(
							'Disable version number on static resource URLs referenced in <head>, which can disclose the WordPress version number. Older version(s) might contain unpatched security loophole(s). Applies to non-logged-in view of pages. This will also increase cacheability of static assets, but may have unintended consequences. Make sure you know what you are doing.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Shortlink', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_shortlink ) }
						onChange={ ( value ) => saveSetting( 'disable_shortlink', value ) }
						help={ __(
							'Disable the default WordPress shortlink <link> tag in <head>. Ignored by search engines and has minimal practical use case. Usually, a dedicated shortlink plugin or service is preferred that allows for nice names in the short links and tracking of clicks when sharing the link on social media.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Emojis', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_emojis ) }
						onChange={ ( value ) => saveSetting( 'disable_emojis', value ) }
						help={ __(
							'Disable emoji support for pages, posts and custom post types on the admin and frontend. The support is primarily useful for older browsers that do not have native support for it. Most modern browsers across different OSes and devices now have native support for it.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Windows Live Writer Manifest', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_wlw_manifest ) }
						onChange={ ( value ) => saveSetting( 'disable_wlw_manifest', value ) }
						help={ __(
							'Disable the Windows Live Writer (WLW) manifest <link> tag in <head>. The WLW app was discontinued in 2017.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Disable Really Simple Discovery', 'apppresser-wp' ) }
						checked={ Boolean( values.disable_rsd ) }
						onChange={ ( value ) => saveSetting( 'disable_rsd', value ) }
						help={ __(
							'Disable the Really Simple Discovery (RSD) <link> tag in <head>. It\'s not needed if your site is not using pingback or remote (XML-RPC) client to manage posts.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default SecurityApp;
