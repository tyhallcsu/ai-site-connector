<?php
/**
 * Plugin Name:       AI Site Connector
 * Plugin URI:        https://github.com/tyhallcsu/ai-site-connector
 * Description:       Connect Claude / Codex / AI coding agents to a self-hosted WordPress site over the REST API using Application Passwords. No WordPress.com or Jetpack required.
 * Version:           0.6.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            sharmanhall
 * Author URI:        https://github.com/tyhallcsu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ai-site-connector
 *
 * Optional wp-config.php constants:
 *   AI_SITE_CONNECTOR_UPDATE_PRERELEASE (bool)
 *     When true, the self-updater considers GitHub pre-release tags
 *     (e.g. v0.2.0-beta.1). Default: false (stable releases only).
 *   AI_SITE_CONNECTOR_UPDATE_DISABLE (bool)
 *     Kill switch — when true, the self-updater registers no hooks and makes
 *     no network calls. Useful for managed hosts that handle updates
 *     out-of-band. Default: false.
 *   AI_SITE_CONNECTOR_MCP_DISABLE (bool)
 *     When true, the MCP HTTP transport route at
 *     /wp-json/ai-site-connector/v1/mcp is not registered. Default: false.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_SITE_CONNECTOR_VERSION', '0.6.0' );
define( 'AI_SITE_CONNECTOR_FILE', __FILE__ );
define( 'AI_SITE_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_SITE_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_SITE_CONNECTOR_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_SITE_CONNECTOR_REST_NAMESPACE', 'ai-site-connector/v1' );
define( 'AI_SITE_CONNECTOR_OPERATOR_ROLE', 'ai_site_operator' );

require_once AI_SITE_CONNECTOR_DIR . 'includes/class-plugin.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-roles.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-user-manager.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-application-passwords.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-app-password-meta.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-app-password-resolver.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-connection-pack-token.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-audit-log.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-audit-digest.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-permissions.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-diagnostics.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-cache.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-media.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-export.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-rest-controller.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-connection-formats.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-admin-page.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-updater.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-backup-manager.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-api-explorer.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-onboarding.php';
require_once AI_SITE_CONNECTOR_DIR . 'includes/class-mcp-server.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AI_SITE_CONNECTOR_DIR . 'includes/class-wp-cli.php';
}

register_activation_hook( __FILE__, array( 'AI_Site_Connector_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AI_Site_Connector_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AI_Site_Connector_Plugin', 'instance' ) );
