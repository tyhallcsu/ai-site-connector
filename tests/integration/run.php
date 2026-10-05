<?php
/**
 * Integration test runner — executes inside a real WordPress install.
 *
 * Invoked by tests/runtime-smoke.sh:
 *   wp eval-file wp-content/plugins/ai-site-connector/tests/integration/run.php --user=admin
 *
 * Every tests/integration/test-*.php file registers cases with asc_it().
 * Fixtures must be synthetic and cleaned up by the case that creates them.
 * Exits non-zero when any case fails or when no case ran, so a silently
 * empty suite cannot pass CI.
 *
 * Optional filter: ASC_IT_FILTER=<substring> runs only matching cases.
 *
 * @package AI_Site_Connector_Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through wp eval-file inside WordPress.\n" );
	exit( 1 );
}

require_once __DIR__ . '/bootstrap.php';

$asc_files = glob( __DIR__ . '/test-*.php' );
sort( $asc_files );
foreach ( $asc_files as $asc_file ) {
	require_once $asc_file;
}

exit( asc_it_run() );
