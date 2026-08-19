/**
 * SEO settings page.
 */
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

const {
	ogFallbackImageId: initialImageId,
	ogFallbackImageUrl: initialImageUrl,
	fields,
	values: initialValues,
	ajaxUrl,
	nonce,
} = window.apppresserSeo || {};

const META_DESCRIPTION_LIMIT = 155;

const SeoApp = () => {
	const [ imageId, setImageId ] = useState( initialImageId || 0 );
	const [ imageUrl, setImageUrl ] = useState( initialImageUrl || '' );
	const [ saving, setSaving ] = useState( false );
	const [ values, setValues ] = useState( () => ( { ...( initialValues || {} ) } ) );
	const mediaFrameRef = useRef( null );

	const saveImage = ( id ) => {
		setSaving( true );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_seo_save_og_fallback_image' );
		formData.append( 'nonce', nonce );
		formData.append( 'imageId', id );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} )
			.then( ( response ) => response.json() )
			.then( ( result ) => {
				if ( result.success ) {
					setImageId( result.data.imageId );
					setImageUrl( result.data.imageUrl );
				}
			} )
			.finally( () => {
				setSaving( false );
			} );
	};

	const openMediaLibrary = () => {
		if ( ! window.wp?.media ) {
			return;
		}

		if ( ! mediaFrameRef.current ) {
			mediaFrameRef.current = window.wp.media( {
				title: __( 'Select Open Graph Fallback Image', 'apppresser-wp' ),
				button: { text: __( 'Use this image', 'apppresser-wp' ) },
				library: { type: 'image' },
				multiple: false,
			} );

			mediaFrameRef.current.on( 'select', () => {
				const attachment = mediaFrameRef.current
					.state()
					.get( 'selection' )
					.first()
					.toJSON();

				if ( attachment.id ) {
					saveImage( attachment.id );
				}
			} );
		}

		mediaFrameRef.current.open();
	};

	const removeImage = () => {
		saveImage( 0 );
	};

	const saveSetting = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_seo_save_setting' );
		formData.append( 'nonce', nonce );
		formData.append( 'key', key );
		formData.append( 'value', value );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const handleTextChange = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
	};

	const handleTextBlur = ( key ) => {
		saveSetting( key, values[ key ] ?? '' );
	};

	const handleToggleChange = ( key, value ) => {
		saveSetting( key, value );
	};

	const field = ( key ) => fields?.[ key ] || {};

	const descriptionLength = ( values.default_description ?? '' ).length;

	return (
		<Panel>
			<PanelBody
				title={ __( 'Open Graph', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<p style={ { marginTop: 0 } }>
							{ __(
								'Set a fallback image to use for social sharing when a post or page has no featured image.',
								'apppresser-wp'
							) }
						</p>

						<p
							style={ {
								marginTop: 0,
								color: '#757575',
								fontSize: '12px',
							} }
						>
							{ __(
								'Recommended size: 1200 × 630 px (1.91:1 aspect ratio).',
								'apppresser-wp'
							) }
						</p>

						{ imageUrl ? (
							<div
								style={ {
									display: 'flex',
									alignItems: 'center',
									gap: '16px',
									marginBottom: '12px',
								} }
							>
								<img
									src={ imageUrl }
									alt={ __( 'Open Graph fallback image', 'apppresser-wp' ) }
									style={ {
										width: '120px',
										height: 'auto',
										border: '1px solid #ddd',
										borderRadius: '4px',
									} }
								/>
								<div>
									<Button
										variant="secondary"
										onClick={ openMediaLibrary }
										disabled={ saving }
									>
										{ __( 'Replace image', 'apppresser-wp' ) }
									</Button>
									<Button
										variant="link"
										isDestructive
										onClick={ removeImage }
										disabled={ saving }
										style={ { marginLeft: '8px' } }
									>
										{ __( 'Remove', 'apppresser-wp' ) }
									</Button>
								</div>
							</div>
						) : (
							<Button
								variant="secondary"
								onClick={ openMediaLibrary }
								disabled={ saving }
							>
								{ __( 'Select image', 'apppresser-wp' ) }
							</Button>
						) }

						{ saving && (
							<span
								style={ {
									fontSize: '12px',
									color: '#757575',
									marginLeft: '8px',
								} }
							>
								{ __( 'Saving…', 'apppresser-wp' ) }
							</span>
						) }
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'General', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<TextControl
						label={ field( 'title_separator' ).label }
						help={ field( 'title_separator' ).help }
						value={ values.title_separator ?? '' }
						onChange={ ( value ) => handleTextChange( 'title_separator', value ) }
						onBlur={ () => handleTextBlur( 'title_separator' ) }
					/>
				</PanelRow>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<TextareaControl
							label={ field( 'default_description' ).label }
							help={ field( 'default_description' ).help }
							value={ values.default_description ?? '' }
							onChange={ ( value ) => handleTextChange( 'default_description', value ) }
							onBlur={ () => handleTextBlur( 'default_description' ) }
							rows={ 3 }
						/>
						<p
							style={ {
								marginTop: '4px',
								fontSize: '12px',
								color:
									descriptionLength > META_DESCRIPTION_LIMIT
										? '#d63638'
										: '#757575',
							} }
						>
							{ descriptionLength } / { META_DESCRIPTION_LIMIT }{ ' ' }
							{ __( 'characters', 'apppresser-wp' ) }
						</p>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Social Media', 'apppresser-wp' ) }
				initialOpen={ false }
			>
				<PanelRow>
					<TextControl
						label={ field( 'twitter_handle' ).label }
						help={ field( 'twitter_handle' ).help }
						value={ values.twitter_handle ?? '' }
						onChange={ ( value ) => handleTextChange( 'twitter_handle', value ) }
						onBlur={ () => handleTextBlur( 'twitter_handle' ) }
					/>
				</PanelRow>
				<PanelRow>
					<TextControl
						label={ field( 'facebook_app_id' ).label }
						help={ field( 'facebook_app_id' ).help }
						value={ values.facebook_app_id ?? '' }
						onChange={ ( value ) => handleTextChange( 'facebook_app_id', value ) }
						onBlur={ () => handleTextBlur( 'facebook_app_id' ) }
					/>
				</PanelRow>
				<PanelRow>
					<TextControl
						label={ field( 'facebook_admins' ).label }
						help={ field( 'facebook_admins' ).help }
						value={ values.facebook_admins ?? '' }
						onChange={ ( value ) => handleTextChange( 'facebook_admins', value ) }
						onBlur={ () => handleTextBlur( 'facebook_admins' ) }
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Robots', 'apppresser-wp' ) }
				initialOpen={ false }
			>
				<PanelRow>
					<ToggleControl
						label={ field( 'noindex_search' ).label }
						help={ field( 'noindex_search' ).help }
						checked={ Boolean( values.noindex_search ) }
						onChange={ ( value ) => handleToggleChange( 'noindex_search', value ) }
					/>
				</PanelRow>
				<PanelRow>
					<ToggleControl
						label={ field( 'noindex_404' ).label }
						help={ field( 'noindex_404' ).help }
						checked={ Boolean( values.noindex_404 ) }
						onChange={ ( value ) => handleToggleChange( 'noindex_404', value ) }
					/>
				</PanelRow>
				<PanelRow>
					<ToggleControl
						label={ field( 'noindex_archives' ).label }
						help={ field( 'noindex_archives' ).help }
						checked={ Boolean( values.noindex_archives ) }
						onChange={ ( value ) => handleToggleChange( 'noindex_archives', value ) }
					/>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default SeoApp;
