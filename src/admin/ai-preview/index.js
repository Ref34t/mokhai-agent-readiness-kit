/**
 * AI Readiness Kit — AI Assistant Preview pane (#45 / AgDR-0046).
 *
 * A buyer-facing panel mounted below the Context Score breakdown. Pick any
 * URL on the site and see it three ways, side by side:
 *
 *   1. Raw HTML      — what bots parse WITHOUT the plugin (the_content output)
 *   2. Markdown View — what bots get WITH the plugin (the #6 converter)
 *   3. llms.txt entry — the line describing this URL in the live /llms.txt
 *
 * Plus an optional Sample AI Summary: a synchronous, on-demand 2-3 sentence
 * preview of what an assistant would say about the page, generated from the
 * Markdown View and cached server-side. No polling — the POST returns the
 * result (or a structured degrade hint) directly.
 *
 * All reads/writes route through the `ai-readiness-kit/v1/ai-preview` REST
 * surface shipped in PR A. The panel is presentation only; the server is the
 * source of truth.
 */

import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const MOUNT_SELECTOR = '#agentready-ai-preview-root';
const BOOTSTRAP_KEY = 'agentreadyAiPreview';

// Reasons the Markdown View pane can be empty, mapped to operator-facing copy.
const NOT_EXPOSABLE_REASONS = {
	cpt: __(
		'This post type is not exposed in the Context Profile.',
		'ai-readiness-kit'
	),
	status: __(
		'This post status is not exposed in the Context Profile.',
		'ai-readiness-kit'
	),
	password: __(
		'This page is password-protected, so it is hidden from AI agents.',
		'ai-readiness-kit'
	),
	noindex: __(
		'This page is marked noindex, so it is hidden from AI agents.',
		'ai-readiness-kit'
	),
};

// Source of the /llms.txt entry description, for the badge.
const DESCRIPTION_SOURCE_LABELS = {
	llm: __( 'AI-generated description', 'ai-readiness-kit' ),
	excerpt: __( 'From the post excerpt', 'ai-readiness-kit' ),
	none: __( 'No description', 'ai-readiness-kit' ),
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

// A scroll-collapsed code box for one pane's content.
function CodeBox( { children } ) {
	return (
		<pre
			style={ {
				maxHeight: '420px',
				overflow: 'auto',
				background: '#1e1e1e',
				color: '#e6e6e6',
				padding: '12px',
				borderRadius: '4px',
				fontSize: '12px',
				lineHeight: 1.5,
				whiteSpace: 'pre-wrap',
				wordBreak: 'break-word',
				margin: 0,
			} }
		>
			{ children }
		</pre>
	);
}

// One of the three side-by-side panes, with a title and a sub-caption.
function Pane( { title, caption, children } ) {
	return (
		<div style={ { flex: '1 1 0', minWidth: '260px' } }>
			<h3 style={ { margin: '0 0 2px' } }>{ title }</h3>
			<p
				className="description"
				style={ { margin: '0 0 8px', minHeight: '32px' } }
			>
				{ caption }
			</p>
			{ children }
		</div>
	);
}

function RawHtmlPane( { rawHtml } ) {
	const caption = rawHtml.truncated
		? sprintf(
				/* translators: %s: full content length in characters. */
				__(
					'What bots parse without the plugin — showing the first %s characters.',
					'ai-readiness-kit'
				),
				Number( rawHtml.full_length ).toLocaleString()
		  )
		: __( 'What bots parse without the plugin.', 'ai-readiness-kit' );

	return (
		<Pane
			title={ __( 'Raw HTML', 'ai-readiness-kit' ) }
			caption={ caption }
		>
			<CodeBox>{ rawHtml.html }</CodeBox>
		</Pane>
	);
}

function MarkdownPane( { markdown, profilePageUrl } ) {
	const { verdict, reason } = markdown.visibility || {};
	let body;

	if ( 'module_disabled' === verdict ) {
		body = (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'Markdown Views is disabled.', 'ai-readiness-kit' ) }{ ' ' }
				{ profilePageUrl && (
					<a href={ profilePageUrl }>
						{ __(
							'Enable it in the Context Profile.',
							'ai-readiness-kit'
						) }
					</a>
				) }
			</Notice>
		);
	} else if ( 'not_exposable' === verdict ) {
		body = (
			<Notice status="info" isDismissible={ false }>
				{ NOT_EXPOSABLE_REASONS[ reason ] ||
					__(
						'This URL is not exposed to AI agents.',
						'ai-readiness-kit'
					) }
			</Notice>
		);
	} else if ( 'error' === verdict ) {
		body = (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Could not render the Markdown View for this URL.',
					'ai-readiness-kit'
				) }
			</Notice>
		);
	} else {
		body = <CodeBox>{ markdown.markdown }</CodeBox>;
	}

	return (
		<Pane
			title={ __( 'Markdown View', 'ai-readiness-kit' ) }
			caption={ __(
				'What bots get with the plugin.',
				'ai-readiness-kit'
			) }
		>
			{ body }
		</Pane>
	);
}

