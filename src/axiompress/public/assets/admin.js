/* Core-owned workspace. Session credentials stay in HttpOnly cookies; CSRF stays in memory. */
( () => {
	'use strict';
	const $ = ( id ) => document.getElementById( id );
	const state = {
		actor: null,
		article: null,
		blocks: [],
		terms: [],
		media: [],
		settings: null,
		dirty: false,
		busy: false,
		pending: null,
		cursor: '',
		view: 'articles',
		reauthenticate: false,
		focusTarget: null,
	};
	const bytes = ( s ) => new TextEncoder().encode( s ).length;
	const key = () =>
		Array.from( crypto.getRandomValues( new Uint8Array( 32 ) ), ( n ) =>
			n.toString( 16 ).padStart( 2, '0' )
		).join( '' );
	const node = ( tag, text, className ) => {
		const result = document.createElement( tag );
		if ( text !== undefined ) result.textContent = text;
		if ( className ) result.className = className;
		return result;
	};
	const button = ( text, action, className ) => {
		const result = node( 'button', text, className );
		result.type = 'button';
		result.addEventListener( 'click', () => task( action ) );
		return result;
	};
	function notice( text, error = false ) {
		$( 'notice' ).textContent = text;
		$( 'notice' ).hidden = ! text;
		$( 'notice' ).classList.toggle( 'warning', error );
	}
	function message( error ) {
		return (
			{
				conflict:
					'This item changed. Reopen it to review the latest version before saving.',
				unauthenticated:
					'Your session ended. Sign in again; keep this tab open to retain your draft.',
				forbidden: 'Your account cannot perform this action.',
				quota_exceeded:
					'This site has reached a resource limit. Contact the site operator.',
				invalid_credentials:
					'The username or password was not accepted.',
				login_throttled:
					'Too many sign-in attempts. Try again in 15 minutes.',
				unsupported_document:
					'This article contains a block the editor cannot safely edit.',
				not_found: 'This item is no longer available.',
				invalid_request: 'Check the entered values and try again.',
			}[ error ] ||
			'The service could not confirm this action. Try again.'
		);
	}
	async function request( path, method = 'GET', data, identity ) {
		const headers = { Accept: 'application/json' };
		if ( data !== undefined )
			headers[ 'Content-Type' ] = 'application/json';
		if ( state.actor ) headers[ 'X-CSRF-Token' ] = state.actor.csrf_token;
		if ( identity ) headers[ 'Idempotency-Key' ] = identity;
		const response = await fetch( path, {
			method,
			headers,
			credentials: 'same-origin',
			cache: 'no-store',
			body: data === undefined ? undefined : JSON.stringify( data ),
			signal: AbortSignal.timeout( 20000 ),
		} );
		if ( response.status >= 500 )
			throw new Error( message( 'unavailable' ) );
		const value = await response.json();
		if (
			response.status === 401 &&
			state.actor &&
			path !== '/v1/session/login'
		) {
			state.reauthenticate = true;
			$( 'workspace' ).hidden = true;
			$( 'login' ).hidden = false;
			$( 'login-message' ).textContent = message( 'unauthenticated' );
		}
		if ( ! response.ok )
			throw Object.assign( new Error( message( value.error ) ), {
				definitive: true,
				status: response.status,
			} );
		return value;
	}
	const platform = ( operation, data = {} ) =>
		request( '/v1/platform/' + operation, 'POST', data );
	async function mutate( path, method, data, after ) {
		if ( state.pending )
			throw new Error(
				'Resolve the previous action with Retry action first.'
			);
		state.pending = { path, method, data, identity: key(), after };
		return retry();
	}
	async function retry() {
		const pending = state.pending;
		if ( ! pending ) return;
		let result;
		try {
			result = await request(
				pending.path,
				pending.method,
				pending.data,
				pending.identity
			);
		} catch ( error ) {
			if (
				error.definitive &&
				! ( error.status === 401 && pending.uncertain )
			)
				state.pending = null;
			if ( ! error.definitive ) pending.uncertain = true;
			$( 'retry-panel' ).hidden = ! state.pending;
			throw error;
		}
		// A confirmed mutation is never repeated because a subsequent read failed.
		state.pending = null;
		$( 'retry-panel' ).hidden = true;
		await pending.after( result );
	}
	const writePlatform = ( operation, data, after ) =>
		mutate( '/v1/platform/' + operation, 'POST', data, after );
	async function task( action ) {
		if ( state.busy ) return;
		const originalFocus = document.activeElement;
		state.focusTarget = null;
		state.busy = true;
		document.body.setAttribute( 'aria-busy', 'true' );
		sync();
		try {
			await action();
		} catch ( error ) {
			notice( error.message, true );
		} finally {
			state.busy = false;
			document.body.removeAttribute( 'aria-busy' );
			sync();
			const target = state.reauthenticate
				? $( 'password' )
				: state.focusTarget ||
				  ( state.pending ? $( 'retry-action' ) : originalFocus );
			if (
				target?.isConnected &&
				! target.disabled &&
				! target.closest( '[hidden]' )
			)
				target.focus();
			else if ( ! $( 'workspace' ).hidden ) $( 'main' ).focus();
		}
	}
	function focusAfter( target ) {
		if ( state.busy ) state.focusTarget = target;
		else target?.focus();
	}
	function sync() {
		const article = state.article;
		const editor = state.view === 'editor';
		const publisher = state.actor && state.actor.role !== 'author';
		const blocked = state.busy || !! state.pending;
		document
			.querySelectorAll( 'button, input, textarea, select' )
			.forEach( ( control ) => {
				control.disabled = control.closest( '#login' )
					? state.busy
					: blocked;
			} );
		$( 'retry-action' ).disabled = state.busy;
		$( 'save-draft' ).hidden = ! editor;
		$( 'save-draft' ).disabled = blocked || ( ! state.dirty && !! article );
		$( 'preview' ).hidden = ! editor || ! article;
		if ( article ) $( 'preview' ).href = '/preview/' + article.id;
		$( 'save-state' ).textContent = editor
			? state.dirty
				? 'Unsaved changes'
				: 'Saved'
			: '';
		$( 'publish' ).hidden = ! publisher;
		$( 'publish' ).disabled =
			blocked ||
			state.dirty ||
			! article ||
			article.revision === article.published_revision;
		$( 'publish' ).textContent =
			article && article.published_revision
				? 'Publish changes'
				: 'Publish';
		$( 'withdraw' ).hidden =
			! publisher || ! article || ! article.published_revision;
		[ 'schedule', 'schedule-time', 'cancel-schedule' ].forEach( ( id ) => {
			$( id ).hidden =
				! publisher ||
				( id === 'cancel-schedule' && ! article?.scheduled_at );
			$( id ).disabled = blocked || state.dirty || ! article;
		} );
		$( 'apply-terms' ).disabled = blocked || state.dirty || ! article;
		$( 'show-history' ).disabled = blocked || ! article;
		$( 'add-block' ).disabled = blocked || state.blocks.length >= 64;
		$( 'publication-state' ).textContent = ! article?.published_revision
			? 'Draft'
			: article.revision === article.published_revision
			? 'Published'
			: 'Published · new draft';
		$( 'publication-help' ).textContent = state.dirty
			? 'Save these changes before publishing.'
			: ! publisher
			? 'An editor or owner can publish your saved draft.'
			: article?.published_revision
			? 'The public article changes only when you publish.'
			: 'Publishing makes this saved revision public.';
		$( 'schedule-state' ).textContent = article?.scheduled_at
			? 'Scheduled for ' +
			  new Date( article.scheduled_at ).toLocaleString()
			: 'No publication scheduled.';
	}
	function dirty() {
		state.dirty = true;
		sync();
		try {
			$( 'document-size' ).textContent =
				bytes( serialize( state.blocks ) ) + ' / 131,072 bytes';
		} catch ( error ) {
			$( 'document-size' ).textContent = error.message;
		}
	}
	const escape = ( text ) =>
		text
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' );
	function serialize( blocks ) {
		if ( blocks.length > 64 )
			throw new Error( 'An article supports up to 64 blocks.' );
		const source = blocks
			.map( ( block ) => {
				if ( block.type === 'image' ) {
					if (
						! /^[a-f0-9]{32}$/.test( block.media ) ||
						bytes( block.alt ) > 512
					)
						throw new Error(
							'Choose an image and keep its description within 512 bytes.'
						);
					return (
						'<!-- wp:axiom/image ' +
						JSON.stringify( { media: block.media, alt: block.alt } )
							.replaceAll( '<', '\\u003C' )
							.replaceAll( '>', '\\u003E' ) +
						' /-->'
					);
				}
				const heading = block.type === 'heading';
				const name = heading
					? 'heading {"level":' + block.level + '}'
					: 'paragraph';
				const tag = heading ? 'h' + block.level : 'p';
				return (
					'<!-- wp:' +
					name +
					' -->\n<' +
					tag +
					'>' +
					escape( block.text ) +
					'</' +
					tag +
					'>\n<!-- /wp:' +
					( heading ? 'heading' : 'paragraph' ) +
					' -->'
				);
			} )
			.join( '\n\n' );
		if ( bytes( source ) > 131072 )
			throw new Error(
				'The article exceeds the 128 KiB document limit.'
			);
		return source;
	}
	function parse( source ) {
		if ( ! source ) return [];
		if ( ! source.startsWith( '<!-- wp:' ) )
			return [ { type: 'paragraph', text: source } ];
		const parsed =
			window.wp.blockSerializationDefaultParser.parse( source );
		const result = [];
		for ( const block of parsed ) {
			if ( block.blockName === null && /^\s*$/.test( block.innerHTML ) )
				continue;
			if ( block.innerBlocks.length )
				throw new Error( message( 'unsupported_document' ) );
			if ( block.blockName === 'axiom/image' ) {
				result.push( {
					type: 'image',
					media: block.attrs.media,
					alt: block.attrs.alt,
				} );
				continue;
			}
			const heading = block.blockName === 'core/heading';
			if ( ! heading && block.blockName !== 'core/paragraph' )
				throw new Error( message( 'unsupported_document' ) );
			const tag = heading ? 'h' + block.attrs.level : 'p';
			const match = block.innerHTML.match(
				new RegExp( '^\\n<' + tag + '>([\\s\\S]*)</' + tag + '>\\n$' )
			);
			if ( ! match ) throw new Error( message( 'unsupported_document' ) );
			const text = match[ 1 ].replace(
				/&(?:amp|lt|gt);/g,
				( entity ) =>
					( { '&amp;': '&', '&lt;': '<', '&gt;': '>' } )[ entity ]
			);
			result.push(
				heading
					? { type: 'heading', level: block.attrs.level, text }
					: { type: 'paragraph', text }
			);
		}
		if ( serialize( result ) !== source )
			throw new Error( message( 'unsupported_document' ) );
		return result;
	}
	function renderBlocks( focusIndex = null ) {
		$( 'blocks' ).replaceChildren();
		state.blocks.forEach( ( block, index ) => {
			const row = node( 'section', undefined, 'block' );
			const tools = node( 'div', undefined, 'block-toolbar' );
			tools.append( node( 'span', index + 1 + ' · ' + block.type ) );
			const actions = node( 'div' );
			if ( index > 0 )
				actions.append(
					button( 'Move up', () => {
						[ state.blocks[ index - 1 ], state.blocks[ index ] ] = [
							block,
							state.blocks[ index - 1 ],
						];
						renderBlocks( index - 1 );
						dirty();
					} )
				);
			if ( index < state.blocks.length - 1 )
				actions.append(
					button( 'Move down', () => {
						[ state.blocks[ index ], state.blocks[ index + 1 ] ] = [
							state.blocks[ index + 1 ],
							block,
						];
						renderBlocks( index + 1 );
						dirty();
					} )
				);
			actions.append(
				button( 'Remove', () => {
					state.blocks.splice( index, 1 );
					renderBlocks( Math.min( index, state.blocks.length - 1 ) );
					dirty();
				} )
			);
			tools.append( actions );
			row.append( tools );
			if ( block.type === 'image' ) {
				const select = node( 'select' );
				select.setAttribute(
					'aria-label',
					'Image for block ' + ( index + 1 )
				);
				select.append( new Option( 'Choose a prepared image', '' ) );
				state.media
					.filter( ( image ) => image.status === 'ready' )
					.forEach( ( image ) =>
						select.append(
							new Option(
								image.name +
									( image.public
										? ' · public'
										: ' · private' ),
								image.id
							)
						)
					);
				if (
					block.media &&
					! Array.from( select.options ).some(
						( option ) => option.value === block.media
					)
				)
					select.append(
						new Option( 'Unavailable image', block.media )
					);
				select.value = block.media;
				select.addEventListener( 'change', () => {
					block.media = select.value;
					dirty();
					renderBlocks( index );
				} );
				const label = node( 'label', 'Image description' );
				label.htmlFor = 'block-alt-' + index;
				const alt = node( 'input' );
				alt.id = label.htmlFor;
				alt.value = block.alt;
				alt.maxLength = 512;
				alt.addEventListener( 'input', () => {
					block.alt = alt.value;
					dirty();
				} );
				row.append( select, label, alt );
				if ( /^[a-f0-9]{32}$/.test( block.media ) ) {
					const image = node( 'img' );
					image.src = '/v1/media/' + block.media + '/file';
					image.alt = block.alt;
					row.append( image );
				}
			} else {
				if ( block.type === 'heading' ) {
					const level = node( 'select' );
					level.setAttribute( 'aria-label', 'Heading level' );
					[ 2, 3, 4 ].forEach( ( n ) =>
						level.append(
							new Option( 'Heading ' + n, String( n ) )
						)
					);
					level.value = String( block.level );
					level.addEventListener( 'change', () => {
						block.level = Number( level.value );
						dirty();
					} );
					row.append( level );
				}
				const text = node( 'textarea' );
				text.rows = block.type === 'heading' ? 2 : 5;
				text.value = block.text;
				text.className =
					'block-text' +
					( block.type === 'heading' ? ' heading' : '' );
				text.setAttribute(
					'aria-label',
					block.type + ' block ' + ( index + 1 )
				);
				text.placeholder =
					block.type === 'heading'
						? 'Section heading'
						: 'Start writing…';
				text.addEventListener( 'input', () => {
					block.text = text.value;
					dirty();
				} );
				row.append( text );
			}
			$( 'blocks' ).append( row );
		} );
		sync();
		if ( focusIndex !== null )
			focusAfter(
				$( 'blocks' ).children[ focusIndex ]?.querySelector(
					'textarea,select,input'
				) || $( 'add-block' )
			);
	}
	function canLeave() {
		if ( state.pending )
			throw new Error(
				'Retry the unconfirmed action before leaving this view.'
			);
		return (
			! state.dirty ||
			window.confirm( 'Discard the unsaved changes to this draft?' )
		);
	}
	function view( name ) {
		state.view = name;
		[ 'articles', 'editor', 'media', 'settings' ].forEach(
			( id ) => ( $( id + '-view' ).hidden = id !== name )
		);
		$( 'breadcrumb' ).textContent = {
			articles: 'Articles',
			editor: 'Article editor',
			media: 'Media',
			settings: 'Site settings',
		}[ name ];
		document.querySelectorAll( '[data-view]' ).forEach( ( control ) => {
			if (
				control.dataset.view ===
				( name === 'editor' ? 'articles' : name )
			)
				control.setAttribute( 'aria-current', 'page' );
			else control.removeAttribute( 'aria-current' );
		} );
		sync();
		focusAfter( name === 'editor' ? $( 'article-title' ) : $( 'main' ) );
	}
	async function listArticles( cursor = '' ) {
		const result = await platform( 'articles.list', { cursor } );
		if ( ! cursor ) $( 'articles-list' ).replaceChildren();
		if ( ! result.items.length && ! cursor )
			$( 'articles-list' ).append(
				node(
					'p',
					'No articles yet. Create your first draft.',
					'empty-state'
				)
			);
		result.items.forEach( ( article ) => {
			const row = node( 'div', undefined, 'article-row' );
			const title = node( 'h2' );
			title.append(
				button(
					article.title || 'Untitled article',
					() => openArticle( article.id ),
					'article-link'
				)
			);
			row.append(
				title,
				node(
					'span',
					article.status === 'published' ? 'Published' : 'Draft',
					'status-label'
				),
				node(
					'time',
					new Date( article.updated_at ).toLocaleDateString()
				)
			);
			$( 'articles-list' ).append( row );
		} );
		state.cursor = result.items.at( -1 )?.id || '';
		$( 'older-articles' ).hidden = result.items.length < 25;
	}
	async function loadEditor( article ) {
		// Parse before replacing the current buffer; unsupported documents never lose their source.
		const blocks = parse( article?.text || '' );
		const [ terms, media ] = await Promise.all( [
			platform( 'terms.list' ),
			platform( 'media.list', { cursor: '' } ),
		] );
		state.article = article;
		state.blocks = blocks;
		state.terms = terms.items;
		state.media = media.items;
		state.dirty = ! article;
		$( 'article-title' ).value = article?.title || '';
		$( 'history' ).hidden = true;
		$( 'history-list' ).replaceChildren();
		renderBlocks();
		renderTerms();
		view( 'editor' );
	}
	async function openArticle( id ) {
		if ( ! canLeave() ) return;
		await loadEditor( await request( '/v1/articles/' + id ) );
	}
	function expected() {
		if ( ! state.article || state.dirty )
			throw new Error( 'Save the draft first.' );
		return {
			expected_revision: state.article.revision,
			expected_version: state.article.version,
		};
	}
	async function reloadArticle() {
		await loadEditor( await request( '/v1/articles/' + state.article.id ) );
	}
	async function save() {
		const title = $( 'article-title' ).value;
		if ( ! title.trim() || bytes( title ) > 4096 )
			throw new Error( 'Enter a title within 4,096 bytes.' );
		const data = { title, text: serialize( state.blocks ) };
		const article = state.article;
		if ( article )
			Object.assign( data, {
				expected_revision: article.revision,
				expected_version: article.version,
			} );
		await mutate(
			'/v1/articles' + ( article ? '/' + article.id : '' ),
			article ? 'PUT' : 'POST',
			data,
			async ( result ) => {
				// Remember a successful create even if its follow-up read fails.
				state.article = { ...article, ...result };
				state.dirty = false;
				await reloadArticle();
				notice( 'Draft saved.' );
			}
		);
	}
	function renderTerms() {
		$( 'article-terms' ).replaceChildren();
		const selected = new Set( state.article?.terms || [] );
		state.terms
			.filter( ( term ) => ! term.archived || selected.has( term.id ) )
			.forEach( ( term ) => {
				const label = node( 'label', undefined, 'term-option' );
				const input = node( 'input' );
				input.type = 'checkbox';
				input.value = term.id;
				input.checked = selected.has( term.id );
				label.append(
					input,
					document.createTextNode(
						term.name + ( term.archived ? ' (archived)' : '' )
					)
				);
				$( 'article-terms' ).append( label );
			} );
		if ( ! $( 'article-terms' ).childElementCount )
			$( 'article-terms' ).append(
				node( 'p', 'No categories or tags have been created.', 'help' )
			);
	}
	async function mediaView() {
		const result = await platform( 'media.list', { cursor: '' } );
		state.media = result.items;
		$( 'media-list' ).replaceChildren();
		if ( ! result.items.length )
			$( 'media-list' ).append(
				node( 'p', 'No images yet.', 'empty-state' )
			);
		result.items.forEach( ( image ) => {
			const row = node( 'section', undefined, 'media-item' );
			if ( image.status === 'ready' ) {
				const img = node( 'img' );
				img.src = '/v1/media/' + image.id + '/file';
				img.alt = image.name;
				img.loading = 'lazy';
				const thumbnail = node( 'div', undefined, 'media-thumb' );
				thumbnail.append( img );
				row.append( thumbnail );
			}
			const info = node( 'div', undefined, 'media-info' );
			info.append(
				node( 'h2', image.name ),
				node(
					'p',
					image.status === 'ready'
						? ( image.public ? 'Public' : 'Private' ) +
								' · ' +
								image.width +
								' × ' +
								image.height
						: {
								pending: 'Waiting for image processing',
								processing: 'Preparing image',
								failed: 'Image could not be prepared. Upload another file.',
						  }[ image.status ],
					'help'
				)
			);
			if (
				image.status === 'ready' &&
				( state.actor.role !== 'author' || image.public )
			) {
				info.append(
					button(
						image.public ? 'Make private' : 'Publish image',
						() =>
							mutate(
								'/v1/media/' +
									image.id +
									( image.public ? '/withdraw' : '/publish' ),
								'POST',
								{ expected_version: image.version },
								async () => {
									await mediaView();
									notice( 'Image visibility updated.' );
								}
							)
					)
				);
			}
			row.append( info );
			$( 'media-list' ).append( row );
		} );
	}
	async function settingsView() {
		const [ settings, terms ] = await Promise.all( [
			platform( 'settings.read' ),
			platform( 'terms.list' ),
		] );
		state.settings = settings;
		state.terms = terms.items;
		$( 'site-title' ).value = settings.title;
		$( 'site-tagline' ).value = settings.tagline;
		$( 'site-theme' ).value = settings.theme;
		$( 'page-size' ).value = settings.page_size;
		$( 'term-list' ).replaceChildren();
		terms.items.forEach( ( term ) => {
			const row = node( 'div', undefined, 'term-row' );
			row.append(
				node(
					'span',
					term.name +
						' · ' +
						term.kind +
						( term.archived ? ' · archived' : '' )
				),
				button( 'Edit', () => {
					$( 'term-id' ).value = term.id;
					$( 'term-version' ).value = term.version;
					$( 'term-name' ).value = term.name;
					$( 'term-slug' ).value = term.slug;
					$( 'term-kind' ).value = term.kind;
					focusAfter( $( 'term-name' ) );
				} )
			);
			if ( ! term.archived )
				row.append(
					button( 'Archive', () =>
						writePlatform(
							'terms.archive',
							{ id: term.id, expected_version: term.version },
							settingsView
						)
					)
				);
			$( 'term-list' ).append( row );
		} );
	}
	async function enter( actor ) {
		if (
			state.reauthenticate &&
			( actor.user_id !== state.actor.user_id ||
				actor.site_id !== state.actor.site_id )
		) {
			throw new Error(
				'Sign in with the original account to resume this workspace.'
			);
		}
		const resume = state.reauthenticate;
		state.reauthenticate = false;
		state.actor = actor;
		$( 'login' ).hidden = true;
		$( 'workspace' ).hidden = false;
		$( 'account-label' ).textContent =
			actor.role[ 0 ].toUpperCase() + actor.role.slice( 1 );
		$( 'settings-nav' ).hidden = actor.role !== 'owner';
		if ( resume ) {
			sync();
			notice( 'Session renewed. Your unsaved draft has been retained.' );
		} else {
			view( 'articles' );
			await listArticles();
		}
	}
	const on = ( id, event, action ) =>
		$( id ).addEventListener( event, ( ev ) => {
			ev.preventDefault();
			task( () => action( ev ) );
		} );
	on( 'login-form', 'submit', async () => {
		$( 'login-message' ).textContent = 'Signing in…';
		try {
			const actor = await request( '/v1/session/login', 'POST', {
				username: $( 'username' ).value,
				password: $( 'password' ).value,
			} );
			$( 'password' ).value = '';
			$( 'login-message' ).textContent = '';
			await enter( actor );
		} catch ( error ) {
			$( 'login-message' ).textContent = error.message;
		}
	} );
	on( 'sign-out', 'click', async () => {
		if ( ! canLeave() ) return;
		await request( '/v1/session', 'DELETE' );
		location.reload();
	} );
	document.querySelectorAll( '[data-view]' ).forEach( ( control ) =>
		control.addEventListener( 'click', () =>
			task( async () => {
				if ( ! canLeave() ) return;
				const target = control.dataset.view;
				if ( target === 'articles' ) await listArticles();
				if ( target === 'media' ) await mediaView();
				if ( target === 'settings' ) await settingsView();
				state.dirty = false;
				view( target );
				notice( '' );
			} )
		)
	);
	on( 'new-article', 'click', async () => {
		if ( canLeave() ) await loadEditor( null );
	} );
	on( 'older-articles', 'click', () => listArticles( state.cursor ) );
	$( 'article-title' ).addEventListener( 'input', dirty );
	on( 'add-block', 'click', () => {
		const type = $( 'block-type' ).value;
		state.blocks.push(
			type === 'image'
				? { type, media: '', alt: '' }
				: type === 'heading'
				? { type, level: 2, text: '' }
				: { type, text: '' }
		);
		renderBlocks( state.blocks.length - 1 );
		dirty();
	} );
	on( 'save-draft', 'click', save );
	on( 'retry-action', 'click', retry );
	[ 'publish', 'withdraw' ].forEach( ( id ) =>
		on( id, 'click', async () => {
			const data = expected();
			await mutate(
				'/v1/articles/' +
					state.article.id +
					'/' +
					( id === 'withdraw' ? 'unpublish' : id ),
				'POST',
				data,
				async () => {
					await reloadArticle();
					notice(
						id === 'publish'
							? 'Article published.'
							: 'Article unpublished.'
					);
				}
			);
		} )
	);
	on( 'schedule', 'click', async () => {
		const date = new Date( $( 'schedule-time' ).value );
		if ( ! Number.isFinite( date.getTime() ) || date <= new Date() )
			throw new Error( 'Choose a future publication time.' );
		await mutate(
			'/v1/articles/' + state.article.id + '/schedule',
			'POST',
			{
				...expected(),
				due_at: date.toISOString().replace( /\.\d{3}Z$/, 'Z' ),
			},
			reloadArticle
		);
	} );
	on( 'cancel-schedule', 'click', () =>
		mutate(
			'/v1/articles/' + state.article.id + '/schedule',
			'DELETE',
			{},
			reloadArticle
		)
	);
	on( 'apply-terms', 'click', () =>
		writePlatform(
			'relationships.write',
			{
				...expected(),
				id: state.article.id,
				terms: JSON.stringify(
					Array.from(
						$( 'article-terms' ).querySelectorAll(
							'input:checked'
						),
						( el ) => el.value
					)
				),
			},
			reloadArticle
		)
	);
	on( 'show-history', 'click', async () => {
		const history = await platform( 'revisions.list', {
			id: state.article.id,
		} );
		$( 'history-list' ).replaceChildren();
		$( 'history' ).hidden = false;
		history.items.forEach( ( revision ) => {
			const row = node( 'div', undefined, 'revision-row' );
			row.append(
				node(
					'span',
					'Revision ' + revision.version + ' · ' + revision.title
				),
				button( 'Restore as draft', async () => {
					const data = expected();
					if (
						! window.confirm(
							'Create a new draft from this revision?'
						)
					)
						return;
					await writePlatform(
						'articles.restore',
						{
							...data,
							id: state.article.id,
							revision: revision.revision_id,
						},
						reloadArticle
					);
				} )
			);
			$( 'history-list' ).append( row );
		} );
	} );
	on( 'refresh-media', 'click', mediaView );
	on( 'upload-form', 'submit', async () => {
		const file = $( 'upload' ).files[ 0 ];
		if (
			! file ||
			file.size > 2097152 ||
			! [ 'image/png', 'image/jpeg', 'image/webp' ].includes( file.type )
		)
			throw new Error( 'Choose a PNG, JPEG or WebP image up to 2 MiB.' );
		const data = new Uint8Array( await file.arrayBuffer() );
		let binary = '';
		for ( let offset = 0; offset < data.length; offset += 8192 )
			binary += String.fromCharCode(
				...data.subarray( offset, offset + 8192 )
			);
		await mutate(
			'/v1/media',
			'POST',
			{ name: file.name, mime: file.type, data: btoa( binary ) },
			async () => {
				$( 'upload-form' ).reset();
				await mediaView();
				notice( 'Image queued. Refresh to check processing.' );
			}
		);
	} );
	on( 'settings-form', 'submit', () =>
		writePlatform(
			'settings.write',
			{
				expected_version: state.settings.version,
				title: $( 'site-title' ).value,
				tagline: $( 'site-tagline' ).value,
				theme: $( 'site-theme' ).value,
				page_size: $( 'page-size' ).value,
			},
			async () => {
				await settingsView();
				notice( 'Site settings saved.' );
			}
		)
	);
	on( 'term-form', 'submit', () =>
		writePlatform(
			'terms.write',
			{
				expected_version: $( 'term-version' ).value,
				id: $( 'term-id' ).value,
				name: $( 'term-name' ).value,
				slug: $( 'term-slug' ).value,
				kind: $( 'term-kind' ).value,
			},
			async () => {
				$( 'term-form' ).reset();
				$( 'term-version' ).value = '0';
				await settingsView();
				notice( 'Term saved.' );
			}
		)
	);
	on( 'reset-term', 'click', () => {
		$( 'term-form' ).reset();
		$( 'term-version' ).value = '0';
	} );
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( state.dirty || state.pending ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	task( async () => {
		try {
			await enter( await request( '/v1/session' ) );
		} catch ( error ) {
			if ( ! error.definitive )
				$( 'login-message' ).textContent = error.message;
		}
	} );
} )();
