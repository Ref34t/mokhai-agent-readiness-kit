/**
 * Mokhai — shared status-pill primitive (#70).
 *
 * Extracted from the descriptions table so every admin surface renders status
 * indicators with one consistent shape + colour vocabulary. Colours live in
 * `admin-ui.css` as `.mokhai-pill--<kind>` modifiers, so this component is
 * markup-only — no inline styles.
 *
 * Usage:
 *   import { Pill } from '../shared/Pill';
 *   <Pill kind="manual">manual</Pill>
 */

/**
 * Known pill kinds. A `kind` outside this set falls back to the neutral
 * `none` styling so an unexpected status string still renders legibly
 * instead of unstyled.
 *
 * @type {string[]}
 */
export const PILL_KINDS = [
	'manual',
	'auto',
	'excerpt',
	'none',
	'pending',
	'needs-retry',
	'failed',
	'done',
	'stale',
	'excluded',
	'skipped',
];

/**
 * Status pill.
 *
 * @param {Object}                    props
 * @param {string}                    props.kind     Status kind (see PILL_KINDS).
 * @param {import('react').ReactNode} props.children Pill label.
 * @return {JSX.Element} Rendered pill.
 */
export function Pill( { kind, children } ) {
	const resolved = PILL_KINDS.includes( kind ) ? kind : 'none';
	return (
		<span className={ `mokhai-pill mokhai-pill--${ resolved }` }>
			{ children }
		</span>
	);
}
