<?php
/**
 * Diagnostics tools: self-test (#72), REST routes (#71), page builder (#69),
 * redirects (#67) — services, REST auth, MCP exposure.
 *
 * @package AI_Site_Connector_Tests
 */

const ASC_IT_DIAG_ROUTES = array(
	'/diagnostics/self-test',
	'/diagnostics/rest-routes',
	'/diagnostics/page-builder',
	'/diagnostics/redirects',
);

asc_it(
	'diagnostics: anonymous and editor are denied on every route',
	function () {
		$editor = asc_it_user( 'editor' );
		try {
			foreach ( ASC_IT_DIAG_ROUTES as $route ) {
				$anon = asc_it_as_user( 0, function () use ( $route ) {
					return asc_it_rest( 'GET', $route );
				} );
				asc_assert_same( 401, $anon->get_status(), "anonymous {$route}" );
				$ed = asc_it_as_user( $editor, function () use ( $route ) {
					return asc_it_rest( 'GET', $route );
				} );
				asc_assert_same( 403, $ed->get_status(), "editor {$route}" );
			}
		} finally {
			asc_it_delete_user( $editor );
		}
	}
);

asc_it(
	'diagnostics: view_diagnostics toggle off denies admin',
	function () {
		asc_it_with_permissions(
			array( 'view_diagnostics' => false ),
			function () {
				$res = asc_it_rest( 'GET', '/diagnostics/redirects' );
				asc_assert_same( 403, $res->get_status(), 'admin with tool disabled' );
			}
		);
	}
);

