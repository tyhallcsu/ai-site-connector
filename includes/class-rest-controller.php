<?php
/**
 * REST API endpoints exposed by the plugin.
 *
 * Namespace: ai-site-connector/v1
 *
 * Endpoints:
 *  - GET /health             (public, MINIMAL safe summary; richer payload only when authenticated)
 *  - GET /me/capabilities    (auth, any logged-in user; returns ONLY the calling user's caps)
 *  - GET /site-info          (auth, edit_posts)
 *  - GET /plugins            (auth, manage_options)
 *  - GET /themes             (auth, manage_options)
 *  - GET /pages              (auth, edit_pages)
 *  - GET /posts              (auth, edit_posts)
 *
 * No write endpoints, no arbitrary code paths.
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
		// Track the most recent authenticated plugin-namespace request so
		// the admin Connection Test page can show "last successful MCP
		// request". Wired as a no-op filter — we only read the request URI.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'maybe_stamp_last_request' ), 10, 3 );
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
	 */
	public static function tools_catalog() {
		return array(
			array(
				'name'       => 'site_capability_report',
				'permission' => AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS,
				'method'     => 'GET',
				'route'      => '/diagnostics/site-report',
				'description' => 'Return a structured capability report: WP/PHP versions, plugins, builders, SEO/cache detection, REST status, user caps, env limits, cron.',
			),
			array(
				'name'       => 'purge_cache',
				'permission' => AI_Site_Connector_Permissions::TOOL_PURGE_CACHE,
				'method'     => 'POST',
				'route'      => '/cache/purge',
				'description' => 'Purge supported cache layers and return a structured report. Layers: object, WP Rocket, LiteSpeed, W3TC, Elementor, Cloudflare.',
			),
			array(
				'name'       => 'upload_media',
				'permission' => AI_Site_Connector_Permissions::TOOL_UPLOAD_MEDIA,
				'method'     => 'POST',
				'route'      => '/media/sideload',
				'description' => 'Sideload a URL into the Media Library with title/alt/caption/description; optionally set featured image and social-image SEO meta.',
			),
			array(
				'name'       => 'export_media_manifest',
				'permission' => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'     => 'GET',
				'route'      => '/export/media-manifest',
				'description' => 'Return a JSON manifest of all attachments with metadata (id, url, alt, caption, sha256, etc.) for downstream repo sync.',
			),
			array(
				'name'       => 'export_recent_changes',
				'permission' => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'     => 'GET',
				'route'      => '/export/recent-changes',
				'description' => 'Return posts/pages modified since the given UTC datetime, with content hash for diffing.',
			),
			array(
				'name'       => 'export_page_content',
				'permission' => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'     => 'GET',
				'route'      => '/export/page/<id>',
				'description' => 'Return a single page/post body, status, slug, modified timestamp, and featured image reference.',
			),
			array(
				'name'       => 'export_site_manifest',
				'permission' => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'method'     => 'GET',
				'route'      => '/export/site-manifest',
				'description' => 'Aggregate manifest: counts + recent changes + detected plugins for a one-call overview.',
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
