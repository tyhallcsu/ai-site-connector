<?php
/**
 * AI Site Connector — uninstall hook.
 *
 * Runs ONLY when the plugin is deleted via WP admin or `wp plugin uninstall`.
 * Does NOT run on simple deactivation. Default behavior: PRESERVE all plugin
 * state — audit log table, AI Site Operator role, options, AI user, and any
 * Application Passwords minted while the plugin was active. Operators who
 * want a true clean removal must opt in via either:
 *
 *   1. The "Wipe data on uninstall" toggle on Tools → AI Site Connector → Audit, OR
 *   2. A wp-config.php constant:
 *        define( 'AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL', true );
 *
 * Even when opted in, this script intentionally does NOT delete:
 *   - The dedicated AI user (may own content; deletion is the operator's call)
 *   - Application Passwords (managed by WP core; revoke via `wp user
 *     application-password delete` or the user's profile screen)
 *
 * @package AI_Site_Connector
 */

// WP core defines this constant before requiring uninstall.php — bail on
// direct access and on any other entry path.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ai_site_connector_opt_in = (bool) get_option( 'ai_site_connector_wipe_on_uninstall', false );
if ( ! $ai_site_connector_opt_in
	&& ! ( defined( 'AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL' ) && AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL )
) {
	// Default path: leave every byte we wrote in place.
	return;
}

global $wpdb;

// 1. Drop the audit log table. After this, any record() calls that somehow
// fire will silently no-op against a missing table — which is fine, we're
// uninstalling.
$ai_site_connector_table = $wpdb->prefix . 'ai_site_connector_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall path.
$wpdb->query( "DROP TABLE IF EXISTS `{$ai_site_connector_table}`" );

// 2. Remove the custom role. Capabilities revert to whatever WP core defines
// for the slug (which is nothing for our slug — the role just disappears).
remove_role( 'ai_site_operator' );

// 3. Delete every option we own.
delete_option( 'ai_site_connector_db_version' );
delete_option( 'ai_site_connector_log_retention_days' );
delete_option( 'ai_site_connector_wipe_on_uninstall' );
delete_option( 'ai_site_connector_disabled' );
// Settings and policy (#116): a wipe must reinstall to the conservative
// defaults, not the old tool permissions or delivery targets. Per-password
// restriction meta stays with the preserved Application Passwords.
foreach ( array(
	'ai_site_connector_tool_permissions',
	'ai_site_connector_read_only_mode',
	'ai_site_connector_webhook_url',
	'ai_site_connector_webhook_secret',
	'ai_site_connector_webhook_format',
	'ai_site_connector_webhook_event_filter',
	'ai_site_connector_digest_cadence',
	'ai_site_connector_digest_recipients',
	'ai_site_connector_onboarding_completed',
	'ai_site_connector_last_request_at',
	'ai_site_connector_cloudflare_api_token',
	'ai_site_connector_cloudflare_zone_id',
) as $ai_site_connector_option ) {
	delete_option( $ai_site_connector_option );
}
delete_transient( 'ai_site_connector_openapi_cache' );
delete_site_transient( 'ai_site_connector_openapi_cache' ); // Network-wide copy written before 0.12.5.
delete_site_transient( 'ai_site_connector_remote_release' );
// Unused one-time connection-pack download tokens.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ai_site_connector_pack_token_' ) . '%', $wpdb->esc_like( '_transient_timeout_ai_site_connector_pack_token_' ) . '%' ) );
// Content-update rollback snapshots (copies of post content) and their index rows.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ai_site_connector_snapshot_' ) . '%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ai_site_connector_snapshot' ) );
// Unfinished duplicate-scan states (attachment IDs and filenames).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ai_site_connector_dupscan_' ) . '%' ) );

// 4. Unschedule the daily prune (deactivation already did this, but if
// uninstall is called without prior deactivation — possible via wp-cli's
// `wp plugin uninstall --deactivate` order in some setups — clear it now).
$ai_site_connector_next = wp_next_scheduled( 'ai_site_connector_audit_log_prune' );
if ( $ai_site_connector_next ) {
	wp_unschedule_event( $ai_site_connector_next, 'ai_site_connector_audit_log_prune' );
}
wp_clear_scheduled_hook( 'ai_site_connector_audit_log_prune' );
foreach ( array( 'ai_site_connector_audit_digest', 'ai_site_connector_update_check', 'ai_site_connector_app_password_sweep', 'ai_site_connector_webhook_deliver' ) as $ai_site_connector_hook ) {
	wp_clear_scheduled_hook( $ai_site_connector_hook );
}

// 5. Clear any flash transients we may have left behind.
foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $ai_site_connector_user ) {
	delete_transient( 'ai_site_connector_flash_' . (int) $ai_site_connector_user->ID );
}

unset(
	$ai_site_connector_opt_in,
	$ai_site_connector_table,
	$ai_site_connector_next,
	$ai_site_connector_option,
	$ai_site_connector_hook,
	$ai_site_connector_user
);
