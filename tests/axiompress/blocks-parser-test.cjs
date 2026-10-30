// Trusted pinned parser compatibility, never a plugin sandbox or an HTML sanitizer.
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const { execFileSync } = require( 'node:child_process' );
const root = path.resolve( __dirname, '../..' );
const fixture = JSON.parse(
	execFileSync(
		'php',
		[ path.join( __dirname, 'blocks-test.php' ), '--fixtures' ],
		{ encoding: 'utf8' }
	)
);
const context = vm.createContext( { window: {} } );
vm.runInContext(
	fs.readFileSync(
		path.join( root, 'src/axiompress/public/assets/block-parser.js' ),
		'utf8'
	),
	context,
	{ timeout: 1000 }
);
context.input = fixture.source;
const blocks = JSON.parse(
	vm.runInContext(
		'JSON.stringify(window.wp.blockSerializationDefaultParser.parse(input))',
		context,
		{ timeout: 1000 }
	)
);
const named = blocks.filter( ( block ) => block.blockName !== null );
assert.equal( named.length, 3 );
assert.deepEqual(
	named.map( ( block ) => block.blockName ),
	[ 'core/paragraph', 'core/heading', 'axiom/image' ]
);
assert.equal( named[ 1 ].attrs.level, 2 );
assert.equal( named[ 2 ].attrs.media, fixture.blocks[ 2 ].media );
assert.equal( named[ 2 ].attrs.alt, fixture.blocks[ 2 ].alt );
assert.ok( named[ 0 ].innerHTML.includes( '&lt;script&gt;' ) );
assert.equal( context.executed, undefined );
process.stdout.write(
	'PASS: 8 pinned-parser/native-document compatibility checks\n'
);
