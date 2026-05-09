<?php
/**
 * WP-CLI commands.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class AI_Site_Connector_CLI {

	/**
	 * Show plugin status / connectivity diagnostics.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector status
	 */
	public function status() {
		$user = wp_get_current_user();
		$rows = array(
			array( 'site_url',         home_url() ),
			array( 'rest_url',         rest_url() ),
			array( 'wp_version',       get_bloginfo( 'version' ) ),
			array( 'php_version',      PHP_VERSION ),
			array( 'is_https',         AI_Site_Connector_Plugin::is_https() ? 'yes' : 'no' ),
			array( 'app_passwords',    AI_Site_Connector_Plugin::app_passwords_available() ? 'available' : 'unavailable' ),
			array( 'rest_reachable',   AI_Site_Connector_Plugin::rest_reachable() ? 'yes' : 'no' ),
			array( 'plugin_version',   AI_SITE_CONNECTOR_VERSION ),
			array( 'cli_user',         $user && $user->ID ? $user->user_login : '—' ),
		);
		\WP_CLI\Utils\format_items( 'table', array_map( function( $r ) { return array( 'key' => $r[0], 'value' => $r[1] ); }, $rows ), array( 'key', 'value' ) );
	}

	/**
	 * Run a health check (returns JSON).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector health
	 */
	public function health() {
		$req = new WP_REST_Request( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health' );
		$res = rest_do_request( $req );
		WP_CLI::log( wp_json_encode( $res->get_data(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Create a dedicated AI user.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login for the new user.
	 *
	 * [--email=<email>]
	 * : Email; defaults to ai-agent@<host>.
	 *
	 * [--role=<role>]
	 * : One of: administrator, editor, ai_site_operator (default).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector create-user --username=ai-agent --role=ai_site_operator
	 */
	public function create_user( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$role     = isset( $assoc['role'] ) ? $assoc['role'] : AI_SITE_CONNECTOR_OPERATOR_ROLE;
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$email    = isset( $assoc['email'] ) ? $assoc['email'] : ( 'ai-agent@' . $host );

		$res = AI_Site_Connector_User_Manager::create_user(
			array(
				'username' => $username,
				'email'    => $email,
				'role'     => $role,
				'display'  => 'AI Agent',
			)
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Created user %s with id %d.', $username, $res ) );
	}

	/**
	 * Generate an Application Password for a user.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login of the WordPress user that owns the new Application Password.
	 *
	 * [--name=<name>]
	 * : App password name; default: "Claude AI Connector - <host> - <date>".
	 *
	 * [--format=<format>]
	 * : json|table|yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector generate-password --username=ai-agent
	 */
	public function generate_password( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$name     = isset( $assoc['name'] ) ? $assoc['name'] : AI_Site_Connector_Application_Passwords::suggested_name();
		$user     = $username ? get_user_by( 'login', $username ) : null;
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}
		$res = AI_Site_Connector_Application_Passwords::create_for_user( $user->ID, $name );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		$pack = array(
			'site_url'             => home_url(),
			'rest_api_base'        => trailingslashit( rest_url() ),
			'username'             => $user->user_login,
			'application_password' => $res['password'],
			'app_password_uuid'    => $res['uuid'],
			'app_password_name'    => $res['name'],
			'test_endpoint'        => trailingslashit( rest_url() ) . 'wp/v2/users/me',
		);
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $pack, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( $format, array( $pack ), array_keys( $pack ) );
		}
		WP_CLI::warning( 'Save the application_password now — it will not be shown again.' );
	}

	/**
	 * Revoke an Application Password by UUID.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login of the WordPress user whose password is being revoked.
	 *
	 * --uuid=<uuid>
	 * : UUID of the Application Password to revoke (from `wp user application-password list` or the plugin UI).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector revoke-password --username=ai-agent --uuid=abc-123
	 */
	public function revoke_password( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$uuid     = isset( $assoc['uuid'] ) ? $assoc['uuid'] : '';
		$user     = $username ? get_user_by( 'login', $username ) : null;
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}
		$res = AI_Site_Connector_Application_Passwords::revoke( $user->ID, $uuid );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( 'Application Password revoked.' );
	}
}
