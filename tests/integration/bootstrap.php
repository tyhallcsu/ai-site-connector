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
	return rest_do_request( $req );
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
