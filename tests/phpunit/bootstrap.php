<?php
/**
 * PHPUnit bootstrap — loads Composer autoload + wp-mock.
 *
 * Plugin classes are NOT required up front; each test loads what it
 * needs via require_once after defining the stubs (ABSPATH, etc) that
 * the class header guards check.
 *
 * @package AI_Site_Connector_Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_VERSION' ) ) {
	define( 'AI_SITE_CONNECTOR_VERSION', '0.8.0' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_REST_NAMESPACE' ) ) {
	define( 'AI_SITE_CONNECTOR_REST_NAMESPACE', 'ai-site-connector/v1' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_OPERATOR_ROLE' ) ) {
	define( 'AI_SITE_CONNECTOR_OPERATOR_ROLE', 'ai_site_operator' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

WP_Mock::bootstrap();
