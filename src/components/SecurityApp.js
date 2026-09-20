/**
 * Security settings page.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, SelectControl, CheckboxControl, RadioControl, Button, TextControl, TextareaControl, Notice, Modal } from '@wordpress/components';

const { settings, xmlrpcModes, restApiModes, restRoutes, loginIdModes, ajaxUrl, nonce, gfActive, botBans, botBlockLog } = window.apppresserSecurity || {};

/**
 * A text/number/textarea field that only pushes its value up (and saves)
 * on blur, so free-typing doesn't fire a save request per keystroke.
 */
const DeferredField = ( { as: Field = TextControl, value, onCommit, ...props } ) => {
	const [ draft, setDraft ] = useState( value );

	return (
		<Field
			{ ...props }
			value={ draft }
			onChange={ setDraft }
			onBlur={ () => {
				if ( draft !== value ) {
					onCommit( draft );
				}
			} }
		/>
	);
};

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

	const [ bans, setBans ] = useState( () => botBans || [] );
	const [ blockLog, setBlockLog ] = useState( () => botBlockLog || [] );
	const [ payloadView, setPayloadView ] = useState( null );
	const [ manualBanIp, setManualBanIp ] = useState( '' );
	const [ manualBanMinutes, setManualBanMinutes ] = useState( '60' );
	const [ banError, setBanError ] = useState( '' );

	const linesToArray = ( text ) => text.split( /[\r\n]+/ ).map( ( line ) => line.trim() ).filter( Boolean );
	const csvToArray = ( text ) => text.split( ',' ).map( ( item ) => item.trim() ).filter( Boolean );

	const submitManualBan = () => {
		setBanError( '' );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_security_bot_manual_ban' );
		formData.append( 'nonce', nonce );
		formData.append( 'ip', manualBanIp );
		formData.append( 'minutes', manualBanMinutes );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( data && data.success ) {
					setBans( data.data.bans );
					setManualBanIp( '' );
				} else {
					setBanError( ( data && data.data && data.data.message ) || __( 'Please enter a valid IP address.', 'apppresser-wp' ) );
				}
			} );
	};

	const unbanIp = ( id ) => {
		const formData = new FormData();
		formData.append( 'action', 'apppresser_security_bot_unban' );
		formData.append( 'nonce', nonce );
		formData.append( 'id', id );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( data && data.success ) {
					setBans( data.data.bans );
				}
			} );
	};

	const clearBlockLog = () => {
		const formData = new FormData();
		formData.append( 'action', 'apppresser_security_clear_block_log' );
		formData.append( 'nonce', nonce );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( data && data.success ) {
					setBlockLog( [] );
				}
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
			{ payloadView && (
				<Modal
					title={ `${ __( 'Submitted Data', 'apppresser-wp' ) } — ${ payloadView.ip }` }
					onRequestClose={ () => setPayloadView( null ) }
				>
					<pre style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: '60vh', overflowY: 'auto', margin: 0, fontSize: '13px' } }>
						{ payloadView.payload }
					</pre>
				</Modal>
			) }
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
								'Default Access leaves the API as WordPress configures it. Restrict All Access turns the REST API off entirely. Allowed Endpoints lets you choose which endpoints are public using the list below: unchecked endpoints require authentication. Logged-in users always have access to all endpoints.',
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

			<PanelBody
				title={ __( 'Bot Block', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<CheckboxControl
						label={ __( 'Enable POST Rate Limiting', 'apppresser-wp' ) }
						checked={ Boolean( values.botblock_enabled ) }
						onChange={ ( value ) => saveSetting( 'botblock_enabled', value ) }
						help={ __(
							'Rate-limits and temporarily bans visitors who submit too many POST requests (e.g. forms) in a short window. Logged-in users are never rate limited.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%', display: 'flex', gap: '16px' } }>
						<DeferredField
							label={ __( 'Max POST Requests', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.botblock_max_requests ?? 3 ) }
							onCommit={ ( value ) => saveSetting( 'botblock_max_requests', Math.max( 1, parseInt( value, 10 ) || 3 ) ) }
						/>
						<DeferredField
							label={ __( 'Time Window (seconds)', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.botblock_window ?? 60 ) }
							onCommit={ ( value ) => saveSetting( 'botblock_window', Math.max( 1, parseInt( value, 10 ) || 60 ) ) }
						/>
						<DeferredField
							label={ __( 'Ban Length (minutes)', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.botblock_ban_length ?? 15 ) }
							onCommit={ ( value ) => saveSetting( 'botblock_ban_length', Math.max( 1, parseInt( value, 10 ) || 15 ) ) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<DeferredField
							as={ TextareaControl }
							label={ __( 'Whitelisted IPs', 'apppresser-wp' ) }
							value={ ( values.botblock_whitelist || [] ).join( '\n' ) }
							onCommit={ ( value ) => saveSetting( 'botblock_whitelist', linesToArray( value ) ) }
							help={ __( 'One IP address per line. These IPs are never rate limited.', 'apppresser-wp' ) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<DeferredField
							as={ TextareaControl }
							label={ __( 'Blocked IPs / Ranges', 'apppresser-wp' ) }
							value={ ( values.botblock_blocked_ip_ranges || [] ).join( '\n' ) }
							onCommit={ ( value ) => saveSetting( 'botblock_blocked_ip_ranges', linesToArray( value ) ) }
							help={ __(
								'One IP or CIDR range per line (e.g. 203.0.113.1 or 3.0.0.0/8). Requests from these are rejected outright and never expire.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>

				{ gfActive && (
					<>
						<PanelRow>
							<div style={ { width: '100%' } }>
								<h4>{ __( 'Gravity Forms', 'apppresser-wp' ) }</h4>
							</div>
						</PanelRow>
						<PanelRow>
							<CheckboxControl
								label={ __( 'Reject URLs/Links in Name and Text Fields', 'apppresser-wp' ) }
								checked={ Boolean( values.botblock_gf_url_block ) }
								onChange={ ( value ) => saveSetting( 'botblock_gf_url_block', value ) }
							/>
						</PanelRow>
						<PanelRow>
							<div style={ { width: '100%' } }>
								<CheckboxControl
									label={ __( 'Reject Submissions Filled Out Too Fast (Time Trap)', 'apppresser-wp' ) }
									checked={ Boolean( values.botblock_time_trap ) }
									onChange={ ( value ) => saveSetting( 'botblock_time_trap', value ) }
								/>
								<DeferredField
									label={ __( 'Minimum Seconds Between Form Load and Submit', 'apppresser-wp' ) }
									type="number"
									min="0"
									value={ String( values.botblock_time_trap_seconds ?? 2 ) }
									onCommit={ ( value ) => saveSetting( 'botblock_time_trap_seconds', Math.max( 0, parseInt( value, 10 ) || 0 ) ) }
								/>
							</div>
						</PanelRow>
						<PanelRow>
							<div style={ { width: '100%' } }>
								<DeferredField
									label={ __( 'Blocked Words', 'apppresser-wp' ) }
									value={ ( values.botblock_blocked_words || [] ).join( ', ' ) }
									onCommit={ ( value ) => saveSetting( 'botblock_blocked_words', csvToArray( value ) ) }
									help={ __( 'Comma-separated list. Submissions containing any of these words are rejected.', 'apppresser-wp' ) }
								/>
							</div>
						</PanelRow>
						<PanelRow>
							<div style={ { width: '100%' } }>
								<DeferredField
									label={ __( 'Blocked Email Domains', 'apppresser-wp' ) }
									value={ ( values.botblock_blocked_email_domains || [] ).join( ', ' ) }
									onCommit={ ( value ) => saveSetting( 'botblock_blocked_email_domains', csvToArray( value ) ) }
									help={ __( 'Comma-separated list (e.g. mailinator.com, tempmail.com). Rejects Email fields using these domains.', 'apppresser-wp' ) }
								/>
							</div>
						</PanelRow>
					</>
				) }

				<PanelRow>
					<div style={ { width: '100%' } }>
						<h4>{ __( 'Currently Banned IPs', 'apppresser-wp' ) }</h4>
						{ bans.length === 0 ? (
							<p>{ __( 'No active bans.', 'apppresser-wp' ) }</p>
						) : (
							<table className="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th>{ __( 'IP Address', 'apppresser-wp' ) }</th>
										<th>{ __( 'Reason', 'apppresser-wp' ) }</th>
										<th>{ __( 'Submitted Data', 'apppresser-wp' ) }</th>
										<th>{ __( 'Banned At', 'apppresser-wp' ) }</th>
										<th>{ __( 'Expires', 'apppresser-wp' ) }</th>
										<th>{ __( 'Action', 'apppresser-wp' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ bans.map( ( ban ) => (
										<tr key={ ban.id }>
											<td>{ ban.ip }</td>
											<td>{ ban.reason }</td>
											<td>
												{ ban.payload ? (
													<Button variant="secondary" size="small" onClick={ () => setPayloadView( ban ) }>
														{ __( 'View', 'apppresser-wp' ) }
													</Button>
												) : (
													'—'
												) }
											</td>
											<td>{ ban.created_at }</td>
											<td>{ ban.expires_at }</td>
											<td>
												<Button isLink onClick={ () => unbanIp( ban.id ) }>
													{ __( 'Unban', 'apppresser-wp' ) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<h4>{ __( 'Recent Blocked Requests', 'apppresser-wp' ) }</h4>
						<p className="description">
							{ __( 'Bans expire quickly and disappear from the table above. This log keeps the 50 most recent blocked requests so you can see what was blocked and why.', 'apppresser-wp' ) }
						</p>
						{ blockLog.length === 0 ? (
							<p>{ __( 'No blocked requests recorded.', 'apppresser-wp' ) }</p>
						) : (
							<>
								<table className="wp-list-table widefat fixed striped">
									<thead>
										<tr>
											<th>{ __( 'Time', 'apppresser-wp' ) }</th>
											<th>{ __( 'IP Address', 'apppresser-wp' ) }</th>
											<th>{ __( 'Reason', 'apppresser-wp' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ blockLog.map( ( entry, index ) => (
											<tr key={ index }>
												<td>{ entry.time }</td>
												<td>{ entry.ip }</td>
												<td>{ entry.reason }</td>
											</tr>
										) ) }
									</tbody>
								</table>
								<div style={ { marginTop: 8 } }>
									<Button isDestructive variant="secondary" onClick={ clearBlockLog }>
										{ __( 'Clear Log', 'apppresser-wp' ) }
									</Button>
								</div>
							</>
						) }
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<h4>{ __( 'Ban an IP', 'apppresser-wp' ) }</h4>
						{ banError && (
							<Notice status="error" isDismissible={ false }>
								{ banError }
							</Notice>
						) }
						<div style={ { display: 'flex', gap: '16px', alignItems: 'flex-end' } }>
							<TextControl
								label={ __( 'IP Address', 'apppresser-wp' ) }
								value={ manualBanIp }
								onChange={ setManualBanIp }
								placeholder="203.0.113.1"
							/>
							<TextControl
								label={ __( 'Duration (minutes)', 'apppresser-wp' ) }
								type="number"
								min="1"
								value={ manualBanMinutes }
								onChange={ setManualBanMinutes }
							/>
							<Button variant="secondary" onClick={ submitManualBan } disabled={ ! manualBanIp }>
								{ __( 'Ban IP', 'apppresser-wp' ) }
							</Button>
						</div>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Limit Login Attempts', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<DeferredField
							label={ __( 'Allowed Retries', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.limit_login_allowed_retries ?? 4 ) }
							onCommit={ ( value ) => saveSetting( 'limit_login_allowed_retries', Math.max( 1, parseInt( value, 10 ) || 4 ) ) }
							help={ __(
								'Number of failed login attempts allowed before the IP address is locked out.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<DeferredField
							label={ __( 'Lockout Time (minutes)', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.limit_login_lockout_minutes ?? 20 ) }
							onCommit={ ( value ) => saveSetting( 'limit_login_lockout_minutes', Math.max( 1, parseInt( value, 10 ) || 20 ) ) }
							help={ __(
								'How long an IP address is locked out after too many failed attempts.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%', display: 'flex', gap: '16px' } }>
						<DeferredField
							label={ __( 'Lockouts Before Increase', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.limit_login_allowed_lockouts ?? 4 ) }
							onCommit={ ( value ) => saveSetting( 'limit_login_allowed_lockouts', Math.max( 1, parseInt( value, 10 ) || 4 ) ) }
						/>
						<DeferredField
							label={ __( 'Increased Lockout Time (hours)', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.limit_login_long_lockout_hours ?? 24 ) }
							onCommit={ ( value ) => saveSetting( 'limit_login_long_lockout_hours', Math.max( 1, parseInt( value, 10 ) || 24 ) ) }
						/>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<p className="description">
							{ __(
								'After the specified number of lockouts, the lockout time increases to the number of hours above.',
								'apppresser-wp'
							) }
						</p>
					</div>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<DeferredField
							label={ __( 'Hours Until Retries Reset', 'apppresser-wp' ) }
							type="number"
							min="1"
							value={ String( values.limit_login_reset_hours ?? 12 ) }
							onCommit={ ( value ) => saveSetting( 'limit_login_reset_hours', Math.max( 1, parseInt( value, 10 ) || 12 ) ) }
							help={ __(
								'Time in hours before failed-attempt counters are reset.',
								'apppresser-wp'
							) }
						/>
					</div>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default SecurityApp;
