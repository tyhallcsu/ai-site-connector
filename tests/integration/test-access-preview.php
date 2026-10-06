<?php
/**
 * Effective-access preview (#124): expired credentials, missing outer or
 * inner scopes, post access, tool switches and read-only mode each get a
 * named reason; core routes are not blamed on plugin settings; nothing is
 * written and no password is shown.
 *
 * @package AI_Site_Connector_Tests
 */

/**
 * Explain an operation, failing the test on WP_Error.
 *
 * @param array $args explain() arguments.
 * @return array
 */
function asc_it_preview( array $args ) {
	$result = AI_Site_Connector_Access_Preview::explain( $args );
	asc_assert( is_array( $result ), 'explain failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : gettype( $result ) ) );
	return $result;
}

/**
 * Checks of one group and status, as "label: detail" lines.
 *
 * @param array  $result From explain().
 * @param string $group  Check group.
 * @param string $status Check status.
 * @return string
 */
function asc_it_preview_find( array $result, $group, $status ) {
	$out = array();
	foreach ( $result['checks'] as $check ) {
		if ( $group === $check['group'] && $status === $check['status'] ) {
			$out[] = $check['label'] . ': ' . $check['detail'];
		}
	}
	return implode( "\n", $out );
}

asc_it(
	'access preview: expired credential and missing outer or inner scope are named (#124)',
	function () {
		$deny   = AI_Site_Connector_Access_Preview::DENY;
		$ns     = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE;
		$editor = asc_it_user( 'editor' );
		$post   = asc_it_post( array( 'post_author' => $editor ) );

		list( $plain, $item ) = WP_Application_Passwords::create_new_application_password( $editor, array( 'name' => 'asc-it-preview' ) );
		$uuid = $item['uuid'];
		$base = array(
			'user_id' => $editor,
			'uuid'    => $uuid,
			'post_id' => $post,
			'dry_run' => true,
		);
		try {
			// Scoped to the route the tool calls only: the MCP endpoint is refused.
			AI_Site_Connector_App_Password_Meta::set_scopes( $editor, $uuid, array( array( 'method' => 'POST', 'route' => $ns . '/content/update' ) ) );
			$inner_only = asc_it_preview( array_merge( $base, array( 'operation' => 'mcp:wp_update_content' ) ) );
			asc_assert_same( 'denied', $inner_only['verdict'], 'inner-only scope through MCP' );
			$scope_deny = asc_it_preview_find( $inner_only, 'scope', $deny );
			asc_assert( false !== strpos( $scope_deny, 'POST ' . $ns . '/mcp' ) && false !== strpos( $scope_deny, 'rest_forbidden_scope' ), 'outer scope denial not named: ' . $scope_deny );
			asc_assert( false !== strpos( asc_it_preview_find( $inner_only, 'scope', AI_Site_Connector_Access_Preview::PASS ), 'Allowed by scope POST ' . $ns . '/content/update' ), 'inner scope match not shown' );
			$direct = asc_it_preview( array_merge( $base, array( 'operation' => 'rest:update_content' ) ) );
			asc_assert_same( '', asc_it_preview_find( $direct, 'scope', $deny ), 'direct REST call refused by scope' );
			asc_assert( 'denied' !== $direct['verdict'], 'dry run over REST denied: ' . implode( ' | ', $direct['denials'] ) );

			// Scoped to the MCP endpoint only: the route the tool calls is refused.
			AI_Site_Connector_App_Password_Meta::set_scopes( $editor, $uuid, array( array( 'method' => 'POST', 'route' => $ns . '/mcp' ) ) );
			$outer_only = asc_it_preview( array_merge( $base, array( 'operation' => 'mcp:wp_update_content' ) ) );
			$scope_deny = asc_it_preview_find( $outer_only, 'scope', $deny );
			asc_assert( false !== strpos( $scope_deny, 'POST ' . $ns . '/content/update' ) && false === strpos( $scope_deny, $ns . '/mcp' ), 'inner scope denial not named: ' . $scope_deny );

			// A post-specific scope cannot be judged until the post is known.
			AI_Site_Connector_App_Password_Meta::set_scopes(
				$editor,
				$uuid,
				array(
					array( 'method' => 'POST', 'route' => $ns . '/mcp' ),
					array( 'method' => 'POST', 'route' => '/wp/v2/posts/' . $post ),
				)
			);
			$no_post = asc_it_preview( array( 'user_id' => $editor, 'uuid' => $uuid, 'operation' => 'mcp:wp_update_post' ) );
			asc_assert( false !== strpos( asc_it_preview_find( $no_post, 'scope', AI_Site_Connector_Access_Preview::UNKNOWN ), 'post ID' ), 'post-specific scope not reported as unknown' );
			$with_post = asc_it_preview( array( 'user_id' => $editor, 'uuid' => $uuid, 'operation' => 'mcp:wp_update_post', 'post_id' => $post ) );
			asc_assert_same( '', asc_it_preview_find( $with_post, 'scope', $deny ) . asc_it_preview_find( $with_post, 'scope', AI_Site_Connector_Access_Preview::UNKNOWN ), 'post-specific scope with its post' );

			// Expired, then valid but limited to an IP range.
			AI_Site_Connector_App_Password_Meta::set_scopes( $editor, $uuid, array() );
			AI_Site_Connector_App_Password_Meta::set_expires_at( $editor, $uuid, time() - 60 );
			$expired = asc_it_preview( array_merge( $base, array( 'operation' => 'rest:update_content' ) ) );
			asc_assert_same( 'denied', $expired['verdict'], 'expired credential' );
			asc_assert( false !== strpos( asc_it_preview_find( $expired, 'credential', $deny ), 'rest_application_password_expired' ), 'expiry denial not named' );
			AI_Site_Connector_App_Password_Meta::set_expires_at( $editor, $uuid, time() + DAY_IN_SECONDS );
			AI_Site_Connector_App_Password_Meta::set_ip_allowlist( $editor, $uuid, array( '192.0.2.0/24' ) );
			$ip = asc_it_preview( array_merge( $base, array( 'operation' => 'rest:update_content' ) ) );
			asc_assert_same( 'conditional', $ip['verdict'], 'IP-limited credential' );
			asc_assert( false !== strpos( asc_it_preview_find( $ip, 'credential', AI_Site_Connector_Access_Preview::CONDITIONAL ), '192.0.2.0/24' ), 'IP allowlist not shown as conditional' );

			// A revoked or unknown UUID, and no credential at all.
			$gone = asc_it_preview( array_merge( $base, array( 'operation' => 'rest:update_content', 'uuid' => wp_generate_uuid4() ) ) );
			asc_assert( false !== strpos( asc_it_preview_find( $gone, 'credential', $deny ), 'no Application Password with that UUID' ), 'unknown UUID not named' );
			$role = asc_it_preview( array_merge( $base, array( 'operation' => 'mcp:wp_update_content', 'uuid' => '' ) ) );
			asc_assert_same( '', asc_it_preview_find( $role, 'scope', AI_Site_Connector_Access_Preview::PASS ) . asc_it_preview_find( $role, 'scope', $deny ), 'scopes evaluated without a credential' );

			$all = wp_json_encode( array( $inner_only, $outer_only, $no_post, $with_post, $expired, $ip, $gone, $role ) );
			asc_assert( false === strpos( $all, $plain ), 'a password appeared in a preview' );
		} finally {
			WP_Application_Passwords::delete_application_password( $editor, $uuid );
			AI_Site_Connector_App_Password_Meta::delete_extras( $editor, $uuid );
			wp_delete_post( $post, true );
			asc_it_delete_user( $editor );
		}
	}
);

asc_it(
	'access preview: post access, tool switches and read-only mode, kept apart from core routes (#124)',
	function () {
		$deny   = AI_Site_Connector_Access_Preview::DENY;
		$author = asc_it_user( 'author' );
		$editor = asc_it_user( 'editor' );
		$theirs = asc_it_post( array( 'post_author' => $editor ) );
		$own    = asc_it_post( array( 'post_author' => $author ) );
		try {
			// Post access: an author cannot export or edit someone else's post.
			$export = asc_it_preview( array( 'user_id' => $author, 'operation' => 'rest:export_page_content', 'post_id' => $theirs ) );
			asc_assert_same( 'denied', $export['verdict'], 'export of another user\'s post' );
			asc_assert( false !== strpos( asc_it_preview_find( $export, 'wordpress', $deny ), 'cannot edit post #' . $theirs ), 'post access denial not named' );
			$core = asc_it_preview( array( 'user_id' => $author, 'operation' => 'mcp:wp_update_post', 'post_id' => $theirs ) );
			asc_assert( false !== strpos( asc_it_preview_find( $core, 'wordpress', $deny ), 'rest_cannot_edit' ), 'core edit denial not named' );
			$mine = asc_it_preview( array( 'user_id' => $author, 'operation' => 'rest:export_page_content', 'post_id' => $own ) );
			asc_assert_same( '', asc_it_preview_find( $mine, 'wordpress', $deny ), 'own post refused' );
			$missing = asc_it_preview( array( 'user_id' => $author, 'operation' => 'mcp:wp_update_content', 'post_id' => 987654321, 'dry_run' => true ) );
			asc_assert( false !== strpos( asc_it_preview_find( $missing, 'wordpress', $deny ), 'does not exist' ), 'missing post not named' );

			// A role without the route's capability.
			$report = asc_it_preview( array( 'user_id' => $editor, 'operation' => 'rest:site_capability_report' ) );
			asc_assert( false !== strpos( asc_it_preview_find( $report, 'wordpress', $deny ), 'manage_options' ), 'route capability denial not named' );

			// Tool switch: write_content refuses applied changes but not dry runs.
			$apply = array( 'user_id' => $editor, 'operation' => 'mcp:wp_update_content', 'post_id' => $theirs );
			asc_it_with_permissions(
				array( 'write_content' => false ),
				function () use ( $apply, $deny ) {
					$off = asc_it_preview( $apply );
					asc_assert( false !== strpos( asc_it_preview_find( $off, 'plugin', $deny ), 'whitelist_off' ), 'tool switch denial not named' );
					$dry = asc_it_preview( array_merge( $apply, array( 'dry_run' => true ) ) );
					asc_assert_same( '', asc_it_preview_find( $dry, 'plugin', $deny ), 'dry run refused by the write switch' );
				}
			);
			asc_it_with_permissions(
				array( 'write_content' => true ),
				function () use ( $apply, $deny ) {
					$on = asc_it_preview( $apply );
					asc_assert_same( '', asc_it_preview_find( $on, 'plugin', $deny ), 'enabled write tool refused' );

					// Read-only mode refuses the plugin tool, not the same edit over core REST.
					update_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION, 1, false );
					try {
						$ro = asc_it_preview( $apply );
						asc_assert( false !== strpos( asc_it_preview_find( $ro, 'plugin', $deny ), 'read_only_mode' ), 'read-only denial not named' );
						$direct = asc_it_preview(
							array(
								'user_id'   => $apply['user_id'],
								'operation' => AI_Site_Connector_Access_Preview::CUSTOM,
								'method'    => 'POST',
								'route'     => '/wp-json/wp/v2/posts/' . $apply['post_id'],
							)
						);
						asc_assert_same( 'allowed', $direct['verdict'], 'core route blamed on plugin settings: ' . implode( ' | ', $direct['denials'] ) );
						asc_assert( false !== strpos( asc_it_preview_find( $direct, 'plugin', AI_Site_Connector_Access_Preview::SKIPPED ), 'Not applied' ), 'core route not marked as outside plugin settings' );
					} finally {
						delete_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION );
					}
				}
			);

			// Connector switch: MCP is refused, the health route still answers.
			update_option( AI_Site_Connector_Permissions::DISABLED_OPTION, 1, false );
			try {
				$off = asc_it_preview( array( 'user_id' => $editor, 'operation' => 'mcp:wp_health' ) );
				asc_assert( false !== strpos( asc_it_preview_find( $off, 'plugin', $deny ), 'ai_site_connector_disabled' ), 'disabled connector not named' );
				$health = asc_it_preview( array( 'user_id' => $editor, 'operation' => AI_Site_Connector_Access_Preview::CUSTOM, 'method' => 'GET', 'route' => '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health' ) );
				asc_assert_same( 'allowed', $health['verdict'], 'health route refused while disabled' );
			} finally {
				delete_option( AI_Site_Connector_Permissions::DISABLED_OPTION );
			}
		} finally {
			wp_delete_post( $theirs, true );
			wp_delete_post( $own, true );
			asc_it_delete_user( $author );
			asc_it_delete_user( $editor );
		}
	}
);

