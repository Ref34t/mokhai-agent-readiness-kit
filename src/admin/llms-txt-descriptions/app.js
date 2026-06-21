/**
 * Mokhai — LLM-powered /llms.txt entry descriptions admin UI
 * (#8 Phase B / AgDR-0029).
 *
 * Server-paginated table of exposed posts with per-row inline edit /
 * regenerate buttons and a top-level "Regenerate stale" button. Mounts
 * underneath the editorial-entries editor on Tools → Context.
 *
 * All mutations go through `ai-readiness-kit/v1/llms-txt/descriptions/*` REST
 * routes — the controller is the source of truth, this UI is presentation
 * only.
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Pill } from '../shared/Pill';
import '../shared/admin-ui.css';

const BOOTSTRAP_KEY = 'agentreadyLlmsTxtDescriptions';

// Poll cadence for rows in `pending` status. Re-fetches the whole page
// (not a per-row GET) every PENDING_POLL_INTERVAL_MS while at least one
// row is pending. STUCK_PENDING_THRESHOLD_MS is the dwell time after
// which the UI surfaces "wp-cron hasn't fired yet" — mirrors the
// 60s hint pattern PR #55 added for the Markdown Views cleanup flow.
const PENDING_POLL_INTERVAL_MS = 4000;
const STUCK_PENDING_THRESHOLD_MS = 60000;

const STATUS_FILTERS = [
	{ value: 'any', label: __( 'All entries', 'mokhai-agent-readiness-kit' ) },
	{
		value: 'missing',
		label: __( 'Missing (no description)', 'mokhai-agent-readiness-kit' ),
	},
	{ value: 'cached', label: __( 'Cached (auto)', 'mokhai-agent-readiness-kit' ) },
	{ value: 'manual', label: __( 'Manual override', 'mokhai-agent-readiness-kit' ) },
	{ value: 'pending', label: __( 'Pending (queued)', 'mokhai-agent-readiness-kit' ) },
	{ value: 'needs-retry', label: __( 'Needs retry', 'mokhai-agent-readiness-kit' ) },
	{ value: 'failed', label: __( 'Failed', 'mokhai-agent-readiness-kit' ) },
	{
		value: 'stale',
		label: __( 'Stale (post edited after generation)', 'mokhai-agent-readiness-kit' ),
	},
];

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

function StatusBadges( { row } ) {
	return (
		<div className="agentready-pill-group">
			{ row.excluded && (
				<Pill kind="excluded">
					{ __( 'excluded', 'mokhai-agent-readiness-kit' ) }
				</Pill>
			) }
			<Pill kind={ row.source }>{ row.source }</Pill>
			{ row.status && row.status !== 'done' && (
				<Pill kind={ row.status }>{ row.status }</Pill>
			) }
			{ row.is_stale && (
				<Pill kind="stale">{ __( 'stale', 'mokhai-agent-readiness-kit' ) }</Pill>
			) }
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
				'mokhai-agent-readiness-kit'
		  )
		: ! bootstrap.llmAvailable
		? __( 'WP AI Client is not configured.', 'mokhai-agent-readiness-kit' )
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
			setError( err.message || __( 'Save failed.', 'mokhai-agent-readiness-kit' ) );
		} finally {
			setPendingAction( null );
		}
	}, [
		apiPath,
		bootstrap.restNonce,
		draft,
		onRowUpdated,
		row.post_id,
		setPendingAction,
	] );

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
			setError(
				err.message || __( 'Clear failed.', 'mokhai-agent-readiness-kit' )
			);
		} finally {
			setPendingAction( null );
		}
	}, [
		apiPath,
		bootstrap.restNonce,
		onRowUpdated,
		row.post_id,
		setPendingAction,
	] );

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
			setError(
				err.message || __( 'Regenerate failed.', 'mokhai-agent-readiness-kit' )
			);
		} finally {
			setPendingAction( null );
		}
	}, [
		apiPath,
		bootstrap.restNonce,
		onRowUpdated,
		row.post_id,
		setPendingAction,
	] );

	return (
		<tr>
			<td className="col-post">
				<div>
					<a
						href={ row.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ row.title || __( '(no title)', 'mokhai-agent-readiness-kit' ) }
					</a>
				</div>
				<div className="agentready-muted--sm">{ row.post_type }</div>
			</td>
			<td className="col-status">
				<StatusBadges row={ row } />
			</td>
			<td>
				{ ! editing && (
					<div className="agentready-descriptions__resolved">
						{ row.resolved || (
							<em className="agentready-muted">
								{ __( '(no description)', 'mokhai-agent-readiness-kit' ) }
							</em>
						) }
					</div>
				) }
				{ editing && (
					<TextareaControl
						label={ __( 'Manual override', 'mokhai-agent-readiness-kit' ) }
						value={ draft }
						onChange={ ( v ) => setDraft( v ) }
						rows={ 2 }
						maxLength={ 200 }
						help={ __(
							'Sticky — never overwritten by automatic regeneration. Max 160 characters; longer entries are truncated.',
							'mokhai-agent-readiness-kit'
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
			<td className="col-actions">
				{ ! editing && (
					<div className="agentready-button-row agentready-button-row--end agentready-button-row--tight">
						<Button
							variant="tertiary"
							onClick={ () => setEditing( true ) }
							disabled={ isBusy }
						>
							{ __( 'Edit', 'mokhai-agent-readiness-kit' ) }
						</Button>
						<span title={ regenDisabledReason || undefined }>
							<Button
								variant="tertiary"
								onClick={ regen }
								disabled={
									isBusy || regenDisabledReason !== null
								}
							>
								{ __( 'Regenerate', 'mokhai-agent-readiness-kit' ) }
							</Button>
						</span>
					</div>
				) }
				{ editing && (
					<div className="agentready-button-row agentready-button-row--end agentready-button-row--tight">
						<Button
							variant="primary"
							onClick={ saveManual }
							disabled={ isBusy }
						>
							{ __( 'Save', 'mokhai-agent-readiness-kit' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => setEditing( false ) }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'mokhai-agent-readiness-kit' ) }
						</Button>
						{ row.manual && (
							<Button
								variant="link"
								isDestructive
								onClick={ clearManual }
								disabled={ isBusy }
							>
								{ __( 'Clear manual', 'mokhai-agent-readiness-kit' ) }
							</Button>
						) }
					</div>
				) }
			</td>
		</tr>
	);
}

export function DescriptionsTable() {
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
	const fetchPage = useCallback(
		async ( { silent = false } = {} ) => {
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
					path: `/${ bootstrap.restNamespace }${
						bootstrap.restBase
					}?${ params.toString() }`,
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
					message:
						err.message ||
						__(
							'Failed to load descriptions.',
							'mokhai-agent-readiness-kit'
						),
				} );
			} finally {
				if ( ! silent ) {
					setLoading( false );
				}
			}
		},
		[ bootstrap, page, perPage, cpt, status ]
	);

	useEffect( () => {
		fetchPage();
	}, [ fetchPage ] );

	// Track whether the current page has any pending row.
	const hasPending = data.items.some( ( row ) => row.status === 'pending' );

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
					__(
						'Scheduled %1$d description job(s); %2$d skipped.',
						'mokhai-agent-readiness-kit'
					),
					response.scheduled,
					response.skipped
				),
			} );
			await fetchPage();
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message ||
					__( 'Bulk regenerate failed.', 'mokhai-agent-readiness-kit' ),
			} );
		} finally {
			setBulkBusy( false );
		}
	}, [ bootstrap, fetchPage ] );

	if ( ! bootstrap ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Mokhai descriptions UI failed to bootstrap. Reload the page; if the issue persists, check the browser console.',
					'mokhai-agent-readiness-kit'
				) }
			</Notice>
		);
	}

	if ( ! bootstrap.enabled ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'LLM descriptions are disabled in the Context Profile above. Toggle "Auto-generate entry descriptions" on to surface this table.',
					'mokhai-agent-readiness-kit'
				) }
			</Notice>
		);
	}

	if ( ! bootstrap.llmAvailable ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'WP AI Client is not configured. Install/activate it and add API credentials before running description backfills.',
					'mokhai-agent-readiness-kit'
				) }
			</Notice>
		);
	}

	return (
		<Panel
			header={ __(
				'LLMs Index — auto-generated descriptions',
				'mokhai-agent-readiness-kit'
			) }
			className="agentready-admin-panel"
		>
			<PanelBody opened>
				<p className="description">
					{ __(
						'One-line descriptions for entries in your exposed post types, generated via the configured LLM and cached on post meta. Rows marked "excluded" (password-protected, noindex, or manually excluded) are listed for visibility but skipped — they never reach /llms.txt. Edit any description inline to set a sticky manual override that survives regeneration.',
						'mokhai-agent-readiness-kit'
					) }
				</p>

				<div className="agentready-toolbar">
					<SelectControl
						label={ __( 'Post type', 'mokhai-agent-readiness-kit' ) }
						value={ cpt }
						options={ [
							{
								value: '',
								label: __(
									'All exposed types',
									'mokhai-agent-readiness-kit'
								),
							},
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
						label={ __( 'Filter', 'mokhai-agent-readiness-kit' ) }
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
							? __( 'Scheduling…', 'mokhai-agent-readiness-kit' )
							: __(
									'Regenerate stale descriptions',
									'mokhai-agent-readiness-kit'
							  ) }
					</Button>
				</div>

				{ flash && (
					<Notice
						status={ flash.type }
						onRemove={ () => setFlash( null ) }
					>
						{ flash.message }
					</Notice>
				) }

				{ hasPending && ! stuckPending && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Refreshing while cron processes the queued jobs…',
							'mokhai-agent-readiness-kit'
						) }
					</Notice>
				) }

				{ stuckPending && (
					<Notice status="warning" isDismissible={ false }>
						<p className="agentready-md-label--first">
							{ __(
								'Description jobs have been pending for over 60 seconds. WP cron fires on every front-end page hit — load any post in another tab, or run "wp cron event run --due-now" from the command line to drain the queue. Auto-refresh has paused.',
								'mokhai-agent-readiness-kit'
							) }
						</p>
						<p className="agentready-button-row">
							<Button
								variant="secondary"
								onClick={ () => {
									setPendingStartedAt( null );
									fetchPage();
								} }
							>
								{ __( 'Check again now', 'mokhai-agent-readiness-kit' ) }
							</Button>
						</p>
					</Notice>
				) }

				{ loading && <Spinner /> }

				{ ! loading && data.items.length === 0 && (
					<p className="agentready-empty">
						{ __(
							'No entries match the current filter.',
							'mokhai-agent-readiness-kit'
						) }
					</p>
				) }

				{ ! loading && data.items.length > 0 && (
					<table className="agentready-descriptions-table">
						<thead>
							<tr>
								<th>{ __( 'Post', 'mokhai-agent-readiness-kit' ) }</th>
								<th>{ __( 'Status', 'mokhai-agent-readiness-kit' ) }</th>
								<th>
									{ __( 'Description', 'mokhai-agent-readiness-kit' ) }
								</th>
								<th className="col-actions">
									{ __( 'Actions', 'mokhai-agent-readiness-kit' ) }
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
					<div className="agentready-pagination">
						<Button
							variant="tertiary"
							disabled={ page <= 1 }
							onClick={ () =>
								setPage( ( p ) => Math.max( 1, p - 1 ) )
							}
						>
							{ __( '← Previous', 'mokhai-agent-readiness-kit' ) }
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$d of %2$d', 'mokhai-agent-readiness-kit' ),
								page,
								data.pages
							) }
						</span>
						<Button
							variant="tertiary"
							disabled={ page >= data.pages }
							onClick={ () =>
								setPage( ( p ) =>
									Math.min( data.pages, p + 1 )
								)
							}
						>
							{ __( 'Next →', 'mokhai-agent-readiness-kit' ) }
						</Button>
						<span className="agentready-muted">
							{ sprintf(
								/* translators: %d: total entries */
								__( '%d total', 'mokhai-agent-readiness-kit' ),
								data.total
							) }
						</span>
					</div>
				) }
			</PanelBody>
		</Panel>
	);
}
