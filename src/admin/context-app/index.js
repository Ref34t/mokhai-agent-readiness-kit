/**
 * AI Readiness Kit — Context app shell (#142 / AgDR-0048).
 *
 * Single React app for Tools → Context. Replaces the three independent mounts
 * (profile / editorial / descriptions) with one cohesive, card-framed surface
 * whose sections switch in place via a `TabPanel` — no page reload — matching
 * the WP AI Client / Connector settings UX on stable WordPress (we deliberately
 * do NOT adopt the alpha-only `@wordpress/boot` framework — see AgDR-0048).
 *
 * Saves go through REST (`apiFetch`) inside each tab view; this shell only owns
 * layout + navigation. First-paint data is still server-rendered into three
 * `window.agentready*` globals so there is no loading flash.
 */

import { createRoot } from '@wordpress/element';
import { Card, CardBody, TabPanel, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ContextProfileApp } from '../context-profile/app';
import { EditorialApp } from '../llms-txt-editorial/app';
import { DescriptionsTable } from '../llms-txt-descriptions/app';
import '../shared/admin-ui.css';

const MOUNT_SELECTOR = '#agentready-context-app';

function readGlobal( key ) {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const data = window[ key ];
	return data && typeof data === 'object' ? data : null;
}

const TABS = [
	{ name: 'profile', title: __( 'Profile', 'agentready-ai-readiness-kit' ) },
	{ name: 'editorial', title: __( 'Editorial', 'agentready-ai-readiness-kit' ) },
	{ name: 'descriptions', title: __( 'Descriptions', 'agentready-ai-readiness-kit' ) },
];

function ContextApp() {
	const profileBootstrap = readGlobal( 'agentreadyContextProfile' );
	const editorialBootstrap = readGlobal( 'agentreadyLlmsTxtEditorial' );

	return (
		<TabPanel
			className="agentready-context-tabs"
			tabs={ TABS }
			initialTabName="profile"
		>
			{ ( tab ) => (
				<Card className="agentready-context-card">
					<CardBody>
						{ tab.name === 'profile' &&
							( profileBootstrap ? (
								<ContextProfileApp
									bootstrap={ profileBootstrap }
								/>
							) : (
								<Notice status="error" isDismissible={ false }>
									{ __(
										'Context Profile failed to load. Reload the page or contact support.',
										'agentready-ai-readiness-kit'
									) }
								</Notice>
							) ) }

						{ tab.name === 'editorial' &&
							( editorialBootstrap ? (
								<EditorialApp
									bootstrap={ editorialBootstrap }
								/>
							) : (
								<Notice status="error" isDismissible={ false }>
									{ __(
										'Editorial entries failed to load. Reload the page or contact support.',
										'agentready-ai-readiness-kit'
									) }
								</Notice>
							) ) }

						{ tab.name === 'descriptions' && <DescriptionsTable /> }
					</CardBody>
				</Card>
			) }
		</TabPanel>
	);
}

function init() {
	const mount = document.querySelector( MOUNT_SELECTOR );
	if ( ! mount ) {
		return;
	}
	const root = createRoot( mount );
	root.render( <ContextApp /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