asc_it(
	'access preview: every MCP and REST tool is covered and every plugin route check is understood (#124)',
	function () {
		$ops   = AI_Site_Connector_Access_Preview::operations();
		$list  = asc_it_mcp( 'tools/list' );
		$names = wp_list_pluck( $list['result']['tools'], 'name' );
		asc_assert( count( $names ) >= 21, 'tools/list returned ' . count( $names ) . ' tools' );
		foreach ( $names as $name ) {
			asc_assert( isset( $ops[ 'mcp:' . $name ] ), "MCP tool {$name} is missing from the access preview" );
		}
		foreach ( AI_Site_Connector_REST_Controller::tools_catalog() as $tool ) {
			asc_assert( isset( $ops[ 'rest:' . $tool['name'] ] ), "REST tool {$tool['name']} is missing from the access preview" );
		}
		$catalog = AI_Site_Connector_Permissions::catalog();
		foreach ( $ops as $key => $op ) {
			foreach ( array_merge( $op['tools'], $op['apply_tools'] ) as $tool ) {
				asc_assert( isset( $catalog[ $tool ] ), "{$key}: unknown tool permission {$tool}" );
			}
			$result = asc_it_preview( array( 'user_id' => get_current_user_id(), 'operation' => $key ) );
			$checks = wp_json_encode( $result['checks'] );
			asc_assert( false === strpos( $checks, 'rest_no_route' ), "{$key}: its route is not registered: {$checks}" );
			asc_assert( false === strpos( asc_it_preview_find( $result, 'wordpress', AI_Site_Connector_Access_Preview::UNKNOWN ), 'own permission check' ), "{$key}: route check not understood" );
		}
		$ns = '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/';
		foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
			if ( 0 !== strpos( $pattern, $ns ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$callback = isset( $handler['permission_callback'] ) ? $handler['permission_callback'] : null;
				asc_assert( null !== AI_Site_Connector_Access_Preview::callback_requirement( $callback ), "permission check of {$pattern} is not understood" );
			}
		}
	}
);

