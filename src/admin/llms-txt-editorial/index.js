/**
 * AgentReady — LLMs Index editorial entries admin UI (#7 Phase C / AgDR-0025).
 *
 * Repeater editor for the `agentready_llms_txt_editorial` option. Mounts
 * underneath the Context Profile editor on Tools → Context. Submits via the
 * standard options.php Settings API flow — the server-side sanitise callback
 * in Editorial_Settings is the source of truth, this UI is presentation
 * only.
 */

import { createRoot, useState, useMemo } from '@wordpress/element';
import {
	Panel,
	PanelBody,
	Button,
	TextControl,
	SelectControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const MOUNT_SELECTOR = '#agentready-llms-txt-editorial-root';
const BOOTSTRAP_KEY = 'agentreadyLlmsTxtEditorial';

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

function makeBlankEntry() {
	return {
		title: '',
		url: '',
		description: '',
		section: 'Featured',
		section_label: '',
	};
}

function EntryRow( { entry, index, total, onChange, onRemove, onMove, sections } ) {
	const isCustom = entry.section === 'Custom';

	return (
		<div
			className="agentready-llms-editorial__row"
			style={ {
				border: '1px solid #ccd0d4',
				padding: '12px',
				marginBottom: '8px',
				borderRadius: '4px',
				background: '#fff',
			} }
		>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: '8px',
				} }
			>
				<strong>
					{ sprintf(
						/* translators: %d: entry index */
						__( 'Entry %d', 'agentready' ),
						index + 1
					) }
				</strong>
				<div style={ { display: 'flex', gap: '4px' } }>
					<Button
						type="button"
						variant="tertiary"
						disabled={ index === 0 }
						onClick={ () => onMove( index, -1 ) }
						aria-label={ __( 'Move entry up', 'agentready' ) }
					>
						{ '↑' }
					</Button>
					<Button
						type="button"
						variant="tertiary"
						disabled={ index === total - 1 }
						onClick={ () => onMove( index, 1 ) }
						aria-label={ __( 'Move entry down', 'agentready' ) }
					>
						{ '↓' }
					</Button>
					<Button
						type="button"
						isDestructive
						variant="tertiary"
						onClick={ () => onRemove( index ) }
						aria-label={ __( 'Remove entry', 'agentready' ) }
					>
						{ __( 'Remove', 'agentready' ) }
					</Button>
				</div>
			</div>

			<TextControl
				label={ __( 'Title', 'agentready' ) }
				value={ entry.title }
				onChange={ ( value ) => onChange( index, 'title', value ) }
				required
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ __( 'URL', 'agentready' ) }
				value={ entry.url }
				onChange={ ( value ) => onChange( index, 'url', value ) }
				type="url"
				placeholder="https://"
				required
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				help={ __(
					'External URLs allowed. Schemes: http, https, mailto.',
					'agentready'
				) }
			/>

			<TextControl
				label={ __( 'Description (optional)', 'agentready' ) }
				value={ entry.description }
				onChange={ ( value ) => onChange( index, 'description', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<SelectControl
				label={ __( 'Section', 'agentready' ) }
				value={ entry.section }
				options={ sections.map( ( s ) => ( { label: s, value: s } ) ) }
				onChange={ ( value ) => onChange( index, 'section', value ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ isCustom && (
				<TextControl
					label={ __( 'Custom section heading', 'agentready' ) }
					value={ entry.section_label }
					onChange={ ( value ) => onChange( index, 'section_label', value ) }
					required
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					help={ __(
						'Heading rendered in /llms.txt when section is Custom.',
						'agentready'
					) }
				/>
			) }
		</div>
	);
}

function EditorialApp( { bootstrap } ) {
	const initialEntries = useMemo(
		() => ( Array.isArray( bootstrap.entries ) ? bootstrap.entries : [] ),
		[ bootstrap.entries ]
	);
	const [ entries, setEntries ] = useState( initialEntries );

	const referer =
		typeof window !== 'undefined'
			? window.location.pathname + window.location.search
			: '';

	const onChange = ( index, field, value ) => {
		setEntries( ( prev ) =>
			prev.map( ( e, i ) => ( i === index ? { ...e, [ field ]: value } : e ) )
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
			[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
			return next;
		} );
	};

	const onAdd = () => {
		setEntries( ( prev ) => [ ...prev, makeBlankEntry() ] );
	};

	return (
		<form
			action={ bootstrap.options_url }
			method="post"
			aria-label={ __( 'AgentReady LLMs Index editorial entries form', 'agentready' ) }
		>
			<input type="hidden" name="option_page" value={ bootstrap.option_group } />
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="_wpnonce" value={ bootstrap.nonce } />
			<input type="hidden" name="_wp_http_referer" value={ referer } />

			<input
				type="hidden"
				name={ `${ bootstrap.option_key }[schema_version]` }
				value="1"
			/>

			{ entries.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No editorial entries yet. Add hand-picked URLs to surface them in /llms.txt alongside auto-listed posts.',
						'agentready'
					) }
				</Notice>
			) }

			<Panel>
				<PanelBody opened>
					{ entries.map( ( entry, index ) => (
						<EntryRow
							key={ index }
							entry={ entry }
							index={ index }
							total={ entries.length }
							sections={ bootstrap.sections || [ 'Featured', 'Resources', 'Custom' ] }
							onChange={ onChange }
							onRemove={ onRemove }
							onMove={ onMove }
						/>
					) ) }

					{ /* Hidden inputs encoding the entries — submitted to options.php on save. */ }
					{ entries.map( ( entry, index ) => (
						<div key={ `hidden-${ index }` } aria-hidden="true">
							<input
								type="hidden"
								name={ `${ bootstrap.option_key }[entries][${ index }][title]` }
								value={ entry.title }
							/>
							<input
								type="hidden"
								name={ `${ bootstrap.option_key }[entries][${ index }][url]` }
								value={ entry.url }
							/>
							<input
								type="hidden"
								name={ `${ bootstrap.option_key }[entries][${ index }][description]` }
								value={ entry.description }
							/>
							<input
								type="hidden"
								name={ `${ bootstrap.option_key }[entries][${ index }][section]` }
								value={ entry.section }
							/>
							{ entry.section === 'Custom' && (
								<input
									type="hidden"
									name={ `${ bootstrap.option_key }[entries][${ index }][section_label]` }
									value={ entry.section_label || '' }
								/>
							) }
						</div>
					) ) }

					<div style={ { marginTop: '12px', display: 'flex', gap: '8px' } }>
						<Button type="button" variant="secondary" onClick={ onAdd }>
							{ __( 'Add entry', 'agentready' ) }
						</Button>
						<Button type="submit" variant="primary">
							{ __( 'Save editorial entries', 'agentready' ) }
						</Button>
					</div>
				</PanelBody>
			</Panel>
		</form>
	);
}

const bootstrap = readBootstrap();
const mount = document.querySelector( MOUNT_SELECTOR );

if ( mount && bootstrap ) {
	const root = createRoot( mount );
	root.render( <EditorialApp bootstrap={ bootstrap } /> );
}
