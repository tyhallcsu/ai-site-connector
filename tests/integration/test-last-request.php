<?php
/**
 * "Last successful plugin API request" counts only real successes (#121).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'last request: denied, missing and failed requests never refresh the success timestamp (#121)',
	function () {
		$key   = AI_Site_Connector_REST_Controller::LAST_REQUEST_OPTION;
		$prev  = get_option( $key, null );
		$stamp = static function () use ( $key ) {
			return (string) get_option( $key, '' );
		};
		$subscriber = asc_it_user( 'subscriber' );
		try {
			delete_option( $key );
			$denied = asc_it_as_user(
				$subscriber,
				function () {
					return asc_it_rest( 'GET', '/plugins' );
				}
			);
			asc_assert( $denied->get_status() >= 400, 'subscriber was allowed to list plugins' );
			asc_assert_same( '', $stamp(), 'denied request stamped' );

			$missing = rest_do_request( new WP_REST_Request( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/no-such-route' ) );
			asc_assert_same( 404, $missing->get_status(), 'unregistered route status' );
			asc_assert_same( '', $stamp(), 'unregistered route stamped' );

			$rpc_error = asc_it_mcp( 'no/such-method' );
			asc_assert( isset( $rpc_error['error'] ), 'unknown MCP method did not error' );
			asc_assert_same( '', $stamp(), 'MCP JSON-RPC error stamped' );

			$tool_error = asc_it_mcp_call( 'wp_update_content', array( 'post_id' => 0, 'changes' => array( 'title' => 'x' ) ) );
			asc_assert( $tool_error['is_error'], 'invalid content update did not fail' );
			asc_assert_same( '', $stamp(), 'MCP tool failure stamped' );

			$ok = asc_it_rest( 'GET', '/health' );
			asc_assert_same( 200, $ok->get_status(), 'health status' );
			asc_assert( '' !== $stamp(), 'successful request was not stamped' );

			delete_option( $key );
			asc_it_mcp( 'initialize', array( 'protocolVersion' => '2024-11-05', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'asc-it', 'version' => '1' ) ) );
			asc_assert( '' !== $stamp(), 'successful MCP initialize was not stamped' );
		} finally {
			asc_it_delete_user( $subscriber );
			if ( null === $prev ) {
				delete_option( $key );
			} else {
				update_option( $key, $prev, false );
			}
		}
	}
);