asc_it(
	'access preview: writes nothing, shows no password, renders on the Credentials tab (#124)',
	function () {
		global $wpdb;
		$editor = asc_it_user( 'editor' );
		$post   = asc_it_post( array( 'post_author' => $editor ) );

		list( $plain, $item ) = WP_Application_Passwords::create_new_application_password( $editor, array( 'name' => 'asc-it-preview-ui' ) );
		$uuid = $item['uuid'];
		AI_Site_Connector_App_Password_Meta::set_scopes( $editor, $uuid, array( array( 'method' => 'POST', 'route' => '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/content/update' ) ) );
		$table = AI_Site_Connector_Audit_Log::table_name();
		$keys  = array( 'asc_cred', 'asc_op', 'asc_post' );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads its own table.
			$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$meta_before  = wp_json_encode( get_user_meta( $editor ) );
			$db_before    = asc_it_db_fingerprint();
			// Every operation, including ones a real request would refuse and log.
			foreach ( array_keys( AI_Site_Connector_Access_Preview::operations() ) as $key ) {
				asc_it_preview( array( 'user_id' => $editor, 'uuid' => $uuid, 'operation' => $key, 'post_id' => $post ) );
			}
			asc_assert_same( $db_before, asc_it_db_fingerprint(), 'the preview changed posts or options' );
			asc_assert_same( $meta_before, wp_json_encode( get_user_meta( $editor ) ), 'the preview changed the credential owner\'s user meta' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads its own table.
			asc_assert_same( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'the preview wrote audit entries' );

			$_GET['asc_cred'] = $editor . ':' . $uuid;
			$_GET['asc_op']   = 'mcp:wp_update_content';
			$_GET['asc_post'] = (string) $post;
			$html             = asc_it_render_admin_tab( 'credentials' );

			asc_assert( false !== strpos( $html, 'id="asc-access-preview"' ), 'preview card missing from the Credentials tab' );
			asc_assert( false !== strpos( $html, 'rest_forbidden_scope' ), 'rendered result lacks the scope denial' );
			asc_assert( 1 === preg_match( '/<option value="' . preg_quote( $editor . ':' . $uuid, '/' ) . '"\s+selected/', $html ), 'chosen credential not kept selected' );
			asc_assert( false === strpos( $html, $plain ), 'the rendered preview shows a password' );
		} finally {
			foreach ( $keys as $key ) {
				unset( $_GET[ $key ] );
			}
			WP_Application_Passwords::delete_application_password( $editor, $uuid );
			AI_Site_Connector_App_Password_Meta::delete_extras( $editor, $uuid );
			wp_delete_post( $post, true );
			asc_it_delete_user( $editor );
		}
	}
);
