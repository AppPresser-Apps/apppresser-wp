/**
 * Options settings page.
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl, ColorPalette, Button, TextControl, TextareaControl, SelectControl, BaseControl, Popover } from '@wordpress/components';

const { settings, options, ajaxUrl, nonce, bannerContent, bannerColor, bannerTextColor: initialBannerTextColor, smtpFields, smtpSettings, customCode, codeEditorSettings } = window.apppresserOptions || {};

const CUSTOM_CODE_SECTIONS = [
	{ key: 'header', label: __( 'Header', 'apppresser-wp' ), help: __( 'These scripts will be printed in the <head> section.', 'apppresser-wp' ) },
	{ key: 'body', label: __( 'Body', 'apppresser-wp' ), help: __( 'These scripts will be printed right after the opening <body> tag.', 'apppresser-wp' ) },
	{ key: 'footer', label: __( 'Footer', 'apppresser-wp' ), help: __( 'These scripts will be printed before the closing </body> tag.', 'apppresser-wp' ) },
];

const SMTP_REQUIRED_FIELDS = [ 'host', 'username', 'password' ];

/**
 * A code field that renders WordPress' bundled CodeMirror editor when
 * available, falling back to a plain textarea (e.g. when the current user
 * has disabled syntax highlighting in their profile).
 */
