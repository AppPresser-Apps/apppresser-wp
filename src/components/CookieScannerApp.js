/**
 * Cookie Scanner - React admin UI.
 */
import { useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	Notice,
	Flex,
	FlexItem,
} from '@wordpress/components';

const {
	ajaxUrl,
	scannerNonce,
	detectedCookies,
	scanAction,
	scanField,
	scanNonceField,
} = window.apppresserCookies || {};

const CATEGORY_OPTIONS = [
	{ value: 'essential', label: __( 'Essential', 'apppresser-wp' ) },
	{ value: 'analytics', label: __( 'Analytics', 'apppresser-wp' ) },
	{ value: 'marketing', label: __( 'Marketing', 'apppresser-wp' ) },
];

const COLUMNS = [
	{ key: 'name', label: __( 'Name', 'apppresser-wp' ), width: '18%' },
	{ key: 'domain', label: __( 'Domain', 'apppresser-wp' ), width: '15%' },
	{ key: 'duration', label: __( 'Duration', 'apppresser-wp' ), width: '10%' },
	{ key: 'category', label: __( 'Category', 'apppresser-wp' ), width: '10%' },
	{ key: 'description', label: __( 'Description', 'apppresser-wp' ), width: '30%' },
];

const CookieScannerApp = () => {
	const [ cookies, setCookies ] = useState(
		() => detectedCookies || []
	);
	const [ editingIndex, setEditingIndex ] = useState( null );
	const [ editValues, setEditValues ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const showNotice = useCallback( ( type, message ) => {
		setNotice( { type, message } );
		setTimeout( () => setNotice( null ), 4000 );
	}, [] );

	// ── Edit ──────────────────────────────────────────────────────────

	const startEdit = useCallback( ( index ) => {
		setEditingIndex( index );
		setEditValues( { ...cookies[ index ] } );
	}, [ cookies ] );

	const cancelEdit = useCallback( () => {
		setEditingIndex( null );
		setEditValues( {} );
	}, [] );

	const updateEditField = useCallback( ( field, value ) => {
		setEditValues( ( prev ) => ( { ...prev, [ field ]: value } ) );
	}, [] );

	// ── Save ─────────────────────────────────────────────────────────

	const saveCookie = useCallback(
		( index ) => {
			setSaving( true );

			const formData = new FormData();
			formData.append( 'action', 'apppresser_save_cookie' );
			formData.append( 'nonce', scannerNonce );
			formData.append( 'index', index );
			formData.append( 'cookie[name]', editValues.name || '' );
			formData.append( 'cookie[domain]', editValues.domain || '' );
			formData.append( 'cookie[duration]', editValues.duration || '' );
			formData.append( 'cookie[category]', editValues.category || 'essential' );
			formData.append(
				'cookie[description]',
				editValues.description || ''
			);

			fetch( ajaxUrl, { method: 'POST', body: formData } )
				.then( ( r ) => r.json() )
				.then( ( data ) => {
					if ( data.success ) {
						setCookies( ( prev ) => {
							const next = [ ...prev ];
							next[ index ] = data.data.cookie;
							return next;
						} );
						setEditingIndex( null );
						setEditValues( {} );
						showNotice( 'success', __( 'Cookie saved.', 'apppresser-wp' ) );
					} else {
						showNotice(
							'error',
							data.data?.message ||
								__( 'Error saving cookie.', 'apppresser-wp' )
						);
					}
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				)
				.finally( () => setSaving( false ) );
		},
		[ editValues, scannerNonce, ajaxUrl, showNotice ]
	);

	// ── Delete ───────────────────────────────────────────────────────

	const deleteCookie = useCallback(
		( index ) => {
			if (
				! window.confirm(
					__(
						'Are you sure you want to delete this cookie?',
						'apppresser-wp'
					)
				)
			) {
				return;
			}

			const formData = new FormData();
			formData.append( 'action', 'apppresser_delete_cookie' );
			formData.append( 'nonce', scannerNonce );
			formData.append( 'index', index );

			fetch( ajaxUrl, { method: 'POST', body: formData } )
				.then( ( r ) => r.json() )
				.then( ( data ) => {
					if ( data.success ) {
						setCookies( ( prev ) =>
							prev.filter( ( _, i ) => i !== index )
						);
						if ( editingIndex === index ) {
							setEditingIndex( null );
							setEditValues( {} );
						}
						showNotice(
							'success',
							__( 'Cookie deleted.', 'apppresser-wp' )
						);
					} else {
						showNotice(
							'error',
							data.data?.message ||
								__( 'Error deleting cookie.', 'apppresser-wp' )
						);
					}
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				);
		},
		[ scannerNonce, ajaxUrl, editingIndex, showNotice ]
	);

	// ── Add ──────────────────────────────────────────────────────────

	const addCookie = useCallback( () => {
		const formData = new FormData();
		formData.append( 'action', 'apppresser_add_cookie' );
		formData.append( 'nonce', scannerNonce );
		formData.append( 'cookie[name]', '' );
		formData.append( 'cookie[domain]', '' );
		formData.append( 'cookie[duration]', 'Session' );
		formData.append( 'cookie[category]', 'essential' );
		formData.append( 'cookie[description]', '' );

		fetch( ajaxUrl, { method: 'POST', body: formData } )
			.then( ( r ) => r.json() )
			.then( ( data ) => {
				if ( data.success ) {
					// Reload to get the new cookie in the list.
					window.location.reload();
				} else {
					showNotice(
						'error',
						data.data?.message ||
							__( 'Error adding cookie.', 'apppresser-wp' )
					);
				}
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			);
	}, [ scannerNonce, ajaxUrl, showNotice ] );

	// ── Render helpers ───────────────────────────────────────────────

	const renderDisplayCell = ( cookie, col ) => {
		if ( col.key === 'category' ) {
			return (
				cookie.category.charAt( 0 ).toUpperCase() +
				cookie.category.slice( 1 )
			);
		}
		return cookie[ col.key ] || '';
	};

	const renderEditCell = ( col ) => {
		const value = editValues[ col.key ] || '';

		if ( col.key === 'category' ) {
			return (
				<SelectControl
					value={ value }
					options={ CATEGORY_OPTIONS }
					onChange={ ( v ) => updateEditField( 'category', v ) }
					__nextHasNoMarginBottom
				/>
			);
		}

		if ( col.key === 'description' ) {
			return (
				<TextareaControl
					value={ value }
					onChange={ ( v ) =>
						updateEditField( 'description', v )
					}
					rows={ 2 }
					__nextHasNoMarginBottom
				/>
			);
		}

		return (
			<TextControl
				value={ value }
				onChange={ ( v ) => updateEditField( col.key, v ) }
				__nextHasNoMarginBottom
			/>
		);
	};

	const hasCookies = cookies.length > 0;
	const urlParams = new URLSearchParams( window.location.search );
	const wasScanned = urlParams.get( 'scanned' ) === '1';

	return (
		<Panel>
			<PanelBody
				title={ __( 'Cookie Scanner', 'apppresser-wp' ) }
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

				<p className="description">
					{ __(
						'Click the button below to scan your site for cookies. This will make a request to your homepage and detect cookies set via HTTP headers and common scripts.',
						'apppresser-wp'
					) }
				</p>

				<form method="post" action="">
					<input
						type="hidden"
						name={ scanNonceField }
						value={ scannerNonce }
					/>
					<input
						type="hidden"
						name={ scanField }
						value={ scanAction }
					/>
					<Button type="submit" variant="primary">
						{ __( 'Scan for Cookies', 'apppresser-wp' ) }
					</Button>
				</form>

				{ hasCookies && (
					<div style={ { marginTop: 20 } }>
						<h3>
							{ sprintf(
								/* translators: %d: number of cookies */
								__(
									'%d Cookie Detected',
									'%d Cookies Detected',
									cookies.length,
									'apppresser-wp'
								),
								cookies.length
							) }
						</h3>

						<p className="description">
							{ __(
								'Edit cookie details below. These will be displayed via the [cookie_policy] shortcode.',
								'apppresser-wp'
							) }
						</p>

						<table
							className="widefat striped apppresser-cookie-table"
							style={ { marginTop: 8 } }
						>
							<thead>
								<tr>
									{ COLUMNS.map( ( col ) => (
										<th
											key={ col.key }
											style={ {
												width: col.width,
											} }
										>
											{ col.label }
										</th>
									) ) }
									<th
										style={ {
											width: '17%',
											whiteSpace: 'nowrap',
										} }
									>
										{ __(
											'Actions',
											'apppresser-wp'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ cookies.map( ( cookie, index ) => {
									const isEditing =
										editingIndex === index;
									return (
										<tr
											key={ index }
											className={
												isEditing
													? 'is-editing'
													: ''
											}
										>
											{ COLUMNS.map(
												( col ) => (
													<td
														key={
															col.key
														}
														style={ {
															verticalAlign:
																'middle',
														} }
													>
														{ isEditing
															? renderEditCell(
																	col
															  )
															: renderDisplayCell(
																	cookie,
																	col
															  ) }
													</td>
												)
											) }
											<td
												style={ {
													verticalAlign:
														'middle',
													whiteSpace:
														'nowrap',
												} }
											>
												{ isEditing ? (
													<Flex gap={ 1 }>
														<FlexItem>
															<Button
																variant="primary"
																size="small"
																onClick={ () =>
																	saveCookie(
																		index
																	)
																}
																isBusy={
																	saving
																}
																disabled={
																	saving
																}
															>
																{ __(
																	'Save',
																	'apppresser-wp'
																) }
															</Button>
														</FlexItem>
														<FlexItem>
															<Button
																variant="tertiary"
																size="small"
																onClick={
																	cancelEdit
																}
																disabled={
																	saving
																}
															>
																{ __(
																	'Cancel',
																	'apppresser-wp'
																) }
															</Button>
														</FlexItem>
													</Flex>
												) : (
													<Flex gap={ 1 }>
														<FlexItem>
															<Button
																variant="secondary"
																size="small"
																onClick={ () =>
																	startEdit(
																		index
																	)
																}
															>
																{ __(
																	'Edit',
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
																	deleteCookie(
																		index
																	)
																}
															>
																{ __(
																	'Delete',
																	'apppresser-wp'
																) }
															</Button>
														</FlexItem>
													</Flex>
												) }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>

						<div style={ { marginTop: 12 } }>
							<Button
								variant="secondary"
								onClick={ addCookie }
							>
								{ __(
									'Add Cookie Manually',
									'apppresser-wp'
								) }
							</Button>
						</div>
					</div>
				) }

				{ ! hasCookies && wasScanned && (
					<>
						<Notice
							status="warning"
							isDismissible={ false }
							style={ { marginTop: 20 } }
						>
							{ __(
								'No cookies were detected. You can add them manually below.',
								'apppresser-wp'
							) }
						</Notice>
						<div style={ { marginTop: 12 } }>
							<Button
								variant="secondary"
								onClick={ addCookie }
							>
								{ __(
									'Add Cookie Manually',
									'apppresser-wp'
								) }
							</Button>
						</div>
					</>
				) }
			</PanelBody>
		</Panel>
	);
};

export default CookieScannerApp;
