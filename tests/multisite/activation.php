<?php
/**
 * Multisite activation scope (#130): the updater's reactivation helper and
 * a rollback round trip keep a network activation network-wide.
 *
 * Run by the CI "Multisite activation" job with `wp eval-file` on a fresh
 * network install where the plugin is network-activated. Fixture backups use
 * version 0.0.1 and are removed at the end.
 *
 * @package AI_Site_Connector_Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}
if ( ! is_multisite() ) {
	WP_CLI::error( 'Run this against a multisite install.' );
}

$asc_ms_check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		WP_CLI::error( 'FAIL ' . $message );
	}
	WP_CLI::log( 'ok   ' . $message );
};

$asc_ms_copy = static function ( $src, $dest, $version ) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	wp_mkdir_p( $dest );
	foreach ( $items as $item ) {
		$target = $dest . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
		if ( $item->isDir() ) {
			wp_mkdir_p( $target );
		} else {
			copy( $item->getPathname(), $target );
		}
	}
	$main = $dest . '/ai-site-connector.php';
	file_put_contents( $main, preg_replace( '/^(\s*\*\s*Version:\s*)\S+/m', '${1}' . $version, (string) file_get_contents( $main ), 1 ) );
};

$asc_ms_check( is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME ), 'network-activated at start' );

// 1. Updater path: the recorded scope is restored network-wide.
$asc_ms_state = AI_Site_Connector_Plugin::activation_state();
$asc_ms_check( $asc_ms_state['active'] && $asc_ms_state['network'], 'activation_state() reports a network activation' );
deactivate_plugins( AI_SITE_CONNECTOR_BASENAME, true, true );
$asc_ms_check( ! is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME ), 'network-deactivated for the test' );
$asc_ms_result = AI_Site_Connector_Plugin::restore_activation( $asc_ms_state, true );
$asc_ms_check( ! is_wp_error( $asc_ms_result ) && is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME ), 'restore_activation() re-activates network-wide' );

// 2. Rollback round trip keeps the network activation.
$asc_ms_plugin = WP_PLUGIN_DIR . '/ai-site-connector';
$asc_ms_base   = AI_Site_Connector_Backup_Manager::backup_base_dir();
$asc_ms_before = AI_Site_Connector_Backup_Manager::file_hashes( $asc_ms_plugin );
$asc_ms_copy( $asc_ms_plugin, $asc_ms_base . '/0.0.1', '0.0.1' );

$asc_ms_down = AI_Site_Connector_Backup_Manager::rollback_to( '0.0.1' );
$asc_ms_check( ! is_wp_error( $asc_ms_down ), 'rollback to the fixture: ' . ( is_wp_error( $asc_ms_down ) ? $asc_ms_down->get_error_message() : 'completed' ) );
$asc_ms_check( is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME ), 'still network-active after rollback' );

$asc_ms_up = AI_Site_Connector_Backup_Manager::rollback_to( AI_SITE_CONNECTOR_VERSION );
$asc_ms_check( ! is_wp_error( $asc_ms_up ), 'restore the original: ' . ( is_wp_error( $asc_ms_up ) ? $asc_ms_up->get_error_message() : 'completed' ) );
$asc_ms_check( is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME ), 'still network-active after restoring' );
$asc_ms_check( AI_Site_Connector_Backup_Manager::file_hashes( $asc_ms_plugin ) === $asc_ms_before, 'original files restored byte for byte' );

WP_CLI::success( 'Network activation preserved through update reactivation and rollback.' );