function LlmsEntryPane( { llmsEntry } ) {
	const body = llmsEntry.present ? (
		<>
			<CodeBox>{ llmsEntry.line }</CodeBox>
			<p className="description" style={ { marginTop: '6px' } }>
				{ DESCRIPTION_SOURCE_LABELS[ llmsEntry.description_source ] ||
					'' }
			</p>
		</>
	) : (
		<Notice status="info" isDismissible={ false }>
			{ __(
				'This URL has no line in /llms.txt because it is not exposed.',
				'ai-readiness-kit'
			) }
		</Notice>
	);

	return (
		<Pane
			title={ __( 'llms.txt entry', 'ai-readiness-kit' ) }
			caption={ __(
				'The line describing this URL in /llms.txt.',
				'ai-readiness-kit'
			) }
		>
			{ body }
		</Pane>
	);
}

// The optional Sample AI Summary box. Always renders; shows the cached
// summary, a structured degrade hint, or a "generate" prompt.
function SummaryBox( { summary, pending, onGenerate } ) {
	const hasText = summary && summary.text;
	const degradeMessage = summary && ! summary.text ? summary.message : null;

	return (
		<Panel>
			<PanelBody
				title={ __( 'Sample AI Summary', 'ai-readiness-kit' ) }
				initialOpen={ true }
			>
				<p className="description">
					{ __(
						'A preview of what an AI assistant would say about this page, generated from its Markdown View.',
						'ai-readiness-kit'
					) }
				</p>

				{ hasText && (
					<p style={ { fontSize: '14px', lineHeight: 1.6 } }>
						{ summary.text }
					</p>
				) }

				{ degradeMessage && (
					<Notice status="info" isDismissible={ false }>
						{ degradeMessage }
					</Notice>
				) }

				<Button
					variant="secondary"
					onClick={ onGenerate }
					disabled={ pending }
				>
					{ pending && <Spinner /> }
					{ hasText
						? __( 'Regenerate summary', 'ai-readiness-kit' )
						: __( 'Generate sample summary', 'ai-readiness-kit' ) }
				</Button>
			</PanelBody>
		</Panel>
	);
}

