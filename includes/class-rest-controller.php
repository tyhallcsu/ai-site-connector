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

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
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
