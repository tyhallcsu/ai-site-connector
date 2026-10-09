<?php
/**
 * Main plugin singleton.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		AI_Site_Connector_Roles::register_hooks();
		AI_Site_Connector_App_Password_Meta::register_hooks();
		AI_Site_Connector_App_Password_Resolver::register_hooks();
		AI_Site_Connector_Usage_Tracker::register_hooks();
		AI_Site_Connector_Audit_Log::register_hooks();
		AI_Site_Connector_Audit_Digest::register_hooks();
		AI_Site_Connector_Audit_Webhook::register_hooks();
		AI_Site_Connector_Permissions::register_hooks();
		AI_Site_Connector_Diagnostics::register_hooks();
		AI_Site_Connector_SEO::register_hooks();
		AI_Site_Connector_Content_Update::register_hooks();
		AI_Site_Connector_Cache::register_hooks();
		AI_Site_Connector_Media::register_hooks();
		AI_Site_Connector_Export::register_hooks();
		AI_Site_Connector_REST_Controller::register_hooks();
		AI_Site_Connector_OpenAPI::register_hooks();
		AI_Site_Connector_Discovery::register_hooks();
		AI_Site_Connector_Updater::register_hooks();
		AI_Site_Connector_Backup_Manager::register_hooks();
		AI_Site_Connector_API_Explorer::register_hooks();
		AI_Site_Connector_MCP_Server::register_hooks();

		if ( is_admin() ) {
			AI_Site_Connector_Admin_Page::register_hooks();
			AI_Site_Connector_Onboarding::register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'AI_Site_Connector_CLI' ) ) {
			// Public command surface — only hyphenated forms.
			//
			// We deliberately do NOT register the class itself as the parent
			// (`WP_CLI::add_command( 'ai-connector', 'AI_Site_Connector_CLI' )`)
			// because that would auto-expose every public PHP method as an
			// underscore-named subcommand, producing duplicate entries in
			// `wp help ai-connector`. PHP method names cannot contain hyphens,
			// so we map each hyphenated subcommand to its underscore method
			// explicitly. WP-CLI auto-synthesizes the parent help block from
			// the registered subcommands.
			WP_CLI::add_command( 'ai-connector status', array( 'AI_Site_Connector_CLI', 'status' ) );
			WP_CLI::add_command( 'ai-connector health', array( 'AI_Site_Connector_CLI', 'health' ) );
			WP_CLI::add_command( 'ai-connector self-test', array( 'AI_Site_Connector_CLI', 'self_test' ) );
			WP_CLI::add_command( 'ai-connector create-user', array( 'AI_Site_Connector_CLI', 'create_user' ) );
			WP_CLI::add_command( 'ai-connector generate-password', array( 'AI_Site_Connector_CLI', 'generate_password' ) );
			WP_CLI::add_command( 'ai-connector revoke-password', array( 'AI_Site_Connector_CLI', 'revoke_password' ) );
			WP_CLI::add_command( 'ai-connector rotate-password', array( 'AI_Site_Connector_CLI', 'rotate_password' ) );
			WP_CLI::add_command( 'ai-connector mcp-self-test', array( 'AI_Site_Connector_CLI', 'mcp_self_test' ) );
			WP_CLI::add_command( 'ai-connector routes', array( 'AI_Site_Connector_CLI', 'routes' ) );
			WP_CLI::add_command( 'ai-connector page-builder', array( 'AI_Site_Connector_CLI', 'page_builder' ) );
			WP_CLI::add_command( 'ai-connector redirects', array( 'AI_Site_Connector_CLI', 'redirects' ) );
			WP_CLI::add_command( 'ai-connector content-inventory', array( 'AI_Site_Connector_CLI', 'content_inventory' ) );
			WP_CLI::add_command( 'ai-connector media-audit', array( 'AI_Site_Connector_CLI', 'media_audit' ) );
			WP_CLI::add_command( 'ai-connector media-duplicates', array( 'AI_Site_Connector_CLI', 'media_duplicates' ) );
			WP_CLI::add_command( 'ai-connector broken-links', array( 'AI_Site_Connector_CLI', 'broken_links' ) );
			WP_CLI::add_command( 'ai-connector export', array( 'AI_Site_Connector_CLI', 'export' ) );
			WP_CLI::add_command( 'ai-connector disable', array( 'AI_Site_Connector_CLI', 'disable' ) );
			WP_CLI::add_command( 'ai-connector enable', array( 'AI_Site_Connector_CLI', 'enable' ) );
			WP_CLI::add_command( 'ai-connector update-content', array( 'AI_Site_Connector_CLI', 'update_content' ) );
			WP_CLI::add_command( 'ai-connector rollback-content', array( 'AI_Site_Connector_CLI', 'rollback_content' ) );
			WP_CLI::add_command( 'ai-connector access-preview', array( 'AI_Site_Connector_CLI', 'access_preview' ) );
		}
	}

	public static function activate() {
		AI_Site_Connector_Roles::ensure_role();
		AI_Site_Connector_Audit_Log::install_table();
		AI_Site_Connector_Audit_Log::maybe_schedule_cron();
		// Clear stale update transients so the Updates card on the very first
		// admin visit shows the current state of GitHub releases, not whatever
		// was cached before activation (or stale data from a prior version).
		if ( class_exists( 'AI_Site_Connector_Updater' ) ) {
			delete_site_transient( AI_Site_Connector_Updater::TRANSIENT_KEY );
		}
		delete_site_transient( 'update_plugins' );
		// Default new installs to "onboarding not yet completed" so the welcome notice shows.
		if ( false === get_option( AI_Site_Connector_Onboarding::OPTION, false )
			&& false === get_option( AI_Site_Connector_Onboarding::OPTION ) ) {
			add_option( AI_Site_Connector_Onboarding::OPTION, 0, '', false );
		}
		AI_Site_Connector_Audit_Log::record(
			'plugin_activated',
			array(
				'message' => sprintf( 'AI Site Connector v%s activated.', AI_SITE_CONNECTOR_VERSION ),
			)
		);
		flush_rewrite_rules();
	}

	public static function deactivate() {
		AI_Site_Connector_Audit_Log::unschedule_cron();
		AI_Site_Connector_Audit_Digest::unschedule_cron();
		if ( class_exists( 'AI_Site_Connector_Updater' ) ) {
			AI_Site_Connector_Updater::unschedule_cron();
		}
		if ( class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			AI_Site_Connector_App_Password_Meta::unschedule_cron();
		}
		AI_Site_Connector_Audit_Log::record(
			'plugin_deactivated',
			array(
				'message' => 'AI Site Connector deactivated. Logs, users, and application passwords are intentionally preserved.',
			)
		);
		flush_rewrite_rules();
	}

	public static function is_https() {
		return is_ssl() || ( defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN );
	}

	public static function require_https() {
		if ( self::is_https() ) {
			return true;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}
		if ( defined( 'AI_SITE_CONNECTOR_ALLOW_HTTP' ) && AI_SITE_CONNECTOR_ALLOW_HTTP ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether this plugin is active, and whether network-wide, so an update
	 * or rollback can restore the same scope afterwards (#130).
	 *
	 * @return array{active:bool, network:bool}
	 */
	public static function activation_state() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$network = is_multisite() && is_plugin_active_for_network( AI_SITE_CONNECTOR_BASENAME );
		return array(
			'active'  => $network || is_plugin_active( AI_SITE_CONNECTOR_BASENAME ),
			'network' => $network,
		);
	}

	/**
	 * Re-activate with the scope recorded by activation_state(). A no-op
	 * when the plugin was inactive before.
	 *
	 * @param array{active:bool, network:bool} $state  Recorded state.
	 * @param bool                             $silent Skip activation hooks.
	 * @return true|WP_Error|null
	 */
	public static function restore_activation( array $state, $silent ) {
		if ( empty( $state['active'] ) ) {
			return null;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		return activate_plugin( AI_SITE_CONNECTOR_BASENAME, '', ! empty( $state['network'] ), (bool) $silent );
	}

	/**
	 * Why Application Passwords are unavailable, when the cause can be
	 * confirmed (#133). Today that is Wordfence's "Disable WordPress
	 * application passwords" option (loginSec_disableApplicationPasswords),
	 * which hooks wp_is_application_passwords_available to false. Brute
	 * Force Protection alone is never reported as the cause. Returns null
	 * when passwords are available or the cause is unknown.
	 *
	 * @return array{source:string, message:string, fix:string, settings_url:string, doc_url:string}|null
	 */
	public static function app_passwords_blocker() {
		if ( self::app_passwords_available() ) {
			return null;
		}
		if ( class_exists( 'wfConfig' ) && is_callable( array( 'wfConfig', 'get' ) ) && wfConfig::get( 'loginSec_disableApplicationPasswords' ) ) {
			return array(
				'source'       => 'wordfence',
				'message'      => __( 'Wordfence is blocking Application Passwords: its "Disable WordPress application passwords" option is on.', 'ai-site-connector' ),
				'fix'          => __( 'In Wordfence → All Options → Brute Force Protection, uncheck "Disable WordPress application passwords", save, then reload this page. Only that one option needs to change.', 'ai-site-connector' ),
				'settings_url' => admin_url( 'admin.php?page=WordfenceWAF&subpage=waf_options#wf-option-loginSec-disableApplicationPasswords-label' ),
				'doc_url'      => 'https://www.wordfence.com/help/firewall/brute-force/#disable-wordpress-application-passwords',
			);
		}
		return null;
	}

	public static function app_passwords_available() {
		return class_exists( 'WP_Application_Passwords' )
			&& function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();
	}

	public static function rest_reachable() {
		$response = wp_remote_get(
			rest_url( 'wp/v2' ),
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		return wp_remote_retrieve_response_code( $response ) < 500;
	}
}
