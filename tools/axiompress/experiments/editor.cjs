// Inspect the pinned local Gutenberg artifact without loading PHP or executing content markup.
const assert = require( 'node:assert/strict' );
const crypto = require( 'node:crypto' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const root = path.resolve( __dirname, '../../..' );
const artifact = path.join( root, 'gutenberg/build/scripts' );
const source = fs.readFileSync(
	path.join( artifact, 'block-serialization-default-parser/index.js' ),
	'utf8'
);
const context = vm.createContext( { window: {} } );
vm.runInContext( source, context, { timeout: 1000 } );
const parse = ( document ) => {
	// vm is only a bounded compatibility harness for trusted pinned project code, never a plugin sandbox.
	context.documentInput = document;
	return JSON.parse(
		vm.runInContext(
			'JSON.stringify(window.wp.blockSerializationDefaultParser.parse(documentInput))',
			context,
			{ timeout: 1000 }
		)
	);
};
const content = '<!-- wp:paragraph --><p>Hello κόσμε</p><!-- /wp:paragraph -->';
const parsed = parse( content );
assert.equal( parsed.length, 1 );
assert.equal( parsed[ 0 ].blockName, 'core/paragraph' );
assert.equal( parsed[ 0 ].innerHTML, '<p>Hello κόσμε</p>' );
assert.deepEqual( parse( '' ), [] );
assert.equal(
	parse( '<!-- wp:vendor/unknown {"answer":42} /-->' )[ 0 ].attrs.answer,
	42
);
const dangerous = parse(
	'<!-- wp:html --><script>window.executed=true</script><!-- /wp:html -->'
);
assert.equal( dangerous[ 0 ].blockName, 'core/html' );
assert.equal(
	dangerous[ 0 ].innerHTML,
	'<script>window.executed=true</script>'
);
assert.equal( vm.runInContext( 'window.executed', context ), undefined );
assert.equal(
	parse(
		'<!-- wp:group --><!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph --><!-- /wp:group -->'
	)[ 0 ].innerBlocks[ 0 ].blockName,
	'core/paragraph'
);
assert.ok(
	parse(
		'<!-- wp:paragraph {bad} --><p>Invalid attributes</p><!-- /wp:paragraph -->'
	).length > 0
);
const packages = {};
for ( const name of [
	'block-serialization-default-parser',
	'blocks',
	'block-editor',
	'editor',
	'core-data',
	'api-fetch',
] ) {
	const assetPath = path.join( artifact, name, 'index.min.asset.php' );
	const asset = fs.readFileSync( assetPath, 'utf8' );
	const match = asset.match( /'dependencies'\s*=>\s*array\(([^)]*)\)/ );
	assert.ok( match, `Missing dependency manifest: ${ name }` );
	const dependencies = [ ...match[ 1 ].matchAll( /'([^']+)'/g ) ].map(
		( value ) => value[ 1 ]
	);
	packages[ name ] = {
		dependencies,
		asset_sha256: crypto
			.createHash( 'sha256' )
			.update( asset )
			.digest( 'hex' ),
	};
}
assert.deepEqual(
	packages[ 'block-serialization-default-parser' ].dependencies,
	[]
);
assert.ok( packages.editor.dependencies.includes( 'wp-core-data' ) );
assert.ok( packages.editor.dependencies.includes( 'wp-api-fetch' ) );
const report = {
	outcome: 'passed',
	checks: 19,
	gutenberg_commit: require( path.join( root, 'package.json' ) ).gutenberg
		.sha,
	parser_sha256: crypto
		.createHash( 'sha256' )
		.update( source )
		.digest( 'hex' ),
	packages,
	decision:
		'Reuse data grammar and selected UI packages behind native adapters; no wholesale editor/PHP bootstrap reuse.',
	limits: 'Parser preserves executable-looking HTML as data; it is neither validation nor sanitization. No full editor UI/API parity claim.',
};
fs.mkdirSync( path.join( root, 'docs' ), { recursive: true } );
fs.writeFileSync(
	path.join( root, 'docs/axiompress-editor-dependencies-last.json' ),
	JSON.stringify( report, null, 2 ) + '\n'
);
process.stdout.write(
	'PASS: 19 pinned editor parser/dependency compatibility checks\n'
);
