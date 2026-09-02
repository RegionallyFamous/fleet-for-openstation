<?php
/**
 * Fail a build when the release version is inconsistent.
 *
 * @package FleetForOpenStation
 */

declare( strict_types=1 );

$root        = dirname( __DIR__ );
$plugin_file = file_get_contents( $root . '/fleet-for-openstation.php' );
$readme      = file_get_contents( $root . '/readme.txt' );

if ( false === $plugin_file || false === $readme ) {
	fwrite( STDERR, "Unable to read plugin release metadata.\n" );
	exit( 1 );
}

$patterns = array(
	'plugin header'     => array( $plugin_file, '/^[ \t*]*Version:\s*([^\s]+)\s*$/mi' ),
	'version constant'  => array( $plugin_file, "/define\(\s*'OPENSTATION_FLEET_VERSION'\s*,\s*'([^']+)'\s*\)/" ),
	'readme stable tag' => array( $readme, '/^Stable tag:\s*([^\s]+)\s*$/mi' ),
	'current changelog' => array( $readme, '/^== Changelog ==\s*\R+^=\s*([^=\s]+)\s*=\s*$/mi' ),
);

$versions = array();
foreach ( $patterns as $label => $specification ) {
	$contents = $specification[0];
	$pattern  = $specification[1];

	if ( 1 !== preg_match( $pattern, $contents, $matches ) ) {
		fwrite( STDERR, "Unable to find {$label}.\n" );
		exit( 1 );
	}

	$versions[ $label ] = $matches[1];
}

$expected = reset( $versions );
foreach ( $versions as $label => $version ) {
	if ( $version !== $expected ) {
		fwrite(
			STDERR,
			sprintf(
				"Release version mismatch: plugin header is %s but %s is %s.\n",
				$expected,
				$label,
				$version
			)
		);
		exit( 1 );
	}
}

if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $expected ) ) {
	fwrite( STDERR, "Release version '{$expected}' is not valid semantic versioning.\n" );
	exit( 1 );
}

echo "Release version {$expected} is consistent.\n";
