/* Disposable first-party integration only; not mounted by the production/native HTTP router. */
( () => {
	'use strict';
	const { createElement: h, Fragment, useState, useEffect } = wp.element;
	const {
		BlockEditorProvider,
		BlockInspector,
		BlockList,
		InspectorControls,
		WritingFlow,
		ObserveTyping,
		RichText,
		RichTextShortcut,
		RichTextToolbarButton,
		useBlockProps,
	} = wp.blockEditor;
	// The pinned build exports the outline only under its experimental alias.
	const ListView =
		wp.blockEditor.ListView || wp.blockEditor.__experimentalListView;
	if ( ! ListView )
		throw new Error(
			'Block outline component unavailable in pinned build.'
		);
	const {
		DropdownMenu,
		MenuItem,
		PanelBody,
		RangeControl,
		SelectControl,
		TabPanel,
		TextControl,
	} = wp.components;
	const { registerBlockType, createBlock } = wp.blocks;
	wp.apiFetch.setFetchHandler( () => {
		throw new Error(
			'WordPress transport is disabled in the native proof.'
		);
	} );
	const allowed = [ 'core/paragraph', 'core/heading', 'axiom/image' ];
	let initialized = false,
		mountedOnce = false;
	let library = [];
	let mounted;
	const ready = new Promise( ( resolve ) => {
		mounted = resolve;
	} );
	let blocks = [],
		article = null,
		actor = null,
		pending = null,
		busy = false,
		dirty = false,
		update,
		refreshShell = null,
		sidebarOpen = true,
		listOpen = false,
		termsList = [];
	const $ = ( id ) => document.getElementById( id );
	const status = ( text ) => {
		$( 'status' ).textContent = text;
	};
	function explain( error ) {
		if ( error.message === 'unauthenticated' )
			return (
				'Session expired or missing. Your edits are retained in this tab. ' +
				'Open sign-in in another tab, then choose Refresh session.' +
				( pending ? ' Then use Retry last write.' : '' )
			);
		if ( error.message === 'conflict' )
			return (
				'This article changed. Your edits are retained. Copy them before using ' +
				'Reload saved draft; reloading discards unsaved edits.'
			);
		if ( pending )
			return (
				'Write could not be confirmed. Your edits are retained and editing is locked. ' +
				'Use Retry last write; do not reload this tab.'
			);
		if ( error.message === 'forbidden' )
			return 'Permission denied. Your edits are retained. Ask the site owner to review your access.';
		if (
			error.status >= 500 ||
			error instanceof TypeError ||
			error.name === 'TimeoutError'
		)
			return 'Service unavailable. Any unsaved edits remain in this tab. Check the service, then choose Refresh session.';
		return error.message;
	}
	const identity = () =>
		Array.from( crypto.getRandomValues( new Uint8Array( 32 ) ), ( n ) =>
			n.toString( 16 ).padStart( 2, '0' )
		).join( '' );
	for ( const [ name, tag, shortcut ] of [
		[ 'bold', 'strong', 'b' ],
		[ 'italic', 'em', 'i' ],
	] ) {
		wp.richText.registerFormatType( 'core/' + name, {
			title: name,
			tagName: tag,
			className: null,
			edit: ( { value, onChange, isActive } ) => {
				const toggle = () =>
					onChange(
						wp.richText.toggleFormat( value, {
							type: 'core/' + name,
						} )
					);
				return h(
					Fragment,
					null,
					h( RichTextShortcut, {
						type: 'primary',
						character: shortcut,
						onUse: toggle,
					} ),
					h( RichTextToolbarButton, {
						icon: name === 'bold' ? 'editor-bold' : 'editor-italic',
						title: name,
						onClick: toggle,
						isActive,
					} )
				);
			},
		} );
	}
	registerBlockType( 'axiom/image', {
		apiVersion: 3,
		title: 'Image',
		category: 'media',
		attributes: {
			media: { type: 'string', default: '' },
			alt: { type: 'string', default: '' },
		},
		supports: {
			html: false,
			customClassName: false,
			className: false,
			reusable: false,
		},
		edit: ( { attributes, setAttributes } ) => {
			const selected = library.find(
				( image ) => image.id === attributes.media
			);
			const options = [
				{ value: '', label: 'Select a published image' },
				...library.map( ( image ) => ( {
					value: image.id,
					label: image.name,
				} ) ),
			];
			return h(
				Fragment,
				null,
				h(
					InspectorControls,
					null,
					h(
						PanelBody,
						{ title: 'Settings', initialOpen: true },
						h( SelectControl, {
							label: 'Published image',
							value: attributes.media,
							options,
							onChange: ( media ) =>
								setAttributes( { media } ),
						} ),
						h( TextControl, {
							label: 'Alternative text',
							value: attributes.alt,
							maxLength: 512,
							placeholder: 'Describe this image',
							onChange: ( alt ) =>
								setAttributes( { alt } ),
						} )
					)
				),
				h(
					'div',
					{ ...useBlockProps() },
					h(
						'label',
						null,
						'Published image',
						h(
							'select',
							{
								'aria-label': 'Published image',
								value: attributes.media,
								onChange: ( event ) =>
									setAttributes( {
										media: event.target.value,
									} ),
							},
							options.map( ( option ) =>
								h(
									'option',
									{
										key: option.value,
										value: option.value,
									},
									option.label
								)
							)
						)
					),
					h( 'input', {
						'aria-label': 'Alternative text',
						value: attributes.alt,
						maxLength: 512,
						placeholder: 'Describe this image',
						onChange: ( event ) =>
							setAttributes( {
								alt: event.target.value,
							} ),
					} ),
					selected &&
						h( 'img', {
							src:
								'/v1/media/' +
								attributes.media +
								'/file',
							alt: attributes.alt,
						} )
				)
			);
		},
		save: () => null,
	} );
	for ( const heading of [ false, true ] ) {
		registerBlockType( heading ? 'core/heading' : 'core/paragraph', {
			apiVersion: 3,
			title: heading ? 'Heading' : 'Paragraph',
			category: 'text',
			attributes: {
				content: { type: 'string', default: '' },
				level: { type: 'number', default: 2 },
			},
			supports: {
				html: false,
				customClassName: false,
				className: false,
				reusable: false,
			},
			edit: ( { attributes, setAttributes } ) =>
				h(
					Fragment,
					null,
					heading &&
						h(
							InspectorControls,
							null,
							h(
								PanelBody,
								{ title: 'Settings', initialOpen: true },
								h( RangeControl, {
									label: 'Level',
									value: attributes.level,
									min: 2,
									max: 4,
									onChange: ( level ) => {
										if ( level >= 2 && level <= 4 )
											setAttributes( { level } );
									},
								} )
							)
						),
					h( RichText, {
						...useBlockProps(),
						tagName: heading ? 'h' + attributes.level : 'p',
						value: attributes.content,
						allowedFormats: [ 'core/bold', 'core/italic' ],
						placeholder: heading ? 'Heading…' : 'Start writing…',
						onChange: ( content ) =>
							setAttributes( { content } ),
					} )
				),
			save: ( { attributes } ) =>
				h( RichText.Content, {
					tagName: heading ? 'h' + attributes.level : 'p',
					value: attributes.content,
				} ),
		} );
	}
	function sync() {
		for ( const id of [
			'save',
			'publish',
			'reload',
			'renew',
			'title',
		] )
			$( id ).disabled =
				busy ||
				( ! initialized && id !== 'renew' ) ||
				( !! pending && ! [ 'save', 'renew' ].includes( id ) );
		$( 'list-view' ).setAttribute(
			'aria-expanded',
			String( listOpen )
		);
		$( 'sidebar-toggle' ).setAttribute(
			'aria-expanded',
			String( sidebarOpen )
		);
		$( 'editor' ).inert = busy || !! pending || ! initialized;
		$( 'publish' ).disabled =
			busy ||
			dirty ||
			!! pending ||
			! article ||
			article.revision === article.published_revision;
		$( 'reload' ).disabled = busy || !! pending || ! article;
		$( 'save' ).textContent = pending ? 'Retry last write' : 'Save draft';
	}
	function changed( next ) {
		blocks = next;
		dirty = true;
		sync();
	}
	function statusText() {
		if ( ! article?.published_revision ) return 'Draft';
		if ( article.revision === article.published_revision )
			return 'Published';
		return 'Published · new draft';
	}
	function DocumentPanel() {
		const selected = new Set( article?.terms || [] );
		const [ checked, setChecked ] = useState( selected );
		const toggle = ( id ) => {
			const next = new Set( checked );
			if ( next.has( id ) ) next.delete( id );
			else next.add( id );
			setChecked( next );
		};
		return h(
			Fragment,
			null,
			h(
				PanelBody,
				{ title: 'Status', initialOpen: true },
				h( 'p', null, 'Status: ' + statusText() ),
				h(
					'p',
					null,
					article?.scheduled_at
						? 'Scheduled for ' +
								new Date(
									article.scheduled_at
								).toLocaleString()
						: 'No publication scheduled.'
				),
				h(
					'div',
					{ className: 'document-actions' },
					h(
						'button',
						{
							type: 'button',
							onClick: () => task( doPublish ),
						},
						article?.published_revision
							? 'Publish changes'
							: 'Publish'
					),
					article?.published_revision &&
						h(
							'button',
							{
								type: 'button',
								onClick: () => task( doUnpublish ),
							},
							'Unpublish'
						)
				)
			),
			h(
				PanelBody,
				{ title: 'Categories and tags', initialOpen: true },
				termsList.length
					? termsList.map( ( term ) =>
							h(
								'label',
								{ key: term.id, className: 'term-option' },
								h( 'input', {
									type: 'checkbox',
									checked: checked.has( term.id ),
									onChange: () => toggle( term.id ),
								} ),
								term.name
							)
						)
					: h( 'p', null, 'No categories or tags.' ),
				h(
					'button',
					{
						type: 'button',
						onClick: () =>
							task( () =>
								applyTerms( Array.from( checked ) )
							),
					},
					'Apply categories and tags'
				)
			)
		);
	}
	function Editor() {
		const [ value, setValue ] = useState( blocks );
		const [ , setShellVersion ] = useState( 0 );
		useEffect( () => {
			mounted();
		}, [] );
		update = ( next ) => {
			blocks = next;
			setValue( next );
		};
		refreshShell = () =>
			setShellVersion( ( version ) => version + 1 );
		const onChange = ( next ) => {
			setValue( next );
			changed( next );
		};
		return h(
			BlockEditorProvider,
			{
				value,
				onInput: onChange,
				onChange,
				settings: {
					allowedBlockTypes: allowed,
					hasFixedToolbar: true,
					canLockBlocks: false,
					disableCustomColors: true,
					disableCustomFontSizes: true,
					__experimentalCanUserUseUnfilteredHTML: false,
				},
			},
			h(
				'div',
				{ className: 'editor-layout' },
				h(
					'div',
					{ className: 'editor-canvas' },
					listOpen &&
						h(
							'div',
							{
								className: 'editor-list',
								'aria-label': 'Block outline',
							},
							h( ListView )
						),
					h(
						ObserveTyping,
						null,
						h( WritingFlow, null, h( BlockList ) )
					)
				),
				sidebarOpen &&
					h(
						'aside',
						{
							className: 'editor-sidebar',
							'aria-label': 'Editor settings',
						},
						h(
							TabPanel,
							{
								tabs: [
									{
										name: 'document',
										title: 'Document',
									},
									{ name: 'block', title: 'Block' },
								],
								initialTabName: 'document',
							},
							( tab ) =>
								tab.name === 'document'
									? h( DocumentPanel, {
											key:
												article?.revision ||
												'new',
										} )
									: h( BlockInspector )
						)
					)
			)
		);
	}
	function addBlock( name ) {
		if ( pending || busy || blocks.length >= 64 ) return;
		const next = [ ...blocks, createBlock( name ) ];
		update( next );
		changed( next );
	}
	function Inserter() {
		const items = [
			[ 'Paragraph', 'core/paragraph' ],
			[ 'Heading', 'core/heading' ],
			[ 'Image', 'axiom/image' ],
		];
		return h(
			DropdownMenu,
			{ text: 'Add block', label: 'Toggle block inserter' },
			( { onClose } ) =>
				items.map( ( [ label, name ] ) =>
					h(
						MenuItem,
						{
							key: name,
							onClick: () => {
								addBlock( name );
								onClose();
							},
						},
						label
					)
				)
		);
	}
	function quote( text ) {
		return JSON.stringify( text )
			.replace( /\\u2028|\\u2029/g, ( sequence ) =>
				String.fromCharCode( parseInt( sequence.slice( 2 ), 16 ) )
			)
			.replace(
				/[<>]/g,
				( character ) =>
					'\\u' +
					character
						.charCodeAt( 0 )
						.toString( 16 )
						.toUpperCase()
						.padStart( 4, '0' )
			);
	}
	function serialize() {
		if ( blocks.length > 64 ) throw new Error( 'At most 64 blocks.' );
		return blocks
			.map( ( block ) => {
				if (
					! allowed.includes( block.name ) ||
					block.innerBlocks.length
				)
					throw new Error( 'Unsupported block.' );
				if ( block.name === 'axiom/image' ) {
					if (
						! /^[a-f0-9]{32}$/.test( block.attributes.media ) ||
						typeof block.attributes.alt !== 'string' ||
						new TextEncoder().encode( block.attributes.alt )
							.length > 512
					)
						throw new Error(
							'Image block needs a prepared media item.'
						);
					return (
						'<!-- wp:axiom/image {"media":"' +
						block.attributes.media +
						'","alt":' +
						quote( block.attributes.alt ) +
						'} /-->'
					);
				}
				const heading = block.name === 'core/heading';
				const tag = heading ? 'h' + block.attributes.level : 'p';
				const attributes = heading
					? '{"level":' +
					  block.attributes.level +
					  ',"axiomVersion":1}'
					: '{"axiomVersion":1}';
				const name = heading ? 'heading' : 'paragraph';
				return (
					'<!-- wp:' +
					name +
					' ' +
					attributes +
					' -->\n<' +
					tag +
					'>' +
					block.attributes.content +
					'</' +
					tag +
					'>\n<!-- /wp:' +
					name +
					' -->'
				);
			} )
			.join( '\n\n' );
	}
	function loadDocument( source ) {
		const parsed = wp.blockSerializationDefaultParser.parse( source );
		const next = [];
		for ( const block of parsed ) {
			if ( block.blockName === null && /^\s*$/.test( block.innerHTML ) )
				continue;
			if ( block.blockName === 'axiom/image' ) {
				const names = Object.keys( block.attrs )
					.sort()
					.join( ',' );
				if (
					names !== 'alt,media' ||
					block.innerBlocks.length ||
					block.innerHTML !== '' ||
					! /^[a-f0-9]{32}$/.test( block.attrs.media ) ||
					typeof block.attrs.alt !== 'string' ||
					new TextEncoder().encode( block.attrs.alt ).length >
						512
				)
					throw new Error(
						'Unsupported document retained on the server; this proof cannot edit it.'
					);
				next.push(
					createBlock( 'axiom/image', {
						media: block.attrs.media,
						alt: block.attrs.alt,
					} )
				);
				continue;
			}
			const heading = block.blockName === 'core/heading';
			const keys = Object.keys( block.attrs ).sort().join( ',' );
			if (
				! allowed.includes( block.blockName ) ||
				block.innerBlocks.length ||
				block.attrs.axiomVersion !== 1 ||
				keys !== ( heading ? 'axiomVersion,level' : 'axiomVersion' ) ||
				( heading && ! [ 2, 3, 4 ].includes( block.attrs.level ) )
			)
				throw new Error(
					'Unsupported document retained on the server; this proof cannot edit it.'
				);
			const tag = heading ? 'h' + block.attrs.level : 'p';
			const match = block.innerHTML.match(
				new RegExp( '^\\n<' + tag + '>([\\s\\S]*)</' + tag + '>\\n$' )
			);
			if (
				! match ||
				! /^(?:[^<&]|&amp;|&lt;|&gt;|<\/?(?:strong|em)>|<br>)*$/.test(
					match[ 1 ]
				)
			)
				throw new Error(
					'Unsupported rich text; document was not replaced.'
				);
			next.push(
				createBlock( block.blockName, {
					content: match[ 1 ],
					...( heading ? { level: block.attrs.level } : {} ),
				} )
			);
		}
		return next;
	}
	async function request( path, method = 'GET', data, key ) {
		const headers = { Accept: 'application/json' };
		if ( data !== undefined )
			headers[ 'Content-Type' ] = 'application/json';
		if ( actor ) headers[ 'X-CSRF-Token' ] = actor.csrf_token;
		if ( key ) headers[ 'Idempotency-Key' ] = key;
		const response = await fetch( path, {
			method,
			headers,
			credentials: 'same-origin',
			cache: 'no-store',
			body: data === undefined ? undefined : JSON.stringify( data ),
			signal: AbortSignal.timeout( 15000 ),
		} );
		const result = await response.json();
		if ( ! response.ok )
			throw Object.assign( new Error( result.error || 'unavailable' ), {
				status: response.status,
			} );
		return result;
	}
	async function task( action ) {
		if ( busy ) return;
		const focus = document.activeElement;
		busy = true;
		sync();
		try {
			await action();
		} catch ( error ) {
			status( explain( error ) );
		} finally {
			busy = false;
			sync();
			if ( focus?.isConnected && ! focus.disabled ) focus.focus();
		}
	}
	async function reload() {
		const result = await request( '/v1/articles/' + article.id );
		const next = loadDocument( result.text );
		article = result;
		update( next );
		$( 'title' ).value = result.title;
		dirty = false;
		status( 'Saved revision loaded.' );
		if ( refreshShell ) refreshShell();
	}
	$( 'title' ).addEventListener( 'input', () => {
		dirty = true;
		sync();
	} );
	async function doPublish() {
		if ( dirty || pending || ! article )
			throw new Error( 'Save the current draft first.' );
		pending = {
			kind: 'publish',
			path: '/v1/articles/' + article.id + '/publish',
			method: 'POST',
			data: {
				expected_revision: article.revision,
				expected_version: article.version,
			},
			key: identity(),
		};
		await commit( pending );
	}
	async function doUnpublish() {
		if ( dirty || pending || ! article?.published_revision )
			throw new Error( 'Save the current draft first.' );
		pending = {
			kind: 'unpublish',
			path: '/v1/articles/' + article.id + '/unpublish',
			method: 'POST',
			data: {
				expected_revision: article.revision,
				expected_version: article.version,
			},
			key: identity(),
		};
		await commit( pending );
	}
	async function applyTerms( ids ) {
		if ( dirty || pending || ! article )
			throw new Error( 'Save the current draft first.' );
		pending = {
			kind: 'terms',
			path: '/v1/platform/relationships.write',
			method: 'POST',
			data: {
				expected_revision: article.revision,
				expected_version: article.version,
				id: article.id,
				terms: JSON.stringify( ids ),
			},
			key: identity(),
		};
		await commit( pending );
	}
	$( 'list-view' ).addEventListener( 'click', () => {
		listOpen = ! listOpen;
		$( 'list-view' ).setAttribute(
			'aria-expanded',
			String( listOpen )
		);
		if ( refreshShell ) refreshShell();
	} );
	$( 'sidebar-toggle' ).addEventListener( 'click', () => {
		sidebarOpen = ! sidebarOpen;
		$( 'sidebar-toggle' ).setAttribute(
			'aria-expanded',
			String( sidebarOpen )
		);
		if ( refreshShell ) refreshShell();
	} );
	async function commit( intent ) {
		let result;
		try {
			result = await request(
				intent.path,
				intent.method,
				intent.data,
				intent.key
			);
		} catch ( error ) {
			if (
				error.status >= 400 &&
				error.status < 500 &&
				! ( error.status === 401 && intent.uncertain )
			)
				pending = null;
			if ( ! error.status || error.status >= 500 )
				intent.uncertain = true;
			throw error;
		}
		pending = null;
		article = result;
		dirty = false;
		history.replaceState( null, '', '/__editor-proof?id=' + article.id );
		if ( intent.kind === 'publish' ) {
			status( 'Saved revision published.' );
			if ( refreshShell ) refreshShell();
		} else if ( intent.kind === 'unpublish' ) {
			status( 'Saved revision unpublished.' );
			if ( refreshShell ) refreshShell();
		} else {
			await reload();
			status(
				intent.kind === 'terms'
					? 'Categories and tags saved.'
					: 'Draft saved with native API.'
			);
			if ( refreshShell ) refreshShell();
		}
	}
	$( 'save' ).addEventListener( 'click', () =>
		task( async () => {
			if ( ! pending ) {
				const data = { title: $( 'title' ).value, text: serialize() };
				if (
					! data.title.trim() ||
					new TextEncoder().encode( data.title ).length > 4096 ||
					new TextEncoder().encode( data.text ).length > 131072
				)
					throw new Error( 'Title/document byte limit.' );
				if ( article )
					Object.assign( data, {
						expected_revision: article.revision,
						expected_version: article.version,
					} );
				pending = {
					kind: 'save',
					path: '/v1/articles' + ( article ? '/' + article.id : '' ),
					method: article ? 'PUT' : 'POST',
					data,
					key: identity(),
				};
			}
			await commit( pending );
		} )
	);
	$( 'publish' ).addEventListener( 'click', () => task( doPublish ) );
	$( 'reload' ).addEventListener( 'click', () =>
		task( async () => {
			if (
				dirty &&
				! confirm(
					'Discard unsaved edits and reload the saved revision?'
				)
			)
				return;
			await reload();
		} )
	);
	$( 'renew' ).addEventListener( 'click', () =>
		task( async () => {
			const fresh = await request( '/v1/session' );
			if (
				actor &&
				( fresh.user_id !== actor.user_id ||
					fresh.site_id !== actor.site_id )
			)
				throw new Error(
					'Use the original account to resume this experiment.'
				);
			actor = fresh;
			if ( ! initialized ) {
				await initialize();
				return;
			}
			status( 'Session refreshed; editor content retained.' );
		} )
	);
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( dirty || pending ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	async function initialize() {
		actor = await request( '/v1/session' );
		try {
			const listed = await request( '/v1/platform/media.list', 'POST', {
				cursor: '',
			} );
			library = listed.items.filter(
				( image ) => image.status === 'ready' && image.public
			);
		} catch ( error ) {
			library = [];
		}
		try {
			const terms = await request(
				'/v1/platform/terms.list',
				'POST',
				{}
			);
			termsList = terms.items.filter( ( term ) => ! term.archived );
		} catch ( error ) {
			termsList = [];
		}
		if ( ! mountedOnce ) {
			mountedOnce = true;
			wp.element.createRoot( $( 'editor' ) ).render( h( Editor ) );
			wp.element
				.createRoot( $( 'inserter' ) )
				.render( h( Inserter ) );
		}
		await ready;
		const id = new URL( location.href ).searchParams.get( 'id' );
		if ( id ) {
			if ( ! /^[1-9][0-9]{0,17}$/.test( id ) )
				throw new Error( 'Invalid article ID.' );
			article = { id };
			await reload();
		} else {
			update( [ createBlock( 'core/paragraph' ) ] );
			status( 'Ready — native session verified.' );
		}
		initialized = true;
	}
	task( initialize );
} )();
