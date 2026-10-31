'use strict';
const { chromium } = require( 'playwright' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { randomBytes } = require( 'node:crypto' );
( async () => {
	const fixture = JSON.parse( fs.readFileSync( 0, 'utf8' ) );
	assert.match( fixture.origin, /^https:\/\/localhost:[0-9]+$/ );
	const browser = await chromium.launch( {
		channel: 'chrome',
		headless: true,
	} );
	const context = await browser.newContext( {
		ignoreHTTPSErrors: true,
		viewport: { width: 1440, height: 1000 },
	} );
	const page = await context.newPage();
	const errors = [],
		requests = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	page.on( 'request', ( request ) => requests.push( request.url() ) );
	let checks = 0;
	const check = ( condition, label ) => {
		assert.ok( condition, label );
		checks++;
	};
	const notice = ( text ) =>
		page.waitForFunction(
			( expected ) =>
				document.getElementById( 'status' ).textContent === expected,
			text
		);
	const login = () =>
		context.request.post( fixture.origin + '/v1/session/login', {
			headers: { Origin: fixture.origin },
			data: { username: fixture.username, password: fixture.password },
		} );
	const output = path.resolve(
		__dirname,
		'../../docs/gutenberg-native-proof'
	);
	fs.mkdirSync( output, { recursive: true } );
	try {
		assert.equal( ( await login() ).status(), 200 );
		await page.goto( fixture.origin + '/__editor-proof' );
		await notice( 'Ready — native session verified.' );
		check(
			( await page
				.locator( '.block-editor-block-list__layout' )
				.count() ) === 1,
			'Actual Gutenberg BlockList mounted'
		);
		await page
			.getByLabel( 'Article title', { exact: true } )
			.fill( 'Gutenberg native proof' );
		const paragraph = page
			.locator( '#editor [contenteditable=true]' )
			.first();
		await paragraph.click();
		await page.keyboard.type( 'Hello ' );
		await page.keyboard.press( 'Control+b' );
		await page.keyboard.type( 'bold' );
		await page.keyboard.press( 'Control+b' );
		await page.keyboard.type( ' world' );
		check(
			( await paragraph.locator( 'strong' ).textContent() ) === 'bold',
			'Actual RichText bold shortcut'
		);
		await page.keyboard.press( 'Control+z' );
		await page.keyboard.press( 'Control+Shift+z' );
		check(
			( await paragraph.textContent() ).includes( 'bold' ),
			'RichText undo/redo retains formatting'
		);
		let stableKey;
		await page.route( '**/v1/articles', async ( route ) => {
			const key = route.request().headers()[ 'idempotency-key' ];
			if ( ! stableKey ) {
				stableKey = key;
				const committed = await route.fetch();
				assert.equal( committed.status(), 201 );
				await route.fulfill( {
					status: 503,
					contentType: 'application/json',
					body: '{"error":"unavailable"}',
				} );
			} else {
				assert.equal( key, stableKey );
				await route.continue();
			}
		} );
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction( () =>
			document
				.getElementById( 'status' )
				.textContent.includes( 'Write could not be confirmed' )
		);
		check(
			( await page.locator( '#editor' ).getAttribute( 'inert' ) ) !==
				null,
			'Unknown write freezes editing'
		);
		await page
			.getByRole( 'button', { name: 'Retry last write', exact: true } )
			.click();
		await notice( 'Draft saved with native API.' );
		await page.unroute( '**/v1/articles' );
		const id = new URL( page.url() ).searchParams.get( 'id' );
		let stored = await (
			await context.request.get( fixture.origin + '/v1/articles/' + id )
		).json();
		check(
			stored.text.includes( '"axiomVersion":1' ) &&
				stored.text.includes( '<strong>bold</strong>' ),
			'Versioned native formatting persists'
		);
		check(
			(
				await context.request.get(
					fixture.origin + '/v1/sites/1/content/' + id
				)
			).status() === 404,
			'Saving does not publish'
		);
		await page.reload();
		await notice( 'Saved revision loaded.' );
		check(
			( await paragraph.locator( 'strong' ).textContent() ) === 'bold',
			'Formatting survives full browser reload'
		);
		await page
			.getByRole( 'button', { name: 'Add heading', exact: true } )
			.click();
		const heading = page.locator( '#editor h2[contenteditable=true]' );
		await heading.click();
		await page.keyboard.type( 'Section ' );
		await page.keyboard.press( 'Control+i' );
		await page.keyboard.type( 'italic' );
		await page.keyboard.press( 'Control+i' );
		check(
			( await heading.locator( 'em' ).textContent() ) === 'italic',
			'Actual heading and italic shortcut'
		);
		// Dispatch paste through the actual contenteditable/RichText handler, not a state-store shortcut.
		await heading.evaluate( ( element ) => {
			const data = new DataTransfer();
			data.setData( 'text/plain', ' pasted text' );
			element.dispatchEvent(
				new ClipboardEvent( 'paste', {
					clipboardData: data,
					bubbles: true,
					cancelable: true,
				} )
			);
		} );
		check(
			( await heading.textContent() ).includes( 'pasted text' ),
			'RichText plain-text paste handler'
		);
		if ( fixture.populated_media && fixture.media ) {
			await page
				.getByRole( 'button', { name: 'Add image', exact: true } )
				.click();
			await page
				.getByLabel( 'Published image', { exact: true } )
				.selectOption( fixture.media.id );
			await page
				.getByLabel( 'Alternative text', { exact: true } )
				.fill( 'Proof image' );
			check(
				( await page
					.locator( '#editor img[src*="' + fixture.media.id + '"]' )
					.count() ) === 1,
				'Prepared image preview in proof editor'
			);
		}
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await notice( 'Draft saved with native API.' );
		if ( fixture.populated_media && fixture.media ) {
			stored = await (
				await context.request.get(
					fixture.origin + '/v1/articles/' + id
				)
			).json();
			check(
				stored.text.includes( 'wp:axiom/image' ) &&
					stored.text.includes(
						'"media":"' + fixture.media.id + '"'
					) &&
					stored.text.includes( '"alt":"Proof image"' ),
				'Prepared image persists in native document'
			);
		}
		await page.reload();
		await notice( 'Saved revision loaded.' );
		check(
			( await heading.textContent() ).includes( 'pasted text' ) &&
				( await heading.locator( 'em' ).count() ) === 1,
			'Heading formatting and paste survive native reload'
		);
		if ( fixture.populated_media && fixture.media ) {
			check(
				( await page
					.getByLabel( 'Published image', { exact: true } )
					.inputValue() ) === fixture.media.id &&
					( await page
						.getByLabel( 'Alternative text', { exact: true } )
						.inputValue() ) === 'Proof image',
				'Image selection survives native reload'
			);
		}
		await page
			.getByRole( 'button', {
				name: 'Publish saved revision',
				exact: true,
			} )
			.click();
		await notice( 'Saved revision published.' );
		const rendered = await context.request.get(
			fixture.origin + '/site/1/articles/' + id
		);
		check(
			rendered.status() === 200 &&
				( await rendered.text() ).includes( '<strong>bold</strong>' ),
			'Native safe renderer preserves approved formatting'
		);
		await page.screenshot( {
			path: path.join( output, 'desktop.png' ),
			fullPage: true,
			animations: 'disabled',
		} );
		await page.setViewportSize( { width: 390, height: 844 } );
		check(
			await page.evaluate(
				() => document.documentElement.scrollWidth <= innerWidth
			),
			'Mobile proof fits viewport'
		);
		await page.screenshot( {
			path: path.join( output, 'mobile.png' ),
			fullPage: true,
			animations: 'disabled',
		} );
		await page.setViewportSize( { width: 1440, height: 1000 } );
		const session = await (
			await context.request.get( fixture.origin + '/v1/session' )
		).json();
		stored = await (
			await context.request.get( fixture.origin + '/v1/articles/' + id )
		).json();
		const competing = await context.request.put(
			fixture.origin + '/v1/articles/' + id,
			{
				headers: {
					Origin: fixture.origin,
					'X-CSRF-Token': session.csrf_token,
					'Idempotency-Key': randomBytes( 32 ).toString( 'hex' ),
				},
				data: {
					expected_revision: stored.revision,
					expected_version: stored.version,
					title: 'Concurrent native edit',
					text: stored.text,
				},
			}
		);
		assert.equal( competing.status(), 200 );
		await page
			.getByLabel( 'Article title', { exact: true } )
			.fill( 'My unsaved title' );
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction( () =>
			document
				.getElementById( 'status' )
				.textContent.includes( 'This article changed' )
		);
		check(
			( await page
				.getByLabel( 'Article title', { exact: true } )
				.inputValue() ) === 'My unsaved title',
			'Conflict retains local buffer'
		);
		const currentSession = await (
			await context.request.get( fixture.origin + '/v1/session' )
		).json();
		assert.equal(
			(
				await context.request.delete( fixture.origin + '/v1/session', {
					headers: {
						Origin: fixture.origin,
						'X-CSRF-Token': currentSession.csrf_token,
					},
				} )
			).status(),
			200
		);
		await page
			.getByRole( 'button', { name: 'Save draft', exact: true } )
			.click();
		await page.waitForFunction( () =>
			document
				.getElementById( 'status' )
				.textContent.includes( 'Session expired or missing' )
		);
		check(
			( await page
				.getByLabel( 'Article title', { exact: true } )
				.inputValue() ) === 'My unsaved title',
			'Expired session retains buffer'
		);
		assert.equal( ( await login() ).status(), 200 );
		await page
			.getByRole( 'button', { name: 'Refresh session', exact: true } )
			.click();
		await notice( 'Session refreshed; editor content retained.' );
		check(
			( await page
				.getByLabel( 'Article title', { exact: true } )
				.inputValue() ) === 'My unsaved title',
			'Native session renewal retains buffer'
		);
		const fresh = await (
			await context.request.get( fixture.origin + '/v1/session' )
		).json();
		const writeHeaders = {
			Origin: fixture.origin,
			'X-CSRF-Token': fresh.csrf_token,
			'Idempotency-Key': randomBytes( 32 ).toString( 'hex' ),
		};
		const malicious =
			'<!-- wp:paragraph {"axiomVersion":1} -->\n<p><strong onclick="alert(1)">x</strong></p>\n<!-- /wp:paragraph -->';
		check(
			(
				await context.request.post( fixture.origin + '/v1/articles', {
					headers: writeHeaders,
					data: { title: 'Rejected markup', text: malicious },
				} )
			).status() === 400,
			'Native HTTP rejects rich-text event attributes'
		);
		const original = '<script>window.untrustedExecuted=true</script>';
		const legacy = await (
			await context.request.post( fixture.origin + '/v1/articles', {
				headers: {
					...writeHeaders,
					'Idempotency-Key': randomBytes( 32 ).toString( 'hex' ),
				},
				data: { title: 'Unconverted source fixture', text: original },
			} )
		).json();
		assert.ok( legacy.id );
		const unsupported = await context.newPage();
		await unsupported.goto(
			fixture.origin + '/__editor-proof?id=' + legacy.id
		);
		await unsupported.waitForFunction( () =>
			document
				.getElementById( 'status' )
				.textContent.includes( 'Unsupported document' )
		);
		check(
			await unsupported
				.getByRole( 'button', { name: 'Save draft', exact: true } )
				.isDisabled(),
			'Unsupported source cannot be overwritten'
		);
		check(
			await unsupported.evaluate(
				() => window.untrustedExecuted === undefined
			),
			'Unsupported source is never mounted as executable markup'
		);
		check(
			(
				await (
					await context.request.get(
						fixture.origin + '/v1/articles/' + legacy.id
					)
				).json()
			).text === original,
			'Unsupported source remains byte-for-byte intact'
		);
		await unsupported.close();
		const anonymous = await browser.newContext( {
			ignoreHTTPSErrors: true,
		} );
		const denied = await anonymous.newPage();
		await denied.goto( fixture.origin + '/__editor-proof' );
		await denied.waitForFunction( () =>
			document
				.getElementById( 'status' )
				.textContent.includes( 'Session expired or missing' )
		);
		check(
			await denied
				.getByRole( 'button', { name: 'Save draft', exact: true } )
				.isDisabled(),
			'Anonymous initialization fails closed'
		);
		await anonymous.close();
		check(
			requests.every( ( url ) => url.startsWith( fixture.origin + '/' ) ),
			'No external network requests'
		);
		check(
			! requests.some(
				( url ) =>
					url.includes( '/wp/v2' ) || url.includes( 'admin-ajax' )
			),
			'No WordPress transport/bootstrap'
		);
		check(
			errors.length === 0,
			'No browser runtime errors: ' + errors.join( '; ' )
		);
		process.stdout.write(
			JSON.stringify( {
				outcome: 'passed',
				checks,
				request_count: requests.length,
			} )
		);
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => {
	process.stderr.write( error.stack + '\n' );
	process.exitCode = 1;
} );
