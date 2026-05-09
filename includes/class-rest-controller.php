<?php
/**
 * REST API endpoints exposed by the plugin.
 *
 * Namespace: ai-site-connector/v1
 *
 * Endpoints:
 *  - GET /health        (public, MINIMAL safe summary; richer payload only when authenticated)
 *  - GET /site-info     (auth, edit_posts)
 *  - GET /plugins       (auth, manage_options)
 *  - GET /themes        (auth, manage_options)
 *  - GET /pages         (auth, edit_pages)
 *  - GET /posts         (auth, edit_posts)
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