const CodeEditorField = ( { id, label, help, value, onChange, onBlur } ) => {
	const textareaRef = useRef( null );
	const onChangeRef = useRef( onChange );
	const onBlurRef = useRef( onBlur );
	onChangeRef.current = onChange;
	onBlurRef.current = onBlur;

	const canUseCodeMirror = Boolean( codeEditorSettings && window.wp && window.wp.codeEditor );

	useEffect( () => {
		if ( ! canUseCodeMirror || ! textareaRef.current ) {
			return undefined;
		}

		const instance = window.wp.codeEditor.initialize( textareaRef.current, codeEditorSettings );
		const cm = instance.codemirror;

		cm.on( 'change', () => onChangeRef.current( cm.getValue() ) );
		cm.on( 'blur', () => onBlurRef.current() );

		return () => {
			cm.toTextArea();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ canUseCodeMirror ] );

	if ( ! canUseCodeMirror ) {
		return (
			<TextareaControl
				label={ label }
				help={ help }
				value={ value }
				onChange={ onChange }
				onBlur={ onBlur }
				rows={ 8 }
				style={ { fontFamily: 'Menlo, Consolas, Monaco, monospace', fontSize: '13px' } }
			/>
		);
	}

	return (
		<BaseControl id={ id } label={ label } help={ help }>
			<textarea id={ id } ref={ textareaRef } defaultValue={ value } />
		</BaseControl>
	);
};

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

	const [ banner, setBanner ] = useState( bannerContent || '' );
	const [ bannerSaving, setBannerSaving ] = useState( false );
	const [ bannerBgColor, setBannerBgColor ] = useState( bannerColor || '#1e1e1e' );
	const [ bannerTextColor, setBannerTextColor ] = useState( initialBannerTextColor || '#ffffff' );
	const editorRef = useRef( null );

	const [ smtp, setSmtp ] = useState( () => ( { ...( smtpSettings || {} ) } ) );
	const [ smtpSavingField, setSmtpSavingField ] = useState( null );

	const [ customCodeValues, setCustomCodeValues ] = useState( () => ( { ...( customCode || {} ) } ) );
	const [ customCodeSavingField, setCustomCodeSavingField ] = useState( null );

	// Link popover state.
	const [ linkPopover, setLinkPopover ] = useState( null );
	const [ linkUrl, setLinkUrl ] = useState( '' );
	const savedRangeRef = useRef( null );

	const handleToggle = ( key, value ) => {
		const previous = toggles[ key ] || false;
		setToggles( ( prev ) => ( { ...prev, [ key ]: value } ) );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_toggle' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'enabled', value ? '1' : '0' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( ! data || ! data.success ) {
					setToggles( ( prev ) => ( { ...prev, [ key ]: previous } ) );
				}
			} )
			.catch( () => {
				setToggles( ( prev ) => ( { ...prev, [ key ]: previous } ) );
			} );
	};

	const handleBannerInput = useCallback( () => {
		if ( ! editorRef.current ) return;
		const html = editorRef.current.innerHTML;
		setBannerSaving( true );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_save_banner' );
		formData.append( 'nonce', nonce );
		formData.append( 'content', html );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} ).finally( () => {
			setBannerSaving( false );
		} );
	}, [ nonce, ajaxUrl ] );

	const handleBannerColor = ( color ) => {
		setBannerBgColor( color );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_save_banner_color' );
		formData.append( 'nonce', nonce );
		formData.append( 'color', color );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const handleBannerTextColor = ( color ) => {
		setBannerTextColor( color );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_save_banner_text_color' );
		formData.append( 'nonce', nonce );
		formData.append( 'color', color );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const handleSmtpChange = ( key, value ) => {
		setSmtp( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const saveSmtpField = ( key, value ) => {
		setSmtpSavingField( key );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_save_smtp' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'value', value || '' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( ( response ) => response.json() )
			.then( ( data ) => {
				if ( data && data.success && SMTP_REQUIRED_FIELDS.includes( key ) ) {
					setToggles( ( prev ) => ( { ...prev, smtp: Boolean( data.data.enabled ) } ) );
				}
			} )
			.finally( () => {
				setSmtpSavingField( null );
			} );
	};

	const handleSmtpBlur = ( key ) => {
		saveSmtpField( key, smtp[ key ] );
	};

	const handleSmtpSelectChange = ( key, value ) => {
		setSmtp( ( prev ) => ( { ...prev, [ key ]: value } ) );
		saveSmtpField( key, value );
	};

	const handleCustomCodeChange = ( key, value ) => {
		setCustomCodeValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const saveCustomCode = ( key ) => {
		setCustomCodeSavingField( key );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_options_save_custom_code' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'content', customCodeValues[ key ] || '' );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} ).finally( () => {
			setCustomCodeSavingField( null );
		} );
	};

	const openLinkPopover = () => {
		const sel = window.getSelection();
		if ( ! sel || sel.rangeCount === 0 || ! editorRef.current ) return;

		const range = sel.getRangeAt( 0 );
		if ( ! editorRef.current.contains( range.commonAncestorContainer ) ) return;

		// Save the selection so we can restore it when applying the link.
		savedRangeRef.current = range.cloneRange();

		// Check if selection is already inside a link.
		let node = range.commonAncestorContainer;
		while ( node && node !== editorRef.current ) {
			if ( node.nodeName === 'A' ) {
				setLinkUrl( node.href || '' );
				break;
			}
			node = node.parentNode;
		}

		setLinkPopover( true );
	};

	const applyLink = () => {
		if ( ! editorRef.current ) return;

		// Restore the saved selection.
		const sel = window.getSelection();
		if ( savedRangeRef.current ) {
			sel.removeAllRanges();
			sel.addRange( savedRangeRef.current );
		}

		if ( sel.rangeCount === 0 ) return;

		const range = sel.getRangeAt( 0 );
		if ( ! editorRef.current.contains( range.commonAncestorContainer ) ) return;

		// Remove existing link if any.
		let node = range.commonAncestorContainer;
		while ( node && node !== editorRef.current ) {
			if ( node.nodeName === 'A' ) {
				const parent = node.parentNode;
				while ( node.firstChild ) {
					parent.insertBefore( node.firstChild, node );
				}
				parent.removeChild( node );
				break;
			}
			node = node.parentNode;
		}

		if ( linkUrl.trim() ) {
			document.execCommand( 'createLink', false, linkUrl.trim() );
		}

		savedRangeRef.current = null;
		setLinkPopover( null );
		setLinkUrl( '' );
		setBanner( editorRef.current.innerHTML );
		handleBannerInput();
		editorRef.current.focus();
	};

	const removeLink = () => {
		if ( ! editorRef.current ) return;

		// Restore the saved selection.
		const sel = window.getSelection();
		if ( savedRangeRef.current ) {
			sel.removeAllRanges();
			sel.addRange( savedRangeRef.current );
		}

		if ( sel.rangeCount === 0 ) return;

		const range = sel.getRangeAt( 0 );
		let node = range.commonAncestorContainer;
		while ( node && node !== editorRef.current ) {
			if ( node.nodeName === 'A' ) {
				const parent = node.parentNode;
				while ( node.firstChild ) {
					parent.insertBefore( node.firstChild, node );
				}
				parent.removeChild( node );
				break;
			}
			node = node.parentNode;
		}

		savedRangeRef.current = null;
		setLinkPopover( null );
		setLinkUrl( '' );
		setBanner( editorRef.current.innerHTML );
		handleBannerInput();
		editorRef.current.focus();
	};

	if ( ! options ) {
		return null;
	}

	const smtpFilled = SMTP_REQUIRED_FIELDS.every( ( key ) => Boolean( smtp[ key ] && smtp[ key ].trim() ) );

	// Separate the header_banner and smtp options from the general toggles.
	const generalOptions = {};
	let bannerOption = null;
	let smtpOption = null;
	Object.entries( options ).forEach( ( [ key, data ] ) => {
		if ( key === 'header_banner' ) {
			bannerOption = { key, ...data };
		} else if ( key === 'smtp' ) {
			smtpOption = { key, ...data };
		} else {
			generalOptions[ key ] = data;
		}
	} );

	return (
		<Panel>
			<PanelBody
				title={ __( 'General Options', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ Object.entries( generalOptions ).map( ( [ key, data ] ) => (
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

			{ bannerOption && (
				<PanelBody
					title={ __( 'Notifications', 'apppresser-wp' ) }
					initialOpen={ false }
				>
					<PanelRow>
						<ToggleControl
							label={ bannerOption.label }
							help={ bannerOption.help }
							checked={ toggles[ bannerOption.key ] || false }
							onChange={ ( value ) => handleToggle( bannerOption.key, value ) }
						/>
					</PanelRow>
					<PanelRow>
						<div style={ { width: '100%' } }>
							<div style={ { marginBottom: '8px', display: 'flex', alignItems: 'center', gap: '8px' } }>
								<span style={ { fontWeight: 500 } }>
									{ __( 'Banner Content', 'apppresser-wp' ) }
								</span>
								<Button
									variant="secondary"
									size="small"
									icon="admin-links"
									onClick={ openLinkPopover }
								>
									{ __( 'Link', 'apppresser-wp' ) }
								</Button>
							</div>
							<div
								ref={ editorRef }
								contentEditable
								suppressContentEditableWarning
								dangerouslySetInnerHTML={ { __html: banner } }
								onBlur={ handleBannerInput }
								style={ {
									minHeight: '40px',
									padding: '8px 12px',
									border: '1px solid #949494',
									borderRadius: '2px',
									fontSize: '14px',
									lineHeight: '1.5',
									background: '#fff',
									cursor: 'text',
								} }
							/>
							{ bannerSaving && (
								<span style={ { fontSize: '12px', color: '#757575', marginTop: '4px', display: 'inline-block' } }>
									{ __( 'Saving…', 'apppresser-wp' ) }
								</span>
							) }
						</div>
					</PanelRow>
					<PanelRow>
						<div style={ { width: '100%', display: 'flex', gap: '24px' } }>
							<div style={ { flex: 1 } }>
								<p style={ { marginBottom: '8px', fontWeight: 500 } }>
									{ __( 'Background Color', 'apppresser-wp' ) }
								</p>
								<ColorPalette
									value={ bannerBgColor }
									onChange={ handleBannerColor }
									clearable={ false }
								/>
							</div>
							<div style={ { flex: 1 } }>
								<p style={ { marginBottom: '8px', fontWeight: 500 } }>
									{ __( 'Text Color', 'apppresser-wp' ) }
								</p>
								<ColorPalette
									value={ bannerTextColor }
									onChange={ handleBannerTextColor }
									clearable={ false }
								/>
							</div>
						</div>
					</PanelRow>
				</PanelBody>
			) }

			{ smtpFields && (
				<PanelBody
					title={ __( 'SMTP', 'apppresser-wp' ) }
					initialOpen={ false }
				>
					<PanelRow>
						<p style={ { margin: 0, color: '#757575' } }>
							{ __( 'Get these credentials from your SMTP provider (e.g. SendGrid, Mailgun, Postmark, Amazon SES, or your host\'s mail service). You\'ll typically find them in the provider\'s dashboard under API keys or SMTP settings.', 'apppresser-wp' ) }
						</p>
					</PanelRow>
					{ smtpOption && (
						<PanelRow>
							<ToggleControl
								label={ smtpOption.label }
								help={ smtpFilled ? smtpOption.help : __( 'Enter SMTP host, username, and password below to enable.', 'apppresser-wp' ) }
								checked={ smtpFilled && ( toggles[ smtpOption.key ] || false ) }
								disabled={ ! smtpFilled }
								onChange={ ( value ) => handleToggle( smtpOption.key, value ) }
							/>
						</PanelRow>
					) }
					{ Object.entries( smtpFields ).map( ( [ key, data ] ) => (
						<PanelRow key={ key }>
							<div style={ { width: '100%' } }>
								{ data.type === 'select' ? (
									<SelectControl
										label={ data.label }
										value={ smtp[ key ] || '' }
										options={ data.options || [] }
										onChange={ ( value ) => handleSmtpSelectChange( key, value ) }
									/>
								) : (
									<TextControl
										label={ data.label }
										type={ data.type === 'password' ? 'password' : 'text' }
										value={ smtp[ key ] || '' }
										onChange={ ( value ) => handleSmtpChange( key, value ) }
										onBlur={ () => handleSmtpBlur( key ) }
										autoComplete={ data.type === 'password' ? 'new-password' : 'off' }
									/>
								) }
								{ smtpSavingField === key && (
									<span style={ { fontSize: '12px', color: '#757575' } }>
										{ __( 'Saving…', 'apppresser-wp' ) }
									</span>
								) }
							</div>
						</PanelRow>
					) ) }
				</PanelBody>
			) }

			<PanelBody
				title={ __( 'Header & Footer Code', 'apppresser-wp' ) }
				initialOpen={ false }
			>
				<PanelRow>
					<p style={ { margin: 0, color: '#757575' } }>
						{ __( 'Enter scripts or HTML to add to each part of the site, on every page.', 'apppresser-wp' ) }
					</p>
				</PanelRow>
				{ CUSTOM_CODE_SECTIONS.map( ( section ) => (
					<PanelRow key={ section.key }>
						<div style={ { width: '100%' } }>
							<CodeEditorField
								id={ `apppresser-custom-code-${ section.key }` }
								label={ section.label }
								value={ customCodeValues[ section.key ] || '' }
								onChange={ ( value ) => handleCustomCodeChange( section.key, value ) }
								onBlur={ () => saveCustomCode( section.key ) }
								help={ section.help }
							/>
							{ customCodeSavingField === section.key && (
								<span style={ { fontSize: '12px', color: '#757575' } }>
									{ __( 'Saving…', 'apppresser-wp' ) }
								</span>
							) }
						</div>
					</PanelRow>
				) ) }
			</PanelBody>

			{ linkPopover && (
				<Popover
					position="bottom right"
					anchorRef={ editorRef }
					onClose={ () => { setLinkPopover( null ); setLinkUrl( '' ); } }
				>
					<div style={ { padding: '12px', minWidth: '260px' } }>
						<TextControl
							label={ __( 'URL', 'apppresser-wp' ) }
							value={ linkUrl }
							onChange={ setLinkUrl }
							placeholder="https://…"
						/>
						<div style={ { display: 'flex', gap: '8px', marginTop: '8px' } }>
							<Button variant="primary" onClick={ applyLink }>
								{ __( 'Apply', 'apppresser-wp' ) }
							</Button>
							<Button variant="tertiary" onClick={ removeLink }>
								{ __( 'Remove', 'apppresser-wp' ) }
							</Button>
						</div>
					</div>
				</Popover>
			) }
		</Panel>
	);
};

export default OptionsApp;
