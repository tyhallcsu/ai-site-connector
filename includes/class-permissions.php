<?php
/**
 * Tool whitelist / permission guard.
 *
 * Centralised gate that every MCP / AI tool consults BEFORE executing. Sits
 * on top of WP capability checks — even if the authenticated user has the
 * WP cap, the tool is refused when its permission key is disabled here.
 *
 * Defaults are deliberately conservative: only read-only introspection
 * tools are enabled out of the box. Operators opt in to write/destructive
 * tools explicitly via the admin UI.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Permissions {

	const OPTION_KEY       = 'ai_site_connector_tool_permissions';
	const READ_ONLY_OPTION = 'ai_site_connector_read_only_mode';

	const TOOL_READ_CONTENT          = 'read_content';
	const TOOL_WRITE_CONTENT         = 'write_content';
	const TOOL_UPLOAD_MEDIA          = 'upload_media';
	const TOOL_UPDATE_SEO            = 'update_seo';
	const TOOL_PURGE_CACHE           = 'purge_cache';
	const TOOL_EXPORT_MANIFEST       = 'export_manifest';
	const TOOL_VIEW_DIAGNOSTICS      = 'view_diagnostics';
	const TOOL_UPDATE_OPTIONS        = 'update_options';
	const TOOL_DESTRUCTIVE_OPERATION = 'destructive_operations';

	public static function register_hooks() {
		add_action( 'admin_post_ai_site_connector_save_permissions', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Catalog of supported tool-permission keys with default state.
	 *
	 * Conservative defaults: read-only introspection on, everything that
	 * mutates the site off until an operator opts in.
	 */
	public static function catalog() {
		return array(
			self::TOOL_READ_CONTENT          => array(
				'label'       => __( 'Read content', 'ai-site-connector' ),
				'description' => __( 'Read posts, pages, plugin and theme metadata via /posts, /pages, /plugins, /themes, /site-info.', 'ai-site-connector' ),
				'default'     => true,
				'category'    => 'read',
				'wp_cap'      => 'edit_posts',
			),
			self::TOOL_VIEW_DIAGNOSTICS      => array(
				'label'       => __( 'View diagnostics', 'ai-site-connector' ),
				'description' => __( 'Run site capability reports and health probes.', 'ai-site-connector' ),
				'default'     => true,
				'category'    => 'read',
				'wp_cap'      => 'manage_options',
			),
			self::TOOL_EXPORT_MANIFEST       => array(
				'label'       => __( 'Export manifest', 'ai-site-connector' ),
				'description' => __( 'Export media manifest, recent changes, page content, or full site manifest for repo sync.', 'ai-site-connector' ),
				'default'     => true,
				'category'    => 'read',
				'wp_cap'      => 'edit_posts',
			),
			self::TOOL_WRITE_CONTENT         => array(
				'label'       => __( 'Write content', 'ai-site-connector' ),
				'description' => __( 'Create or update posts and pages. Does not include deletion (see destructive_operations).', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'write',
				'wp_cap'      => 'edit_posts',
			),
			self::TOOL_UPLOAD_MEDIA          => array(
				'label'       => __( 'Upload media', 'ai-site-connector' ),
				'description' => __( 'Upload files to the Media Library and set basic metadata (title, alt, caption, description).', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'write',
				'wp_cap'      => 'upload_files',
			),
			self::TOOL_UPDATE_SEO            => array(
				'label'       => __( 'Update SEO metadata', 'ai-site-connector' ),
				'description' => __( 'Write Rank Math / Yoast / AIOSEO meta keys (title, description, canonical, social images) when those plugins are active.', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'write',
				'wp_cap'      => 'edit_posts',
			),
			self::TOOL_PURGE_CACHE           => array(
				'label'       => __( 'Purge cache', 'ai-site-connector' ),
				'description' => __( 'Flush WP object cache and any supported cache plugin (WP Rocket, LiteSpeed, W3TC, Elementor, Cloudflare).', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'write',
				'wp_cap'      => 'manage_options',
			),
			self::TOOL_UPDATE_OPTIONS        => array(
				'label'       => __( 'Update site options', 'ai-site-connector' ),
				'description' => __( 'Modify wp_options values via MCP tools (currently not exposed by core endpoints; reserved for future use).', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'admin',
				'wp_cap'      => 'manage_options',
			),
			self::TOOL_DESTRUCTIVE_OPERATION => array(
				'label'       => __( 'Destructive operations', 'ai-site-connector' ),
				'description' => __( 'Allow deletion of posts, pages, attachments, users, or plugin/theme files. Independent of write_content; both are required for a destructive mutation.', 'ai-site-connector' ),
				'default'     => false,
				'category'    => 'destructive',
				'wp_cap'      => 'manage_options',
			),
		);
	}

	/**
	 * Get the persisted permission map merged with defaults.
	 *
	 * Unknown keys in the option are ignored. Missing keys fall back to
	 * their catalog default. This makes adding a new tool permission key
	 * forward-compatible — old stored options keep working.
	 */
	public static function get_all() {
		$catalog = self::catalog();
		$stored  = (array) get_option( self::OPTION_KEY, array() );
		$out     = array();
		foreach ( $catalog as $key => $meta ) {
			$enabled = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : (bool) $meta['default'];
			$out[ $key ] = array(
				'label'       => $meta['label'],
				'description' => $meta['description'],
				'category'    => $meta['category'],
				'wp_cap'      => $meta['wp_cap'],
				'enabled'     => $enabled,
				'default'     => (bool) $meta['default'],
			);
		}
		return $out;
	}

	/**
	 * Is global read-only mode on? When true, every non-read tool is
	 * implicitly denied regardless of its individual setting.
	 */
	public static function is_read_only() {
		return (bool) get_option( self::READ_ONLY_OPTION, false );
	}

	/**
	 * Can the current (authenticated) user execute the named tool?
	 *
	 * Order of evaluation:
	 *   1. Tool exists in catalog — unknown tools always denied.
	 *   2. Read-only mode — non-read tools denied.
	 *   3. WP capability check — uses the catalog's wp_cap.
	 *   4. Tool whitelist setting — must be enabled.
	 *   5. Filter override — site owners can extend.
	 *
	 * @param string $tool    Tool slug from the catalog.
	 * @param array  $context Optional context (object_id, etc.) passed to filter.
	 * @return bool
	 */
	public static function can( $tool, $context = array() ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $tool ] ) ) {
			return false;
		}

		$meta    = $catalog[ $tool ];
		$is_read = 'read' === $meta['category'];

		if ( self::is_read_only() && ! $is_read ) {
			return self::apply_filter( $tool, false, $context, 'read_only_mode' );
		}

		if ( ! empty( $meta['wp_cap'] ) && ! current_user_can( $meta['wp_cap'] ) ) {
			return self::apply_filter( $tool, false, $context, 'wp_cap' );
		}

		$enabled = self::get_all();
		$allowed = ! empty( $enabled[ $tool ]['enabled'] );

		return self::apply_filter( $tool, $allowed, $context, $allowed ? 'allowed' : 'whitelist_off' );
	}

	/**
	 * Hard guard for REST/CLI callers. Returns true when allowed; returns a
	 * WP_Error 403 (rest_forbidden_tool) when denied. The reason code in
	 * the error data is machine-parseable.
	 *
	 * @param string $tool
	 * @param array  $context
	 * @return true|WP_Error
	 */
	public static function require_permission( $tool, $context = array() ) {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $tool ] ) ) {
			return new WP_Error(
				'rest_forbidden_tool',
				/* translators: %s: tool slug */
				sprintf( __( 'Unknown tool: %s', 'ai-site-connector' ), $tool ),
				array( 'status' => 400, 'reason' => 'unknown_tool' )
			);
		}

		$meta = $catalog[ $tool ];

		if ( self::is_read_only() && 'read' !== $meta['category'] ) {
			return self::denied( $tool, 'read_only_mode', __( 'Site is in read-only mode for AI tools.', 'ai-site-connector' ) );
		}

		if ( ! empty( $meta['wp_cap'] ) && ! current_user_can( $meta['wp_cap'] ) ) {
			return self::denied( $tool, 'wp_cap', __( 'Authenticated user lacks the WordPress capability for this tool.', 'ai-site-connector' ) );
		}

		$enabled = self::get_all();
		if ( empty( $enabled[ $tool ]['enabled'] ) ) {
			return self::denied( $tool, 'whitelist_off', __( 'This tool is disabled in the AI Site Connector permission settings.', 'ai-site-connector' ) );
		}

		/** This filter mirrors the one in can() so a programmatic deny still wins. */
		$ok = (bool) apply_filters( 'ai_site_connector_can_execute_tool', true, $tool, $context );
		if ( ! $ok ) {
			return self::denied( $tool, 'filter_override', __( 'Tool refused by ai_site_connector_can_execute_tool filter.', 'ai-site-connector' ) );
		}

		return true;
	}

	/**
	 * Per-Application-Password scope + IP allowlist + expiry enforcement.
	 *
	 * Returns true (allow), null (no-op — request not authed via App Password,
	 * or password has no extras), or WP_Error (deny). Called from the
	 * rest_pre_dispatch filter for every REST request once auth has run.
	 *
	 * @param string $route  REST route (e.g. '/wp/v2/posts').
	 * @param string $method HTTP method ('GET', 'POST', ...).
	 * @return true|null|WP_Error
	 */
	public static function require_route_scope( $route, $method ) {
		if ( ! class_exists( 'AI_Site_Connector_App_Password_Resolver' )
			|| ! class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			return null;
		}
		$uuid = AI_Site_Connector_App_Password_Resolver::current_uuid();
		if ( ! $uuid ) {
			return null;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		// 1. Expiry check (defense in depth alongside the daily sweep cron).
		if ( AI_Site_Connector_App_Password_Meta::is_expired( $user_id, $uuid ) ) {
			AI_Site_Connector_Audit_Log::record(
				'application_password_expired_use',
				array(
					'tool'    => 'rest',
					'status'  => 'denied',
					'message' => sprintf( 'Expired Application Password used: uuid=%s, route=%s.', $uuid, $route ),
					'meta'    => array( 'uuid' => $uuid, 'route' => $route ),
				)
			);
			return new WP_Error(
				'rest_application_password_expired',
				__( 'This Application Password has expired.', 'ai-site-connector' ),
				array( 'status' => 401, 'reason' => 'expired' )
			);
		}

		// 2. Scope check.
		$scopes = AI_Site_Connector_App_Password_Meta::get_scopes( $user_id, $uuid );
		if ( ! empty( $scopes ) && ! AI_Site_Connector_App_Password_Meta::route_matches_scopes( $method, $route, $scopes ) ) {
			AI_Site_Connector_Audit_Log::record(
				'route_scope_denied',
				array(
					'tool'    => 'rest',
					'status'  => 'denied',
					'message' => sprintf( 'Scope denied: method=%s route=%s uuid=%s.', $method, $route, $uuid ),
					'meta'    => array( 'route' => $route, 'method' => $method, 'uuid' => $uuid ),
				)
			);
			return new WP_Error(
				'rest_forbidden_scope',
				__( 'This Application Password is not allowed to call this route.', 'ai-site-connector' ),
				array( 'status' => 403, 'reason' => 'scope_off' )
			);
		}

		// 3. IP allowlist check (per-password). Source IP is REMOTE_ADDR by
		// default; XFF only honored when WP_TRUSTED_PROXIES is true (admin
		// opt-in for sites behind a reverse proxy that's been configured to
		// not forward arbitrary client-supplied headers).
		$cidrs = AI_Site_Connector_App_Password_Meta::get_ip_allowlist( $user_id, $uuid );
		if ( ! empty( $cidrs ) ) {
			$client_ip = self::resolve_client_ip();
			if ( '' === $client_ip || ! AI_Site_Connector_App_Password_Meta::ip_matches_cidr( $client_ip, $cidrs ) ) {
				AI_Site_Connector_Audit_Log::record(
					'ip_allowlist_denied',
					array(
						'tool'    => 'rest',
						'status'  => 'denied',
						'message' => sprintf( 'IP allowlist denied: route=%s uuid=%s.', $route, $uuid ),
						'meta'    => array( 'uuid' => $uuid, 'route' => $route, 'ip_hash' => AI_Site_Connector_Audit_Log::hash_ip( $client_ip ) ),
					)
				);
				return new WP_Error(
					'rest_forbidden_ip',
					__( 'This Application Password is not allowed from your IP.', 'ai-site-connector' ),
					array( 'status' => 403, 'reason' => 'ip_off' )
				);
			}
		}

		return true;
	}

	/**
	 * Resolve the client IP for allowlist matching. Default is REMOTE_ADDR;
	 * X-Forwarded-For is only honored if the WP_TRUSTED_PROXIES constant is
	 * defined and true (operator opt-in for reverse-proxy setups).
	 */
	private static function resolve_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( defined( 'WP_TRUSTED_PROXIES' ) && WP_TRUSTED_PROXIES
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$first = trim( strtok( $xff, ',' ) );
			if ( '' !== $first ) {
				$ip = $first;
			}
		}
		/**
		 * Filter the resolved client IP used for per-password allowlist
		 * matching. Hosts with non-standard proxy headers can override.
		 *
		 * @param string $ip
		 */
		return (string) apply_filters( 'ai_site_connector_request_ip', $ip );
	}

	private static function denied( $tool, $reason, $message ) {
		// Tool denial is itself an auditable event. Logging the denial is
		// critical for security review — silent denies mask hostile probing.
		if ( class_exists( 'AI_Site_Connector_Audit_Log' ) ) {
			AI_Site_Connector_Audit_Log::record(
				'tool_denied',
				array(
					'tool'    => $tool,
					'status'  => 'denied',
					'summary' => sprintf( 'Denied: %s (%s)', $tool, $reason ),
					'meta'    => array( 'reason' => $reason ),
				)
			);
		}
		return new WP_Error(
			'rest_forbidden_tool',
			$message,
			array( 'status' => 403, 'reason' => $reason, 'tool' => $tool )
		);
	}

	/**
	 * Apply the can-execute filter consistently from can().
	 *
	 * @param string $tool
	 * @param bool   $allowed
	 * @param array  $context
	 * @param string $reason  Informational only — passed to the filter as context.
	 * @return bool
	 */
	private static function apply_filter( $tool, $allowed, $context, $reason ) {
		/**
		 * Filter the final allow/deny decision for an AI tool.
		 *
		 * @param bool   $allowed Current decision.
		 * @param string $tool    Tool slug.
		 * @param array  $context Caller-supplied context.
		 * @param string $reason  Informational reason for the current decision.
		 */
		return (bool) apply_filters( 'ai_site_connector_can_execute_tool', $allowed, $tool, $context, $reason );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		$catalog = self::catalog();
		$update  = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
		$posted = isset( $_POST['ai_site_connector_perms'] ) && is_array( $_POST['ai_site_connector_perms'] )
			? array_map( 'rest_sanitize_boolean', wp_unslash( $_POST['ai_site_connector_perms'] ) )
			: array();
		foreach ( $catalog as $key => $_meta ) {
			$update[ $key ] = isset( $posted[ $key ] ) ? (bool) $posted[ $key ] : false;
		}
		update_option( self::OPTION_KEY, $update );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$read_only = ! empty( $_POST['ai_site_connector_read_only_mode'] );
		update_option( self::READ_ONLY_OPTION, $read_only ? 1 : 0 );

		AI_Site_Connector_Audit_Log::record(
			'permissions_updated',
			array(
				'tool'    => '',
				'status'  => 'success',
				'summary' => sprintf(
					'Tool permissions updated: %d enabled. Read-only mode: %s.',
					count( array_filter( $update ) ),
					$read_only ? 'on' : 'off'
				),
				'meta'    => array(
					'enabled'   => array_keys( array_filter( $update ) ),
					'disabled'  => array_keys( array_diff_key( $update, array_filter( $update ) ) ),
					'read_only' => (bool) $read_only,
				),
			)
		);

		if ( class_exists( 'AI_Site_Connector_Admin_Page' ) ) {
			AI_Site_Connector_Admin_Page::flash_public(
				__( 'Tool permissions saved.', 'ai-site-connector' ),
				'success'
			);
			AI_Site_Connector_Admin_Page::redirect_back_public( 'permissions' );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . AI_Site_Connector_Admin_Page::PAGE_SLUG . '&tab=permissions' ) );
		exit;
	}
}
