/**
 * AgentReady — LLM-powered /llms.txt entry descriptions admin UI
 * (#8 Phase B / AgDR-0029).
 *
 * Server-paginated table of exposed posts with per-row inline edit /
 * regenerate buttons and a top-level "Regenerate stale" button. Mounts
 * underneath the editorial-entries editor on Tools → Context.
 *
 * All mutations go through `agentready/v1/llms-txt/descriptions/*` REST
 * routes — the controller is the source of truth, this UI is presentation
 * only.
 */

import { createRoot, useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const MOUNT_SELECTOR = '#agentready-llms-txt-descriptions-root';
const BOOTSTRAP_KEY = 'agentreadyLlmsTxtDescriptions';

// Poll cadence for rows in `pending` status. Re-fetches the whole page
// (not a per-row GET) every PENDING_POLL_INTERVAL_MS while at least one
// row is pending. STUCK_PENDING_THRESHOLD_MS is the dwell time after
// which the UI surfaces "wp-cron hasn't fired yet" — mirrors the
// 60s hint pattern PR #55 added for the Markdown Views cleanup flow.
const PENDING_POLL_INTERVAL_MS = 4000;
const STUCK_PENDING_THRESHOLD_MS = 60000;

const STATUS_FILTERS = [
	{ value: 'any', label: __( 'All entries', 'agentready' ) },
	{ value: 'missing', label: __( 'Missing (no description)', 'agentready' ) },
	{ value: 'cached', label: __( 'Cached (auto)', 'agentready' ) },
	{ value: 'manual', label: __( 'Manual override', 'agentready' ) },
	{ value: 'pending', label: __( 'Pending (queued)', 'agentready' ) },
	{ value: 'needs-retry', label: __( 'Needs retry', 'agentready' ) },
	{ value: 'failed', label: __( 'Failed', 'agentready' ) },
	{ value: 'stale', label: __( 'Stale (post edited after generation)', 'agentready' ) },
];

const PILL_STYLES = {
	manual: { background: '#dcedc8', color: '#33691e' },
	auto: { background: '#e3f2fd', color: '#0d47a1' },
	excerpt: { background: '#f5f5f5', color: '#555' },
	none: { background: '#fbeaea', color: '#a94442' },
	pending: { background: '#fff8e1', color: '#996f00' },
	'needs-retry': { background: '#fff8e1', color: '#996f00' },
	failed: { background: '#fbeaea', color: '#a94442' },
	done: { background: '#e8f5e9', color: '#2e7d32' },
	stale: { background: '#fff3e0', color: '#bf360c' },
};

function readBootstrap() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const data = window[ BOOTSTRAP_KEY ];
	if ( ! data || typeof data !== 'object' ) {
		return null;
	}
	return data;
}

function Pill( { kind, children } ) {
	const style = PILL_STYLES[ kind ] || PILL_STYLES.none;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '2px 8px',
				borderRadius: '10px',
				fontSize: '11px',
				fontWeight: 600,
				textTransform: 'uppercase',
				letterSpacing: '0.04em',
				...style,
			} }
		>
			{ children }
		</span>
	);
}

function StatusBadges( { row } ) {
	return (
		<div style={ { display: 'flex', gap: '4px', flexWrap: 'wrap' } }>
			<Pill kind={ row.source }>{ row.source }</Pill>
			{ row.status && row.status !== 'done' && (
				<Pill kind={ row.status }>{ row.status }</Pill>
			) }
			{ row.is_stale && <Pill kind="stale">{ __( 'stale', 'agentready' ) }</Pill> }
		</div>
	);
}