function AiPreviewPanel() {
	const bootstrap = readBootstrap();
	const [ posts, setPosts ] = useState( [] );
	const [ selectedId, setSelectedId ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ loadingPosts, setLoadingPosts ] = useState( true );
	const [ loadingPreview, setLoadingPreview ] = useState( false );
	const [ summaryPending, setSummaryPending ] = useState( false );
	const [ flash, setFlash ] = useState( null );

	const apiPath = useCallback(
		( suffix ) =>
			`/${ bootstrap.restNamespace }${ bootstrap.restBase }${ suffix }`,
		[ bootstrap ]
	);

	// Load the dropdown once on mount.
	useEffect( () => {
		if ( ! bootstrap ) {
			return;
		}
		( async () => {
			try {
				const response = await apiFetch( {
					path: apiPath( '/posts?per_page=100' ),
					headers: { 'X-WP-Nonce': bootstrap.restNonce },
				} );
				setPosts( response.posts || [] );
			} catch ( err ) {
				setFlash( {
					type: 'error',
					message:
						err.message ||
						__(
							'Failed to load the URL list.',
							'ai-readiness-kit'
						),
				} );
			} finally {
				setLoadingPosts( false );
			}
		} )();
	}, [ bootstrap, apiPath ] );

	const fetchPreview = useCallback(
		async ( postId ) => {
			if ( ! bootstrap || ! postId ) {
				setPreview( null );
				return;
			}
			setLoadingPreview( true );
			setFlash( null );
			try {
				const response = await apiFetch( {
					path: apiPath(
						`/preview?post=${ encodeURIComponent( postId ) }`
					),
					headers: { 'X-WP-Nonce': bootstrap.restNonce },
				} );
				setPreview( response );
			} catch ( err ) {
				setPreview( null );
				setFlash( {
					type: 'error',
					message:
						err.message ||
						__( 'Failed to load the preview.', 'ai-readiness-kit' ),
				} );
			} finally {
				setLoadingPreview( false );
			}
		},
		[ bootstrap, apiPath ]
	);

	const onSelect = useCallback(
		( value ) => {
			setSelectedId( value );
			fetchPreview( value );
		},
		[ fetchPreview ]
	);

	const onGenerateSummary = useCallback( async () => {
		if ( ! bootstrap || ! selectedId ) {
			return;
		}
		setSummaryPending( true );
		try {
			const response = await apiFetch( {
				path: apiPath(
					`/summary?post=${ encodeURIComponent( selectedId ) }`
				),
				method: 'POST',
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			setPreview( ( prev ) =>
				prev ? { ...prev, summary: response } : prev
			);
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message ||
					__( 'Failed to generate the summary.', 'ai-readiness-kit' ),
			} );
		} finally {
			setSummaryPending( false );
		}
	}, [ bootstrap, selectedId, apiPath ] );

	if ( ! bootstrap ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'AI Assistant Preview failed to bootstrap. Reload the page; if the issue persists, check the browser console.',
					'ai-readiness-kit'
				) }
			</Notice>
		);
	}

	const options = [
		{ label: __( '— Select a URL —', 'ai-readiness-kit' ), value: '' },
		...posts.map( ( post ) => ( {
			label: `${ post.title } (${ post.type })`,
			value: String( post.id ),
		} ) ),
	];

	return (
		<div style={ { marginTop: '24px' } }>
			<h2>{ __( 'AI Assistant Preview', 'ai-readiness-kit' ) }</h2>
			<p className="description">
				{ __(
					'See exactly what an AI assistant reads when it visits a page on your site.',
					'ai-readiness-kit'
				) }
			</p>

			{ flash && (
				<Notice
					status={ flash.type }
					onRemove={ () => setFlash( null ) }
				>
					{ flash.message }
				</Notice>
			) }

			<SelectControl
				label={ __( 'URL to preview', 'ai-readiness-kit' ) }
				value={ selectedId }
				options={ options }
				onChange={ onSelect }
				disabled={ loadingPosts }
				__nextHasNoMarginBottom
			/>

			{ loadingPreview && <Spinner /> }

			{ ! loadingPreview && preview && (
				<>
					<div
						style={ {
							display: 'flex',
							gap: '16px',
							flexWrap: 'wrap',
							marginTop: '12px',
						} }
					>
						<RawHtmlPane rawHtml={ preview.raw_html } />
						<MarkdownPane
							markdown={ preview.markdown }
							profilePageUrl={ bootstrap.profilePageUrl }
						/>
						<LlmsEntryPane llmsEntry={ preview.llms_entry } />
					</div>

					<div style={ { marginTop: '20px' } }>
						<SummaryBox
							summary={ preview.summary }
							pending={ summaryPending }
							onGenerate={ onGenerateSummary }
						/>
					</div>
				</>
			) }
		</div>
	);
}

const target = document.querySelector( MOUNT_SELECTOR );
if ( target ) {
	const root = createRoot( target );
	root.render( <AiPreviewPanel /> );
}
