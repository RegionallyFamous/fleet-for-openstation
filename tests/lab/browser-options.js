/** Trust ONLY this disposable lab certificate in isolated Chromium, not the OS. */
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { execFileSync } = require( 'node:child_process' );
const { createHash } = require( 'node:crypto' );
module.exports = () => {
	if ( ! process.env.FLEET_LAB_RUNNER ) { return {}; }
	const root = path.dirname( process.env.FLEET_LAB_RUNNER );
	if ( ! fs.existsSync( path.join( root, '.fleet-lab' ) ) ) { throw new Error( 'Unmarked TLS fixture.' ); }
	const pem = execFileSync( 'openssl', [ 'x509', '-pubkey', '-noout', '-in', path.join( root, 'tls.crt' ) ] );
	const der = execFileSync( 'openssl', [ 'pkey', '-pubin', '-outform', 'DER' ], { input: pem } );
	return { args: [ `--ignore-certificate-errors-spki-list=${ createHash( 'sha256' ).update( der ).digest( 'base64' ) }` ] };
};
