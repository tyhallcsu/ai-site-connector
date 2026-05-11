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
		AI_Site_Connector_Audit_Log::register_hooks();
		AI_Site_Connector_Audit_Digest::register_hooks();
		AI_Site_Connector_REST_Controller::register_hooks();
		AI_Site_Connector_Updater::register_hooks();
		AI_Site_Connector_Backup_Manager::register_hooks();
		AI_Site_Connector_API_Explorer::register_hooks();

		if ( is_admin() ) {
			AI_Site_Connector_Admin_Page::register_hooks();
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
		}
	}

	public static function activate() {
		AI_Site_Connector_Roles::ensure_role();
		AI_Site_Connector_Audit_Log::install_table();
		AI_Site_Connector_Audit_Log::maybe_schedule_cron();
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
