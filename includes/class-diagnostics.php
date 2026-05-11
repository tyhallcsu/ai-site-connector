<?php
/**
 * Site capability report — comprehensive diagnostics surface for AI tools.
 *
 * Read-only. Returns a structured snapshot of everything an AI agent needs
 * to plan a safe interaction: WP/PHP versions, theme/plugin landscape,
 * page-builder & SEO & cache plugin detection, REST/MCP status, current
 * user capabilities, environment limits, cron health.
 *
 * No secrets, tokens, or credentials are ever included in the report.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Diagnostics {

	public static function register_hooks() {
		// Pure service class — no hooks to register. Method here for
		// symmetry with the other modules so class-plugin.php can call it
		// without special-casing.
	}

	/**
	 * Build the full capability report. Safe for both authenticated REST
	 * callers (with view_diagnostics permission) and the admin Diagnostics
	 * tab — same shape, no caller-specific redaction needed.
	 *
	 * @return array
	 */
	public static function generate() {
		global $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$theme  = wp_get_theme();
		$parent = $theme && $theme->parent() ? $theme->parent() : null;

		$user            = wp_get_current_user();
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$all_plugins     = get_plugins();
		$active_normalized = self::normalize_active_plugins( $all_plugins, $active_plugins );

		$uploads = wp_upload_dir();

		$report = array(
			'generated_at'  => gmdate( 'c' ),
			'plugin'        => array(
				'name'    => 'ai-site-connector',
				'version' => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? AI_SITE_CONNECTOR_VERSION : '',
			),
			'wordpress'     => array(
				'version'     => get_bloginfo( 'version' ),
				'is_multisite' => is_multisite(),
				'site_url'    => home_url(),
				'rest_url'    => rest_url(),
				'admin_email' => get_bloginfo( 'admin_email' ),
				'language'    => get_bloginfo( 'language' ),
				'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
				'https'       => AI_Site_Connector_Plugin::is_https(),
				'app_passwords_available' => AI_Site_Connector_Plugin::app_passwords_available(),
				'db_version'  => isset( $wpdb->db_version ) ? $wpdb->db_version() : '',
			),
			'php'           => array(
				'version'             => PHP_VERSION,
				'memory_limit'        => self::ini_bytes( 'memory_limit' ),
				'memory_limit_raw'    => (string) ini_get( 'memory_limit' ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'max_input_vars'      => (int) ini_get( 'max_input_vars' ),
				'post_max_size'       => self::ini_bytes( 'post_max_size' ),
				'post_max_size_raw'   => (string) ini_get( 'post_max_size' ),
				'upload_max_filesize' => self::ini_bytes( 'upload_max_filesize' ),
				'upload_max_filesize_raw' => (string) ini_get( 'upload_max_filesize' ),
				'curl_available'      => function_exists( 'curl_init' ),
				'mbstring_available'  => function_exists( 'mb_strlen' ),
				'imagick_available'   => extension_loaded( 'imagick' ),
				'gd_available'        => extension_loaded( 'gd' ),
			),
			'wp_uploads'    => array(
				'basedir'      => isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '',
				'baseurl'      => isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '',
				'writable'     => isset( $uploads['basedir'] ) ? wp_is_writable( (string) $uploads['basedir'] ) : false,
				'max_upload_size' => (int) wp_max_upload_size(),
			),
			'theme'         => array(
				'name'        => $theme ? (string) $theme->get( 'Name' ) : '',
				'version'     => $theme ? (string) $theme->get( 'Version' ) : '',
				'template'    => $theme ? (string) $theme->get_template() : '',
				'stylesheet'  => $theme ? (string) $theme->get_stylesheet() : '',
				'is_block_theme' => $theme && method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false,
				'parent'      => $parent ? array(
					'name'       => (string) $parent->get( 'Name' ),
					'version'    => (string) $parent->get( 'Version' ),
					'stylesheet' => (string) $parent->get_stylesheet(),
				) : null,
			),
			'active_plugins' => $active_normalized,
			'detected'      => array(
				'page_builders' => self::detect_page_builders( $active_plugins, $theme ),
				'seo'           => self::detect_seo( $active_plugins ),
				'cache'         => self::detect_cache( $active_plugins ),
			),
			'rest_mcp'      => array(
				'namespace'           => AI_SITE_CONNECTOR_REST_NAMESPACE,
				'health_endpoint'     => trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health',
				'rest_reachable'      => AI_Site_Connector_Plugin::rest_reachable(),
				'registered_routes'   => self::registered_plugin_routes(),
				'read_only_mode'      => class_exists( 'AI_Site_Connector_Permissions' ) ? AI_Site_Connector_Permissions::is_read_only() : false,
				'tool_permissions'    => class_exists( 'AI_Site_Connector_Permissions' )
					? array_map(
						static function ( $row ) {
							return array( 'enabled' => (bool) $row['enabled'], 'default' => (bool) $row['default'] );
						},
						AI_Site_Connector_Permissions::get_all()
					)
					: array(),
			),
			'current_user'  => array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'roles'        => array_values( (array) $user->roles ),
				'capabilities' => self::user_caps_snapshot( $user ),
			),
			'cron'          => array(
				'disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'next_audit_prune' => wp_next_scheduled( AI_Site_Connector_Audit_Log::CRON_HOOK ),
				'doing_cron'       => defined( 'DOING_CRON' ) && DOING_CRON,
				'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			),
			'database'      => array(
				'audit_log_table'   => AI_Site_Connector_Audit_Log::table_name(),
				'audit_log_version' => (string) get_option( AI_Site_Connector_Audit_Log::DB_VERSION_OPTION, '' ),
				'audit_retention_days' => AI_Site_Connector_Audit_Log::retention_days(),
			),
		);

		/**
		 * Filter the assembled diagnostics report just before it's returned.
		 *
		 * Use this to extend the report with site-specific signals (e.g. a
		 * custom plugin's health bit). Do not use it to add secrets — the
		 * report is returned over REST to any caller with view_diagnostics.
		 *
		 * @param array $report
		 */
		return (array) apply_filters( 'ai_site_connector_diagnostics_report', $report );
	}

	private static function normalize_active_plugins( $all, $active ) {
		$out = array();
		foreach ( (array) $active as $file ) {
			$data = isset( $all[ $file ] ) ? $all[ $file ] : array();
			$out[] = array(
				'file'    => (string) $file,
				'slug'    => self::slug_from_file( (string) $file ),
				'name'    => isset( $data['Name'] ) ? wp_strip_all_tags( (string) $data['Name'] ) : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			);
		}
		return $out;
	}

	private static function slug_from_file( $file ) {
		$slash = strpos( $file, '/' );
		return false === $slash ? $file : substr( $file, 0, $slash );
	}

	/**
	 * Detect installed page builders. Returns an array keyed by builder
	 * with a per-builder boolean. Stable contract — callers can branch on
	 * `$report['detected']['page_builders']['elementor'] === true`.
	 */
	private static function detect_page_builders( $active_plugins, $theme ) {
		$by_slug = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), (array) $active_plugins ) );
		return array(
			'elementor'      => isset( $by_slug['elementor'] ) || isset( $by_slug['elementor-pro'] ),
			'beaver_builder' => isset( $by_slug['beaver-builder-lite-version'] ) || isset( $by_slug['bb-plugin'] ),
			'divi'           => $theme && 'Divi' === $theme->get( 'Template' ),
			'gutenberg_block_theme' => $theme && method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme(),
			'oxygen'         => isset( $by_slug['oxygen'] ),
			'bricks_theme'   => $theme && 'Bricks' === $theme->get( 'Name' ),
		);
	}

	private static function detect_seo( $active_plugins ) {
		$by_slug = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), (array) $active_plugins ) );
		return array(
			'rank_math' => isset( $by_slug['seo-by-rank-math'] ) || isset( $by_slug['seo-by-rank-math-pro'] ),
			'yoast'     => isset( $by_slug['wordpress-seo'] ) || isset( $by_slug['wordpress-seo-premium'] ),
			'aioseo'    => isset( $by_slug['all-in-one-seo-pack'] ) || isset( $by_slug['all-in-one-seo-pack-pro'] ),
			'seopress'  => isset( $by_slug['wp-seopress'] ),
		);
	}

	private static function detect_cache( $active_plugins ) {
		$by_slug = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), (array) $active_plugins ) );
		return array(
			'wp_rocket'      => isset( $by_slug['wp-rocket'] ),
			'litespeed'      => isset( $by_slug['litespeed-cache'] ),
			'w3_total_cache' => isset( $by_slug['w3-total-cache'] ),
			'wp_super_cache' => isset( $by_slug['wp-super-cache'] ),
			'cache_enabler'  => isset( $by_slug['cache-enabler'] ),
			'elementor'      => isset( $by_slug['elementor'] ),
			'cloudflare'     => isset( $by_slug['cloudflare'] ),
			'object_cache'   => function_exists( 'wp_cache_flush' ) && wp_using_ext_object_cache(),
		);
	}

	private static function registered_plugin_routes() {
		$server = rest_get_server();
		if ( ! $server ) {
			return array();
		}
		$routes = $server->get_routes();
		$ns     = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/';
		$out    = array();
		foreach ( $routes as $route => $_handlers ) {
			if ( 0 === strpos( (string) $route, $ns ) ) {
				$out[] = (string) $route;
			}
		}
		sort( $out );
		return $out;
	}

	private static function user_caps_snapshot( $user ) {
		// Same curated list used by /me/capabilities — keeps the two
		// surfaces in sync. Anything plugin-specific is added via the
		// existing ai_site_connector_introspection_caps filter.
		$caps = array(
			'read',
			'edit_posts',
			'edit_pages',
			'publish_posts',
			'publish_pages',
			'edit_others_posts',
			'upload_files',
			'manage_options',
			'install_plugins',
			'edit_themes',
		);
		/** Re-use the same filter as the REST capabilities endpoint. */
		$caps = (array) apply_filters( 'ai_site_connector_introspection_caps', $caps );
		$caps = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );
		$out  = array();
		foreach ( $caps as $cap ) {
			$out[ $cap ] = (bool) user_can( $user, $cap );
		}
		return $out;
	}

	/**
	 * Convert an ini shorthand value ("256M") to an integer byte count.
	 *
	 * @param string $key ini key (memory_limit, post_max_size, upload_max_filesize)
	 * @return int Bytes, 0 if unparseable.
	 */
	private static function ini_bytes( $key ) {
		$raw = (string) ini_get( $key );
		if ( '' === $raw || '-1' === $raw ) {
			return -1;
		}
		$unit = strtolower( substr( $raw, -1 ) );
		$val  = (int) $raw;
		switch ( $unit ) {
			case 'g':
				return $val * 1024 * 1024 * 1024;
			case 'm':
				return $val * 1024 * 1024;
			case 'k':
				return $val * 1024;
			default:
				return $val;
		}
	}
}
