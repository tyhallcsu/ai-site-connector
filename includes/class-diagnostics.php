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
				'app_passwords_blocked_by' => self::app_passwords_blocked_by(),
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

	/**
	 * Builder / SEO / cache plugin detection from the active plugin list.
	 * Side-effect free (unlike generate(), which probes REST over HTTP).
	 *
	 * @return array{page_builders:array,seo:array,cache:array}
	 */
	public static function detected_plugins() {
		$files = self::active_plugin_files();
		return array(
			'page_builders' => self::site_builders(),
			'seo'           => self::detect_seo( $files ),
			'cache'         => self::detect_cache( $files ),
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

	// === MCP self-test (#72) ==================================================

	/**
	 * Structured pass/warn/fail check list tailored to the MCP surface.
	 * Read-only: no option, post, meta, or filesystem writes. Complements
	 * generate() (which is a broader snapshot).
	 *
	 * @return array {
	 *   @type string $generated_at ISO-8601 UTC.
	 *   @type array  $checks       Each entry: { name, status: 'pass'|'warn'|'fail', message }.
	 *   @type array  $summary      { pass, warn, fail } counts.
	 *   @type string $overall      'fail' if any check failed, else 'warn' if any warned, else 'pass'.
	 * }
	 */
	public static function self_test() {
		$checks = array();

		$checks[] = self::check(
			'plugin_loaded',
			defined( 'AI_SITE_CONNECTOR_VERSION' ) ? 'pass' : 'fail',
			defined( 'AI_SITE_CONNECTOR_VERSION' )
				? sprintf( 'AI Site Connector v%s', AI_SITE_CONNECTOR_VERSION )
				: 'AI_SITE_CONNECTOR_VERSION constant missing.'
		);

		$server   = function_exists( 'rest_get_server' ) ? rest_get_server() : null;
		$checks[] = self::check(
			'rest_server_available',
			$server ? 'pass' : 'fail',
			$server ? 'rest_get_server() returns a server.' : 'rest_get_server() unavailable.'
		);

		$mcp_route = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp';
		if ( defined( 'AI_SITE_CONNECTOR_MCP_DISABLE' ) && AI_SITE_CONNECTOR_MCP_DISABLE ) {
			$checks[] = self::check( 'mcp_route_registered', 'warn', 'MCP route intentionally disabled via AI_SITE_CONNECTOR_MCP_DISABLE.' );
		} else {
			$present  = $server && array_key_exists( $mcp_route, $server->get_routes() );
			$checks[] = self::check(
				'mcp_route_registered',
				$present ? 'pass' : 'fail',
				$present ? $mcp_route . ' present.' : $mcp_route . ' is not registered.'
			);
		}

		$user = wp_get_current_user();
		if ( $user && $user->ID > 0 ) {
			$caps = array();
			foreach ( array( 'manage_options', 'edit_posts', 'edit_pages', 'upload_files' ) as $cap ) {
				$caps[] = $cap . '=' . ( user_can( $user, $cap ) ? 'yes' : 'no' );
			}
			$checks[] = self::check(
				'authenticated_user',
				'pass',
				sprintf( 'Authenticated as user id %d (roles=%s; %s).', $user->ID, implode( ',', (array) $user->roles ), implode( ', ', $caps ) )
			);
		} else {
			$checks[] = self::check( 'authenticated_user', 'warn', 'Self-test called without an authenticated user.' );
		}

		$uploads     = wp_upload_dir( null, false );
		$uploads_dir = ( is_array( $uploads ) && empty( $uploads['error'] ) && isset( $uploads['basedir'] ) ) ? (string) $uploads['basedir'] : '';
		$uploads_ok  = '' !== $uploads_dir && is_dir( $uploads_dir ) && wp_is_writable( $uploads_dir );
		$checks[]    = self::check(
			'uploads_writable',
			$uploads_ok ? 'pass' : 'fail',
			$uploads_ok ? 'Uploads directory is writable.' : 'Uploads directory is missing or not writable.'
		);

		// Export directory: created lazily by the Export tab, so absence is a
		// warning as long as it could be created (uploads writable).
		$export_dir = '' !== $uploads_dir ? trailingslashit( $uploads_dir ) . 'ai-site-connector/exports' : '';
		if ( '' !== $export_dir && is_dir( $export_dir ) ) {
			$export_ok = wp_is_writable( $export_dir );
			$checks[]  = self::check(
				'export_dir_writable',
				$export_ok ? 'pass' : 'fail',
				$export_ok ? 'Export directory exists and is writable.' : 'Export directory exists but is not writable.'
			);
		} else {
			$checks[] = self::check(
				'export_dir_writable',
				$uploads_ok ? 'warn' : 'fail',
				$uploads_ok ? 'Export directory not created yet; it will be created on first export.' : 'Export directory cannot be created because uploads is not writable.'
			);
		}

		$temp_dir = function_exists( 'get_temp_dir' ) ? (string) get_temp_dir() : '';
		$temp_ok  = '' !== $temp_dir && wp_is_writable( $temp_dir );
		$checks[] = self::check(
			'temp_dir_writable',
			$temp_ok ? 'pass' : 'warn',
			$temp_ok ? 'Temporary directory is writable.' : 'Temporary directory is not writable; media sideloads may fail.'
		);

		$seo_plugin = AI_Site_Connector_SEO::detect_seo_plugin();
		$checks[]   = self::check(
			'seo_plugin_detected',
			'none' === $seo_plugin ? 'warn' : 'pass',
			'none' === $seo_plugin
				? 'No supported SEO plugin active; native fallbacks are used for reads and SEO writes are unavailable.'
				: sprintf( 'Detected SEO plugin: %s (writable fields: %s).', $seo_plugin, implode( ',', AI_Site_Connector_SEO::writable_fields( $seo_plugin ) ) ?: 'none' )
		);

		$builders      = self::site_builders();
		$builder_names = array_keys( array_filter( $builders ) );
		$checks[]      = self::check(
			'page_builder_detected',
			empty( $builder_names ) ? 'warn' : 'pass',
			empty( $builder_names )
				? 'No page builder evidence at the site level.'
				: sprintf( 'Detected page builders: %s.', implode( ',', $builder_names ) )
		);

		$audit_ok = self::audit_log_table_exists();
		$checks[] = self::check(
			'audit_log_table_present',
			$audit_ok ? 'pass' : 'warn',
			$audit_ok ? 'Audit log table present.' : 'Audit log table missing — install or upgrade may not have completed.'
		);

		$checks[] = self::seo_dry_run_check();

		/**
		 * Filter the self-test checks before the summary is computed.
		 * Entries must keep the { name, status, message } shape.
		 *
		 * @param array $checks
		 */
		$checks = (array) apply_filters( 'ai_site_connector_self_test_checks', $checks );

		$summary = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		);
		$clean   = array();
		foreach ( $checks as $c ) {
			if ( ! is_array( $c ) || ! isset( $c['name'], $c['status'] ) || ! isset( $summary[ $c['status'] ] ) ) {
				continue;
			}
			++$summary[ $c['status'] ];
			$clean[] = self::check( (string) $c['name'], (string) $c['status'], isset( $c['message'] ) ? (string) $c['message'] : '' );
		}

		$overall = $summary['fail'] > 0 ? 'fail' : ( $summary['warn'] > 0 ? 'warn' : 'pass' );

		return array(
			'generated_at' => gmdate( 'c' ),
			'overall'      => $overall,
			'checks'       => $clean,
			'summary'      => $summary,
		);
	}

	private static function check( $name, $status, $message ) {
		return array(
			'name'    => $name,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Run a real SEO dry-run against the most recent post the current user
	 * can edit and prove its post meta is byte-identical afterwards.
	 */
	private static function seo_dry_run_check() {
		$ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'posts_per_page'   => 5,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$target = 0;
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_post', (int) $id ) ) {
				$target = (int) $id;
				break;
			}
		}
		if ( ! $target ) {
			return self::check( 'seo_dry_run_invariant', 'warn', 'No editable post available to exercise the SEO dry-run.' );
		}

		$before = wp_json_encode( get_post_meta( $target ) );
		$sim    = AI_Site_Connector_SEO::update_seo_meta( $target, array( 'title' => 'ai-site-connector self-test' ), true );
		$after  = wp_json_encode( get_post_meta( $target ) );
		$ok     = is_array( $sim ) && false === $sim['applied'] && $before === $after;

		return self::check(
			'seo_dry_run_invariant',
			$ok ? 'pass' : 'fail',
			$ok
				? sprintf( 'SEO dry-run against post %d left post meta unchanged (reason=%s).', $target, $sim['reason'] )
				: sprintf( 'SEO dry-run against post %d mutated post meta or reported applied=true.', $target )
		);
	}

	private static function audit_log_table_exists() {
		global $wpdb;
		if ( ! class_exists( 'AI_Site_Connector_Audit_Log' ) ) {
			return false;
		}
		$name = AI_Site_Connector_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		return (string) $found === (string) $name;
	}

	// === REST route inventory (#71) ===========================================

	/**
	 * Live REST route inventory walked from rest_get_server()->get_routes().
	 * Never serialises callables — only echoes whether each endpoint declares
	 * a permission_callback, and argument metadata (type/required/enum/
	 * description) from the route schema.
	 *
	 * @param array $args { namespace?: string } Optional exact-namespace filter.
	 * @return array { generated_at, route_count, namespaces: string[], routes: array[] }
	 */
	public static function rest_routes( $args = array() ) {
		$filter_ns = isset( $args['namespace'] ) ? trim( (string) $args['namespace'], '/' ) : '';
		$server    = rest_get_server();
		if ( ! $server ) {
			return array(
				'generated_at' => gmdate( 'c' ),
				'route_count'  => 0,
				'namespaces'   => array(),
				'routes'       => array(),
			);
		}

		// WP REST namespaces are multi-segment ("wp/v2", "ai-site-connector/v1").
		// Match each route against the registered list, longest first, so
		// "wp/v2" wins over "wp" when both are registered.
		$registered = array_map( 'strval', (array) $server->get_namespaces() );
		usort(
			$registered,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$out        = array();
		$namespaces = array();
		foreach ( $server->get_routes() as $route => $handlers ) {
			$bare      = ltrim( (string) $route, '/' );
			$namespace = '';
			foreach ( $registered as $ns ) {
				if ( '' !== $ns && ( $bare === $ns || 0 === strpos( $bare, $ns . '/' ) ) ) {
					$namespace = $ns;
					break;
				}
			}
			if ( '' === $namespace && '' !== $bare ) {
				// Routes registered outside get_namespaces(): keep the first
				// segment so the output is never empty.
				$parts     = explode( '/', $bare, 2 );
				$namespace = $parts[0];
			}
			if ( '' !== $filter_ns && $namespace !== $filter_ns ) {
				continue;
			}
			if ( '' !== $namespace ) {
				$namespaces[ $namespace ] = true;
			}

			$methods   = array();
			$args_out  = array();
			$endpoints = 0;
			$with_perm = 0;
			$public    = 0;
			foreach ( (array) $handlers as $handler ) {
				if ( ! is_array( $handler ) ) {
					continue;
				}
				++$endpoints;
				if ( isset( $handler['methods'] ) ) {
					$m_list = is_string( $handler['methods'] ) ? explode( ',', $handler['methods'] ) : array_keys( array_filter( (array) $handler['methods'] ) );
					foreach ( $m_list as $m ) {
						$m = strtoupper( trim( (string) $m ) );
						if ( '' !== $m ) {
							$methods[ $m ] = true;
						}
					}
				}
				if ( ! empty( $handler['permission_callback'] ) ) {
					++$with_perm;
					if ( '__return_true' === $handler['permission_callback'] ) {
						++$public;
					}
				}
				if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
					foreach ( $handler['args'] as $arg_name => $spec ) {
						$args_out[ (string) $arg_name ] = self::summarize_arg( $spec );
					}
				}
			}
			$methods = array_keys( $methods );
			sort( $methods );
			ksort( $args_out );

			$out[] = array(
				'namespace'               => $namespace,
				'route'                   => (string) $route,
				'methods'                 => $methods,
				'args'                    => (object) $args_out,
				'has_permission_callback' => $endpoints > 0 && $with_perm === $endpoints,
				// True when any endpoint is explicitly open (__return_true) or
				// has no permission callback at all.
				'public'                  => $public > 0 || $with_perm < $endpoints,
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['route'], $b['route'] );
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

	/**
	 * Reduce a route arg spec to JSON-safe, secret-free metadata. Defaults
	 * and callbacks are deliberately omitted.
	 */
	private static function summarize_arg( $spec ) {
		$out = array(
			'type'     => '',
			'required' => false,
		);
		if ( ! is_array( $spec ) ) {
			return $out;
		}
		if ( isset( $spec['type'] ) ) {
			$out['type'] = is_array( $spec['type'] ) ? implode( '|', array_map( 'strval', $spec['type'] ) ) : (string) $spec['type'];
		}
		$out['required'] = ! empty( $spec['required'] );
		if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) ) {
			$out['enum'] = array_values( array_filter( $spec['enum'], 'is_scalar' ) );
		}
		if ( isset( $spec['description'] ) && is_string( $spec['description'] ) ) {
			$out['description'] = $spec['description'];
		}
		return $out;
	}

	// === Page builder detector (#69) ==========================================

	const PAGE_BUILDER_MAX_POSTS = 100;

	/**
	 * Active plugin basenames, including network-activated plugins.
	 *
	 * @return string[]
	 */
	private static function active_plugin_files() {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( 'strval', $files ) ) );
	}

	/**
	 * Site-level builder evidence used by both self_test() and page_builder().
	 *
	 * @return array<string,bool>
	 */
	private static function site_builders() {
		$theme    = wp_get_theme();
		$files    = self::active_plugin_files();
		$builders = self::detect_page_builders( $files, $theme );
		$by_slug  = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), $files ) );
		$template = $theme ? (string) $theme->get_template() : '';

		$builders['divi']           = $builders['divi'] || isset( $by_slug['divi-builder'] ) || 'divi' === strtolower( $template );
		$builders['fusion_builder'] = isset( $by_slug['fusion-builder'] ) || isset( $by_slug['fusion-core'] ) || 'avada' === strtolower( $template );
		$builders['wpbakery']       = isset( $by_slug['js_composer'] ) || defined( 'WPB_VC_VERSION' );
		$builders['bricks']         = $builders['bricks_theme'] || 'bricks' === strtolower( $template );
		$builders['block_editor']   = ! isset( $by_slug['classic-editor'] ) && function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'page' );
		unset( $builders['bricks_theme'] );
		ksort( $builders );
		return $builders;
	}

	/**
	 * Page builder detection — site-level always, per-post optional.
	 *
	 * @param array $args { post_ids?: int[] } At most PAGE_BUILDER_MAX_POSTS IDs.
	 * @return array|WP_Error
	 */
	public static function page_builder( $args = array() ) {
		$post_ids = array();
		if ( isset( $args['post_ids'] ) ) {
			$raw      = is_array( $args['post_ids'] ) ? $args['post_ids'] : wp_parse_id_list( $args['post_ids'] );
			$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		}
		if ( count( $post_ids ) > self::PAGE_BUILDER_MAX_POSTS ) {
			return new WP_Error(
				'asc_too_many_posts',
				sprintf( 'At most %d post_ids may be inspected per request.', self::PAGE_BUILDER_MAX_POSTS ),
				array( 'status' => 400 )
			);
		}

		$theme = wp_get_theme();
		$out   = array(
			'generated_at' => gmdate( 'c' ),
			'site'         => array(
				'detected'     => self::site_builders(),
				'active_theme' => $theme ? array(
					'name'     => (string) $theme->get( 'Name' ),
					'template' => (string) $theme->get_template(),
					'is_block' => method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme(),
				) : null,
			),
		);

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
			if ( ! current_user_can( 'read_post', $post_id ) ) {
				$per_post[ (string) $post_id ] = array( 'error' => 'forbidden' );
				continue;
			}
			$per_post[ (string) $post_id ] = array(
				'post_type' => (string) $post->post_type,
				'evidence'  => self::post_builder_evidence( $post ),
			);
		}

		$out['per_post'] = $per_post;
		return $out;
	}

	/**
	 * Per-post builder clues from well-known meta keys and content markers.
	 * Unknown or malformed meta never throws; it simply yields no evidence.
	 *
	 * @param WP_Post $post
	 * @return array<string,bool> Only builders with evidence are listed.
	 */
	private static function post_builder_evidence( $post ) {
		$id       = (int) $post->ID;
		$content  = (string) $post->post_content;
		$has_meta = static function ( $key ) use ( $id ) {
			$v = get_post_meta( $id, $key, true );
			return ! ( '' === $v || null === $v || false === $v || array() === $v );
		};
		$meta_eq  = static function ( $key, $expected ) use ( $id ) {
			$v = get_post_meta( $id, $key, true );
			return is_scalar( $v ) && (string) $expected === (string) $v;
		};

		$evidence = array(
			'elementor'      => $has_meta( '_elementor_data' ) || $meta_eq( '_elementor_edit_mode', 'builder' ),
			'beaver_builder' => $has_meta( '_fl_builder_data' ) || $meta_eq( '_fl_builder_enabled', '1' ) || $meta_eq( '_fl_builder_enabled', 'enabled' ),
			'divi'           => $meta_eq( '_et_pb_use_builder', 'on' ),
			'fusion_builder' => $meta_eq( 'fusion_builder_status', 'active' ) || $meta_eq( '_fusion_builder_status', 'active' ),
			'wpbakery'       => $meta_eq( '_wpb_vc_js_status', 'true' ) || false !== strpos( $content, '[vc_row' ),
			'oxygen'         => $has_meta( 'ct_builder_shortcodes' ) || $has_meta( 'ct_builder_json' ) || $has_meta( 'ct_other_template' ),
			'bricks'         => $has_meta( '_bricks_page_content_2' ),
			'block_editor'   => function_exists( 'has_blocks' ) && has_blocks( $content ),
		);
		return array_filter( $evidence );
	}

	// === Redirect manager helper (#67) ========================================

	const REDIRECTS_MAX_LIMIT = 1000;

	/**
	 * Detect installed redirect plugins and export existing redirects.
	 * Read-only. Every supported plugin that is active is listed in
	 * `plugins_present`; the first one with a readable data source supplies
	 * the redirects (`plugin_detected`). Falls back to 'none'.
	 *
	 * Row schema (stable across plugins), one row per stored redirect:
	 *   { id, source, target, status_code, match_type, enabled, plugin,
	 *     additional_sources: [{ source, match_type }] }
	 * `total`, `limit`, `offset`, `next_offset` all count stored redirects.
	 *
	 * @param array $args { limit?: int (1..1000, default 500), offset?: int }
	 * @return array
	 */
	public static function redirects( $args = array() ) {
		global $wpdb;

		$limit  = isset( $args['limit'] ) && (int) $args['limit'] > 0 ? min( self::REDIRECTS_MAX_LIMIT, (int) $args['limit'] ) : 500;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$by_slug = array_flip( array_map( array( __CLASS__, 'slug_from_file' ), self::active_plugin_files() ) );

		$candidates = array(
			'rankmath'      => isset( $by_slug['seo-by-rank-math'] ) || isset( $by_slug['seo-by-rank-math-pro'] ),
			'redirection'   => isset( $by_slug['redirection'] ),
			'aioseo'        => isset( $by_slug['all-in-one-seo-pack'] ) || isset( $by_slug['all-in-one-seo-pack-pro'] ) || isset( $by_slug['aioseo-redirects'] ),
			'yoast_premium' => isset( $by_slug['wordpress-seo-premium'] ),
		);
		/**
		 * Filter which redirect plugins are considered active.
		 *
		 * @param array<string,bool> $candidates
		 */
		$candidates = (array) apply_filters( 'ai_site_connector_redirect_plugins', $candidates );
		$present    = array_keys( array_filter( $candidates ) );

		$detected    = 'none';
		$rows        = array();
		$total       = 0;
		$unavailable = array();
		$fallback    = null;
		foreach ( $present as $plugin ) {
			switch ( $plugin ) {
				case 'rankmath':
					$res = self::redirects_rankmath( $wpdb, $limit, $offset );
					break;
				case 'redirection':
					$res = self::redirects_redirection( $wpdb, $limit, $offset );
					break;
				case 'aioseo':
					$res = self::redirects_aioseo( $wpdb, $limit, $offset );
					break;
				case 'yoast_premium':
					$res = self::redirects_yoast_premium( $limit, $offset );
					break;
				default:
					$res = null;
			}
			if ( null === $res ) {
				$unavailable[] = $plugin;
				continue;
			}
			if ( 0 === $res['total'] ) {
				// An empty leftover table must not hide another plugin's data.
				if ( null === $fallback ) {
					$fallback = array( $plugin, $res );
				}
				continue;
			}
			$fallback = array( $plugin, $res );
			break;
		}
		if ( null !== $fallback ) {
			list( $detected, $res ) = $fallback;
			$rows                   = $res['rows'];
			$total                  = $res['total'];
		}

		return array(
			'generated_at'     => gmdate( 'c' ),
			'plugin_detected'  => $detected,
			'plugins_present'  => $present,
			'data_unavailable' => $unavailable,
			'total'            => $total,
			'count'            => count( $rows ),
			'limit'            => $limit,
			'offset'           => $offset,
			'next_offset'      => $offset + count( $rows ) < $total ? $offset + count( $rows ) : null,
			'redirects'        => $rows,
		);
	}

	private static function table_exists( $wpdb, $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === (string) $table;
	}

	private static function redirect_row( $id, $source, $target, $code, $match, $enabled, $plugin, $additional = array() ) {
		return array(
			'id'                 => (int) $id,
			'source'             => (string) $source,
			'target'             => (string) $target,
			'status_code'        => (int) $code,
			'match_type'         => (string) $match,
			'enabled'            => (bool) $enabled,
			'plugin'             => $plugin,
			'additional_sources' => $additional,
		);
	}

	private static function redirects_rankmath( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( ! self::table_exists( $wpdb, $table ) ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, sources, url_to, header_code, status FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			// `sources` is a serialized list of { pattern, comparison }. The
			// first is the primary source; the rest go in additional_sources
			// so one stored redirect stays one row (pagination stays exact).
			// Never unserialize objects from a third-party table.
			$raw     = isset( $row['sources'] ) ? (string) $row['sources'] : '';
			$sources = is_serialized( $raw ) ? @unserialize( $raw, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$parsed  = array();
			foreach ( is_array( $sources ) ? $sources : array() as $src ) {
				$parsed[] = array(
					'source'     => is_array( $src ) && isset( $src['pattern'] ) && is_scalar( $src['pattern'] ) ? (string) $src['pattern'] : '',
					'match_type' => is_array( $src ) && isset( $src['comparison'] ) && is_scalar( $src['comparison'] ) ? (string) $src['comparison'] : 'exact',
				);
			}
			$primary = $parsed ? array_shift( $parsed ) : array(
				'source'     => '',
				'match_type' => 'exact',
			);
			$out[]   = self::redirect_row(
				$row['id'],
				$primary['source'],
				isset( $row['url_to'] ) ? $row['url_to'] : '',
				isset( $row['header_code'] ) ? $row['header_code'] : 0,
				$primary['match_type'],
				isset( $row['status'] ) && 'active' === $row['status'],
				'rankmath',
				$parsed
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	private static function redirects_redirection( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'redirection_items';
		if ( ! self::table_exists( $wpdb, $table ) ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::redirect_row(
				$row['id'],
				isset( $row['url'] ) ? $row['url'] : '',
				isset( $row['action_data'] ) ? $row['action_data'] : '',
				isset( $row['action_code'] ) ? $row['action_code'] : 0,
				// Redirection keeps regex-ness in its own column; match_type
				// stays 'url' for regex redirects.
				! empty( $row['regex'] ) ? 'regex' : ( isset( $row['match_type'] ) ? $row['match_type'] : 'url' ),
				! isset( $row['status'] ) || 'enabled' === $row['status'],
				'redirection'
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	private static function redirects_aioseo( $wpdb, $limit, $offset ) {
		$table = $wpdb->prefix . 'aioseo_redirects';
		if ( ! self::table_exists( $wpdb, $table ) ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::redirect_row(
				isset( $row['id'] ) ? $row['id'] : 0,
				isset( $row['source_url'] ) ? $row['source_url'] : '',
				isset( $row['target_url'] ) ? $row['target_url'] : '',
				isset( $row['type'] ) ? $row['type'] : 0,
				! empty( $row['regex'] ) ? 'regex' : 'exact',
				! isset( $row['enabled'] ) || ! empty( $row['enabled'] ),
				'aioseo'
			);
		}
		return array(
			'rows'  => $out,
			'total' => $total,
		);
	}

	private static function redirects_yoast_premium( $limit, $offset ) {
		// Yoast Premium keeps redirects in wp_options keyed by origin. The
		// shape is undocumented and varies by version; return null (try the
		// next plugin) rather than misrepresent an unrecognised shape.
		$raw = get_option( 'wpseo-premium-redirects-base', null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$all = array();
		$i   = 0;
		foreach ( $raw as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$i;
			$all[] = self::redirect_row(
				$i,
				isset( $row['origin'] ) ? $row['origin'] : ( is_string( $key ) ? $key : '' ),
				isset( $row['url'] ) ? $row['url'] : '',
				isset( $row['type'] ) ? $row['type'] : 0,
				isset( $row['format'] ) ? $row['format'] : 'plain',
				true,
				'yoast_premium'
			);
		}
		return array(
			'rows'  => array_slice( $all, $offset, $limit ),
			'total' => count( $all ),
		);
	}

	/**
	 * HTTP sign-in probe: GET /wp/v2/users/me with Basic auth, the way an AI
	 * client connects. Shared by the connection-pack pre-flight, the
	 * Connection Test live check and `wp ai-connector self-test --username`
	 * (#106). Catches hosts or security plugins that strip the Authorization
	 * header, WAFs that block Basic auth, and disabled REST.
	 *
	 * @param string     $username     Login.
	 * @param string     $password     Application Password (never stored or logged here).
	 * @param array|null $skip_context Passed to the ai_site_connector_skip_preflight
	 *                                 filter; null skips the filter (CLI self-test).
	 * @return array{status:string, code:int|string, hint:string} status pass|fail|skipped.
	 */
	public static function auth_probe( $username, $password, $skip_context = null ) {
		/**
		 * Filter to skip the pre-flight loopback check. Useful for hosts
		 * where the WP install cannot make HTTP requests to itself.
		 *
		 * @param bool  $skip    Default false.
		 * @param array $context Connection pack (pre-flight) or { username, live_check }.
		 */
		if ( null !== $skip_context && apply_filters( 'ai_site_connector_skip_preflight', false, $skip_context ) ) {
			return array(
				'status' => 'skipped',
				'code'   => 'filter',
				'hint'   => __( 'Pre-flight check skipped by ai_site_connector_skip_preflight filter.', 'ai-site-connector' ),
			);
		}

		$response = wp_remote_get(
			rest_url( 'wp/v2/users/me' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'fail',
				'code'   => $response->get_error_code(),
				'hint'   => sprintf(
					/* translators: %s: WP error message. */
					__( 'Could not reach REST API: %s', 'ai-site-connector' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array(
				'status' => 'pass',
				'code'   => 200,
				'hint'   => __( 'REST API accepts the Application Password.', 'ai-site-connector' ),
			);
		}
		$hints = array(
			401 => __( 'The most common cause is your host stripping the Authorization header (some shared hosts and security plugins do this). See SECURITY.md and scripts/diagnose-hosting-auth.sh for fixes.', 'ai-site-connector' ),
			403 => __( 'The user may lack the required REST capability, or a security plugin / WAF is blocking REST access.', 'ai-site-connector' ),
			404 => __( 'The REST API may be disabled, or pretty permalinks are off. Settings → Permalinks → save without changes.', 'ai-site-connector' ),
			500 => __( 'WordPress returned a server error. Check the PHP error log.', 'ai-site-connector' ),
		);
		return array(
			'status' => 'fail',
			'code'   => $code,
			'hint'   => isset( $hints[ $code ] ) ? $hints[ $code ] : __( 'Unexpected response. Review the server log and security plugin settings.', 'ai-site-connector' ),
		);
	}

	/**
	 * Live sign-in check without a lasting credential (#106): mint a
	 * temporary Application Password for $user, probe with it over HTTP,
	 * then revoke it, eagerly and again at shutdown in case of a fatal
	 * error. The plaintext stays inside this method and is never logged.
	 *
	 * @param WP_User $user              User to sign in as.
	 * @param bool    $honor_skip_filter Consult ai_site_connector_skip_preflight.
	 * @return array{status:string, code:int|string, hint:string}
	 */
	public static function credential_round_trip( WP_User $user, $honor_skip_filter = true ) {
		$context = array(
			'username'   => $user->user_login,
			'live_check' => true,
		);
		if ( $honor_skip_filter && apply_filters( 'ai_site_connector_skip_preflight', false, $context ) ) {
			return array(
				'status' => 'skipped',
				'code'   => 'filter',
				'hint'   => __( 'Pre-flight check skipped by ai_site_connector_skip_preflight filter.', 'ai-site-connector' ),
			);
		}
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			return array(
				'status' => 'fail',
				'code'   => 'app_passwords_unavailable',
				'hint'   => self::app_passwords_unavailable_hint(),
			);
		}
		$created = AI_Site_Connector_Application_Passwords::create_for_user( $user->ID, 'AI Site Connector live check - ' . gmdate( 'Y-m-d H:i:s' ) );
		if ( is_wp_error( $created ) ) {
			return array(
				'status' => 'fail',
				'code'   => 'mint_failed',
				'hint'   => $created->get_error_message(),
			);
		}
		$user_id = (int) $user->ID;
		$uuid    = isset( $created['uuid'] ) ? (string) $created['uuid'] : '';
		register_shutdown_function(
			static function () use ( $user_id, $uuid ) {
				if ( '' !== $uuid ) {
					AI_Site_Connector_Application_Passwords::revoke( $user_id, $uuid );
				}
			}
		);
		$password = isset( $created['password'] ) ? (string) $created['password'] : '';
		unset( $created );
		$result = self::auth_probe( $user->user_login, $password, null );
		unset( $password );
		if ( '' !== $uuid ) {
			AI_Site_Connector_Application_Passwords::revoke( $user_id, $uuid );
		}
		AI_Site_Connector_Audit_Log::record(
			'live_signin_check',
			array(
				'message' => sprintf(
					/* translators: 1: user login, 2: result, 3: HTTP status or error code. */
					__( 'Live sign-in check for %1$s: %2$s (%3$s).', 'ai-site-connector' ),
					$user->user_login,
					$result['status'],
					(string) $result['code']
				),
			)
		);
		return $result;
	}

	/** Confirmed source blocking Application Passwords (#133), or null. */
	private static function app_passwords_blocked_by() {
		$blocker = AI_Site_Connector_Plugin::app_passwords_blocker();
		return $blocker ? $blocker['source'] : null;
	}

	/** Why Application Passwords are unavailable, with the fix when known (#133). */
	private static function app_passwords_unavailable_hint() {
		$blocker = AI_Site_Connector_Plugin::app_passwords_blocker();
		return $blocker
			? $blocker['message'] . ' ' . $blocker['fix']
			: __( 'Application Passwords are not available on this site (HTTPS, environment type, or a security plugin disabled them).', 'ai-site-connector' );
	}
}