function DescriptionRow( {
	row,
	bootstrap,
	onRowUpdated,
	pendingAction,
	setPendingAction,
} ) {
	const [ editing, setEditing ] = useState( false );
	const [ draft, setDraft ] = useState( row.manual || row.auto || '' );
	const [ error, setError ] = useState( null );

	const apiPath = ( suffix = '' ) =>
		`/${ bootstrap.restNamespace }${ bootstrap.restBase }/${ row.post_id }${ suffix }`;

	const isBusy = pendingAction === row.post_id;
	const isManual = row.source === 'manual';
	const regenDisabledReason = isManual
		? __(
				'Manual override is sticky. Use "Clear manual" first to regenerate.',
				'agentready'
		  )
		: ! bootstrap.llmAvailable
		? __( 'WP AI Client is not configured.', 'agentready' )
		: null;

	const saveManual = useCallback( async () => {
		setPendingAction( row.post_id );
		setError( null );
		try {
			const updated = await apiFetch( {
				path: apiPath(),
				method: 'PATCH',
				data: { manual: draft },
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			onRowUpdated( updated );
			setEditing( false );
		} catch ( err ) {
			setError( err.message || __( 'Save failed.', 'agentready' ) );
		} finally {
			setPendingAction( null );
		}
	}, [ apiPath, bootstrap.restNonce, draft, onRowUpdated, row.post_id, setPendingAction ] );

	const clearManual = useCallback( async () => {
		setPendingAction( row.post_id );
		setError( null );
		try {
			const updated = await apiFetch( {
				path: apiPath( '/manual' ),
				method: 'DELETE',
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			onRowUpdated( updated );
			setDraft( updated.auto || '' );
			setEditing( false );
		} catch ( err ) {
			setError( err.message || __( 'Clear failed.', 'agentready' ) );
		} finally {
			setPendingAction( null );
		}
	}, [ apiPath, bootstrap.restNonce, onRowUpdated, row.post_id, setPendingAction ] );

	const regen = useCallback( async () => {
		setPendingAction( row.post_id );
		setError( null );
		try {
			const updated = await apiFetch( {
				path: apiPath( '/regenerate' ),
				method: 'POST',
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			onRowUpdated( updated );
		} catch ( err ) {
			setError( err.message || __( 'Regenerate failed.', 'agentready' ) );
		} finally {
			setPendingAction( null );
		}
	}, [ apiPath, bootstrap.restNonce, onRowUpdated, row.post_id, setPendingAction ] );

	return (
		<tr style={ { borderBottom: '1px solid #eee' } }>
			<td style={ { padding: '8px 6px', verticalAlign: 'top', width: '30%' } }>
				<div>
					<a href={ row.url } target="_blank" rel="noopener noreferrer">
						{ row.title || __( '(no title)', 'agentready' ) }
					</a>
				</div>
				<div style={ { color: '#888', fontSize: '11px', marginTop: '2px' } }>
					{ row.post_type }
				</div>
			</td>
			<td style={ { padding: '8px 6px', verticalAlign: 'top', width: '15%' } }>
				<StatusBadges row={ row } />
			</td>
			<td style={ { padding: '8px 6px', verticalAlign: 'top' } }>
				{ ! editing && (
					<div style={ { whiteSpace: 'pre-wrap' } }>
						{ row.resolved || (
							<em style={ { color: '#888' } }>
								{ __( '(no description)', 'agentready' ) }
							</em>
						) }
					</div>
				) }
				{ editing && (
					<TextareaControl
						label={ __( 'Manual override', 'agentready' ) }
						value={ draft }
						onChange={ ( v ) => setDraft( v ) }
						rows={ 2 }
						maxLength={ 200 }
						help={ __(
							'Sticky — never overwritten by automatic regeneration. Max 160 characters; longer entries are truncated.',
							'agentready'
						) }
						__nextHasNoMarginBottom
					/>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
			</td>
			<td style={ { padding: '8px 6px', verticalAlign: 'top', width: '20%', textAlign: 'right' } }>
				{ ! editing && (
					<div style={ { display: 'flex', gap: '4px', justifyContent: 'flex-end' } }>
						<Button variant="tertiary" onClick={ () => setEditing( true ) } disabled={ isBusy }>
							{ __( 'Edit', 'agentready' ) }
						</Button>
						<span title={ regenDisabledReason || undefined }>
							<Button
								variant="tertiary"
								onClick={ regen }
								disabled={ isBusy || regenDisabledReason !== null }
							>
								{ __( 'Regenerate', 'agentready' ) }
							</Button>
						</span>
					</div>
				) }
				{ editing && (
					<div style={ { display: 'flex', gap: '4px', justifyContent: 'flex-end' } }>
						<Button variant="primary" onClick={ saveManual } disabled={ isBusy }>
							{ __( 'Save', 'agentready' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setEditing( false ) } disabled={ isBusy }>
							{ __( 'Cancel', 'agentready' ) }
						</Button>
						{ row.manual && (
							<Button variant="link" isDestructive onClick={ clearManual } disabled={ isBusy }>
								{ __( 'Clear manual', 'agentready' ) }
							</Button>
						) }
					</div>
				) }
			</td>
		</tr>
	);
}

function DescriptionsTable() {
	const bootstrap = readBootstrap();
	const [ page, setPage ] = useState( 1 );
	const [ perPage ] = useState( 20 );
	const [ cpt, setCpt ] = useState( '' );
	const [ status, setStatus ] = useState( 'any' );
	const [ data, setData ] = useState( { items: [], total: 0, pages: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ pendingAction, setPendingAction ] = useState( null );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ flash, setFlash ] = useState( null );
	// Tracks when polling first observed a pending row in the current page.
	// Reset to null whenever every row clears `pending`. The stuck-pending
	// banner reads this to compute dwell time vs STUCK_PENDING_THRESHOLD_MS.
	const [ pendingStartedAt, setPendingStartedAt ] = useState( null );
	// Forces the stuck-pending banner to re-render once the dwell window
	// expires — without a tick the component would keep showing the old
	// "polling…" state until the next data refresh.
	const [ , setNowTick ] = useState( Date.now() );

	// `silent` skips the loading toggle so the 4s poll re-fetches don't
	// flicker the table by swapping content for the Spinner on every tick.
	// Initial mount + filter changes call without `silent` so the
	// first-paint spinner still appears.
	const fetchPage = useCallback( async ( { silent = false } = {} ) => {
		if ( ! bootstrap ) {
			return;
		}
		if ( ! silent ) {
			setLoading( true );
		}
		try {
			const params = new URLSearchParams( {
				paged: String( page ),
				per_page: String( perPage ),
				status,
			} );
			if ( cpt ) {
				params.set( 'cpt', cpt );
			}
			const response = await apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }?${ params.toString() }`,
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			let items = response.items || [];
			// Client-side narrow for `stale` — REST returns posts with
			// `_auto` set; we filter to those whose is_stale is true.
			if ( status === 'stale' ) {
				items = items.filter( ( row ) => row.is_stale );
			}
			setData( { ...response, items } );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message: err.message || __( 'Failed to load descriptions.', 'agentready' ),
			} );
		} finally {
			if ( ! silent ) {
				setLoading( false );
			}
		}
	}, [ bootstrap, page, perPage, cpt, status ] );

	useEffect( () => {
		fetchPage();
	}, [ fetchPage ] );

	// Track whether the current page has any pending row.
	const hasPending = data.items.some(
		( row ) => row.status === 'pending'
	);

	// Manage the `pendingStartedAt` lifecycle: start when we first see a
	// pending row, clear when none remain. Independent of the polling
	// interval so a single batch lifecycle drives both the spinner and
	// the stuck-pending banner.
	useEffect( () => {
		if ( hasPending && pendingStartedAt === null ) {
			setPendingStartedAt( Date.now() );
		} else if ( ! hasPending && pendingStartedAt !== null ) {
			setPendingStartedAt( null );
		}
	}, [ hasPending, pendingStartedAt ] );

	const stuckPending =
		pendingStartedAt !== null &&
		Date.now() - pendingStartedAt > STUCK_PENDING_THRESHOLD_MS;

	// Poll the page while any row is pending — but stop once we've
	// crossed the stuck-pending threshold. After 60s of pending dwell
	// the operator clearly needs to act (drain cron manually); hammering
	// the REST endpoint at 4s cadence after that point burns cycles for
	// nothing. The stuck-pending banner exposes a "Check again now"
	// button so the operator can manually re-poll after their drain.
	useEffect( () => {
		if ( ! hasPending || stuckPending ) {
			return undefined;
		}
		const id = setInterval( () => {
			fetchPage( { silent: true } );
			// Tick "now" so the threshold check re-evaluates on schedule
			// rather than waiting for the next fetchPage to land.
			setNowTick( Date.now() );
		}, PENDING_POLL_INTERVAL_MS );
		return () => clearInterval( id );
	}, [ hasPending, stuckPending, fetchPage ] );

	const handleRowUpdated = useCallback( ( updated ) => {
		setData( ( prev ) => ( {
			...prev,
			items: prev.items.map( ( r ) =>
				r.post_id === updated.post_id ? updated : r
			),
		} ) );
	}, [] );

	const bulkRegen = useCallback( async () => {
		if ( ! bootstrap ) {
			return;
		}
		setBulkBusy( true );
		setFlash( null );
		try {
			const response = await apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }/bulk-regenerate-stale`,
				method: 'POST',
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			setFlash( {
				type: 'success',
				message: sprintf(
					/* translators: 1: scheduled count, 2: skipped count */
					__( 'Scheduled %1$d description job(s); %2$d skipped.', 'agentready' ),
					response.scheduled,
					response.skipped
				),
			} );
			await fetchPage();
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message: err.message || __( 'Bulk regenerate failed.', 'agentready' ),
			} );
		} finally {
			setBulkBusy( false );
		}
	}, [ bootstrap, fetchPage ] );

	if ( ! bootstrap ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'AgentReady descriptions UI failed to bootstrap. Reload the page; if the issue persists, check the browser console.',
					'agentready'
				) }
			</Notice>
		);
	}

	if ( ! bootstrap.enabled ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'LLM descriptions are disabled in the Context Profile above. Toggle "Auto-generate entry descriptions" on to surface this table.',
					'agentready'
				) }
			</Notice>
		);
	}

	if ( ! bootstrap.llmAvailable ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'WP AI Client is not configured. Install/activate it and add API credentials before running description backfills.',
					'agentready'
				) }
			</Notice>
		);
	}

	return (
		<div>
			<div
				style={ {
					display: 'flex',
					gap: '12px',
					alignItems: 'flex-end',
					marginBottom: '12px',
					flexWrap: 'wrap',
				} }
			>
				<SelectControl
					label={ __( 'Post type', 'agentready' ) }
					value={ cpt }
					options={ [
						{ value: '', label: __( 'All exposed types', 'agentready' ) },
						...( bootstrap.exposedCpts || [] ).map( ( c ) => ( {
							value: c,
							label: c,
						} ) ),
					] }
					onChange={ ( v ) => {
						setCpt( v );
						setPage( 1 );
					} }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Filter', 'agentready' ) }
					value={ status }
					options={ STATUS_FILTERS }
					onChange={ ( v ) => {
						setStatus( v );
						setPage( 1 );
					} }
					__nextHasNoMarginBottom
				/>
				<Button
					variant="secondary"
					onClick={ bulkRegen }
					disabled={ bulkBusy }
				>
					{ bulkBusy
						? __( 'Scheduling…', 'agentready' )
						: __( 'Regenerate stale descriptions', 'agentready' ) }
				</Button>
			</div>

			{ flash && (
				<Notice status={ flash.type } onRemove={ () => setFlash( null ) }>
					{ flash.message }
				</Notice>
			) }

			{ hasPending && ! stuckPending && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Refreshing while cron processes the queued jobs…',
						'agentready'
					) }
				</Notice>
			) }

			{ stuckPending && (
				<Notice status="warning" isDismissible={ false }>
					<p style={ { margin: 0 } }>
						{ __(
							'Description jobs have been pending for over 60 seconds. WP cron fires on every front-end page hit — load any post in another tab, or run "wp cron event run --due-now" from the command line to drain the queue. Auto-refresh has paused.',
							'agentready'
						) }
					</p>
					<p style={ { margin: '8px 0 0 0' } }>
						<Button
							variant="secondary"
							onClick={ () => {
								setPendingStartedAt( null );
								fetchPage();
							} }
						>
							{ __( 'Check again now', 'agentready' ) }
						</Button>
					</p>
				</Notice>
			) }

			{ loading && <Spinner /> }

			{ ! loading && data.items.length === 0 && (
				<p style={ { fontStyle: 'italic', color: '#888' } }>
					{ __( 'No entries match the current filter.', 'agentready' ) }
				</p>
			) }

			{ ! loading && data.items.length > 0 && (
				<table
					style={ {
						width: '100%',
						borderCollapse: 'collapse',
						background: '#fff',
					} }
				>
					<thead>
						<tr style={ { background: '#f8f8f8', textAlign: 'left' } }>
							<th style={ { padding: '8px 6px' } }>
								{ __( 'Post', 'agentready' ) }
							</th>
							<th style={ { padding: '8px 6px' } }>
								{ __( 'Status', 'agentready' ) }
							</th>
							<th style={ { padding: '8px 6px' } }>
								{ __( 'Description', 'agentready' ) }
							</th>
							<th style={ { padding: '8px 6px', textAlign: 'right' } }>
								{ __( 'Actions', 'agentready' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( row ) => (
							<DescriptionRow
								key={ row.post_id }
								row={ row }
								bootstrap={ bootstrap }
								onRowUpdated={ handleRowUpdated }
								pendingAction={ pendingAction }
								setPendingAction={ setPendingAction }
							/>
						) ) }
					</tbody>
				</table>
			) }

			{ ! loading && data.pages > 1 && (
				<div
					style={ {
						display: 'flex',
						gap: '8px',
						alignItems: 'center',
						marginTop: '12px',
					} }
				>
					<Button
						variant="tertiary"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
					>
						{ __( '← Previous', 'agentready' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'agentready' ),
							page,
							data.pages
						) }
					</span>
					<Button
						variant="tertiary"
						disabled={ page >= data.pages }
						onClick={ () => setPage( ( p ) => Math.min( data.pages, p + 1 ) ) }
					>
						{ __( 'Next →', 'agentready' ) }
					</Button>
					<span style={ { color: '#888', marginLeft: '8px' } }>
						{ sprintf(
							/* translators: %d: total entries */
							__( '%d total', 'agentready' ),
							data.total
						) }
					</span>
				</div>
			) }
		</div>
	);
}

const target = document.querySelector( MOUNT_SELECTOR );
if ( target ) {
	const root = createRoot( target );
	root.render( <DescriptionsTable /> );
}
