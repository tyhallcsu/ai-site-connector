<?php
/**
 * /.well-known/ai-site-connector.json discovery file.
 *
 * Lets AI tools auto-detect the plugin's REST namespace, MCP endpoint,
 * OpenAPI URL, and supported auth methods without trial-and-error
 * probing. Modeled on the OAuth well-known pattern.
 *
 * Disable via constant AI_SITE_CONNECTOR_DISCOVERY_DISABLE.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Discovery {

	const QUERY_VAR = 'ai_site_connector_wellknown';

	public static function register_hooks() {
		if ( defined( 'AI_SITE_CONNECTOR_DISCOVERY_DISABLE' ) && AI_SITE_CONNECTOR_DISCOVERY_DISABLE ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );
	}

	public static function register_rewrite() {
		add_rewrite_rule(
			'^\.well-known/ai-site-connector\.json/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Build the discovery payload. Pure function of plugin constants + rest_url()
	 * so it can be exercised in unit tests without spinning up WP request state.
	 *
	 * Shape contract documented in docs/DISCOVERY.md. spec_version MUST be bumped
	 * when an existing field is renamed/removed/reshaped (additive changes do not
	 * require a bump).
	 *
	 * @return array<string, mixed>
	 */
	public static function build_payload() {
		return array(
			'spec_version'      => '1',
			'plugin'            => 'ai-site-connector',
			'version'           => AI_SITE_CONNECTOR_VERSION,
			'homepage'          => 'https://github.com/tyhallcsu/ai-site-connector',
			'rest_namespace'    => AI_SITE_CONNECTOR_REST_NAMESPACE,
			'rest_base'         => trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE,
			'openapi_url'       => rest_url( AI_SITE_CONNECTOR_REST_NAMESPACE . '/openapi.json' ),
			'tools_catalog_url' => rest_url( AI_SITE_CONNECTOR_REST_NAMESPACE . '/tools' ),
			'mcp'               => array(
				'http' => rest_url( AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp' ),
			),
			'auth_methods'      => array( 'basic_auth_application_password' ),
			'status'            => 'active',
		);
	}

	public static function maybe_serve() {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( self::build_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
