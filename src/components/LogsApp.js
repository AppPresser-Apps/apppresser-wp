/**
 * Logs settings page.
 */
import { useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Panel,
	PanelBody,
	PanelRow,
	ToggleControl,
	Button,
	Notice,
	Flex,
	FlexItem,
} from '@wordpress/components';

const { ajaxUrl, nonce, emailLog, lockoutLog } = window.apppresserLogs || {};

const LogsApp = () => {
	const [ enabled, setEnabled ] = useState( () => Boolean( emailLog?.enabled ) );
	const [ entries, setEntries ] = useState( () => emailLog?.entries || [] );
	const [ total, setTotal ] = useState( () => emailLog?.total || 0 );
	const [ lockoutEntries, setLockoutEntries ] = useState( () => lockoutLog?.entries || [] );
	const [ lockoutTotal, setLockoutTotal ] = useState( () => lockoutLog?.total || 0 );
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
			setEntries( data.data.entries || [] );
			setTotal( data.data.total || 0 );
		}
	}, [] );

	const toggleEnabled = useCallback(
		( value ) => {
			setEnabled( value );
			post( 'apppresser_logs_toggle', { enabled: value ? '1' : '0' } )
				.then( ( data ) => {
					if ( data && data.success ) {
						setEnabled( data.data.enabled );
					}
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				);
		},
		[ post, showNotice ]
	);

	const refresh = useCallback( () => {
		setBusy( true );
		post( 'apppresser_logs_get_emails' )
			.then( ( data ) => {
				applyResult( data );
				showNotice( 'success', __( 'Log refreshed.', 'apppresser-wp' ) );
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			)
			.finally( () => setBusy( false ) );
	}, [ post, applyResult, showNotice ] );

	const clearLog = useCallback( () => {
		if (
			! window.confirm(
				__(
					'Are you sure you want to clear the entire email log? This cannot be undone.',
					'apppresser-wp'
				)
			)
		) {
			return;
		}

		setBusy( true );
		post( 'apppresser_logs_clear_emails' )
			.then( ( data ) => {
				applyResult( data );
				showNotice( 'success', __( 'Email log cleared.', 'apppresser-wp' ) );
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			)
			.finally( () => setBusy( false ) );
	}, [ post, applyResult, showNotice ] );

	const deleteEntry = useCallback(
		( id ) => {
			if (
				! window.confirm(
					__(
						'Are you sure you want to delete this log entry?',
						'apppresser-wp'
					)
				)
			) {
				return;
			}

			post( 'apppresser_logs_delete_email', { id } )
				.then( ( data ) => {
					applyResult( data );
					showNotice( 'success', __( 'Log entry deleted.', 'apppresser-wp' ) );
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

	const sendTestEmail = useCallback( () => {
		setBusy( true );
		post( 'apppresser_logs_send_test_email' )
			.then( ( data ) => {
				if ( data && data.success ) {
					applyResult( data );
					showNotice(
						'success',
						data.data.message ||
							__( 'Test email sent.', 'apppresser-wp' )
					);
				} else {
					showNotice(
						'error',
						( data && data.data && data.data.message ) ||
							__(
								'The test email could not be sent.',
								'apppresser-wp'
							)
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
	}, [ post, applyResult, showNotice ] );

	const applyLockoutResult = useCallback( ( data ) => {
		if ( data && data.success ) {
			setLockoutEntries( data.data.entries || [] );
			setLockoutTotal( data.data.total || 0 );
		}
	}, [] );

	const refreshLockouts = useCallback( () => {
		setBusy( true );
		post( 'apppresser_logs_get_lockouts' )
			.then( ( data ) => {
				applyLockoutResult( data );
				showNotice( 'success', __( 'Lockout log refreshed.', 'apppresser-wp' ) );
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			)
			.finally( () => setBusy( false ) );
	}, [ post, applyLockoutResult, showNotice ] );

	const clearLockouts = useCallback( () => {
		if (
			! window.confirm(
				__(
					'Are you sure you want to clear the entire lockout log? This cannot be undone.',
					'apppresser-wp'
				)
			)
		) {
			return;
		}

		setBusy( true );
		post( 'apppresser_logs_clear_lockouts' )
			.then( ( data ) => {
				applyLockoutResult( data );
				showNotice( 'success', __( 'Lockout log cleared.', 'apppresser-wp' ) );
			} )
			.catch( () =>
				showNotice(
					'error',
					__( 'Network error. Please try again.', 'apppresser-wp' )
				)
			)
			.finally( () => setBusy( false ) );
	}, [ post, applyLockoutResult, showNotice ] );

	const unlockLockout = useCallback(
		( entry ) => {
			post( 'apppresser_logs_unlock_lockout', { id: entry.id, ip: entry.ip } )
				.then( ( data ) => {
					applyLockoutResult( data );
					showNotice( 'success', __( 'Lockout removed.', 'apppresser-wp' ) );
				} )
				.catch( () =>
					showNotice(
						'error',
						__( 'Network error. Please try again.', 'apppresser-wp' )
					)
				);
		},
		[ post, applyLockoutResult, showNotice ]
	);

	return (
		<Panel>
			<PanelBody
				title={ __( 'Outgoing Emails', 'apppresser-wp' ) }
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
					<ToggleControl
						label={ __( 'Log outgoing emails', 'apppresser-wp' ) }
						checked={ enabled }
						onChange={ toggleEnabled }
						help={ __(
							'Record every email sent through wp_mail() so it can be reviewed here.',
							'apppresser-wp'
						) }
					/>
				</PanelRow>

				<PanelRow>
					<div style={ { width: '100%'} }>
						<Flex justify="space-between" align="center" style={ { marginBottom: '10px'} }>
							<FlexItem>
								<p className="description">
									{ sprintf(
										/* translators: %d: number of logged emails */
										__(
											'%d email logged',
											'%d emails logged',
											total,
											'apppresser-wp'
										),
										total
									) }
								</p>
							</FlexItem>
							<FlexItem>
								<Flex gap={ 2 }>
									<FlexItem>
										<Button
											variant="secondary"
											onClick={ refresh }
											isBusy={ busy }
											disabled={ busy }
										>
											{ __( 'Refresh', 'apppresser-wp' ) }
										</Button>
									</FlexItem>
									<FlexItem>
										<Button
											variant="primary"
											onClick={ sendTestEmail }
											isBusy={ busy }
											disabled={ busy }
										>
											{ __( 'Send Test Email', 'apppresser-wp' ) }
										</Button>
									</FlexItem>
									<FlexItem>
										<Button
											variant="secondary"
											isDestructive
											onClick={ clearLog }
											disabled={ busy || entries.length === 0 }
										>
											{ __( 'Clear Log', 'apppresser-wp' ) }
										</Button>
									</FlexItem>
								</Flex>
							</FlexItem>
						</Flex>

						{ entries.length === 0 ? (
							<p>
								{ __(
									'No emails have been logged yet.',
									'apppresser-wp'
								) }
							</p>
						) : (
							<table className="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th style={ { width: '18%' } }>
											{ __( 'Date', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '22%' } }>
											{ __( 'To', 'apppresser-wp' ) }
										</th>
										<th>
											{ __( 'Subject', 'apppresser-wp' ) }
										</th>
										<th>
											{ __( 'Message', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '10%' } }>
											{ __( 'Status', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '16%' } }>
											{ __( 'Actions', 'apppresser-wp' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ entries.map( ( entry ) => (
										<FragmentRow
											key={ entry.id }
											entry={ entry }
											onDelete={ () =>
												deleteEntry( entry.id )
											}
										/>
									) ) }
								</tbody>
							</table>
						) }
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Login Lockouts', 'apppresser-wp' ) }
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
						<Flex justify="space-between" align="center" style={ { marginBottom: '10px' } }>
							<FlexItem>
								<p className="description">
									{ sprintf(
										/* translators: %d: number of lockout log entries */
										__(
											'%d lockout logged',
											'%d lockouts logged',
											lockoutTotal,
											'apppresser-wp'
										),
										lockoutTotal
									) }
								</p>
							</FlexItem>
							<FlexItem>
								<Flex gap={ 2 }>
									<FlexItem>
										<Button
											variant="secondary"
											onClick={ refreshLockouts }
											isBusy={ busy }
											disabled={ busy }
										>
											{ __( 'Refresh', 'apppresser-wp' ) }
										</Button>
									</FlexItem>
									<FlexItem>
										<Button
											variant="secondary"
											isDestructive
											onClick={ clearLockouts }
											disabled={ busy || lockoutEntries.length === 0 }
										>
											{ __( 'Clear Log', 'apppresser-wp' ) }
										</Button>
									</FlexItem>
								</Flex>
							</FlexItem>
						</Flex>

						{ lockoutEntries.length === 0 ? (
							<p>
								{ __(
									'No lockouts have been logged yet.',
									'apppresser-wp'
								) }
							</p>
						) : (
							<table className="wp-list-table widefat fixed striped">
								<thead>
									<tr>
										<th style={ { width: '20%' } }>
											{ __( 'Date', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '20%' } }>
											{ __( 'IP Address', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '20%' } }>
											{ __( 'Username', 'apppresser-wp' ) }
										</th>
										<th>
											{ __( 'Reason', 'apppresser-wp' ) }
										</th>
										<th style={ { width: '12%' } }>
											{ __( 'Actions', 'apppresser-wp' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ lockoutEntries.map( ( entry ) => (
										<LockoutRow
											key={ entry.id }
											entry={ entry }
											onUnlock={ () =>
												unlockLockout( entry )
											}
										/>
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

/**
 * A single email log row.
 */
const FragmentRow = ( { entry, onDelete } ) => {
	return (
		<tr>
			<td>{ entry.created_at }</td>
			<td>{ entry.to }</td>
			<td>{ entry.subject }</td>
			<td>
				<div
					style={ {
						whiteSpace: 'pre-wrap',
						wordBreak: 'break-word',
						maxHeight: 200,
						overflowY: 'auto',
					} }
				>
					{ entry.message }
				</div>
			</td>
			<td>
				{ entry.status === 'failed' ? (
					<span style={ { color: '#b32d2e' } }>
						{ __( 'Failed', 'apppresser-wp' ) }
					</span>
				) : (
					<span style={ { color: '#007017' } }>
						{ __( 'Sent', 'apppresser-wp' ) }
					</span>
				) }
			</td>
			<td>
				<Button
					variant="link"
					size="small"
					isDestructive
					onClick={ onDelete }
				>
					{ __( 'Delete', 'apppresser-wp' ) }
				</Button>
			</td>
		</tr>
	);
};

/**
 * A single lockout log row.
 */
const LockoutRow = ( { entry, onUnlock } ) => {
	return (
		<tr>
			<td>{ entry.created_at }</td>
			<td>{ entry.ip }</td>
			<td>{ entry.username }</td>
			<td>
				{ entry.reason === 'long_lockout' ? (
					<span style={ { color: '#b32d2e' } }>
						{ __( 'Long Lockout', 'apppresser-wp' ) }
					</span>
				) : (
					<span style={ { color: '#007017' } }>
						{ __( 'Lockout', 'apppresser-wp' ) }
					</span>
				) }
			</td>
			<td>
				<Button
					variant="link"
					size="small"
					onClick={ onUnlock }
				>
					{ __( 'Unlock', 'apppresser-wp' ) }
				</Button>
			</td>
		</tr>
	);
};

export default LogsApp;
