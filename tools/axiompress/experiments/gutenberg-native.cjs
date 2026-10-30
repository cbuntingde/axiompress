/* Build a closed, pinned asset inventory for the disposable native-editor experiment. */
'use strict';
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const crypto = require( 'node:crypto' );
const root = path.resolve( __dirname, '../../..' );
const pin = require( path.join( root, 'package.json' ) ).gutenberg.sha;
if (
	! /^[a-f0-9]{40}$/.test( pin ) ||
	fs
		.readFileSync( path.join( root, 'gutenberg/.gutenberg-hash' ), 'utf8' )
		.trim() !== pin
) {
	throw new Error(
		'Installed Gutenberg build marker differs from the repository pin.'
	);
}
const sources = new Map();
const visiting = new Set();
const forbidden = new Set( [
	'wp-editor',
	'wp-core-data',
	'wp-plugins',
	'wp-server-side-render',
	'wp-block-library',
] );
function visit( handle ) {
	if ( sources.has( handle ) ) return;
	if ( visiting.has( handle ) || forbidden.has( handle ) )
		throw new Error( 'Forbidden or cyclic editor dependency: ' + handle );
	visiting.add( handle );
	let file,
		dependencies = [],
		modules = [];
	if (
		[ 'react', 'react-dom', 'react-jsx-runtime', 'moment' ].includes(
			handle
		)
	) {
		file = 'src/wp-includes/js/dist/vendor/' + handle + '.js';
		dependencies = [ 'react', 'moment' ].includes( handle )
			? []
			: [ 'react' ];
	} else {
		if ( ! /^wp-[a-z0-9-]+$/.test( handle ) )
			throw new Error( 'Unknown artifact handle: ' + handle );
		const name = handle.slice( 3 );
		const manifest = fs.readFileSync(
			path.join(
				root,
				'gutenberg/build/scripts',
				name,
				'index.min.asset.php'
			),
			'utf8'
		);
		const match = manifest.match( /'dependencies' => array\(([^)]*)\)/ );
		if ( ! match ) throw new Error( 'Unsupported pinned asset manifest' );
		dependencies = Array.from(
			match[ 1 ].matchAll( /'([^']+)'/g ),
			( entry ) => entry[ 1 ]
		);
		modules = Array.from(
			manifest.matchAll( /'id' => '([^']+)', 'import' => '([^']+)'/g ),
			( entry ) => ( { id: entry[ 1 ], import: entry[ 2 ] } )
		);
		if ( modules.some( ( entry ) => entry.import !== 'dynamic' ) )
			throw new Error( 'Static module dependency needs review' );
		file = 'gutenberg/build/scripts/' + name + '/index.js';
	}
	dependencies.forEach( visit );
	const data = fs.readFileSync( path.join( root, file ) );
	sources.set( handle, {
		handle,
		file,
		dependencies,
		disabled_modules: modules,
		bytes: data.length,
		sha256: crypto.createHash( 'sha256' ).update( data ).digest( 'hex' ),
	} );
	visiting.delete( handle );
}
visit( 'wp-block-editor' );
const result = {
	experiment: 'native-gutenberg/1',
	gutenberg: pin,
	scripts: Array.from( sources.values() ),
	styles: [
		'gutenberg/build/styles/components/style.css',
		'gutenberg/build/styles/block-editor/style.css',
	],
	disabled: [
		'uploads',
		'embeds',
		'dynamic module imports',
		'third-party registration',
		'WordPress entity adapters',
		'api-fetch transport (transitive utility, deny-all handler required before mounting)',
	],
};
result.total_script_bytes = result.scripts.reduce(
	( sum, asset ) => sum + asset.bytes,
	0
);
process.stdout.write( JSON.stringify( result, null, 2 ) + '\n' );
