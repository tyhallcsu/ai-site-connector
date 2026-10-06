<?php
/**
 * Multisite OpenAPI cache (#129): each site's document names its own REST
 * endpoint regardless of which site filled the cache first. Run by the CI
 * "Multisite activation" job with `wp eval-file` on a two-site network.
 *
 * @package AI_Site_Connector_Tests
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}
if ( ! is_multisite() ) {
	WP_CLI::error( 'Run this against a multisite install.' );
}

$asc_ms_sites = get_sites( array( 'number' => 2, 'orderby' => 'id' ) );
if ( count( $asc_ms_sites ) < 2 ) {
	WP_CLI::error( 'Needs two sites.' );
}
foreach ( $asc_ms_sites as $asc_ms_site ) {
	switch_to_blog( (int) $asc_ms_site->blog_id );
	delete_transient( AI_Site_Connector_OpenAPI::CACHE_TRANSIENT );
	restore_current_blog();
}
delete_site_transient( AI_Site_Connector_OpenAPI::CACHE_TRANSIENT );

// Fill the cache from site 1, then ask site 2, then site 1 again.
foreach ( array( 0, 1, 0 ) as $asc_ms_index ) {
	$asc_ms_id = (int) $asc_ms_sites[ $asc_ms_index ]->blog_id;
	switch_to_blog( $asc_ms_id );
	$asc_ms_expected = trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE;
	$asc_ms_spec     = AI_Site_Connector_OpenAPI::serve_spec()->get_data();
	$asc_ms_actual   = $asc_ms_spec['servers'][0]['url'];
	restore_current_blog();
	if ( $asc_ms_actual !== $asc_ms_expected ) {
		WP_CLI::error( "FAIL site {$asc_ms_id} served {$asc_ms_actual}, expected {$asc_ms_expected}" );
	}
	WP_CLI::log( "ok   site {$asc_ms_id} OpenAPI server is {$asc_ms_actual}" );
}
WP_CLI::success( 'Each site serves its own OpenAPI server URL.' );
