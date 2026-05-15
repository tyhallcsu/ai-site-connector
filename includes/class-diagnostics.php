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

	// === MCP self-test (Issue 18) =============================================

	/**
	 * Structured pass/warn/fail check list tailored to the MCP surface.
	 * Read-only. Complements generate() (which is a broader snapshot).
	 *
	 * @return array {
	 *   @type array $checks  Each entry: { name, status: 'pass'|'warn'|'fail', message }.
	 *   @type array $summary Counts keyed by status.
	 * }
	 */
	public static function self_test() {
		$checks = array();

		// Plugin loaded.
		$checks[] = array(
			'name'    => 'plugin_loaded',
			'status'  => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? 'pass' : 'fail',
			'message' => defined( 'AI_SITE_CONNECTOR_VERSION' )
				? sprintf( 'AI Site Connector v%s', AI_SITE_CONNECTOR_VERSION )
				: 'AI_SITE_CONNECTOR_VERSION constant missing.',
		);

		// REST server available.
		$rest_ok  = (bool) rest_get_server();
		$checks[] = array(
			'name'    => 'rest_server_available',
			'status'  => $rest_ok ? 'pass' : 'fail',
			'message' => $rest_ok ? 'rest_get_server() returns server.' : 'rest_get_server() unavailable.',
		);

		// MCP route registered (v0.5.0+).
		$mcp_route_present = false;
		if ( $rest_ok ) {
			$routes            = rest_get_server()->get_routes();
			$mcp_route_present = isset( $routes[ '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp' ] );
		}
		if ( defined( 'AI_SITE_CONNECTOR_MCP_DISABLE' ) && AI_SITE_CONNECTOR_MCP_DISABLE ) {
			$checks[] = array(
				'name'    => 'mcp_route_registered',
				'status'  => 'warn',
				'message' => 'MCP route intentionally disabled via AI_SITE_CONNECTOR_MCP_DISABLE.',
			);
		} else {
			$checks[] = array(
				'name'    => 'mcp_route_registered',
				'status'  => $mcp_route_present ? 'pass' : 'warn',
				'message' => $mcp_route_present
					? '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp present.'
					: 'MCP route not registered (class-mcp-server.php may be unloaded).',
			);
		}

		// Authenticated user + capabilities.
		$user      = wp_get_current_user();
		$logged_in = $user && $user->ID > 0;
		$checks[]  = array(
			'name'    => 'authenticated_user',
			'status'  => $logged_in ? 'pass' : 'warn',
			'message' => $logged_in
				? sprintf( 'Authenticated as %s (id=%d, roles=%s).', $user->user_login, $user->ID, implode( ',', (array) $user->roles ) )
				: 'Self-test called without authentication.',
		);

		// Upload directory writable.
		$uploads        = wp_upload_dir();
		$uploads_dir    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$uploads_okwrite = '' !== $uploads_dir && wp_is_writable( $uploads_dir );
		$checks[]       = array(
			'name'    => 'uploads_writable',
			'status'  => $uploads_okwrite ? 'pass' : 'fail',
			'message' => $uploads_okwrite
				? sprintf( 'Uploads directory writable: %s', $uploads_dir )
				: sprintf( 'Uploads directory not writable: %s', $uploads_dir ),
		);

		// SEO plugin detected.
		$seo_plugin = class_exists( 'AI_Site_Connector_SEO' )
			? AI_Site_Connector_SEO::detect_seo_plugin()
			: 'unknown';
		$checks[] = array(
			'name'    => 'seo_plugin_detected',
			'status'  => 'none' === $seo_plugin || 'unknown' === $seo_plugin ? 'warn' : 'pass',
			'message' => 'unknown' === $seo_plugin
				? 'SEO abstraction class missing.'
				: ( 'none' === $seo_plugin ? 'No SEO plugin active.' : sprintf( 'Detected SEO plugin: %s.', $seo_plugin ) ),
		);

		// Page builder detected.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$builders       = self::detect_page_builders( $active_plugins, wp_get_theme() );
		$builder_names  = array_keys( array_filter( $builders ) );
		$checks[]       = array(
			'name'    => 'page_builder_detected',
			'status'  => empty( $builder_names ) ? 'warn' : 'pass',
			'message' => empty( $builder_names )
				? 'No page builder evidence at the site level.'
				: sprintf( 'Detected page builders: %s.', implode( ',', $builder_names ) ),
		);

		// Audit log writable.
		$audit_table_ok = class_exists( 'AI_Site_Connector_Audit_Log' )
			&& self::audit_log_table_exists();
		$checks[] = array(
			'name'    => 'audit_log_table_present',
			'status'  => $audit_table_ok ? 'pass' : 'warn',
			'message' => $audit_table_ok
				? sprintf( 'Audit log table %s present.', AI_Site_Connector_Audit_Log::table_name() )
				: 'Audit log table missing — install or upgrade may not have completed.',
		);

		// SEO dry-run sim — confirms zero mutation invariant of the abstraction.
		$sim_ok = false;
		if ( class_exists( 'AI_Site_Connector_SEO' ) ) {
			// Generate a synthetic post ID that will not exist (max+1) to avoid
			// any real-post side effect. Treat post_not_found as expected.
			$sim    = AI_Site_Connector_SEO::update_seo_meta( 0, array( 'title' => 'self-test' ), true );
			$sim_ok = isset( $sim['applied'] ) && false === $sim['applied'];
		}
		$checks[] = array(
			'name'    => 'seo_dry_run_invariant',
			'status'  => $sim_ok ? 'pass' : 'warn',
			'message' => $sim_ok
				? 'SEO update_seo_meta dry-run returned applied=false (zero mutation).'
				: 'SEO abstraction could not confirm dry-run invariant.',
		);

		$summary = array( 'pass' => 0, 'warn' => 0, 'fail' => 0 );
		foreach ( $checks as $c ) {
			if ( isset( $summary[ $c['status'] ] ) ) {
				$summary[ $c['status'] ]++;
			}
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'checks'       => $checks,
			'summary'      => $summary,
		);
	}

	private static function audit_log_table_exists() {
		global $wpdb;
		$name = AI_Site_Connector_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
		return (string) $found === (string) $name;
	}

	// === REST route inventory (Issue 17) =====================================

	/**
	 * Live REST route inventory walked from rest_get_server()->get_routes().
	 * Never serialises callables — only echoes whether each route declares a
	 * permission_callback. Safe for unauthenticated reads of route shape only
	 * when wrapped by a permission gate; we still require view_diagnostics.
	 *
	 * @return array { routes: array<int, array>, route_count: int, namespaces: string[] }
	 */
	public static function rest_routes() {
		$server = rest_get_server();
		if ( ! $server ) {
			return array( 'routes' => array(), 'route_count' => 0, 'namespaces' => array() );
		}

		$out         = array();
		$namespaces  = array();
		$routes_data = $server->get_routes();
		foreach ( $routes_data as $route => $handlers ) {
			$methods                  = array();
			$args_summary             = array();
			$has_permission_callback  = false;
			foreach ( (array) $handlers as $handler ) {
				if ( isset( $handler['methods'] ) ) {
					foreach ( (array) $handler['methods'] as $m => $on ) {
						if ( $on ) {
							$methods[ $m ] = true;
						}
					}
				}
				if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
					foreach ( $handler['args'] as $arg_name => $arg_spec ) {
						$type = '';
						if ( is_array( $arg_spec ) && isset( $arg_spec['type'] ) ) {
							$type = is_array( $arg_spec['type'] ) ? implode( '|', (array) $arg_spec['type'] ) : (string) $arg_spec['type'];
						}
						$args_summary[ (string) $arg_name ] = $type;
					}
				}
				if ( ! empty( $handler['permission_callback'] ) ) {
					$has_permission_callback = true;
				}
			}

			$namespace = '';
			if ( '/' === substr( (string) $route, 0, 1 ) ) {
				$parts     = explode( '/', ltrim( (string) $route, '/' ), 2 );
				$namespace = isset( $parts[0] ) ? (string) $parts[0] : '';
				if ( '' !== $namespace ) {
					$namespaces[ $namespace ] = true;
				}
			}

			$out[] = array(
				'namespace'               => $namespace,
				'route'                   => (string) $route,
				'methods'                 => array_keys( $methods ),
				'args_summary'            => $args_summary,
				'has_permission_callback' => (bool) $has_permission_callback,
			);
		}

		// Sort deterministically — route name asc — so the manifest output is
		// stable between runs even when WP returns routes in registration order.
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $a['route'], (string) $b['route'] );
			}
		);

		$ns_list = array_keys( $namespaces );
		sort( $ns_list );

		return array(
			'generated_at' => gmdate( 'c' ),
			'route_count'  => count( $out ),
			'namespaces'   => $ns_list,
			'routes'       => $out,
		);
	}

	// === Page builder detector (Issue 13) =====================================

	/**
	 * Page builder detection — site-level always, per-post optional.
	 *
	 * @param array $args { post_ids?: int[] }
	 * @return array
	 */
	public static function page_builder( $args = array() ) {
		$theme            = wp_get_theme();
		$active_plugins   = (array) get_option( 'active_plugins', array() );
		$site_builders    = self::detect_page_builders( $active_plugins, $theme );
		$by_slug          = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), $active_plugins ) );

		// Extend the existing detection with builders generate() doesn't surface
		// in detail. Conservative — only flags presence, not version.
		$site_builders['fusion_builder'] = isset( $by_slug['fusion-builder'] )
			|| isset( $by_slug['fusion-core'] )
			|| ( $theme && in_array( $theme->get( 'Template' ), array( 'Avada', 'avada' ), true ) );
		$site_builders['wpbakery']       = isset( $by_slug['js_composer'] ) || defined( 'WPB_VC_VERSION' );
		$site_builders['bricks']         = $site_builders['bricks_theme'];

		$out = array(
			'generated_at' => gmdate( 'c' ),
			'site'         => array(
				'detected'      => $site_builders,
				'active_theme'  => $theme ? array(
					'name'     => (string) $theme->get( 'Name' ),
					'template' => (string) $theme->get_template(),
					'is_block' => method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme(),
				) : null,
			),
		);

		$post_ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] )
			? array_filter( array_map( 'intval', $args['post_ids'] ) )
			: array();
		if ( empty( $post_ids ) ) {
			return $out;
		}

		$per_post = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				$per_post[ (string) $post_id ] = array( 'error' => 'post_not_found' );
				continue;
			}

			$evidence = array();
			if ( '' !== (string) get_post_meta( $post_id, '_elementor_data', true ) ) {
				$evidence['elementor'] = true;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_fl_builder_data', true )
				|| 'enabled' === (string) get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
				$evidence['beaver_builder'] = true;
			}
			if ( 'on' === (string) get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
				$evidence['divi'] = true;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_fusion_builder_status', true )
				|| '' !== (string) get_post_meta( $post_id, 'fusion_builder_status', true ) ) {
				$evidence['fusion_builder'] = true;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_wpb_vc_js_status', true )
				|| false !== strpos( (string) $post->post_content, '[vc_row' ) ) {
				$evidence['wpbakery'] = true;
			}
			if ( '' !== (string) get_post_meta( $post_id, 'ct_builder_shortcodes', true )
				|| '' !== (string) get_post_meta( $post_id, 'ct_other_template', true ) ) {
				$evidence['oxygen'] = true;
			}
			if ( '' !== (string) get_post_meta( $post_id, '_bricks_page_content_2', true ) ) {
				$evidence['bricks'] = true;
			}
			if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
				$evidence['gutenberg_blocks'] = true;
			}

			$per_post[ (string) $post_id ] = array(
				'post_type' => (string) $post->post_type,
				'evidence'  => $evidence,
			);
		}

		$out['per_post'] = $per_post;
		return $out;
	}

	// === Redirect manager helper (Issue 11) ===================================

	/**
	 * Detect installed redirect plugins and export existing redirects.
	 * Read-only. Returns an empty list and plugin_detected='none' when no
	 * redirect plugin is present.
	 *
	 * @param array $args { limit?: int, offset?: int }
	 * @return array
	 */
	public static function redirects( $args = array() ) {
		global $wpdb;

		$limit  = isset( $args['limit'] ) ? max( 0, min( 5000, (int) $args['limit'] ) ) : 500;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$by_slug = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), (array) get_option( 'active_plugins', array() ) ) );

		// Each detector returns array|null. First non-null wins.
		$detected = 'none';
		$rows     = array();

		if ( isset( $by_slug['seo-by-rank-math'] ) || isset( $by_slug['seo-by-rank-math-pro'] ) ) {
			$res = self::redirects_rankmath( $wpdb, $limit, $offset );
			if ( null !== $res ) {
				$detected = 'rankmath';
				$rows     = $res;
			}
		} elseif ( isset( $by_slug['redirection'] ) ) {
			$res = self::redirects_redirection( $wpdb, $limit, $offset );
			if ( null !== $res ) {
				$detected = 'redirection';
				$rows     = $res;
			}
		} elseif ( isset( $by_slug['all-in-one-seo-pack'] ) || isset( $by_slug['all-in-one-seo-pack-pro'] ) ) {
			$res = self::redirects_aioseo( $wpdb, $limit, $offset );
			if ( null !== $res ) {
				$detected = 'aioseo';
				$rows     = $res;
			}
		} elseif ( isset( $by_slug['wordpress-seo-premium'] ) ) {
			$res = self::redirects_yoast_premium();
			if ( null !== $res ) {
				$detected = 'yoast_premium';
				$rows     = $res;
			}
		}

		return array(
			'generated_at'    => gmdate( 'c' ),
			'plugin_detected' => $detected,
			'count'           => count( $rows ),
			'limit'           => $limit,
			'offset'          => $offset,
			'redirects'       => $rows,
		);
	}

	private static function redirects_rankmath( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'rank_math_redirections';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( (string) $exists !== (string) $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sources, url_to, header_code, status FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$src = '';
			if ( ! empty( $row['sources'] ) ) {
				$decoded = maybe_unserialize( $row['sources'] );
				if ( is_array( $decoded ) && isset( $decoded[0]['pattern'] ) ) {
					$src = (string) $decoded[0]['pattern'];
				}
			}
			$out[] = array(
				'source'      => $src,
				'target'      => (string) ( isset( $row['url_to'] ) ? $row['url_to'] : '' ),
				'status_code' => (int) ( isset( $row['header_code'] ) ? $row['header_code'] : 0 ),
				'match_type'  => 'rankmath:' . (string) ( isset( $row['status'] ) ? $row['status'] : 'active' ),
			);
		}
		return $out;
	}

	private static function redirects_redirection( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( (string) $exists !== (string) $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, action_data, action_code, match_type FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source'      => (string) ( isset( $row['url'] ) ? $row['url'] : '' ),
				'target'      => (string) ( isset( $row['action_data'] ) ? $row['action_data'] : '' ),
				'status_code' => (int) ( isset( $row['action_code'] ) ? $row['action_code'] : 0 ),
				'match_type'  => 'redirection:' . (string) ( isset( $row['match_type'] ) ? $row['match_type'] : 'url' ),
			);
		}
		return $out;
	}

	private static function redirects_aioseo( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'aioseo_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( (string) $exists !== (string) $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_url, target_url, type, regex FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'source'      => (string) ( isset( $row['source_url'] ) ? $row['source_url'] : '' ),
				'target'      => (string) ( isset( $row['target_url'] ) ? $row['target_url'] : '' ),
				'status_code' => (int) ( isset( $row['type'] ) ? $row['type'] : 0 ),
				'match_type'  => 'aioseo:' . ( ! empty( $row['regex'] ) ? 'regex' : 'exact' ),
			);
		}
		return $out;
	}

	private static function redirects_yoast_premium() {
		// Yoast Premium serialises redirects into wp_options. Best-effort,
		// schema not officially documented and varies by version. Return null
		// rather than misrepresent if the option shape isn't recognisable.
		$raw = get_option( 'wpseo-premium-redirects-base', null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'source'      => isset( $row['origin'] ) ? (string) $row['origin'] : '',
				'target'      => isset( $row['url'] ) ? (string) $row['url'] : '',
				'status_code' => isset( $row['type'] ) ? (int) $row['type'] : 0,
				'match_type'  => 'yoast_premium:' . ( isset( $row['format'] ) ? (string) $row['format'] : 'plain' ),
			);
		}
		return $out;
	}
}
