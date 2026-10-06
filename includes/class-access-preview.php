<?php
/**
 * Effective-access preview (#124).
 *
 * Explains whether a user, optionally through one of their Application
 * Passwords, could run one operation, and which check would refuse it:
 * the credential itself, its route scopes, the user's role and post
 * access, and this plugin's settings. Only current policy is read: no
 * password is minted or shown, no request is sent, nothing is written and
 * no tool runs. Custom code can still refuse a real request, so whatever
 * the preview cannot decide is reported as unknown, never as allowed.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Access_Preview {

	const PASS        = 'pass';
	const DENY        = 'deny';
	const CONDITIONAL = 'conditional';
	const UNKNOWN     = 'unknown';
	const SKIPPED     = 'not_applicable';

	/** Operation key for a REST route the operator types in. */
	const CUSTOM = 'custom_route';

	/** Methods accepted for a typed route. */
	const METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Every operation the preview explains, keyed "mcp:<tool>" or
	 * "rest:<tool>".
	 *
	 * Fields: channel, name, method, route ({base} is the post type's
	 * core route, {post} the post ID), post_type (for {base}), tools
	 * (permissions always checked), apply_tools (checked only when changes
	 * are applied), seo (update_seo is checked when SEO fields change) and
	 * object (content: the safe-update rules; edit_post: the post must be
	 * editable). The MCP list mirrors AI_Site_Connector_MCP_Server's
	 * dispatch_tool(); a test fails when a tool is missing here.
	 *
	 * @return array
	 */
	public static function operations() {
		$ns      = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE;
		$content = array(
			'tools'       => array(),
			'apply_tools' => array( AI_Site_Connector_Permissions::TOOL_WRITE_CONTENT ),
			'seo'         => true,
			'object'      => 'content',
		);
		$diag    = array( 'tools' => array( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS ) );
		$export  = array( 'tools' => array( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST ) );
		$write   = array( 'tools' => array( AI_Site_Connector_Permissions::TOOL_WRITE_CONTENT ) );
		$bundle  = array( 'tools' => array( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST, AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS ) );

		$mcp = array(
			'wp_health'                 => array( 'GET', $ns . '/health', array() ),
			'wp_site_info'              => array( 'GET', $ns . '/site-info', array() ),
			'wp_list_posts'             => array( 'GET', '{base}', array() ),
			'wp_list_pages'             => array( 'GET', '{base}', array( 'post_type' => 'page' ) ),
			'wp_get_post'               => array( 'GET', '{base}/{post}', array() ),
			'wp_create_post'            => array( 'POST', '{base}', $write ),
			'wp_update_post'            => array( 'POST', '{base}/{post}', $write ),
			'wp_list_plugins'           => array( 'GET', $ns . '/plugins', array() ),
			'wp_list_themes'            => array( 'GET', $ns . '/themes', array() ),
			'wp_self_test'              => array( 'GET', $ns . '/diagnostics/self-test', $diag ),
			'wp_rest_routes'            => array( 'GET', $ns . '/diagnostics/rest-routes', $diag ),
			'wp_page_builder'           => array( 'GET', $ns . '/diagnostics/page-builder', $diag ),
			'wp_content_inventory'      => array( 'GET', $ns . '/export/content-inventory', $export ),
			'wp_media_audit'            => array( 'GET', $ns . '/media/audit', $export ),
			'wp_media_duplicates'       => array( 'GET', $ns . '/media/duplicates', $export ),
			'wp_broken_links'           => array( 'GET', $ns . '/content/broken-links', $export ),
			'wp_export_bundle'          => array( 'GET', $ns . '/export/bundle', $bundle ),
			'wp_update_content'         => array( 'POST', $ns . '/content/update', $content ),
			'wp_list_content_snapshots' => array(
				'GET',
				$ns . '/content/snapshots/{post}',
				array(
					'tools'  => array( AI_Site_Connector_Permissions::TOOL_READ_CONTENT ),
					'object' => 'content',
				),
			),
			'wp_rollback_content'       => array( 'POST', $ns . '/content/rollback', $content ),
			'wp_redirects'              => array( 'GET', $ns . '/diagnostics/redirects', $diag ),
		);

		// REST tools take method, route and permission from the tools catalog.
		$rest = array(
			'update_content'      => $content,
			'rollback_content'    => $content,
			'export_bundle'       => $bundle,
			'export_page_content' => array( 'object' => 'edit_post' ),
		);

		$ops = array();
		foreach ( $mcp as $name => $def ) {
			$ops[ 'mcp:' . $name ] = self::operation( 'mcp', $name, $def[0], $def[1], $def[2] );
		}
		foreach ( AI_Site_Connector_REST_Controller::tools_catalog() as $tool ) {
			$extra = array_merge( array( 'tools' => array( $tool['permission'] ) ), isset( $rest[ $tool['name'] ] ) ? $rest[ $tool['name'] ] : array() );
			$route = $ns . str_replace( '<id>', '{post}', $tool['route'] );

			$ops[ 'rest:' . $tool['name'] ] = self::operation( 'rest', $tool['name'], $tool['method'], $route, $extra );
		}
		return $ops;
	}

	/**
	 * Explain one operation for one user and, optionally, one credential.
	 *
	 * @param array $args {
	 *     @type int    $user_id   User to evaluate.
	 *     @type string $uuid      Application Password UUID; '' evaluates the role alone.
	 *     @type string $operation Key from operations(), or self::CUSTOM.
	 *     @type string $method    HTTP method of a typed route.
	 *     @type string $route     Typed REST route, e.g. /wp/v2/posts/12.
	 *     @type int    $post_id   Target post for object checks (optional).
	 *     @type bool   $dry_run   Preview a dry run of a content update or rollback.
	 * }
	 * @return array|WP_Error
	 */
	public static function explain( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'user_id'   => 0,
				'uuid'      => '',
				'operation' => '',
				'method'    => 'GET',
				'route'     => '',
				'post_id'   => 0,
				'dry_run'   => false,
			)
		);
		$user = get_userdata( (int) $args['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'asc_preview_user', __( 'Choose an existing user.', 'ai-site-connector' ) );
		}
		if ( self::CUSTOM === $args['operation'] ) {
			$op = self::custom_operation( $args['method'], $args['route'] );
		} else {
			$ops = self::operations();
			$op  = isset( $ops[ $args['operation'] ] ) ? $ops[ $args['operation'] ] : null;
		}
		if ( ! $op ) {
			return new WP_Error( 'asc_preview_operation', __( 'Choose an operation, or a method and a REST route that starts with /.', 'ai-site-connector' ) );
		}

		$post_id = max( 0, (int) $args['post_id'] );
		$post    = $post_id ? get_post( $post_id ) : null;
		$route   = self::resolve_route( $op, $post_id, $post );
		$routes  = array();
		if ( 'mcp' === $op['channel'] ) {
			$routes[] = array(
				'role'   => 'outer',
				'method' => 'POST',
				'route'  => '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp',
			);
			$routes[] = array(
				'role'   => 'inner',
				'method' => $op['method'],
				'route'  => $route,
			);
		} else {
			$routes[] = array(
				'role'   => 'direct',
				'method' => $op['method'],
				'route'  => $route,
			);
		}

		$checks = array_merge(
			self::credential_checks( $user, (string) $args['uuid'], $routes ),
			self::wordpress_checks( $user, $op, $routes, $post_id, $post ),
			self::plugin_checks( $user, $op, $route, ! empty( $args['dry_run'] ) )
		);

		$denials = array();
		$open    = false;
		foreach ( $checks as $check ) {
			if ( self::DENY === $check['status'] ) {
				$denials[] = $check['label'] . ': ' . $check['detail'];
			} elseif ( self::CONDITIONAL === $check['status'] || self::UNKNOWN === $check['status'] ) {
				$open = true;
			}
		}
		if ( $denials ) {
			$verdict = 'denied';
		} else {
			$verdict = $open ? 'conditional' : 'allowed';
		}

		return array(
			'verdict'   => $verdict,
			'operation' => 'custom' === $op['channel'] ? $op['method'] . ' ' . $route : $op['channel'] . ':' . $op['name'],
			'user'      => $user->user_login,
			'uuid'      => (string) $args['uuid'],
			'dry_run'   => ! empty( $args['dry_run'] ),
			'routes'    => $routes,
			'checks'    => $checks,
			'denials'   => $denials,
			'notes'     => array(
				__( 'Read-only mode and tool permissions apply only to this plugin\'s MCP tools and /ai-site-connector/v1 routes. The same credential can call WordPress core routes such as POST /wp/v2/posts directly; only its route scopes and the user\'s role limit those.', 'ai-site-connector' ),
				__( 'Security plugins, filters and server rules can still refuse a real request. To test the actual connection, run the live sign-in check on the Connection Test tab.', 'ai-site-connector' ),
			),
		);
	}

	/**
	 * Credential state and route scopes.
	 *
	 * @param WP_User $user   User.
	 * @param string  $uuid   Application Password UUID, or ''.
	 * @param array   $routes Routes the operation reaches.
	 * @return array
	 */
	private static function credential_checks( WP_User $user, $uuid, array $routes ) {
		$label = __( 'Application Password', 'ai-site-connector' );
		if ( '' === $uuid ) {
			return array( self::check( 'credential', $label, self::SKIPPED, __( 'None selected. Route scopes, IP allowlist and expiry are not evaluated; the results show what the role and this plugin\'s settings allow.', 'ai-site-connector' ) ) );
		}

		$checks = array();
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			$blocker  = AI_Site_Connector_Plugin::app_passwords_blocker();
			$checks[] = self::check( 'credential', __( 'Application Passwords', 'ai-site-connector' ), self::DENY, $blocker ? $blocker['message'] : __( 'Application Passwords are not available on this site, so WordPress rejects every Application Password.', 'ai-site-connector' ) );
		} elseif ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( $user ) ) {
			$checks[] = self::check( 'credential', __( 'Application Passwords', 'ai-site-connector' ), self::DENY, __( 'Application Passwords are turned off for this user by a filter.', 'ai-site-connector' ) );
		}

		$item = self::find_password( $user->ID, $uuid );
		if ( ! $item ) {
			$checks[] = self::check( 'credential', $label, self::DENY, __( 'This user has no Application Password with that UUID; it may have been revoked or rotated.', 'ai-site-connector' ) );
			return $checks;
		}
		$checks[] = self::check(
			'credential',
			$label,
			self::PASS,
			/* translators: 1: Application Password name, 2: creation date. */
			sprintf( __( '"%1$s", created %2$s.', 'ai-site-connector' ), isset( $item['name'] ) ? $item['name'] : '', isset( $item['created'] ) ? gmdate( 'Y-m-d', (int) $item['created'] ) : '?' )
		);

		$expires = AI_Site_Connector_App_Password_Meta::get_expires_at( $user->ID, $uuid );
		if ( ! $expires ) {
			$checks[] = self::check( 'credential', __( 'Expiry', 'ai-site-connector' ), self::PASS, __( 'Never expires.', 'ai-site-connector' ) );
		} elseif ( $expires <= time() ) {
			/* translators: %s: UTC date and time. */
			$checks[] = self::check( 'credential', __( 'Expiry', 'ai-site-connector' ), self::DENY, sprintf( __( 'Expired on %s UTC; every request is refused (401 rest_application_password_expired).', 'ai-site-connector' ), gmdate( 'Y-m-d H:i', $expires ) ) );
		} else {
			/* translators: %s: UTC date and time. */
			$checks[] = self::check( 'credential', __( 'Expiry', 'ai-site-connector' ), self::PASS, sprintf( __( 'Valid until %s UTC.', 'ai-site-connector' ), gmdate( 'Y-m-d H:i', $expires ) ) );
		}

		$cidrs = AI_Site_Connector_App_Password_Meta::get_ip_allowlist( $user->ID, $uuid );
		if ( $cidrs ) {
			/* translators: %s: comma-separated IP addresses or CIDR ranges. */
			$checks[] = self::check( 'credential', __( 'IP allowlist', 'ai-site-connector' ), self::CONDITIONAL, sprintf( __( 'Only from %s. Requests from any other address are refused (403 rest_forbidden_ip); the caller\'s address is not known here.', 'ai-site-connector' ), implode( ', ', $cidrs ) ) );
		} else {
			$checks[] = self::check( 'credential', __( 'IP allowlist', 'ai-site-connector' ), self::PASS, __( 'None: any IP address.', 'ai-site-connector' ) );
		}

		$scopes = AI_Site_Connector_App_Password_Meta::get_scopes( $user->ID, $uuid );
		if ( ! $scopes ) {
			$checks[] = self::check( 'scope', __( 'Route scopes', 'ai-site-connector' ), self::PASS, __( 'None set: any route the role allows.', 'ai-site-connector' ) );
			return $checks;
		}
		foreach ( $routes as $r ) {
			$label = self::route_label( $r );
			if ( false !== strpos( $r['route'], '{base}' ) ) {
				$checks[] = self::check( 'scope', $label, self::SKIPPED, __( 'Not reached: the post type is not available over REST.', 'ai-site-connector' ) );
				continue;
			}
			$match = self::matching_scope( $r['method'], $r['route'], $scopes );
			if ( $match ) {
				/* translators: 1: HTTP method, 2: REST route. */
				$checks[] = self::check( 'scope', $label, self::PASS, sprintf( __( 'Allowed by scope %1$s %2$s.', 'ai-site-connector' ), $match['method'], $match['route'] ) );
			} elseif ( false !== strpos( $r['route'], '{post}' ) ) {
				$checks[] = self::check( 'scope', $label, self::UNKNOWN, __( 'Depends on the post ID; enter one to check it.', 'ai-site-connector' ) );
			} else {
				/* translators: 1: HTTP method, 2: REST route. */
				$checks[] = self::check( 'scope', $label, self::DENY, sprintf( __( 'No scope allows %1$s %2$s, so the request is refused (403 rest_forbidden_scope).', 'ai-site-connector' ), $r['method'], $r['route'] ) );
			}
		}
		return $checks;
	}

	/**
	 * The WordPress side: each route's permission check and post access.
	 *
	 * @param WP_User      $user    User.
	 * @param array        $op      Operation.
	 * @param array        $routes  Routes the operation reaches.
	 * @param int          $post_id Requested post ID, or 0.
	 * @param WP_Post|null $post    That post, when it exists.
	 * @return array
	 */
	private static function wordpress_checks( WP_User $user, array $op, array $routes, $post_id, $post ) {
		$checks = array();
		foreach ( $routes as $r ) {
			$checks[] = self::route_check( $user, $r, $post_id, $post );
		}
		if ( '' === $op['object'] ) {
			return $checks;
		}
		$label = __( 'Post access', 'ai-site-connector' );
		if ( ! $post_id ) {
			$checks[] = self::check( 'wordpress', $label, self::UNKNOWN, __( 'Enter a post ID to check access to a specific post.', 'ai-site-connector' ) );
			return $checks;
		}
		if ( ! $post ) {
			/* translators: %d: post ID. */
			$checks[] = self::check( 'wordpress', $label, self::DENY, sprintf( __( 'Post #%d does not exist (404).', 'ai-site-connector' ), $post_id ) );
			return $checks;
		}
		if ( 'content' === $op['object'] ) {
			if ( ! in_array( $post->post_type, AI_Site_Connector_Content_Update::writable_post_types(), true ) ) {
				/* translators: %s: post type. */
				$checks[] = self::check( 'wordpress', $label, self::DENY, sprintf( __( 'Posts of type %s cannot be changed with this tool (400 asc_unsupported_post_type).', 'ai-site-connector' ), $post->post_type ) );
				return $checks;
			}
			if ( ! in_array( $post->post_status, AI_Site_Connector_Content_Update::CORE_STATUSES, true ) ) {
				/* translators: %s: post status. */
				$checks[] = self::check( 'wordpress', $label, self::DENY, sprintf( __( 'Posts in status %s cannot be changed with this tool (400 asc_unsupported_status).', 'ai-site-connector' ), $post->post_status ) );
				return $checks;
			}
		}
		if ( user_can( $user, 'edit_post', $post->ID ) ) {
			/* translators: %d: post ID. */
			$checks[] = self::check( 'wordpress', $label, self::PASS, sprintf( __( 'The user can edit post #%d.', 'ai-site-connector' ), $post->ID ) );
		} else {
			/* translators: 1: post ID, 2: error code. */
			$checks[] = self::check( 'wordpress', $label, self::DENY, sprintf( __( 'The user cannot edit post #%1$d (403 %2$s).', 'ai-site-connector' ), $post->ID, 'content' === $op['object'] ? 'asc_forbidden_post' : 'rest_forbidden' ) );
		}
		return $checks;
	}

	/**
	 * The permission check of the route that would handle a request.
	 *
	 * @param WP_User      $user    User.
	 * @param array        $r       Route: role, method, route.
	 * @param int          $post_id Requested post ID, or 0.
	 * @param WP_Post|null $post    That post, when it exists.
	 * @return array Check.
	 */
	private static function route_check( WP_User $user, array $r, $post_id, $post ) {
		$label = self::route_label( $r );
		if ( false !== strpos( $r['route'], '{base}' ) ) {
			/* translators: 1: post ID, 2: post type. */
			return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'Post #%1$d is a %2$s, which is not available over the REST API (400 asc_unsupported_post_type).', 'ai-site-connector' ), $post_id, $post ? $post->post_type : '?' ) );
		}
		$core = self::core_post_route( $r['route'] );
		if ( $core ) {
			return self::core_route_check( $user, $r['method'], $core, $label );
		}
		$handler = self::find_handler( $r['method'], $r['route'] );
		if ( ! $handler ) {
			if ( 'outer' === $r['role'] && defined( 'AI_SITE_CONNECTOR_MCP_DISABLE' ) && AI_SITE_CONNECTOR_MCP_DISABLE ) {
				return self::check( 'wordpress', $label, self::DENY, __( 'The MCP endpoint is turned off by AI_SITE_CONNECTOR_MCP_DISABLE (404 rest_no_route).', 'ai-site-connector' ) );
			}
			/* translators: 1: HTTP method, 2: REST route. */
			return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'No route is registered for %1$s %2$s (404 rest_no_route).', 'ai-site-connector' ), $r['method'], $r['route'] ) );
		}
		$need = self::callback_requirement( isset( $handler['permission_callback'] ) ? $handler['permission_callback'] : null );
		if ( null === $need ) {
			return self::check( 'wordpress', $label, self::UNKNOWN, __( 'Decided by the route\'s own permission check, which the preview does not run.', 'ai-site-connector' ) );
		}
		if ( 'public' === $need ) {
			return self::check( 'wordpress', $label, self::PASS, __( 'Public route.', 'ai-site-connector' ) );
		}
		if ( 'signed_in' === $need ) {
			return self::check( 'wordpress', $label, self::PASS, __( 'Any signed-in user.', 'ai-site-connector' ) );
		}
		if ( user_can( $user, $need ) ) {
			/* translators: %s: capability. */
			return self::check( 'wordpress', $label, self::PASS, sprintf( __( 'Needs the %s capability; the user has it.', 'ai-site-connector' ), $need ) );
		}
		/* translators: %s: capability. */
		return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'Needs the %s capability, which this user lacks (403 rest_forbidden).', 'ai-site-connector' ), $need ) );
	}

	/**
	 * WordPress core's posts controller, approximated with the same
	 * capabilities it checks.
	 *
	 * @param WP_User $user   User.
	 * @param string  $method HTTP method.
	 * @param array   $core   From core_post_route(): type, id.
	 * @param string  $label  Check label.
	 * @return array Check.
	 */
	private static function core_route_check( WP_User $user, $method, array $core, $label ) {
		$type = $core['type'];
		if ( null === $core['id'] ) {
			if ( 'GET' === $method ) {
				/* translators: %s: capability. */
				return self::check( 'wordpress', $label, self::PASS, sprintf( __( 'WordPress core: published items are public; other statuses need %s.', 'ai-site-connector' ), $type->cap->edit_posts ) );
			}
			if ( 'POST' !== $method ) {
				/* translators: 1: HTTP method, 2: REST route. */
				return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'No route is registered for %1$s %2$s (404 rest_no_route).', 'ai-site-connector' ), $method, self::post_type_base( $type->name ) ) );
			}
			if ( user_can( $user, $type->cap->create_posts ) ) {
				/* translators: %s: capability. */
				return self::check( 'wordpress', $label, self::PASS, sprintf( __( 'WordPress core: creating needs %s; the user has it.', 'ai-site-connector' ), $type->cap->create_posts ) );
			}
			/* translators: %s: capability. */
			return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'WordPress core: creating needs %s, which this user lacks (403 rest_cannot_create).', 'ai-site-connector' ), $type->cap->create_posts ) );
		}
		if ( 0 === $core['id'] ) {
			return self::check( 'wordpress', $label, self::UNKNOWN, __( 'WordPress core decides per post; enter a post ID to check it.', 'ai-site-connector' ) );
		}
		$post = get_post( $core['id'] );
		if ( ! $post || $post->post_type !== $type->name ) {
			/* translators: 1: post ID, 2: post type. */
			return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'There is no %2$s with ID %1$d (404 rest_post_invalid_id).', 'ai-site-connector' ), $core['id'], $type->name ) );
		}
		if ( 'GET' === $method ) {
			if ( 'publish' === $post->post_status && is_post_type_viewable( $type ) ) {
				return self::check( 'wordpress', $label, self::PASS, __( 'WordPress core: published, so anyone can read it.', 'ai-site-connector' ) );
			}
			if ( user_can( $user, 'read_post', $post->ID ) ) {
				return self::check( 'wordpress', $label, self::PASS, __( 'WordPress core: the user can read this post.', 'ai-site-connector' ) );
			}
			/* translators: %d: post ID. */
			return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'WordPress core: the user cannot read post #%d (403 rest_forbidden).', 'ai-site-connector' ), $post->ID ) );
		}
		$cap  = 'DELETE' === $method ? 'delete_post' : 'edit_post';
		$code = 'DELETE' === $method ? 'rest_cannot_delete' : 'rest_cannot_edit';
		if ( user_can( $user, $cap, $post->ID ) ) {
			/* translators: 1: capability, 2: post ID. */
			return self::check( 'wordpress', $label, self::PASS, sprintf( __( 'WordPress core: %1$s on post #%2$d; the user has it.', 'ai-site-connector' ), $cap, $post->ID ) );
		}
		/* translators: 1: capability, 2: post ID, 3: error code. */
		return self::check( 'wordpress', $label, self::DENY, sprintf( __( 'WordPress core: the user lacks %1$s on post #%2$d (403 %3$s).', 'ai-site-connector' ), $cap, $post->ID, $code ) );
	}

	/**
	 * The connector switch and tool permissions.
	 *
	 * @param WP_User $user    User.
	 * @param array   $op      Operation.
	 * @param string  $route   Route the operation reaches (inner route for MCP).
	 * @param bool    $dry_run Whether a content update or rollback is a dry run.
	 * @return array
	 */
	private static function plugin_checks( WP_User $user, array $op, $route, $dry_run ) {
		$ns = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE;
		if ( 'mcp' !== $op['channel'] && 0 !== strpos( strtolower( $route ) . '/', strtolower( $ns ) . '/' ) ) {
			return array( self::check( 'plugin', __( 'Tool permissions and read-only mode', 'ai-site-connector' ), self::SKIPPED, __( 'Not applied: they cover only this plugin\'s MCP tools and /ai-site-connector/v1 routes. Route scopes and the user\'s role still limit this route.', 'ai-site-connector' ) ) );
		}

		$label = __( 'Connector switch', 'ai-site-connector' );
		if ( 'mcp' !== $op['channel'] && strtolower( $ns . '/health' ) === strtolower( untrailingslashit( $route ) ) ) {
			return array( self::check( 'plugin', $label, self::PASS, __( 'The health route answers even while the connector is disabled.', 'ai-site-connector' ) ) );
		}
		if ( AI_Site_Connector_Permissions::is_disabled() ) {
			return array( self::check( 'plugin', $label, self::DENY, __( 'AI Site Connector is disabled on this site, so its routes and MCP answer 503 (ai_site_connector_disabled).', 'ai-site-connector' ) ) );
		}
		$checks = array( self::check( 'plugin', $label, self::PASS, __( 'Enabled.', 'ai-site-connector' ) ) );

		if ( 'custom' === $op['channel'] ) {
			$checks[] = self::check( 'plugin', __( 'Tool permissions', 'ai-site-connector' ), self::UNKNOWN, __( 'Pick the matching tool from the operation list to check the tool permissions this route applies.', 'ai-site-connector' ) );
			return $checks;
		}
		foreach ( $op['tools'] as $tool ) {
			$checks[] = self::tool_check( $user, $tool, false );
		}
		foreach ( $op['apply_tools'] as $tool ) {
			$checks[] = $dry_run
				? self::check( 'plugin', self::tool_label( $tool, true ), self::SKIPPED, __( 'Not checked for a dry run.', 'ai-site-connector' ) )
				: self::tool_check( $user, $tool, true );
		}
		if ( $op['seo'] && ! $dry_run ) {
			$seo = self::tool_check( $user, AI_Site_Connector_Permissions::TOOL_UPDATE_SEO, true );
			if ( self::DENY === $seo['status'] ) {
				$seo['status'] = self::CONDITIONAL;
				/* translators: %s: why the SEO permission refuses. */
				$seo['detail'] = sprintf( __( 'Only when SEO fields change: %s', 'ai-site-connector' ), $seo['detail'] );
			}
			$checks[] = $seo;
		}
		if ( ! $op['tools'] && ! $op['apply_tools'] ) {
			$checks[] = self::check( 'plugin', __( 'Tool permissions', 'ai-site-connector' ), self::SKIPPED, __( 'This operation checks no tool permission, so read-only mode and the tool switches do not limit it.', 'ai-site-connector' ) );
		} elseif ( has_filter( 'ai_site_connector_can_execute_tool' ) ) {
			$checks[] = self::check( 'plugin', __( 'Custom filter', 'ai-site-connector' ), self::UNKNOWN, __( 'Code on this site hooks ai_site_connector_can_execute_tool and can still refuse the tool when it runs.', 'ai-site-connector' ) );
		}
		return $checks;
	}

	/**
	 * One tool permission, with the reason it refuses.
	 *
	 * @param WP_User $user  User.
	 * @param string  $tool  Tool permission key.
	 * @param bool    $apply Whether it applies only when changes are applied.
	 * @return array Check.
	 */
	private static function tool_check( WP_User $user, $tool, $apply ) {
		$label  = self::tool_label( $tool, $apply );
		$reason = AI_Site_Connector_Permissions::gate_reason( $tool, $user );
		if ( 'allowed' === $reason ) {
			return self::check( 'plugin', $label, self::PASS, __( 'On.', 'ai-site-connector' ) );
		}
		$detail = AI_Site_Connector_Permissions::gate_message( $reason );
		if ( 'wp_cap' === $reason ) {
			$all = AI_Site_Connector_Permissions::get_all();
			/* translators: %s: capability. */
			$detail = sprintf( __( 'This tool needs the %s capability, which this user lacks.', 'ai-site-connector' ), $all[ $tool ]['wp_cap'] );
		} elseif ( 'read_only_mode' === $reason ) {
			$detail .= ' ' . __( 'Read-only mode blocks this plugin\'s write tools only; it does not limit WordPress core routes.', 'ai-site-connector' );
		}
		/* translators: 1: explanation, 2: reason code. */
		return self::check( 'plugin', $label, self::DENY, sprintf( __( '%1$s (reason: %2$s)', 'ai-site-connector' ), $detail, $reason ) );
	}

	/**
	 * @param string $tool  Tool permission key.
	 * @param bool   $apply Whether it applies only when changes are applied.
	 * @return string
	 */
	private static function tool_label( $tool, $apply ) {
		$all  = AI_Site_Connector_Permissions::get_all();
		$name = isset( $all[ $tool ] ) ? $all[ $tool ]['label'] : $tool;
		/* translators: %s: tool permission name. */
		$label = sprintf( __( 'Tool permission: %s', 'ai-site-connector' ), $name );
		return $apply ? $label . ' ' . __( '(when applying changes)', 'ai-site-connector' ) : $label;
	}

	/**
	 * Requirement of a permission callback this preview understands:
	 * public, signed_in, a capability, or null when it cannot tell.
	 *
	 * @param mixed $callback Route permission_callback.
	 * @return string|null
	 */
	public static function callback_requirement( $callback ) {
		// WordPress runs no check at all when a route has no callback.
		if ( empty( $callback ) || '__return_true' === $callback ) {
			return 'public';
		}
		if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_string( $callback[0] ) || ! is_string( $callback[1] ) ) {
			return null;
		}
		$known = array(
			'AI_Site_Connector_REST_Controller::auth_admin'      => 'manage_options',
			'AI_Site_Connector_REST_Controller::auth_edit_posts' => 'edit_posts',
			'AI_Site_Connector_REST_Controller::auth_edit_pages' => 'edit_pages',
			'AI_Site_Connector_REST_Controller::auth_upload'     => 'upload_files',
			'AI_Site_Connector_REST_Controller::auth_logged_in'  => 'signed_in',
			// Also refuses while disabled; the connector switch check covers that.
			'AI_Site_Connector_MCP_Server::permission'           => 'signed_in',
		);
		$key = $callback[0] . '::' . $callback[1];
		return isset( $known[ $key ] ) ? $known[ $key ] : null;
	}

	/**
	 * The handler WordPress would pick for a method and route, matched the
	 * way WP_REST_Server does it.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route; {post} stands for any ID.
	 * @return array|null Handler.
	 */
	private static function find_handler( $method, $route ) {
		$path = str_replace( '{post}', '1', $route );
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			if ( ! preg_match( '@^' . $pattern . '$@i', $path ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				if ( ! empty( $handler['methods'][ $method ] ) ) {
					return $handler;
				}
			}
		}
		return null;
	}

	/**
	 * A post type route served by core's posts controller, with the item ID
	 * (0 for the {post} placeholder) or null for the collection.
	 *
	 * @param string $route REST route.
	 * @return array|null { type: WP_Post_Type, id: int|null }
	 */
	private static function core_post_route( $route ) {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			$controller = ! empty( $type->rest_controller_class ) ? $type->rest_controller_class : 'WP_REST_Posts_Controller';
			if ( ! is_string( $controller ) || ! is_a( $controller, 'WP_REST_Posts_Controller', true ) ) {
				continue;
			}
			$base = self::post_type_base( $type->name );
			if ( $route === $base ) {
				return array(
					'type' => $type,
					'id'   => null,
				);
			}
			if ( 0 === strpos( $route, $base . '/' ) ) {
				$rest = substr( $route, strlen( $base ) + 1 );
				if ( '{post}' === $rest || ctype_digit( $rest ) ) {
					return array(
						'type' => $type,
						'id'   => '{post}' === $rest ? 0 : (int) $rest,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Core REST collection route of a post type, as the MCP server builds it.
	 *
	 * @param string $post_type Post type.
	 * @return string Route, or '' when the type is not available over REST.
	 */
	private static function post_type_base( $post_type ) {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return '';
		}
		$base      = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
		$namespace = ! empty( $obj->rest_namespace ) ? $obj->rest_namespace : 'wp/v2';
		return '/' . trim( $namespace, '/' ) . '/' . trim( $base, '/' );
	}

	/**
	 * Fill {base} and {post} in an operation's route.
	 *
	 * @param array        $op      Operation.
	 * @param int          $post_id Requested post ID, or 0.
	 * @param WP_Post|null $post    That post, when it exists.
	 * @return string
	 */
	private static function resolve_route( array $op, $post_id, $post ) {
		$route = $op['route'];
		if ( false !== strpos( $route, '{base}' ) ) {
			$type = $post && false !== strpos( $route, '{post}' ) ? $post->post_type : $op['post_type'];
			$base = self::post_type_base( $type );
			if ( '' !== $base ) {
				$route = str_replace( '{base}', $base, $route );
			}
		}
		return $post_id ? str_replace( '{post}', (string) $post_id, $route ) : $route;
	}

	/**
	 * A typed route as an operation, or null when it is not usable.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route, optionally with a /wp-json prefix.
	 * @return array|null
	 */
	private static function custom_operation( $method, $route ) {
		$method = strtoupper( (string) $method );
		$route  = preg_replace( '/[?#].*$/', '', trim( (string) $route ) );
		if ( ! in_array( $method, self::METHODS, true ) || '' === $route || '/' !== $route[0] ) {
			return null;
		}
		$route = preg_replace( '#^/wp-json(?=/)#', '', $route );
		$route = '/' . trim( $route, '/' );
		if ( '/' === $route ) {
			return null;
		}
		return self::operation( 'custom', '', $method, $route, array() );
	}

	/**
	 * @param string $channel mcp, rest or custom.
	 * @param string $name    Tool name.
	 * @param string $method  HTTP method of the route the tool reaches.
	 * @param string $route   That route.
	 * @param array  $extra   Overrides.
	 * @return array
	 */
	private static function operation( $channel, $name, $method, $route, array $extra ) {
		return array_merge(
			array(
				'channel'     => $channel,
				'name'        => $name,
				'method'      => $method,
				'route'       => $route,
				'post_type'   => 'post',
				'tools'       => array(),
				'apply_tools' => array(),
				'seo'         => false,
				'object'      => '',
			),
			$extra
		);
	}

	/**
	 * The first scope that allows a request, or null.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $scopes Scopes.
	 * @return array|null
	 */
	private static function matching_scope( $method, $route, array $scopes ) {
		foreach ( $scopes as $scope ) {
			if ( AI_Site_Connector_App_Password_Meta::route_matches_scopes( $method, $route, array( $scope ) ) ) {
				return $scope;
			}
		}
		return null;
	}

	/**
	 * A user's Application Password record (never the password).
	 *
	 * @param int    $user_id User ID.
	 * @param string $uuid    UUID.
	 * @return array|null
	 */
	private static function find_password( $user_id, $uuid ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return null;
		}
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( isset( $item['uuid'] ) && $item['uuid'] === $uuid ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param array $r Route: role, method, route.
	 * @return string
	 */
	private static function route_label( array $r ) {
		$roles = array(
			'outer'  => __( 'MCP endpoint', 'ai-site-connector' ),
			'inner'  => __( 'Route the tool calls', 'ai-site-connector' ),
			'direct' => __( 'Route', 'ai-site-connector' ),
		);
		return $roles[ $r['role'] ] . ' ' . $r['method'] . ' ' . $r['route'];
	}

	/**
	 * @param string $group  credential, scope, wordpress or plugin.
	 * @param string $label  What was checked.
	 * @param string $status One of the status constants.
	 * @param string $detail Why.
	 * @return array
	 */
	private static function check( $group, $label, $status, $detail ) {
		return array(
			'group'  => $group,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		);
	}
}
