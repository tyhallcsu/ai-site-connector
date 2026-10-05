<?php
/**
 * Minimal assertion harness for the in-WordPress integration suite.
 *
 * Deliberately tiny: no PHPUnit/WP test-suite install, so it runs in the
 * same disposable site the runtime smoke test already builds.
 *
 * @package AI_Site_Connector_Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput,WordPress.PHP.DevelopmentFunctions

class ASC_IT_Failure extends Exception {}

$GLOBALS['asc_it_cases'] = array();

/**
 * Register a test case.
 *
 * @param string   $name Case name, shown in output.
 * @param callable $fn   Throws ASC_IT_Failure (via asc_assert*) on failure.
 */
function asc_it( $name, $fn ) {
	$GLOBALS['asc_it_cases'][] = array( $name, $fn );
}

function asc_assert( $cond, $message ) {
	if ( ! $cond ) {
		throw new ASC_IT_Failure( $message );
	}
}

function asc_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new ASC_IT_Failure( $message . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

/**
 * Snapshot everything a read-only/dry-run tool could plausibly mutate:
 * posts, postmeta, term relationships, options touched by the plugin.
 * Compared before/after to prove zero mutation.
 */
function asc_it_db_fingerprint() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$parts = array(
		$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(post_modified_gmt), ''), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', ID, post_title, post_name, post_status, post_content, post_excerpt, post_parent))), 0)) FROM {$wpdb->posts}" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', post_id, meta_key, meta_value))), 0)) FROM {$wpdb->postmeta}" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', object_id, term_taxonomy_id))), 0)) FROM {$wpdb->term_relationships}" ),
		$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', option_name, option_value))), 0)) FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name NOT IN ('cron', 'ai_site_connector_last_request_at')" ),
	);
	// phpcs:enable
	return implode( '#', $parts );
}

/**
 * Dispatch a REST request in-process as the current user.
 *
 * @return WP_REST_Response
 */
function asc_it_rest( $method, $route, $params = array() ) {
	$req = new WP_REST_Request( $method, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $params );
	} else {
		$req->set_body_params( $params );
	}
	return asc_it_response( rest_do_request( $req ) );
}

/**
 * WordPress < 5.7 returns a rest_pre_dispatch WP_Error from dispatch()
 * unconverted; normalise to a WP_REST_Response like newer versions.
 *
 * @return WP_REST_Response
 */
function asc_it_response( $res ) {
	if ( is_wp_error( $res ) ) {
		$data   = $res->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		return new WP_REST_Response(
			array(
				'code'    => $res->get_error_code(),
				'message' => $res->get_error_message(),
				'data'    => $data,
			),
			$status
		);
	}
	return $res;
}

/**
 * Run a callable as a specific user, restoring the previous user afterwards.
 */
function asc_it_as_user( $user_id, $fn ) {
	$prev = get_current_user_id();
	wp_set_current_user( $user_id );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $prev );
	}
}

/**
 * Create a user fixture with a role; returns the user ID.
 */
function asc_it_user( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'asc_it_' . $role . '_' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'asc-it-' . $role . '-' . wp_generate_password( 6, false ) . '@example.test',
			'role'       => $role,
		)
	);
	asc_assert( is_int( $id ), 'could not create ' . $role . ' fixture' );
	return $id;
}

function asc_it_delete_user( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $id );
}

/**
 * Create a post fixture; returns the post ID.
 */
function asc_it_post( $args = array() ) {
	$id = wp_insert_post(
		array_merge(
			array(
				'post_title'   => 'ASC IT fixture',
				'post_content' => 'Fixture body.',
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			$args
		),
		true
	);
	asc_assert( is_int( $id ) && $id > 0, 'could not create post fixture' );
	return $id;
}

/**
 * Run $fn with a filter attached, always detaching it afterwards.
 */
function asc_it_with_filter( $hook, $callback, $fn, $priority = 10, $accepted_args = 1 ) {
	add_filter( $hook, $callback, $priority, $accepted_args );
	try {
		return $fn();
	} finally {
		remove_filter( $hook, $callback, $priority );
	}
}

/**
 * Run $fn with tool permissions overridden, restoring the stored option.
 */
function asc_it_with_permissions( array $overrides, $fn ) {
	$key  = AI_Site_Connector_Permissions::OPTION_KEY;
	$prev = get_option( $key, null );
	update_option( $key, array_merge( (array) $prev, $overrides ) );
	try {
		return $fn();
	} finally {
		if ( null === $prev ) {
			delete_option( $key );
		} else {
			update_option( $key, $prev );
		}
	}
}

/**
 * Send a JSON-RPC message to the MCP endpoint in-process.
 *
 * @return array Decoded JSON-RPC response.
 */
function asc_it_mcp( $method, $params = array() ) {
	$req = new WP_REST_Request( 'POST', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp' );
	$req->set_header( 'content-type', 'application/json' );
	$req->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => (object) $params,
			)
		)
	);
	return (array) asc_it_response( rest_do_request( $req ) )->get_data();
}

/**
 * Decode the JSON text payload of an MCP tools/call result.
 *
 * @return array { is_error: bool, data: mixed }
 */
function asc_it_mcp_call( $tool, $arguments = array() ) {
	$res = asc_it_mcp(
		'tools/call',
		array(
			'name'      => $tool,
			'arguments' => (object) $arguments,
		)
	);
	asc_assert( isset( $res['result']['content'][0]['text'] ), 'MCP tools/call returned no content: ' . wp_json_encode( $res ) );
	return array(
		'is_error' => ! empty( $res['result']['isError'] ),
		'data'     => json_decode( $res['result']['content'][0]['text'], true ),
	);
}

function asc_it_run() {
	$filter = (string) getenv( 'ASC_IT_FILTER' );
	$pass   = 0;
	$fail   = 0;
	foreach ( $GLOBALS['asc_it_cases'] as $case ) {
		list( $name, $fn ) = $case;
		if ( '' !== $filter && false === strpos( $name, $filter ) ) {
			continue;
		}
		try {
			$fn();
			++$pass;
			fwrite( STDOUT, "  ok   {$name}\n" );
		} catch ( Throwable $e ) {
			++$fail;
			fwrite( STDOUT, "  FAIL {$name}: " . $e->getMessage() . "\n" );
			if ( ! ( $e instanceof ASC_IT_Failure ) ) {
				fwrite( STDOUT, '       at ' . $e->getFile() . ':' . $e->getLine() . "\n" );
			}
		}
	}
	fwrite( STDOUT, sprintf( "integration: %d passed, %d failed\n", $pass, $fail ) );
	if ( 0 === $pass + $fail ) {
		fwrite( STDERR, "integration: no cases executed\n" );
		return 1;
	}
	return $fail > 0 ? 1 : 0;
}
