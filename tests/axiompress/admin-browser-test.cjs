/* Invoked only by the disposable Linux database harness. Secrets arrive on stdin, never argv/logs. */
'use strict';
const { chromium } = require( 'playwright' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

( async () => {
	const fixture = JSON.parse( fs.readFileSync( 0, 'utf8' ) );
	assert.match( fixture.origin, /^https:\/\/localhost:[0-9]+$/ );
	const browser = await chromium.launch( {
		channel: 'chrome',
		headless: true,
	} );
	// Trust is limited to this disposable context; no OS/browser-profile certificate changes.
	const context = await browser.newContext( {
		ignoreHTTPSErrors: true,
		viewport: { width: 1440, height: 1000 },
	} );
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	let checks = 0;
	const check = ( value ) => {
		assert.ok( value );
		checks++;
	};
	const screenshots = path.resolve( __dirname, '../../docs/phase4-admin' );
	fs.mkdirSync( screenshots, { recursive: true } );
	try {
		await page.goto( fixture.origin + '/admin' );
		await page
			.getByLabel( 'Username', { exact: true } )
			.fill( fixture.username );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( fixture.password );
		await page
			.getByRole( 'button', { name: 'Sign in', exact: true } )
			.click();
		await page.locator( '#workspace' ).waitFor( { state: 'visible' } );
		await page
			.getByRole( 'button', { name: 'Site settings', exact: true } )
			.click();
		await page.waitForFunction(
			() => document.activeElement.id === 'main'
		);
		const termName =
			'Field notes about publishing, careful editing and stories from the community';
		await page.getByLabel( 'Name', { exact: true } ).fill( termName );
		await page.getByLabel( 'Slug', { exact: true } ).fill( 'field-notes' );
		await page
			.getByRole( 'button', { name: 'Save term', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'notice' ).textContent ===
				'Term saved.'
		);
		await page
			.getByRole( 'button', { name: 'Articles', exact: true } )
			.click();
		await page.locator( '#articles-view' ).waitFor( { state: 'visible' } );
		await page
			.locator( '#articles-view' )
			.getByRole( 'button', { name: 'New article', exact: true } )
			.click();
		await page.locator( '#editor-view' ).waitFor( { state: 'visible' } );
		await page.waitForFunction(
			() => document.activeElement.id === 'article-title'
		);
		await page
			.getByLabel( 'Article title', { exact: true } )
			.fill( 'The writing canvas <test>' );
		await page
			.getByRole( 'button', { name: 'Add block', exact: true } )
			.focus();
		await page.keyboard.press( 'Enter' );
		await page.waitForFunction(
			() =>
				document.activeElement.getAttribute( 'aria-label' ) ===
				'paragraph block 1'
		);
		check( true );
		await page
			.getByRole( 'button', { name: 'Add block', exact: true } )
			.click();
		await page
			.getByRole( 'button', { name: 'Move up', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.activeElement.getAttribute( 'aria-label' ) ===
				'paragraph block 1'
		);
		await page
			.getByRole( 'button', { name: 'Remove', exact: true } )
			.first()
			.click();
		await page.waitForFunction(
			() =>
				document.activeElement.getAttribute( 'aria-label' ) ===
				'paragraph block 1'
		);
		check( true );
		await page
			.getByLabel( 'paragraph block 1', { exact: true } )
			.fill( 'A real draft, with <literal markup> & clear intent.' );
		check(
			( await page.locator( '#save-state' ).textContent() ) ===
				'Unsaved changes'
		);
		let firstKey;
		await page.route( '**/v1/articles', async ( route ) => {
			if ( ! firstKey ) {
				firstKey = route.request().headers()[ 'idempotency-key' ];
				const committed = await route.fetch();
				assert.equal( committed.status(), 201 );
				await route.fulfill( {
					status: 503,
					contentType: 'application/json',
					body: '{"error":"unavailable"}',
				} );
			} else {
				assert.equal(
					route.request().headers()[ 'idempotency-key' ],
					firstKey
				);
				await route.continue();
			}
		} );
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.locator( '#retry-panel' ).waitFor( { state: 'visible' } );
		check(
			await page
				.getByRole( 'button', { name: 'Save draft', exact: true } )
				.isDisabled()
		);
		await page
			.getByRole( 'button', { name: 'Retry action', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'notice' ).textContent ===
				'Draft saved.'
		);
		await page.unroute( '**/v1/articles' );
		check( !! firstKey );
		check(
			( await page
				.getByLabel( 'paragraph block 1', { exact: true } )
				.inputValue() ) ===
				'A real draft, with <literal markup> & clear intent.'
		);
		const previewPath = await page
			.locator( '#preview' )
			.getAttribute( 'href' );
		const category = page.getByRole( 'checkbox', {
			name: termName,
			exact: true,
		} );
		await category.check();
		check( ( await category.boundingBox() ).width === 16 );
		await page
			.getByRole( 'button', {
				name: 'Apply categories and tags',
				exact: true,
			} )
			.click();
		await page.waitForFunction(
			() => ! document.body.hasAttribute( 'aria-busy' )
		);
		const savedArticle = await (
			await context.request.get(
				fixture.origin +
					'/v1/articles/' +
					previewPath.split( '/' ).at( -1 )
			)
		).json();
		check( savedArticle.terms.length === 1 );
		const preview = await context.request.get(
			fixture.origin + previewPath
		);
		check(
			preview.status() === 200 &&
				( await preview.text() ).includes( '&lt;literal markup&gt;' )
		);
		await page
			.getByRole( 'button', { name: 'Publish', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'publication-state' ).textContent ===
				'Published'
		);
		check(
			await page
				.getByRole( 'button', { name: 'Publish changes', exact: true } )
				.isDisabled()
		);
		await page
			.getByLabel( 'paragraph block 1', { exact: true } )
			.fill( 'A new private revision.' );
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'publication-state' ).textContent ===
				'Published · new draft'
		);
		const articleId = previewPath.split( '/' ).at( -1 );
		const publicArticle = await context.request.get(
			fixture.origin + '/site/1/articles/' + articleId
		);
		const html = await publicArticle.text();
		check(
			publicArticle.status() === 200 &&
				html.includes( '&lt;literal markup&gt;' ) &&
				! html.includes( 'A new private revision.' )
		);
		await page.waitForFunction(
			() => ! document.body.hasAttribute( 'aria-busy' )
		);
		await page.screenshot( {
			path: path.join( screenshots, 'desktop.png' ),
			fullPage: true,
			animations: 'disabled',
		} );
		check(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		);
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.screenshot( {
			path: path.join( screenshots, 'mobile.png' ),
			fullPage: true,
			animations: 'disabled',
		} );
		check(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			)
		);
		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page
			.getByRole( 'button', {
				name: 'View revision history',
				exact: true,
			} )
			.click();
		await page.locator( '#history' ).waitFor( { state: 'visible' } );
		check(
			( await page
				.getByRole( 'button', {
					name: 'Restore as draft',
					exact: true,
				} )
				.count() ) === 3
		);
		await page
			.getByRole( 'button', { name: 'Media', exact: true } )
			.click();
		await page.locator( '#media-view' ).waitFor( { state: 'visible' } );
		if ( fixture.populated_media ) {
			const images = page.locator( '.media-thumb img' );
			check( ( await images.count() ) >= 2 );
			await images.evaluateAll( ( items ) =>
				Promise.all( items.map( ( img ) => img.decode() ) )
			);
			check(
				await images.evaluateAll( ( items ) =>
					items.every(
						( img ) =>
							img.getBoundingClientRect().width <=
								img.parentElement.getBoundingClientRect()
									.width &&
							img.getBoundingClientRect().height <=
								img.parentElement.getBoundingClientRect().height
					)
				)
			);
			await page.screenshot( {
				path: path.join( screenshots, 'media-desktop.png' ),
				fullPage: true,
				animations: 'disabled',
			} );
			await page.setViewportSize( { width: 390, height: 844 } );
			check(
				await page.evaluate(
					() => document.documentElement.scrollWidth <= innerWidth
				)
			);
			await page.screenshot( {
				path: path.join( screenshots, 'media-mobile.png' ),
				fullPage: true,
				animations: 'disabled',
			} );
			await page.setViewportSize( { width: 1440, height: 1000 } );
		}
		await page
			.getByRole( 'button', { name: 'Site settings', exact: true } )
			.click();
		await page.locator( '#settings-view' ).waitFor( { state: 'visible' } );
		await page
			.getByLabel( 'Site title', { exact: true } )
			.fill( 'Canvas acceptance site' );
		await page
			.getByRole( 'button', { name: 'Save settings', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'notice' ).textContent ===
				'Site settings saved.'
		);
		const publicSite = await context.request.get(
			fixture.origin + '/site/1'
		);
		check(
			( await publicSite.text() ).includes( 'Canvas acceptance site' )
		);
		await page
			.getByRole( 'button', { name: 'Dashboard', exact: true } )
			.click();
		await page.locator( '#dashboard-view' ).waitFor( { state: 'visible' } );
		const glance = await page.locator( '#glance-list' ).textContent();
		for ( const section of [
			'articles',
			'published',
			'drafts',
			'scheduled',
			'media items',
			'categories and tags',
		] )
			check( glance.includes( section ) );
		check(
			( await page.locator( '#recent-list .article-row' ).count() ) >=
				2
		);
		await page
			.getByRole( 'button', { name: 'Articles', exact: true } )
			.click();
		await page.locator( '#articles-view' ).waitFor( { state: 'visible' } );
		const tabCount = async ( label ) => {
			const text = await page
				.getByRole( 'button', {
					name: new RegExp( '^' + label + ' \\(\\d+\\)$' ),
				} )
				.textContent();
			return Number( text.match( /\((\d+)\)/ )[ 1 ] );
		};
		const tabCounts = {
			All: await tabCount( 'All' ),
			Published: await tabCount( 'Published' ),
			Drafts: await tabCount( 'Drafts' ),
			Scheduled: await tabCount( 'Scheduled' ),
		};
		check( tabCounts.All >= 2 );
		check(
			tabCounts.All ===
				tabCounts.Published + tabCounts.Drafts
		);
		const rowsAre = ( expected ) =>
			page.waitForFunction(
				( count ) =>
					document.querySelectorAll(
						'#articles-list .article-row'
					).length === count,
				expected
			);
		const emptyIs = () =>
			page.waitForFunction( () =>
				document
					.getElementById( 'articles-list' )
					.textContent.includes(
						'No articles match this filter.'
					)
			);
		const openTab = async ( label ) => {
			await page
				.getByRole( 'button', {
					name: new RegExp( '^' + label + ' \\(\\d+\\)$' ),
				} )
				.click();
			if ( tabCounts[ label ] === 0 ) await emptyIs();
			else await rowsAre( tabCounts[ label ] );
			check( true );
		};
		check(
			( await page
				.getByRole( 'link', { name: 'View', exact: true } )
				.count() ) >= 1
		);
		await page
			.locator( '#articles-view' )
			.getByRole( 'button', { name: 'New article', exact: true } )
			.click();
		await page.locator( '#editor-view' ).waitFor( { state: 'visible' } );
		await page
			.getByLabel( 'Article title', { exact: true } )
			.fill( 'Term probe' );
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'notice' ).textContent ===
				'Draft saved.'
		);
		await page
			.getByRole( 'checkbox', { name: termName, exact: true } )
			.check();
		await page
			.getByRole( 'button', {
				name: 'Apply categories and tags',
				exact: true,
			} )
			.click();
		await page.waitForFunction(
			() => ! document.body.hasAttribute( 'aria-busy' )
		);
		check(
			( await page.locator( '#notice' ).textContent() ) ===
				'Draft saved.'
		);
		await page
			.getByRole( 'button', { name: 'Articles', exact: true } )
			.click();
		await page.locator( '#articles-view' ).waitFor( { state: 'visible' } );
		const probeRow = page.locator( '.article-row', {
			has: page.getByRole( 'button', {
				name: 'Term probe',
				exact: true,
			} ),
		} );
		check( ( await probeRow.count() ) === 1 );
		check(
			( await probeRow
				.locator( '.article-meta' )
				.textContent() ).includes( 'Field notes' )
		);
		tabCounts.Drafts = await tabCount( 'Drafts' );
		tabCounts.All = await tabCount( 'All' );
		await openTab( 'Drafts' );
		await openTab( 'Scheduled' );
		await openTab( 'Published' );
		await page
			.getByLabel( 'Search articles by title', { exact: true } )
			.fill( 'canvas' );
		await page
			.getByRole( 'button', { name: 'Search', exact: true } )
			.click();
		await rowsAre( 1 );
		check( true );
		await page
			.getByLabel( 'Search articles by title', { exact: true } )
			.fill( 'no such title anywhere' );
		await page
			.getByRole( 'button', { name: 'Search', exact: true } )
			.click();
		await emptyIs();
		check( true );
		await page
			.getByLabel( 'Search articles by title', { exact: true } )
			.fill( '' );
		await page
			.getByRole( 'button', { name: 'Search', exact: true } )
			.click();
		await rowsAre( tabCounts.Published );
		check( true );
		await page
			.getByRole( 'button', {
				name: 'The writing canvas <test>',
				exact: true,
			} )
			.click();
		await page
			.getByLabel( 'paragraph block 1', { exact: true } )
			.fill( 'Retained across session expiry.' );
		const session = await (
			await context.request.get( fixture.origin + '/v1/session' )
		).json();
		assert.equal(
			(
				await context.request.delete( fixture.origin + '/v1/session', {
					headers: {
						Origin: fixture.origin,
						'X-CSRF-Token': session.csrf_token,
					},
				} )
			).status(),
			200
		);
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.locator( '#login' ).waitFor( { state: 'visible' } );
		await page
			.getByLabel( 'Password', { exact: true } )
			.fill( fixture.password );
		await page
			.getByRole( 'button', { name: 'Sign in', exact: true } )
			.click();
		await page.locator( '#workspace' ).waitFor( { state: 'visible' } );
		check(
			( await page
				.getByLabel( 'paragraph block 1', { exact: true } )
				.inputValue() ) === 'Retained across session expiry.'
		);
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction(
			() =>
				document.getElementById( 'notice' ).textContent ===
				'Draft saved.'
		);
		check( errors.length === 0 );
		await page
			.getByRole( 'button', { name: 'Sign out', exact: true } )
			.click();
		await page.locator( '#login' ).waitFor( { state: 'visible' } );
		check(
			(
				await context.request.get( fixture.origin + previewPath )
			).status() === 401
		);
		process.stdout.write(
			JSON.stringify( {
				outcome: 'passed',
				checks,
				screenshots: [ 'desktop.png', 'mobile.png' ],
			} )
		);
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	process.stderr.write( error.stack + '\n' );
	process.exitCode = 1;
} );
