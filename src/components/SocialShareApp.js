/**
 * Social Share settings page.
 */
import { useState, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Panel, PanelBody, PanelRow, ToggleControl } from '@wordpress/components';

const {
	settings,
	buttons,
	colors: initialColors,
	buttonOrder: initialOrder,
	ajaxUrl,
	nonce,
} = window.apppresserSocialShare || {};

const SocialShareApp = () => {
	const [ toggles, setToggles ] = useState( () => {
		const initial = {};
		if ( settings ) {
			Object.keys( settings ).forEach( ( key ) => {
					initial[ key ] = settings[ key ] === '1' || settings[ key ] === true;
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

	const [ buttonOrder, setButtonOrder ] = useState(
		() => initialOrder || ( buttons ? Object.keys( buttons ) : [] )
	);

	const buttonOrderRef = useRef( buttonOrder );
	buttonOrderRef.current = buttonOrder;

	const [ dragOverIndex, setDragOverIndex ] = useState( null );

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

	const saveOrder = ( newOrder ) => {
		setButtonOrder( newOrder );

		const formData = new FormData();
		formData.append( 'action', 'apppresser_social_share_order' );
		formData.append( 'nonce', nonce );
		formData.append( 'order', JSON.stringify( newOrder ) );

		fetch( ajaxUrl, {
			method: 'POST',
			body: formData,
		} );
	};

	const handleDragStart = useCallback( ( e, index ) => {
		e.dataTransfer.effectAllowed = 'move';
		e.dataTransfer.setData( 'text/plain', index.toString() );
		// Slight delay so the browser snapshots the element after opacity change
		requestAnimationFrame( () => {
			e.currentTarget.style.opacity = '0.4';
		} );
	}, [] );

	const handleDragEnd = useCallback( ( e ) => {
		e.currentTarget.style.opacity = '1';
		setDragOverIndex( null );
	}, [] );

	const handleDragOver = useCallback( ( e, index ) => {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
		setDragOverIndex( index );
	}, [] );

	const handleDragLeave = useCallback( () => {
		setDragOverIndex( null );
	}, [] );

	const handleDrop = useCallback( ( e, dropIndex ) => {
		e.preventDefault();
		setDragOverIndex( null );

		const dragIndex = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );

		if ( isNaN( dragIndex ) || dragIndex === dropIndex ) {
			return;
		}

		const newOrder = [ ...buttonOrderRef.current ];
		const [ movedItem ] = newOrder.splice( dragIndex, 1 );
		newOrder.splice( dropIndex, 0, movedItem );
		saveOrder( newOrder );
	}, [] );

	if ( ! buttons ) {
		return null;
	}

	return (
		<Panel>
			<PanelBody
				title={ __( 'Social Share Buttons', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ buttonOrder.map( ( key, index ) => {
					const data = buttons[ key ];
					if ( ! data ) {
						return null;
					}

					const isDragOver = dragOverIndex === index;

					return (
						<PanelRow key={ key }>
							<div
								style={ {
									width: '100%',
									display: 'flex',
									alignItems: 'flex-start',
									gap: '8px',
									padding: '4px',
									borderRadius: '4px',
									background: isDragOver ? '#f0f6fc' : 'transparent',
									borderTop: isDragOver ? '2px solid #007cba' : '2px solid transparent',
									transition: 'background 0.15s, border-color 0.15s',
								} }
								onDragOver={ ( e ) => handleDragOver( e, index ) }
								onDragLeave={ handleDragLeave }
								onDrop={ ( e ) => handleDrop( e, index ) }
							>
								<div style={ { flex: 1 } }>
									<ToggleControl
										label={ data.label }
										help={ data.help }
										checked={ toggles[ key ] || false }
										onChange={ ( value ) =>
											handleChange( key, 'enabled', value )
										}
									/>
									{ toggles[ key ] && colors[ key ] && (
										<div
											style={ {
												display: 'flex',
												gap: '12px',
												marginTop: '8px',
												alignItems: 'center',
											} }
										>
											<label
												style={ {
													display: 'flex',
													alignItems: 'center',
													gap: '6px',
													fontSize: '12px',
												} }
											>
												{ __( 'Background', 'apppresser-wp' ) }
												<input
													type="color"
													value={ colors[ key ].bg }
													onChange={ ( e ) =>
														handleChange( key, 'bg', e.target.value )
													}
													style={ {
														width: '32px',
														height: '32px',
														padding: '0',
														border: '1px solid #ccc',
														borderRadius: '4px',
														cursor: 'pointer',
													} }
												/>
											</label>
											<label
												style={ {
													display: 'flex',
													alignItems: 'center',
													gap: '6px',
													fontSize: '12px',
												} }
											>
												{ __( 'Text', 'apppresser-wp' ) }
												<input
													type="color"
													value={ colors[ key ].text }
													onChange={ ( e ) =>
														handleChange( key, 'text', e.target.value )
													}
													style={ {
														width: '32px',
														height: '32px',
														padding: '0',
														border: '1px solid #ccc',
														borderRadius: '4px',
														cursor: 'pointer',
													} }
												/>
											</label>
										</div>
									) }
								</div>
								<div
									draggable={ true }
									onDragStart={ ( e ) => handleDragStart( e, index ) }
									onDragEnd={ handleDragEnd }
									style={ {
										cursor: 'grab',
										padding: '4px 2px',
										marginTop: '4px',
										display: 'flex',
										flexDirection: 'column',
										gap: '3px',
										userSelect: 'none',
									} }
									title={ __( 'Drag to reorder', 'apppresser-wp' ) }
								>
									{ [ 0, 1, 2 ].map( ( i ) => (
										<div
											key={ i }
											style={ {
												display: 'flex',
												gap: '2px',
											} }
										>
											<div
												style={ {
													width: '4px',
													height: '4px',
													borderRadius: '50%',
													background: '#999',
												} }
											/>
											<div
												style={ {
													width: '4px',
													height: '4px',
													borderRadius: '50%',
													background: '#999',
												} }
											/>
										</div>
									) ) }
								</div>
							</div>
						</PanelRow>
					);
				} ) }
			</PanelBody>
		</Panel>
	);
};

export default SocialShareApp;
