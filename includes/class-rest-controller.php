<?php
/**
 * REST API endpoints exposed by the plugin.
 *
 * Namespace: ai-site-connector/v1
 *
 * The surface is narrow and permission-gated. Every write path runs through
 * AI_Site_Connector_Permissions::require_permission() AND the underlying WP
 * capability check. There is no file editor, no SQL exec, no plugin installer.
 *
 * Read endpoints
 *  - GET  /health                    (public, MINIMAL summary; richer when authed)
 *  - GET  /me/capabilities           (auth; returns ONLY the calling user's caps)
 *  - GET  /site-info                 (auth, edit_posts)
 *  - GET  /plugins                   (auth, manage_options)
 *  - GET  /themes                    (auth, manage_options)
 *  - GET  /pages                     (auth, edit_pages)
 *  - GET  /posts                     (auth, edit_posts)
 *  - GET  /tools                     (auth; MCP tool catalog with per-tool allow state)
 *  - GET  /diagnostics/site-report   (auth, manage_options)
 *  - GET  /export/media-manifest     (auth, edit_posts)
 *  - GET  /export/recent-changes     (auth, edit_posts)
 *  - GET  /export/page/<id>          (auth, edit_posts)
 *  - GET  /export/site-manifest      (auth, manage_options)
 *
 * Write endpoints (added in v0.4.0; each requires its own permission slug)
 *  - POST /cache/purge               (auth, manage_options + purge_cache)
 *  - POST /media/sideload            (auth, upload_files + upload_media)
 *  - POST /credentials/rotate-password (auth, manage_options)
 *
 * One-time-token endpoint
 *  - GET  /connection-pack/<token>   (token IS the credential; 5-min single-use)
 *
 * Sibling controllers register two more routes in the same namespace:
 *  - POST /mcp                       (class-mcp-server.php; JSON-RPC 2.0 MCP transport)
 *  - GET  /openapi.json              (class-openapi.php; public OpenAPI 3 spec)
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_REST_Controller {

	const LAST_REQUEST_OPTION = 'ai_site_connector_last_request_at';

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Enforce per-Application-Password scopes + IP allowlist + expiry on
		// EVERY REST route (plugin namespace and core /wp/v2/*). Runs at
		// priority 9 — before maybe_stamp_last_request — so denied requests
		// don't get counted as "successful MCP request".
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_enforce_app_password_extras' ), 9, 3 );
		// Track the most recent authenticated plugin-namespace request so
		// the admin Connection Test page can show "last successful MCP
		// request". Wired as a no-op filter — we only read the request URI.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_stamp_last_request' ), 10, 3 );
	}

	/**
	 * Per-Application-Password enforcement filter. If the request is authed
	 * via App Password and the password has extras (scopes / IP allowlist /
	 * expiry), short-circuit with a WP_Error when checks fail.
	 *
	 * @param mixed           $result  Default null (continue dispatch).
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 * @return mixed
	 */
	public static function maybe_enforce_app_password_extras( $result, $server, $request ) {
		unset( $server );
		// If an earlier filter already returned a WP_Error, don't override it.
		if ( is_wp_error( $result ) || ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}
		// Cheap bail: only meaningful once the user is resolved. Returns null
		// for guest / cookie / nonce auth (which doesn't have a UUID anyway).
		if ( ! class_exists( 'AI_Site_Connector_Permissions' ) ) {
			return $result;
		}
		$check = AI_Site_Connector_Permissions::require_route_scope(
			(string) $request->get_route(),
			(string) $request->get_method()
		);
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $result;
	}

	public static function maybe_stamp_last_request( $result, $server, $request ) {
		if ( is_user_logged_in() && $request instanceof WP_REST_Request ) {
			$route = (string) $request->get_route();
			if ( 0 === strpos( $route, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/' ) ) {
				update_option( self::LAST_REQUEST_OPTION, gmdate( 'c' ), false );
			}
		}
		return $result;
	}

	/**
	 * Catalog of MCP-style tools this plugin exposes. Drives the
	 * `/tools` route and the Connection Test admin page.
	 *
	 * Each entry carries the original (name, permission, method, route,
	 * description) plus tool-metadata layer (risk_level, read_only,
	 * supports_dry_run, input_schema, output_schema) so callers can plan
	 * before invoking. `risk_level` ∈ {read, low, medium, high, destructive}.
	 */
	public static function tools_catalog() {
		return array(
			array(
				'name'             => 'site_capability_report',
				'permission'       => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'           => 'GET',
				'route'            => '/diagnostics/site-report',
				'description'      => 'Return a structured capability report: WP/PHP versions, plugins, builders, SEO/cache detection, REST status, user caps, env limits, cron.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'purge_cache',
				'permission'       => AI_Site_Connector_Permissions::TOOL_PURGE_CACHE,
				'method'           => 'POST',
				'route'            => '/cache/purge',
				'description'      => 'Purge supported cache layers and return a structured report. Layers: object, WP Rocket, LiteSpeed, W3TC, Elementor, Cloudflare.',
				'risk_level'       => 'medium',
				'read_only'        => false,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'object'     => array( 'type' => 'boolean' ),
						'rocket'     => array( 'type' => 'boolean' ),
						'litespeed'  => array( 'type' => 'boolean' ),
						'w3tc'       => array( 'type' => 'boolean' ),
						'elementor'  => array( 'type' => 'boolean' ),
						'cloudflare' => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'upload_media',
				'permission'       => AI_Site_Connector_Permissions::TOOL_UPLOAD_MEDIA,
				'method'           => 'POST',
				'route'            => '/media/sideload',
				'description'      => 'Sideload a URL into the Media Library with title/alt/caption/description; optionally set featured image and social-image SEO meta.',
				'risk_level'       => 'medium',
				'read_only'        => false,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'                => array( 'type' => 'string' ),
						'title'              => array( 'type' => 'string' ),
						'alt_text'           => array( 'type' => 'string' ),
						'caption'            => array( 'type' => 'string' ),
						'description'        => array( 'type' => 'string' ),
						'post_id'            => array( 'type' => 'integer' ),
						'set_featured_image' => array( 'type' => 'boolean' ),
						'seo_social_image'   => array( 'type' => 'boolean' ),
						'filename_override'  => array( 'type' => 'string' ),
					),
				),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'export_media_manifest',
				'permission'       => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'           => 'GET',
				'route'            => '/export/media-manifest',
				'description'      => 'Return a JSON manifest of all attachments with metadata (id, url, alt, caption, sha256, etc.) for downstream repo sync.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'limit'          => array( 'type' => 'integer' ),
						'offset'         => array( 'type' => 'integer' ),
						'include_sha256' => array( 'type' => 'boolean' ),
					),
				),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'export_recent_changes',
				'permission'       => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'           => 'GET',
				'route'            => '/export/recent-changes',
				'description'      => 'Return posts/pages modified since the given UTC datetime, with content hash for diffing.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'limit'      => array( 'type' => 'integer' ),
						'since'      => array( 'type' => 'string' ),
						'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'export_page_content',
				'permission'       => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'           => 'GET',
				'route'            => '/export/page/<id>',
				'description'      => 'Return a single page/post body, status, slug, modified timestamp, and featured image reference.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'export_site_manifest',
				'permission'       => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'           => 'GET',
				'route'            => '/export/site-manifest',
				'description'      => 'Aggregate manifest: counts + recent changes + detected plugins for a one-call overview.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(),
				'output_schema'    => array( 'type' => 'object' ),
			),
			array(
				'name'             => 'mcp_self_test',
				'permission'       => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'           => 'GET',
				'route'            => '/diagnostics/self-test',
				'description'      => 'Structured pass/warn/fail self-test of the MCP surface — plugin loaded, REST reachable, MCP route registered, uploads writable, SEO/page-builder detected, audit log present, SEO dry-run invariant.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'checks'  => array( 'type' => 'array' ),
						'summary' => array( 'type' => 'object' ),
					),
				),
			),
			array(
				'name'             => 'rest_route_inventory',
				'permission'       => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'           => 'GET',
				'route'            => '/diagnostics/rest-routes',
				'description'      => 'Live REST route inventory via rest_get_server()->get_routes(). Never serialises callables — only echoes has_permission_callback boolean. Read-only.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'route_count' => array( 'type' => 'integer' ),
						'namespaces'  => array( 'type' => 'array' ),
						'routes'      => array( 'type' => 'array' ),
					),
				),
			),
			array(
				'name'             => 'page_builder_detect',
				'permission'       => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'           => 'GET',
				'route'            => '/diagnostics/page-builder',
				'description'      => 'Page builder detection: site-level always, per-post optional via ?post_ids=. Read-only.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'post_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'site'     => array( 'type' => 'object' ),
						'per_post' => array( 'type' => 'object' ),
					),
				),
			),
			array(
				'name'             => 'redirect_export',
				'permission'       => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'           => 'GET',
				'route'            => '/diagnostics/redirects',
				'description'      => 'Detect redirect plugins (Rank Math, Redirection, AIOSEO, Yoast Premium) and export existing redirects. Read-only; falls back to plugin_detected="none" gracefully.',
				'risk_level'       => 'read',
				'read_only'        => true,
				'supports_dry_run' => false,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'limit'  => array( 'type' => 'integer' ),
						'offset' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'    => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_detected' => array( 'type' => 'string' ),
						'count'           => array( 'type' => 'integer' ),
						'redirects'       => array( 'type' => 'array' ),
					),
				),
			),
		);
	}

	public static function register_routes() {
		$ns = AI_SITE_CONNECTOR_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_health' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/me/capabilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_me_capabilities' ),
				'permission_callback' => array( __CLASS__, 'auth_logged_in' ),
			)
		);

		register_rest_route(
			$ns,
			'/site-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_site_info' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_posts' ),
			)
		);

		register_rest_route(
			$ns,
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_plugins' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_themes' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_pages' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_pages' ),
			)
		);

		register_rest_route(
			$ns,
			'/posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_posts' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_posts' ),
			)
		);

		// === MCP tool surface (v0.2.0) ============================================

		register_rest_route(
			$ns,
			'/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_tools_list' ),
				'permission_callback' => array( __CLASS__, 'auth_logged_in' ),
			)
		);

		register_rest_route(
			$ns,
			'/diagnostics/site-report',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_site_report' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'route_cache_purge' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
				'args'                => array(
					'object'     => array( 'type' => 'boolean', 'default' => true ),
					'rocket'     => array( 'type' => 'boolean', 'default' => true ),
					'litespeed'  => array( 'type' => 'boolean', 'default' => true ),
					'w3tc'       => array( 'type' => 'boolean', 'default' => true ),
					'elementor'  => array( 'type' => 'boolean', 'default' => true ),
					'cloudflare' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/media/sideload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'route_media_sideload' ),
				'permission_callback' => array( __CLASS__, 'auth_upload' ),
				'args'                => array(
					'url'                => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
					'title'              => array( 'type' => 'string' ),
					'alt_text'           => array( 'type' => 'string' ),
					'caption'            => array( 'type' => 'string' ),
					'description'        => array( 'type' => 'string' ),
					'post_id'            => array( 'type' => 'integer', 'default' => 0 ),
					'set_featured_image' => array( 'type' => 'boolean', 'default' => false ),
					'seo_social_image'   => array( 'type' => 'boolean', 'default' => false ),
					'filename_override'  => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/export/media-manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_export_media_manifest' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_posts' ),
				'args'                => array(
					'limit'          => array( 'type' => 'integer', 'default' => 1000 ),
					'offset'         => array( 'type' => 'integer', 'default' => 0 ),
					'include_sha256' => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/export/recent-changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_export_recent_changes' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_posts' ),
				'args'                => array(
					'limit'      => array( 'type' => 'integer', 'default' => 50 ),
					'since'      => array( 'type' => 'string' ),
					'post_types' => array( 'type' => 'array', 'default' => array( 'post', 'page' ) ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/export/page/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_export_page_content' ),
				'permission_callback' => array( __CLASS__, 'auth_edit_posts' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/export/site-manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_export_site_manifest' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/credentials/rotate-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'route_rotate_password' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer', 'required' => true ),
					'uuid'    => array( 'type' => 'string',  'required' => true ),
					'name'    => array( 'type' => 'string',  'required' => false ),
				),
			)
		);

		// One-time-token connection-pack download. Public on purpose: the
		// token IS the credential. 5-minute single-use TTL enforced by the
		// transient. Captured tokens that have already been consumed get 410.
		register_rest_route(
			$ns,
			'/connection-pack/(?P<token>[A-Za-z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_consume_pack_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		// === Diagnostics tool surface (queue batch) ==============================

		register_rest_route(
			$ns,
			'/diagnostics/self-test',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_self_test' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/diagnostics/rest-routes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_rest_routes' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
			)
		);

		register_rest_route(
			$ns,
			'/diagnostics/page-builder',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_page_builder' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
				'args'                => array(
					'post_ids' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/diagnostics/redirects',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_redirects' ),
				'permission_callback' => array( __CLASS__, 'auth_admin' ),
				'args'                => array(
					'limit'  => array( 'type' => 'integer', 'default' => 500 ),
					'offset' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	public static function route_consume_pack_token( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		$pack  = AI_Site_Connector_Connection_Pack_Token::consume( $token );
		if ( is_wp_error( $pack ) ) {
			return $pack;
		}
		// Force download — this is JSON containing a plaintext password.
		$response = new WP_REST_Response( $pack, 200 );
		$response->header( 'Content-Disposition', 'attachment; filename="ai-site-connector-pack.json"' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	public static function route_rotate_password( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'user_id' );
		$uuid    = (string) $request->get_param( 'uuid' );
		$name    = $request->get_param( 'name' );
		$res     = AI_Site_Connector_Application_Passwords::rotate( $user_id, $uuid, $name );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public static function auth_upload() {
		return is_user_logged_in() && current_user_can( 'upload_files' );
	}

	// === MCP tool route handlers ==============================================
	//
	// Each handler:
	//   1. Calls AI_Site_Connector_Permissions::require_permission()
	//      — short-circuits with a 403 WP_Error if the tool is disabled.
	//   2. Invokes the worker class.
	//   3. Wraps the result in a REST response.
	//
	// Audit logging happens inside the worker classes (cache/media/export)
	// so the same code path covers REST + CLI + admin-post.

	public static function route_tools_list( WP_REST_Request $request ) {
		$catalog = self::tools_catalog();
		// Reflect each tool's current allow state for the calling user.
		foreach ( $catalog as &$tool ) {
			$tool['allowed'] = AI_Site_Connector_Permissions::can( (string) $tool['permission'] );
		}
		unset( $tool );
		return rest_ensure_response(
			array(
				'tools'                  => $catalog,
				'read_only_mode'         => AI_Site_Connector_Permissions::is_read_only(),
				'last_successful_request' => (string) get_option( self::LAST_REQUEST_OPTION, '' ),
			)
		);
	}

	public static function route_site_report( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$report = AI_Site_Connector_Diagnostics::generate();
		AI_Site_Connector_Audit_Log::record(
			'diagnostics_report',
			array(
				'tool'    => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'status'  => AI_Site_Connector_Audit_Log::STATUS_SUCCESS,
				'summary' => 'Site capability report generated via REST.',
			)
		);
		return rest_ensure_response( $report );
	}

	public static function route_cache_purge( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_PURGE_CACHE );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$opts = array(
			'object'     => (bool) $request->get_param( 'object' ),
			'rocket'     => (bool) $request->get_param( 'rocket' ),
			'litespeed'  => (bool) $request->get_param( 'litespeed' ),
			'w3tc'       => (bool) $request->get_param( 'w3tc' ),
			'elementor'  => (bool) $request->get_param( 'elementor' ),
			'cloudflare' => (bool) $request->get_param( 'cloudflare' ),
		);
		return rest_ensure_response( AI_Site_Connector_Cache::purge( $opts ) );
	}

	public static function route_media_sideload( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_UPLOAD_MEDIA );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$args = array(
			'url'                => (string) $request->get_param( 'url' ),
			'title'              => (string) $request->get_param( 'title' ),
			'alt_text'           => (string) $request->get_param( 'alt_text' ),
			'caption'            => (string) $request->get_param( 'caption' ),
			'description'        => (string) $request->get_param( 'description' ),
			'post_id'            => (int) $request->get_param( 'post_id' ),
			'set_featured_image' => (bool) $request->get_param( 'set_featured_image' ),
			'seo_social_image'   => (bool) $request->get_param( 'seo_social_image' ),
			'filename_override'  => (string) $request->get_param( 'filename_override' ),
		);
		$res = AI_Site_Connector_Media::sideload( $args );
		if ( is_wp_error( $res ) ) {
			AI_Site_Connector_Audit_Log::record(
				'media_upload_failed',
				array(
					'tool'    => AI_Site_Connector_Permissions::TOOL_UPLOAD_MEDIA,
					'status'  => AI_Site_Connector_Audit_Log::STATUS_FAILURE,
					'summary' => 'Media sideload failed: ' . $res->get_error_message(),
					'meta'    => array( 'source_host' => (string) wp_parse_url( $args['url'], PHP_URL_HOST ) ),
				)
			);
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public static function route_export_media_manifest( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$args = array(
			'limit'          => (int) $request->get_param( 'limit' ),
			'offset'         => (int) $request->get_param( 'offset' ),
			'include_sha256' => (bool) $request->get_param( 'include_sha256' ),
		);
		return rest_ensure_response( AI_Site_Connector_Export::media_manifest( $args ) );
	}

	public static function route_export_recent_changes( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$args = array(
			'limit'      => (int) $request->get_param( 'limit' ),
			'since'      => (string) $request->get_param( 'since' ),
			'post_types' => (array) $request->get_param( 'post_types' ),
		);
		return rest_ensure_response( AI_Site_Connector_Export::recent_changes( $args ) );
	}

	public static function route_export_page_content( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id  = (int) $request->get_param( 'id' );
		$res = AI_Site_Connector_Export::page_content( $id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	public static function route_export_site_manifest( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return rest_ensure_response( AI_Site_Connector_Export::site_manifest() );
	}

	// === Diagnostics tool surface (queue batch) ==============================

	public static function route_self_test( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = AI_Site_Connector_Diagnostics::self_test();
		AI_Site_Connector_Audit_Log::record(
			'mcp_self_test',
			array(
				'tool'    => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'status'  => AI_Site_Connector_Audit_Log::STATUS_SUCCESS,
				'summary' => sprintf(
					'MCP self-test: %d pass / %d warn / %d fail.',
					isset( $result['summary']['pass'] ) ? (int) $result['summary']['pass'] : 0,
					isset( $result['summary']['warn'] ) ? (int) $result['summary']['warn'] : 0,
					isset( $result['summary']['fail'] ) ? (int) $result['summary']['fail'] : 0
				),
			)
		);
		return rest_ensure_response( $result );
	}

	public static function route_rest_routes( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return rest_ensure_response( AI_Site_Connector_Diagnostics::rest_routes() );
	}

	public static function route_page_builder( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$args = array(
			'post_ids' => (array) $request->get_param( 'post_ids' ),
		);
		return rest_ensure_response( AI_Site_Connector_Diagnostics::page_builder( $args ) );
	}

	public static function route_redirects( WP_REST_Request $request ) {
		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$args = array(
			'limit'  => (int) $request->get_param( 'limit' ),
			'offset' => (int) $request->get_param( 'offset' ),
		);
		return rest_ensure_response( AI_Site_Connector_Diagnostics::redirects( $args ) );
	}

	public static function auth_admin() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function auth_logged_in() {
		return is_user_logged_in();
	}

	public static function auth_edit_posts() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public static function auth_edit_pages() {
		return is_user_logged_in() && current_user_can( 'edit_pages' );
	}

	public static function route_health( WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$is_auth = $user && $user->ID > 0;

		// Minimal payload for unauthenticated callers — does NOT leak WP/PHP versions,
		// theme, plugin counts, multisite status, or current user details.
		$payload = array(
			'plugin'         => 'ai-site-connector',
			'plugin_version' => AI_SITE_CONNECTOR_VERSION,
			'site_url'       => home_url(),
			'rest_url'       => rest_url(),
			'https'          => AI_Site_Connector_Plugin::is_https(),
			'authenticated'  => (bool) $is_auth,
			'timestamp'      => gmdate( 'c' ),
		);

		if ( $is_auth ) {
			$active_thm  = wp_get_theme();
			$plugins_act = get_option( 'active_plugins', array() );

			$payload['wp_version']          = get_bloginfo( 'version' );
			$payload['php_version']         = PHP_VERSION;
			$payload['is_multisite']        = is_multisite();
			$payload['app_passwords']       = AI_Site_Connector_Plugin::app_passwords_available();
			$payload['active_theme']        = $active_thm ? $active_thm->get( 'Name' ) : '';
			$payload['active_plugin_count'] = is_array( $plugins_act ) ? count( $plugins_act ) : 0;
			$payload['user']                = array(
				'id'    => (int) $user->ID,
				'login' => $user->user_login,
				'roles' => array_values( (array) $user->roles ),
			);

			AI_Site_Connector_Audit_Log::record(
				'health_accessed_authenticated',
				array(
					'message' => sprintf( 'Health endpoint accessed by authenticated user %s (id=%d).', $user->user_login, $user->ID ),
				)
			);
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Return ONLY the calling user's roles + a curated capability map.
	 *
	 * Lets an AI agent (or any authenticated client) introspect what it can
	 * actually do on this site without making 5 speculative requests and
	 * observing 403s. The endpoint never reveals another user's permissions
	 * — current_user_can() is keyed to the authenticated user, period.
	 *
	 * The capability list is curated rather than comprehensive: it covers
	 * the WP core caps that matter for content/site automation. Sites that
	 * need additional caps reported can extend via:
	 *
	 *   add_filter( 'ai_site_connector_introspection_caps', function ( $caps ) {
	 *       $caps[] = 'gf_full_access'; // e.g. a plugin's custom cap
	 *       return $caps;
	 *   } );
	 */
	public static function route_me_capabilities( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		$caps = array(
			// Reading.
			'read',
			// Posts and pages — own.
			'edit_posts',
			'edit_pages',
			'edit_published_posts',
			'edit_published_pages',
			'publish_posts',
			'publish_pages',
			// Posts and pages — others.
			'edit_others_posts',
			'edit_others_pages',
			// Deletes.
			'delete_posts',
			'delete_pages',
			'delete_published_posts',
			'delete_published_pages',
			'delete_others_posts',
			'delete_others_pages',
			// Media.
			'upload_files',
			// Comments.
			'moderate_comments',
			// Users.
			'list_users',
			'edit_users',
			'create_users',
			'delete_users',
			// Site administration — explicitly listed so AIs can confirm
			// they do NOT have these (the common case).
			'manage_options',
			'manage_categories',
			'install_plugins',
			'activate_plugins',
			'edit_plugins',
			'install_themes',
			'switch_themes',
			'edit_themes',
			'edit_files',
			'export',
			'import',
			'unfiltered_html',
		);

		/**
		 * Filter the capability list reported by /me/capabilities.
		 *
		 * Only adds keys to the introspection response — does not grant or
		 * revoke any capability. Pure introspection extension.
		 *
		 * @param string[] $caps Capability slugs.
		 */
		$caps = (array) apply_filters( 'ai_site_connector_introspection_caps', $caps );
		$caps = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );

		$capabilities = array();
		foreach ( $caps as $cap ) {
			$capabilities[ $cap ] = (bool) user_can( $user, $cap );
		}

		return rest_ensure_response(
			array(
				'user_id'              => (int) $user->ID,
				'login'                => $user->user_login,
				'roles'                => array_values( (array) $user->roles ),
				'capabilities'         => $capabilities,
				'operator_role_active' => in_array( AI_SITE_CONNECTOR_OPERATOR_ROLE, (array) $user->roles, true ),
			)
		);
	}

	public static function route_site_info( WP_REST_Request $request ) {
		$theme = wp_get_theme();
		return rest_ensure_response(
			array(
				'site_name'      => get_bloginfo( 'name' ),
				'site_url'       => home_url(),
				'admin_email'    => get_bloginfo( 'admin_email' ),
				'language'       => get_bloginfo( 'language' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'php_version'    => PHP_VERSION,
				'active_theme'   => array(
					'name'    => $theme ? $theme->get( 'Name' ) : '',
					'version' => $theme ? $theme->get( 'Version' ) : '',
					'parent'  => $theme && $theme->parent() ? $theme->parent()->get( 'Name' ) : '',
				),
				'is_multisite'   => is_multisite(),
			)
		);
	}

	public static function route_plugins( WP_REST_Request $request ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = array_flip( (array) get_option( 'active_plugins', array() ) );

		$out = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'file'        => $file,
				'name'        => isset( $data['Name'] ) ? $data['Name'] : '',
				'version'     => isset( $data['Version'] ) ? $data['Version'] : '',
				'author'      => isset( $data['Author'] ) ? wp_strip_all_tags( $data['Author'] ) : '',
				'description' => isset( $data['Description'] ) ? wp_strip_all_tags( $data['Description'] ) : '',
				'active'      => isset( $active[ $file ] ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function route_themes( WP_REST_Request $request ) {
		$themes = wp_get_themes();
		$active = wp_get_theme();
		$out    = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
				'active'     => $active && $active->get_stylesheet() === $stylesheet,
			);
		}
		return rest_ensure_response( $out );
	}

	public static function route_pages( WP_REST_Request $request ) {
		$pages = get_pages(
			array(
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
				'number'      => 200,
			)
		);
		$out = array();
		foreach ( $pages as $p ) {
			$out[] = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title,
				'slug'   => $p->post_name,
				'status' => $p->post_status,
				'parent' => (int) $p->post_parent,
				'link'   => get_permalink( $p->ID ),
			);
		}
		return rest_ensure_response( $out );
	}

	public static function route_posts( WP_REST_Request $request ) {
		$posts = get_posts(
			array(
				'posts_per_page' => 50,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title,
				'slug'   => $p->post_name,
				'status' => $p->post_status,
				'date'   => $p->post_date_gmt,
				'link'   => get_permalink( $p->ID ),
			);
		}
		return rest_ensure_response( $out );
	}
}
