/**
 * Redirects settings page.
 */
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	Button,
	Notice,
	Flex,
	FlexItem,
	TextControl,
	SelectControl,
} from '@wordpress/components';

const { ajaxUrl, nonce, redirects, notFound } = window.apppresserRedirects || {};

const STATUS_OPTIONS = [
	{ value: '301', label: '301 — Moved Permanently' },
	{ value: '302', label: '302 — Found' },
	{ value: '303', label: '303 — See Other' },
	{ value: '307', label: '307 — Temporary Redirect' },
	{ value: '308', label: '308 — Permanent Redirect' },
];

const RedirectsApp = () => {
	const [ items, setItems ] = useState( () => redirects || [] );
	const [ notFoundItems, setNotFoundItems ] = useState( () => notFound || [] );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ status, setStatus ] = useState( '301' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const showNotice = useCallback( ( type, message ) => {
		setNotice( { type, message } );
		setTimeout( () => setNotice( null ), 4000 );
	}, [] );

	const post = useCallback(
		( action, body = {} ) => {
			const formData = new FormData();
			formData.append( 'action', action );
			formData.append( 'nonce', nonce );
			Object.entries( body ).forEach( ( [ key, value ] ) => {
				formData.append( key, value );
			} );

			return fetch( ajaxUrl, { method: 'POST', body: formData } ).then(
				( response ) => response.json()
			);
		},
		[ ajaxUrl, nonce ]
	);

	const applyResult = useCallback( ( data ) => {
		if ( data && data.success ) {
			setItems( data.data.redirects || [] );
		}
	}, [] );

	const applyNotFoundResult = useCallback( ( data ) => {
		if ( data && data.success ) {
			setNotFoundItems( data.data.notFound || [] );
		}
	}, [] );

	const addRedirect = useCallback( () => {
		if ( ! from || ! to ) {
			showNotice(
				'error',
				__( 'Please enter both a source and destination URL.', 'apppresser-wp' )
			);
			return;
		}

		setBusy( true );
		post( 'apppresser_redirects_add', { from, to, status } )
			.then( ( data ) => {
				if ( data && data.success ) {
					applyResult( data );
					setFrom( '' );
					setTo( '' );
					setStatus( '301' );
					showNotice( 'success', __( 'Redirect added.', 'apppresser-wp' ) );
				} else {
					showNotice(
						'error',
						( data && data.data && data.data.message ) ||
							__( 'Could not add redirect.', 'apppresser-wp' )
					);
				}
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			)
			.finally( () => setBusy( false ) );
	}, [ from, to, status, post, applyResult, showNotice ] );

	const deleteRedirect = useCallback(
		( source ) => {
			post( 'apppresser_redirects_delete', { from: source } )
				.then( ( data ) => {
					applyResult( data );
					showNotice( 'success', __( 'Redirect removed.', 'apppresser-wp' ) );
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				);
		},
		[ post, applyResult, showNotice ]
	);

	const addFrom404 = useCallback(
		( url ) => {
			setFrom( url );
			setTo( '' );
			showNotice(
				'info',
				__( 'Enter a destination URL and click Add Redirect.', 'apppresser-wp' )
			);
		},
		[ showNotice ]
	);

	const delete404 = useCallback(
		( id ) => {
			post( 'apppresser_redirects_delete_404', { id } )
				.then( ( data ) => {
					applyNotFoundResult( data );
					showNotice( 'success', __( '404 entry removed.', 'apppresser-wp' ) );
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				);
		},
		[ post, applyNotFoundResult, showNotice ]
	);

	const clear404s = useCallback( () => {
		if (
			! window.confirm(
				__(
					'Are you sure you want to clear all 404 entries? This cannot be undone.',
					'apppresser-wp'
				)
			)
		) {
			return;
		}

		post( 'apppresser_redirects_clear_404s' )
			.then( ( data ) => {
				applyNotFoundResult( data );
				showNotice( 'success', __( '404 log cleared.', 'apppresser-wp' ) );
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			);
	}, [ post, applyNotFoundResult, showNotice ] );

	return (
		<Panel>
			<PanelBody
				title={ __( 'URL Redirects', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				{ notice && (
					<Notice
						status={ notice.type }
						isDismissible
						onRemove={ () => setNotice( null ) }
						style={ { marginBottom: 16 } }
					>
						{ notice.message }
					</Notice>
				) }

				<PanelRow>
					<div style={ { width: '100%' } }>
						<p className="description">
							{ __(
								'Redirect one URL to another. When a visitor requests the source path, they are sent to the destination URL.',
								'apppresser-wp'
							) }
						</p>

						<Flex align="flex-end" gap={ 2 } style={ { marginBottom: 16 } }>
							<FlexItem style={ { flex: 1 } }>
								<TextControl
									label={ __( 'From', 'apppresser-wp' ) }
									value={ from }
									onChange={ setFrom }
									placeholder="/old-page"
									help={ __(
										'The source path, e.g. /old-page.',
										'apppresser-wp'
									) }
								/>
							</FlexItem>
							<FlexItem style={ { flex: 1 } }>
								<TextControl
									label={ __( 'To', 'apppresser-wp' ) }
									value={ to }
									onChange={ setTo }
									placeholder="https://example.com/new-page"
									help={ __(
										'The destination URL.',
										'apppresser-wp'
									) }
								/>
							</FlexItem>
							<FlexItem style={ { flex: '0 0 220px' } }>
								<SelectControl
									label={ __( 'Status', 'apppresser-wp' ) }
									value={ status }
									options={ STATUS_OPTIONS }
                  onChange={setStatus}
                  help={ __(
										'Type of redirect.',
										'apppresser-wp'
									) }
								/>
							</FlexItem>
						</Flex>

						<Button
							variant="primary"
							onClick={ addRedirect }
							isBusy={ busy }
							disabled={ busy }
							style={ { marginBottom: 16 } }
						>
							{ __( 'Add Redirect', 'apppresser-wp' ) }
						</Button>

						{ items.length === 0 ? (
							<p>{ __( 'No redirects configured yet.', 'apppresser-wp' ) }</p>
						) : (
							<table className="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th>{ __( 'From', 'apppresser-wp' ) }</th>
										<th>{ __( 'To', 'apppresser-wp' ) }</th>
										<th style={ { width: '10%' } }>
											{ __( 'Status', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '12%' } }>
											{ __( 'Actions', 'apppresser-wp' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ items.map( ( item ) => (
										<tr key={ item.from }>
											<td>{ item.from }</td>
											<td>{ item.to }</td>
											<td>{ item.status || 301 }</td>
											<td>
												<Button
													variant="link"
													size="small"
													isDestructive
													onClick={ () =>
														deleteRedirect( item.from )
													}
												>
													{ __( 'Remove', 'apppresser-wp' ) }
												</Button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( '404 Hits', 'apppresser-wp' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%' } }>
						<Flex
							justify="space-between"
							align="center"
							style={ { marginBottom: 10 } }
						>
							<FlexItem>
								<p className="description">
									{ __(
										'Pages that returned a 404. Turn them into redirects or remove them.',
										'apppresser-wp'
									) }
								</p>
							</FlexItem>
							<FlexItem>
								<Button
									variant="secondary"
									isDestructive
									onClick={ clear404s }
									disabled={ notFoundItems.length === 0 }
								>
									{ __( 'Clear All', 'apppresser-wp' ) }
								</Button>
							</FlexItem>
						</Flex>

						{ notFoundItems.length === 0 ? (
							<p>{ __( 'No 404s recorded yet.', 'apppresser-wp' ) }</p>
						) : (
							<table className="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th>{ __( 'URL', 'apppresser-wp' ) }</th>
										<th style={ { width: '14%' } }>
											{ __( 'IP Address', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '10%' } }>
											{ __( 'Hits', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '18%' } }>
											{ __( 'Last Seen', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '20%' } }>
											{ __( 'Actions', 'apppresser-wp' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ notFoundItems.map( ( item ) => (
										<tr key={ item.id }>
											<td>{ item.url }</td>
											<td>{ item.ip || '—' }</td>
											<td>{ item.hits }</td>
											<td>{ item.last_seen }</td>
											<td>
												<Flex gap={ 2 }>
													<FlexItem>
														<Button
															variant="link"
															size="small"
															onClick={ () =>
																addFrom404( item.url )
															}
														>
															{ __(
																'Add Redirect',
																'apppresser-wp'
															) }
														</Button>
													</FlexItem>
													<FlexItem>
														<Button
															variant="link"
															size="small"
															isDestructive
															onClick={ () =>
																delete404( item.id )
															}
														>
															{ __( 'Delete', 'apppresser-wp' ) }
														</Button>
													</FlexItem>
												</Flex>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) }
					</div>
				</PanelRow>
			</PanelBody>
		</Panel>
	);
};

export default RedirectsApp;
