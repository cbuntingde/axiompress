/* Disposable first-party integration only; not mounted by the production/native HTTP router. */
( () => {
	'use strict';
	const { createElement: h, Fragment, useState, useEffect } = wp.element;
	const {
		BlockEditorProvider,
		BlockList,
		WritingFlow,
		ObserveTyping,
		RichText,
		RichTextShortcut,
		RichTextToolbarButton,
		useBlockProps,
	} = wp.blockEditor;
	const { registerBlockType, createBlock } = wp.blocks;
	wp.apiFetch.setFetchHandler( () => {
		throw new Error(
			'WordPress transport is disabled in the native proof.'
		);
	} );
	const allowed = [ 'core/paragraph', 'core/heading' ];
	let initialized = false,
		mountedOnce = false;
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
		update;
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
				h( RichText, {
					...useBlockProps(),
					tagName: heading ? 'h' + attributes.level : 'p',
					value: attributes.content,
					allowedFormats: [ 'core/bold', 'core/italic' ],
					placeholder: heading ? 'Heading…' : 'Start writing…',
					onChange: ( content ) => setAttributes( { content } ),
				} ),
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
			'paragraph',
			'heading',
			'title',
		] )
			$( id ).disabled =
				busy ||
				( ! initialized && id !== 'renew' ) ||
				( !! pending && ! [ 'save', 'renew' ].includes( id ) );
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
	function Editor() {
		const [ value, setValue ] = useState( blocks );
		useEffect( () => {
			mounted();
		}, [] );
		update = ( next ) => {
			blocks = next;
			setValue( next );
		};
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
			h( ObserveTyping, null, h( WritingFlow, null, h( BlockList ) ) )
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
	}
	$( 'title' ).addEventListener( 'input', () => {
		dirty = true;
		sync();
	} );
	for ( const [ id, name ] of [
		[ 'paragraph', 'core/paragraph' ],
		[ 'heading', 'core/heading' ],
	] )
		$( id ).addEventListener( 'click', () => {
			if ( pending || blocks.length >= 64 ) return;
			const next = [ ...blocks, createBlock( name ) ];
			update( next );
			changed( next );
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
		if ( intent.kind === 'publish' ) status( 'Saved revision published.' );
		else {
			await reload();
			status( 'Draft saved with native API.' );
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
	$( 'publish' ).addEventListener( 'click', () =>
		task( async () => {
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
		} )
	);
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
		if ( ! mountedOnce ) {
			mountedOnce = true;
			wp.element.createRoot( $( 'editor' ) ).render( h( Editor ) );
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
