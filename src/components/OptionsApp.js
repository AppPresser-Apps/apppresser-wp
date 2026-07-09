/**
 * Options settings page.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl, ColorPalette, Button, TextControl, Popover } from '@wordpress/components';

const { settings, options, ajaxUrl, nonce, bannerContent, bannerColor, bannerTextColor: initialBannerTextColor } = window.apppresserOptions || {};

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

	// Link popover state.
	const [ linkPopover, setLinkPopover ] = useState( null );
	const [ linkUrl, setLinkUrl ] = useState( '' );
	const savedRangeRef = useRef( null );

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

	// Separate the header_banner option from the general toggles.
	const generalOptions = {};
	let bannerOption = null;
	Object.entries( options ).forEach( ( [ key, data ] ) => {
		if ( key === 'header_banner' ) {
			bannerOption = { key, ...data };
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