asc_it(
	'diagnostics: all four routes are read-only for site content',
	function () {
		$post   = asc_it_post();
		$before = asc_it_db_fingerprint();
		try {
			foreach ( ASC_IT_DIAG_ROUTES as $route ) {
				$res = asc_it_rest( 'GET', $route, '/diagnostics/page-builder' === $route ? array( 'post_ids' => array( $post ) ) : array() );
				asc_assert_same( 200, $res->get_status(), "admin {$route}" );
			}
			asc_assert_same( $before, asc_it_db_fingerprint(), 'a diagnostics route mutated the DB' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

// --- self-test ------------------------------------------------------------

asc_it(
	'self-test: stable schema, warn case on clean install',
	function () {
		$post = asc_it_post();
		try {
			$res = AI_Site_Connector_Diagnostics::self_test();
			asc_assert_same( array( 'generated_at', 'overall', 'checks', 'summary' ), array_keys( $res ), 'top-level keys' );
			$names = array();
			foreach ( $res['checks'] as $c ) {
				asc_assert_same( array( 'name', 'status', 'message' ), array_keys( $c ), 'check keys' );
				asc_assert( in_array( $c['status'], array( 'pass', 'warn', 'fail' ), true ), 'status enum' );
				$names[ $c['name'] ] = $c['status'];
			}
			foreach ( array( 'plugin_loaded', 'rest_server_available', 'mcp_route_registered', 'authenticated_user', 'uploads_writable', 'export_dir_writable', 'temp_dir_writable', 'seo_plugin_detected', 'page_builder_detected', 'audit_log_table_present', 'seo_dry_run_invariant' ) as $expected ) {
				asc_assert( isset( $names[ $expected ] ), "missing check {$expected}" );
			}
			asc_assert_same( 'pass', $names['mcp_route_registered'], 'mcp route' );
			asc_assert_same( 'pass', $names['seo_dry_run_invariant'], 'dry-run invariant' );
			asc_assert_same( 'warn', $names['seo_plugin_detected'], 'no SEO plugin warns' );
			asc_assert_same( 'warn', $res['overall'], 'overall' );
			asc_assert_same( count( $res['checks'] ), array_sum( $res['summary'] ), 'summary totals' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'self-test: unwritable uploads produces fail',
	function () {
		$res = asc_it_with_filter(
			'upload_dir',
			static function ( $dirs ) {
				$dirs['basedir'] = '/nonexistent-asc-it/uploads';
				$dirs['path']    = '/nonexistent-asc-it/uploads';
				return $dirs;
			},
			function () {
				return AI_Site_Connector_Diagnostics::self_test();
			}
		);
		$by = array_column( $res['checks'], 'status', 'name' );
		asc_assert_same( 'fail', $by['uploads_writable'], 'uploads check' );
		asc_assert_same( 'fail', $by['export_dir_writable'], 'export dir check' );
		asc_assert_same( 'fail', $res['overall'], 'overall' );
		asc_assert( false === strpos( wp_json_encode( $res ), '/nonexistent-asc-it' ), 'self-test leaked filesystem path' );
	}
);

// --- REST routes ------------------------------------------------------------

asc_it(
	'rest-routes: namespaces, methods, args, no callables',
	function () {
		$res = AI_Site_Connector_Diagnostics::rest_routes();
		asc_assert( in_array( 'wp/v2', $res['namespaces'], true ), 'wp/v2 namespace' );
		asc_assert( in_array( 'ai-site-connector/v1', $res['namespaces'], true ), 'plugin namespace' );
		asc_assert_same( count( $res['routes'] ), $res['route_count'], 'route_count' );

		$by_route = array();
		foreach ( $res['routes'] as $r ) {
			$by_route[ $r['route'] ] = $r;
		}
		$redir = $by_route['/ai-site-connector/v1/diagnostics/redirects'];
		asc_assert_same( 'ai-site-connector/v1', $redir['namespace'], 'namespace match' );
		asc_assert_same( array( 'GET' ), $redir['methods'], 'methods' );
		$args = (array) $redir['args'];
		asc_assert_same( 'integer', $args['limit']['type'], 'arg type' );
		asc_assert_same( true, $redir['has_permission_callback'], 'permission callback' );
		asc_assert_same( false, $redir['public'], 'gated route not public' );

		$json = wp_json_encode( $res );
		asc_assert( false === strpos( $json, '"callback"' ), 'callback key leaked' );
		asc_assert( false === strpos( $json, '"permission_callback"' ), 'permission_callback key leaked' );
		asc_assert( false === strpos( $json, 'AI_Site_Connector_' ), 'class name leaked' );

		$sorted = array_column( $res['routes'], 'route' );
		$copy   = $sorted;
		sort( $copy, SORT_STRING );
		asc_assert_same( $copy, $sorted, 'routes sorted' );
	}
);

asc_it(
	'rest-routes: namespace filter and unusual handlers do not break output',
	function () {
		$server = rest_get_server();
		// Unusual shapes: string methods, non-array arg spec, no permission callback.
		$server->register_route(
			'asc-it/v1',
			'/asc-it/v1/odd',
			array(
				array(
					'methods'  => 'GET,POST',
					'callback' => '__return_null',
					'args'     => array( 'weird' => 'not-an-array' ),
				),
				'not-a-handler',
			)
		);
		$res = AI_Site_Connector_Diagnostics::rest_routes( array( 'namespace' => 'asc-it/v1' ) );
		// register_route() also adds the namespace index route /asc-it/v1.
		asc_assert_same( array( '/asc-it/v1', '/asc-it/v1/odd' ), array_column( $res['routes'], 'route' ), 'filtered routes' );
		asc_assert_same( array( 'asc-it/v1' ), $res['namespaces'], 'filtered namespaces' );
		$odd = $res['routes'][1];
		asc_assert_same( array( 'GET', 'POST' ), $odd['methods'], 'string methods parsed' );
		asc_assert_same( false, $odd['has_permission_callback'], 'missing permission callback reported' );
		asc_assert_same( true, $odd['public'], 'route without permission callback is public' );
		asc_assert_same( '', ( (array) $odd['args'] )['weird']['type'], 'non-array spec summarised' );
	}
);

// --- page builder -----------------------------------------------------------

asc_it(
	'page-builder: per-post evidence for elementor, beaver, blocks, none, unknown',
	function () {
		$plain     = asc_it_post( array( 'post_content' => 'Plain text.' ) );
		$elementor = asc_it_post();
		$beaver    = asc_it_post();
		$blocks    = asc_it_post( array( 'post_content' => "<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->" ) );
		$weird     = asc_it_post( array( 'post_content' => 'Plain text.' ) );
		update_post_meta( $elementor, '_elementor_data', '[{"elType":"section"}]' );
		update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
		update_post_meta( $beaver, '_fl_builder_enabled', '1' );
		update_post_meta( $weird, '_elementor_edit_mode', array( 'unexpected' => 'shape' ) );
		update_post_meta( $weird, '_some_unknown_builder', 'x' );
		$ids = array( $plain, $elementor, $beaver, $blocks, $weird, 999999999 );
		try {
			$res = AI_Site_Connector_Diagnostics::page_builder( array( 'post_ids' => $ids ) );
			$pp  = $res['per_post'];
			asc_assert_same( array(), $pp[ (string) $plain ]['evidence'], 'plain' );
			asc_assert_same( array( 'elementor' => true ), $pp[ (string) $elementor ]['evidence'], 'elementor' );
			asc_assert_same( array( 'beaver_builder' => true ), $pp[ (string) $beaver ]['evidence'], 'beaver' );
			asc_assert_same( array( 'block_editor' => true ), $pp[ (string) $blocks ]['evidence'], 'blocks' );
			asc_assert_same( array(), $pp[ (string) $weird ]['evidence'], 'unknown/malformed meta' );
			asc_assert_same( 'post_not_found', $pp['999999999']['error'], 'missing post' );
			asc_assert( isset( $res['site']['detected']['elementor'] ), 'site evidence' );
			asc_assert_same( false, $res['site']['detected']['elementor'], 'no elementor plugin active' );
		} finally {
			foreach ( array( $plain, $elementor, $beaver, $blocks, $weird ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'page-builder: no post_ids omits per_post; >100 ids rejected with 400',
	function () {
		$res = AI_Site_Connector_Diagnostics::page_builder();
		asc_assert( ! isset( $res['per_post'] ), 'per_post present without ids' );
		$too_many = AI_Site_Connector_Diagnostics::page_builder( array( 'post_ids' => range( 1, 101 ) ) );
		asc_assert( is_wp_error( $too_many ), 'service accepted 101 ids' );
		$rest = asc_it_rest( 'GET', '/diagnostics/page-builder', array( 'post_ids' => implode( ',', range( 1, 101 ) ) ) );
		asc_assert_same( 400, $rest->get_status(), 'REST status for 101 ids' );
	}
);

asc_it(
	'page-builder: author gets forbidden for a private post (service level)',
	function () {
		$author = asc_it_user( 'author' );
		$post   = asc_it_post( array( 'post_status' => 'private' ) );
		try {
			$res = asc_it_as_user( $author, function () use ( $post ) {
				return AI_Site_Connector_Diagnostics::page_builder( array( 'post_ids' => array( $post ) ) );
			} );
			asc_assert_same( 'forbidden', $res['per_post'][ (string) $post ]['error'], 'private post' );
		} finally {
			wp_delete_post( $post, true );
			asc_it_delete_user( $author );
		}
	}
);

// --- redirects ----------------------------------------------------------------

function asc_it_with_redirect_plugins( array $present, $fn ) {
	return asc_it_with_filter(
		'ai_site_connector_redirect_plugins',
		static function ( $candidates ) use ( $present ) {
			foreach ( $candidates as $k => $v ) {
				$candidates[ $k ] = in_array( $k, $present, true );
			}
			return $candidates;
		},
		$fn
	);
}

asc_it(
	'redirects: no plugin fallback',
	function () {
		$res = AI_Site_Connector_Diagnostics::redirects();
		asc_assert_same( 'none', $res['plugin_detected'], 'plugin' );
		asc_assert_same( array(), $res['redirects'], 'rows' );
		asc_assert_same( 0, $res['total'], 'total' );
	}
);

asc_it(
	'redirects: rank math table missing falls through to redirection',
	function () {
		global $wpdb;
		$table = $wpdb->prefix . 'redirection_items';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table} (id int unsigned NOT NULL AUTO_INCREMENT, url mediumtext, regex int unsigned NOT NULL DEFAULT 0, action_data mediumtext, action_code int, match_type varchar(20), status varchar(20) DEFAULT 'enabled', PRIMARY KEY (id))" );
		for ( $i = 1; $i <= 3; $i++ ) {
			$wpdb->insert( $table, array( 'url' => 3 === $i ? '^/old-(.*)$' : "/old-{$i}", 'regex' => 3 === $i ? 1 : 0, 'action_data' => "/new-{$i}", 'action_code' => 301, 'match_type' => 'url', 'status' => 3 === $i ? 'disabled' : 'enabled' ) );
		}
		try {
			$res = asc_it_with_redirect_plugins(
				array( 'rankmath', 'redirection' ),
				function () {
					return AI_Site_Connector_Diagnostics::redirects( array( 'limit' => 2, 'offset' => 1 ) );
				}
			);
			asc_assert_same( 'redirection', $res['plugin_detected'], 'plugin' );
			asc_assert_same( array( 'rankmath' ), $res['data_unavailable'], 'rank math unavailable' );
			asc_assert_same( 3, $res['total'], 'total' );
			asc_assert_same( 2, $res['count'], 'page size' );
			asc_assert_same( '/old-2', $res['redirects'][0]['source'], 'offset applied' );
			asc_assert_same( array( 'id', 'source', 'target', 'status_code', 'match_type', 'enabled', 'plugin', 'additional_sources' ), array_keys( $res['redirects'][0] ), 'row schema' );
			asc_assert_same( false, $res['redirects'][1]['enabled'], 'disabled flag' );
			asc_assert_same( 'regex', $res['redirects'][1]['match_type'], 'regex column honoured' );
			asc_assert_same( 'url', $res['redirects'][0]['match_type'], 'plain url match' );
			asc_assert_same( null, $res['next_offset'], 'last page next_offset' );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable
		}
	}
);

asc_it(
	'redirects: empty rank math table does not hide redirection data',
	function () {
		global $wpdb;
		$rm = $wpdb->prefix . 'rank_math_redirections';
		$rd = $wpdb->prefix . 'redirection_items';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$rm} (id bigint unsigned NOT NULL AUTO_INCREMENT, sources text, url_to text, header_code smallint, status varchar(25), PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE {$rd} (id int unsigned NOT NULL AUTO_INCREMENT, url mediumtext, regex int unsigned NOT NULL DEFAULT 0, action_data mediumtext, action_code int, match_type varchar(20), status varchar(20) DEFAULT 'enabled', PRIMARY KEY (id))" );
		$wpdb->insert( $rd, array( 'url' => '/x', 'action_data' => '/y', 'action_code' => 301, 'match_type' => 'url' ) );
		try {
			$res = asc_it_with_redirect_plugins(
				array( 'rankmath', 'redirection' ),
				function () {
					return AI_Site_Connector_Diagnostics::redirects();
				}
			);
			asc_assert_same( 'redirection', $res['plugin_detected'], 'plugin' );
			asc_assert_same( 1, $res['total'], 'total' );

			$only_rm = asc_it_with_redirect_plugins(
				array( 'rankmath' ),
				function () {
					return AI_Site_Connector_Diagnostics::redirects();
				}
			);
			asc_assert_same( 'rankmath', $only_rm['plugin_detected'], 'empty table still reported as detected' );
			asc_assert_same( 0, $only_rm['total'], 'empty total' );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$rm}" );
			$wpdb->query( "DROP TABLE IF EXISTS {$rd}" );
			// phpcs:enable
		}
	}
);

asc_it(
	'redirects: rank math multi-source rows expand',
	function () {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table} (id bigint unsigned NOT NULL AUTO_INCREMENT, sources text, url_to text, header_code smallint, status varchar(25), PRIMARY KEY (id))" );
		$wpdb->insert(
			$table,
			array(
				'sources'     => maybe_serialize( array( array( 'pattern' => 'a', 'comparison' => 'exact' ), array( 'pattern' => 'b', 'comparison' => 'regex' ) ) ),
				'url_to'      => 'https://example.test/c',
				'header_code' => 301,
				'status'      => 'active',
			)
		);
		$wpdb->insert( $table, array( 'sources' => 'garbage', 'url_to' => '/d', 'header_code' => 302, 'status' => 'inactive' ) );
		try {
			$res = asc_it_with_redirect_plugins(
				array( 'rankmath' ),
				function () {
					return AI_Site_Connector_Diagnostics::redirects();
				}
			);
			asc_assert_same( 'rankmath', $res['plugin_detected'], 'plugin' );
			asc_assert_same( 2, $res['total'], 'total redirects' );
			asc_assert_same( 2, $res['count'], 'one row per stored redirect' );
			asc_assert_same( 'a', $res['redirects'][0]['source'], 'primary source' );
			asc_assert_same( array( array( 'source' => 'b', 'match_type' => 'regex' ) ), $res['redirects'][0]['additional_sources'], 'additional sources' );
			asc_assert_same( '', $res['redirects'][1]['source'], 'malformed sources handled' );
			asc_assert_same( false, $res['redirects'][1]['enabled'], 'inactive' );

			$page = asc_it_with_redirect_plugins(
				array( 'rankmath' ),
				function () {
					return AI_Site_Connector_Diagnostics::redirects( array( 'limit' => 1 ) );
				}
			);
			asc_assert_same( 1, $page['next_offset'], 'next_offset counts stored redirects' );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable
		}
	}
);

asc_it(
	'redirects: REST rejects out-of-range limit',
	function () {
		asc_assert_same( 400, asc_it_rest( 'GET', '/diagnostics/redirects', array( 'limit' => 0 ) )->get_status(), 'limit=0' );
		asc_assert_same( 400, asc_it_rest( 'GET', '/diagnostics/redirects', array( 'limit' => 5000 ) )->get_status(), 'limit=5000' );
		asc_assert_same( 400, asc_it_rest( 'GET', '/diagnostics/redirects', array( 'offset' => -1 ) )->get_status(), 'offset=-1' );
	}
);

// --- MCP exposure ---------------------------------------------------------------

asc_it(
	'mcp: diagnostics tools listed and callable by admin',
	function () {
		$list  = asc_it_mcp( 'tools/list' );
		$names = array_column( $list['result']['tools'], 'name' );
		foreach ( array( 'wp_self_test', 'wp_rest_routes', 'wp_page_builder', 'wp_redirects' ) as $tool ) {
			asc_assert( in_array( $tool, $names, true ), "tools/list missing {$tool}" );
		}
		$call = asc_it_mcp_call( 'wp_redirects', array( 'limit' => 10 ) );
		asc_assert_same( false, $call['is_error'], 'isError' );
		asc_assert_same( 'none', $call['data']['plugin_detected'], 'payload' );
		$routes = asc_it_mcp_call( 'wp_rest_routes', array( 'namespace' => 'ai-site-connector/v1' ) );
		asc_assert_same( array( 'ai-site-connector/v1' ), $routes['data']['namespaces'], 'namespace arg forwarded' );
	}
);

asc_it(
	'mcp: editor gets isError=true with 403 payload',
	function () {
		$editor = asc_it_user( 'editor' );
		try {
			$call = asc_it_as_user( $editor, function () {
				return asc_it_mcp_call( 'wp_self_test' );
			} );
			asc_assert_same( true, $call['is_error'], 'isError' );
			asc_assert_same( 403, $call['data']['status'], 'status' );
		} finally {
			asc_it_delete_user( $editor );
		}
	}
);

asc_it(
	'mcp: invalid page-builder input surfaces as tool error',
	function () {
		$call = asc_it_mcp_call( 'wp_page_builder', array( 'post_ids' => range( 1, 101 ) ) );
		asc_assert_same( true, $call['is_error'], 'isError' );
		asc_assert_same( 400, $call['data']['status'], 'status' );
	}
);
