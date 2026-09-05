/** Companion bundles may execute before the desktop has finished booting. */
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const assert = require( 'node:assert/strict' );
let callback;
const shell = { ready( value ) { callback = value; } };
const window = { wp: { os: shell } };
vm.runInNewContext( fs.readFileSync( require( 'node:path' ).join( __dirname, '../assets/fleet-app.js' ), 'utf8' ), { window } );
assert.equal( typeof callback, 'function' );
assert.equal( window.__openStationFleetEffects, undefined, 'Do not mark initialization complete before the shell is ready.' );
assert.equal( shell.loadComponents, undefined, 'This fixture deliberately has no live shell APIs yet.' );
console.log( 'Fleet bridge queues behind the public shell-ready contract.' );
