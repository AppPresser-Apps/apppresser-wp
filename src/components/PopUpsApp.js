/**
 * Pop-ups settings page.
 */
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	SelectControl,
	Button,
	FormTokenField,
} from '@wordpress/components';

const { popUps: initialPopUps, pages = [], ajaxUrl, nonce } = window.apppresserPopUps || {};

const TRIGGER_OPTIONS = [
	{ value: 'page_load', label: __( 'Every page load', 'apppresser-wp' ) },
	{ value: 'on_page', label: __( 'On specific pages', 'apppresser-wp' ) },
];

const DISMISSAL_OPTIONS = [
	{ value: '12_hours', label: __( '12 hours', 'apppresser-wp' ) },
	{ value: 'daily', label: __( 'Daily', 'apppresser-wp' ) },
	{ value: 'weekly', label: __( 'Weekly', 'apppresser-wp' ) },
	{ value: 'monthly', label: __( 'Monthly', 'apppresser-wp' ) },
	{ value: 'every_page_load', label: __( 'Every page load', 'apppresser-wp' ) },
];

const createPopUp = () => ( {
	id: crypto.randomUUID ? crypto.randomUUID() : 'popup-' + Date.now(),
	enabled: true,
	content: '',
	trigger: 'page_load',
	dismissal: 'every_page_load',
	pages: [],
} );

/**
 * TinyMCE editor wrapper for a single pop-up.
 *
 * @param {Object}   props
 * @param {string}   props.popUpId  Unique pop-up ID.
 * @param {string}   props.content  Initial HTML content.
 * @param {Function} props.onChange Called with new HTML content.
 */
