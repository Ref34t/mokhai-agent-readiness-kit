/**
 * Mokhai — LLMs Index editorial entries view (#7 Phase C / #142).
 *
 * Repeater editor for the `agentready_llms_txt_editorial` option. Rendered as
 * the "Editorial" tab of the Context app shell (#142 / AgDR-0048). Saves via
 * the `ai-readiness-kit/v1/llms-txt/editorial` REST route through `apiFetch`
 * — no page reload — instead of the legacy options.php form POST. The
 * server-side `Editorial_Settings::sanitize()` remains the source of truth.
 */

import { useState, useMemo } from '@wordpress/element';
import {
	Button,
	TextControl,
	SelectControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import '../shared/admin-ui.css';

const DEFAULT_SECTIONS = [ 'Featured', 'Resources', 'Custom' ];

function makeBlankEntry() {
	return {
		title: '',
		url: '',
		description: '',
		section: 'Featured',
		section_label: '',
	};
}

function EntryRow( {
	entry,
	index,
	total,
	onChange,
	onRemove,
	onMove,
	sections,
} ) {
	const isCustom = entry.section === 'Custom';

	return (
		<div className="agentready-editorial__row">
			<div className="agentready-editorial__row-header">
				<strong>
					{ sprintf(
						/* translators: %d: entry index */
						__( 'Entry %d', 'mokhai-agent-readiness-kit' ),
						index + 1
					) }
				</strong>
				<div className="agentready-button-row agentready-button-row--tight">
					<Button
						type="button"
						variant="tertiary"
						disabled={ index === 0 }
						onClick={ () => onMove( index, -1 ) }
						aria-label={ __( 'Move entry up', 'mokhai-agent-readiness-kit' ) }
					>
						{ '↑' }
					</Button>
					<Button
						type="button"
						variant="tertiary"
						disabled={ index === total - 1 }
						onClick={ () => onMove( index, 1 ) }
						aria-label={ __(
							'Move entry down',
							'mokhai-agent-readiness-kit'
						) }
					>
						{ '↓' }
					</Button>
					<Button
						type="button"
						isDestructive
						variant="tertiary"
						onClick={ () => onRemove( index ) }
						aria-label={ __( 'Remove entry', 'mokhai-agent-readiness-kit' ) }
					>
						{ __( 'Remove', 'mokhai-agent-readiness-kit' ) }
					</Button>
				</div>
			</div>

			<TextControl
				label={ __( 'Title', 'mokhai-agent-readiness-kit' ) }
				value={ entry.title }
				onChange={ ( value ) => onChange( index, 'title', value ) }
				required
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'URL', 'mokhai-agent-readiness-kit' ) }
				value={ entry.url }
				onChange={ ( value ) => onChange( index, 'url', value ) }
				type="url"
				placeholder="https://"
				required
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				help={ __(
					'External URLs allowed. Schemes: http, https, mailto.',
					'mokhai-agent-readiness-kit'
				) }
			/>

			<TextControl
				label={ __( 'Description (optional)', 'mokhai-agent-readiness-kit' ) }
				value={ entry.description }
				onChange={ ( value ) =>
					onChange( index, 'description', value )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<SelectControl
				label={ __( 'Section', 'mokhai-agent-readiness-kit' ) }
				value={ entry.section }
				options={ sections.map( ( s ) => ( { label: s, value: s } ) ) }
				onChange={ ( value ) => onChange( index, 'section', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ isCustom && (
				<TextControl
					label={ __( 'Custom section heading', 'mokhai-agent-readiness-kit' ) }
					value={ entry.section_label }
					onChange={ ( value ) =>
						onChange( index, 'section_label', value )
					}
					required
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					help={ __(
						'Heading rendered in /llms.txt when section is Custom.',
						'mokhai-agent-readiness-kit'
					) }
				/>
			) }
		</div>
	);
}

export function EditorialApp( { bootstrap } ) {
	const initialEntries = useMemo(
		() => ( Array.isArray( bootstrap.entries ) ? bootstrap.entries : [] ),
		[ bootstrap.entries ]
	);
	const [ entries, setEntries ] = useState( initialEntries );
	const [ saving, setSaving ] = useState( false );
	const [ flash, setFlash ] = useState( null );

	const sections = bootstrap.sections || DEFAULT_SECTIONS;

	const onChange = ( index, field, value ) => {
		setEntries( ( prev ) =>
			prev.map( ( e, i ) =>
				i === index ? { ...e, [ field ]: value } : e
			)
		);
	};

	const onRemove = ( index ) => {
		setEntries( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
	};

	const onMove = ( index, direction ) => {
		setEntries( ( prev ) => {
			const next = [ ...prev ];
			const target = index + direction;
			if ( target < 0 || target >= next.length ) {
				return prev;
			}
			[ next[ index ], next[ target ] ] = [
				next[ target ],
				next[ index ],
			];
			return next;
		} );
	};

	const onAdd = () => {
		setEntries( ( prev ) => [ ...prev, makeBlankEntry() ] );
	};

	const save = async () => {
		setSaving( true );
		setFlash( null );
		try {
			const response = await apiFetch( {
				path: `/${ bootstrap.restNamespace }${ bootstrap.restBase }`,
				method: 'PUT',
				data: { entries },
				headers: { 'X-WP-Nonce': bootstrap.restNonce },
			} );
			// The PUT response is the re-sanitised, persisted shape — adopt it
			// so the UI reflects exactly what the server kept (dropped entries,
			// clamped sections).
			setEntries(
				Array.isArray( response.entries ) ? response.entries : []
			);
			setFlash( {
				type: 'success',
				message: __( 'Editorial entries saved.', 'mokhai-agent-readiness-kit' ),
			} );
		} catch ( err ) {
			setFlash( {
				type: 'error',
				message:
					err.message || __( 'Save failed.', 'mokhai-agent-readiness-kit' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div aria-label={ __( 'Editorial entries', 'mokhai-agent-readiness-kit' ) }>
			<p className="description">
				{ __(
					'Hand-curated entries published in /llms.txt alongside the auto-listed posts. Each entry has a title, URL, optional description, and a section heading.',
					'mokhai-agent-readiness-kit'
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

			{ entries.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No editorial entries yet. Add hand-picked URLs to surface them in /llms.txt alongside auto-listed posts.',
						'mokhai-agent-readiness-kit'
					) }
				</Notice>
			) }

			{ entries.map( ( entry, index ) => (
				<EntryRow
					key={ index }
					entry={ entry }
					index={ index }
					total={ entries.length }
					sections={ sections }
					onChange={ onChange }
					onRemove={ onRemove }
					onMove={ onMove }
				/>
			) ) }

			<div className="agentready-button-row">
				<Button
					type="button"
					variant="secondary"
					onClick={ onAdd }
					disabled={ saving }
				>
					{ __( 'Add entry', 'mokhai-agent-readiness-kit' ) }
				</Button>
				<Button
					type="button"
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'mokhai-agent-readiness-kit' )
						: __( 'Save editorial entries', 'mokhai-agent-readiness-kit' ) }
				</Button>
				{ saving && <Spinner /> }
			</div>
		</div>
	);
}