const PopUpEditor = ( { popUpId, content, onChange } ) => {
	const editorRef = useRef( null );
	const mediaFrameRef = useRef( null );
	const textareaId = 'apppresser-popup-content-' + popUpId;

	useEffect( () => {
		const editorApi = window.wp?.oldEditor || window.wp?.editor;

		if ( ! editorApi ) {
			return;
		}

		const settings = {
			tinymce: {
				toolbar1:
					'formatselect,|,bold,italic,underline,strikethrough,|,link,unlink,|,bullist,numlist,|,alignleft,aligncenter,alignright,alignjustify,|,forecolor,|,undo,redo',
				plugins: 'lists,link,textcolor,image,media,wpeditimage',
				menubar: false,
				statusbar: false,
				resize: false,
				height: 500,
				setup: ( editor ) => {
					editorRef.current = editor;
					editor.on( 'change', () => {
						onChange( editor.getContent() );
					} );
				},
			},
			quicktags: false,
			mediaButtons: false,
		};

		editorApi.initialize( textareaId, settings );

		return () => {
			if ( editorRef.current ) {
				editorApi.remove( textareaId );
				editorRef.current = null;
			}
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Sync external content changes into the editor.
	useEffect( () => {
		if ( editorRef.current && editorRef.current.getContent() !== content ) {
			editorRef.current.setContent( content || '' );
		}
	}, [ content ] );

	const openMediaLibrary = () => {
		if ( ! window.wp?.media ) {
			return;
		}

		// Reuse existing frame or create a new one.
		if ( ! mediaFrameRef.current ) {
			mediaFrameRef.current = window.wp.media( {
				title: 'Insert Media',
				button: { text: 'Insert into pop-up' },
				multiple: false,
			} );

			mediaFrameRef.current.on( 'select', () => {
				const attachment = mediaFrameRef.current
					.state()
					.get( 'selection' )
					.first()
					.toJSON();

				if ( editorRef.current && attachment.url ) {
					const img =
						'<img src="' +
						attachment.url +
						'" alt="' +
						( attachment.alt || '' ) +
						'" />';
					editorRef.current.insertContent( img );
					onChange( editorRef.current.getContent() );
				}
			} );
		}

		mediaFrameRef.current.open();
	};

	return (
		<div
			style={ {
				marginTop: 16,
				marginBottom: 16,
			} }
		>
			<label
				htmlFor={ textareaId }
				style={ {
					display: 'block',
					marginBottom: 8,
					fontWeight: 600,
					fontSize: 13,
				} }
			>
				{ __( 'Content', 'apppresser-wp' ) }
			</label>
			<Button
				variant="secondary"
				onClick={ openMediaLibrary }
				style={ { marginBottom: 8 } }
			>
				{ __( 'Add Media', 'apppresser-wp' ) }
			</Button>
			<textarea
				id={ textareaId }
				defaultValue={ content || '' }
				style={ { width: '100%' } }
			/>
		</div>
	);
};

const PopUpsApp = () => {
	const [ popUps, setPopUps ] = useState( () => {
		if ( initialPopUps && initialPopUps.length > 0 ) {
			return initialPopUps;
		}
		return [];
	} );
	const [ isSaving, setIsSaving ] = useState( false );

	const savePopUps = useCallback(
		async ( updatedPopUps ) => {
			setIsSaving( true );

			const formData = new FormData();
			formData.append( 'action', 'apppresser_pop_ups_save' );
			formData.append( 'nonce', nonce );
			formData.append( 'popUps', JSON.stringify( updatedPopUps ) );

			try {
				await fetch( ajaxUrl, {
					method: 'POST',
					body: formData,
				} );
			} catch ( error ) {
				// Silently fail — state is already updated optimistically.
			}

			setIsSaving( false );
		},
		[ ajaxUrl, nonce ]
	);

	const addPopUp = () => {
		setPopUps( ( prev ) => {
			const next = [ ...prev, createPopUp() ];
			savePopUps( next );
			return next;
		} );
	};

	const removePopUp = ( id ) => {
		setPopUps( ( prev ) => {
			const next = prev.filter( ( p ) => p.id !== id );
			savePopUps( next );
			return next;
		} );
	};

	const updatePopUp = ( id, field, value ) => {
		setPopUps( ( prev ) => {
			const next = prev.map( ( p ) =>
				p.id === id ? { ...p, [ field ]: value } : p
			);
			savePopUps( next );
			return next;
		} );
	};

	return (
		<>
			<Panel>
				{ popUps.length === 0 ? (
					<PanelBody
						title={ __( 'Pop Ups', 'apppresser-wp' ) }
						initialOpen={ true }
					>
						<p>
							{ __(
								'No pop-ups have been created yet. Click the button below to add one.',
								'apppresser-wp'
							) }
						</p>
					</PanelBody>
				) : (
					popUps.map( ( popUp, index ) => (
						<PanelBody
							key={ popUp.id }
							title={
								popUp.content
									? __( 'Pop Up', 'apppresser-wp' ) +
									  ' ' +
									  ( index + 1 )
									: __( 'Pop Up', 'apppresser-wp' ) +
									  ' ' +
									  ( index + 1 ) +
									  ' (' +
									  __( 'empty', 'apppresser-wp' ) +
									  ')'
							}
							initialOpen={ true }
						>
							<PanelRow>
								<ToggleControl
									label={ __(
										'Enable this pop-up',
										'apppresser-wp'
									) }
									checked={ popUp.enabled }
									onChange={ ( value ) =>
										updatePopUp(
											popUp.id,
											'enabled',
											value
										)
									}
								/>
							</PanelRow>

							<PanelRow>
								<SelectControl
									label={ __(
										'Trigger',
										'apppresser-wp'
									) }
									value={ popUp.trigger }
									options={ TRIGGER_OPTIONS }
									onChange={ ( value ) =>
										updatePopUp(
											popUp.id,
											'trigger',
											value
										)
									}
								/>
							</PanelRow>

							{ popUp.trigger === 'on_page' && (
								<PanelRow>
									<FormTokenField
										label={ __(
											'Pages',
											'apppresser-wp'
										) }
										help={ __(
											'Select the pages where this pop-up should appear.',
											'apppresser-wp'
										) }
										value={ (
											popUp.pages || []
										).map( ( id ) => {
											var page = pages.find(
												( p ) =>
													Number( p.value ) ===
													Number( id )
											);
											return page
												? page.label
												: String( id );
										} ) }
										suggestions={ pages.map(
											( p ) => p.label
										) }
										onChange={ ( tokens ) => {
											var ids = tokens
												.map( ( token ) => {
													var match = pages.find(
														( p ) =>
															p.label ===
															token
													);
													return match
														? Number(
																match.value
														  )
														: null;
												} )
												.filter( Boolean );
											updatePopUp(
												popUp.id,
												'pages',
												ids
											);
										} }
									/>
								</PanelRow>
							) }

							<PanelRow>
								<SelectControl
									label={ __(
										'Re-show after dismissal',
										'apppresser-wp'
									) }
									help={ __(
										'How long after a visitor closes this pop-up should it appear again?',
										'apppresser-wp'
									) }
									value={ popUp.dismissal || 'every_page_load' }
									options={ DISMISSAL_OPTIONS }
									onChange={ ( value ) =>
										updatePopUp(
											popUp.id,
											'dismissal',
											value
										)
									}
								/>
							</PanelRow>

							<PopUpEditor
								popUpId={ popUp.id }
								content={ popUp.content }
								onChange={ ( value ) =>
									updatePopUp(
										popUp.id,
										'content',
										value
									)
								}
							/>

							<Button
								isDestructive
								variant="secondary"
								onClick={ () => removePopUp( popUp.id ) }
							>
								{ __( 'Remove Pop Up', 'apppresser-wp' ) }
							</Button>
						</PanelBody>
					) )
				) }
			</Panel>

			<div style={ { marginTop: 16, textAlign: 'center' } }>
				<Button
					isPrimary
					onClick={ addPopUp }
					disabled={ isSaving }
				>
					{ __( 'Add Pop Up', 'apppresser-wp' ) }
				</Button>
			</div>
		</>
	);
};

export default PopUpsApp;
