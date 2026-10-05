<?php
/**
 * Site-wide enable/disable switch (#75 `disable` / `enable`).
 *
 * @package AI_Site_Connector_Tests
 */

function asc_it_while_disabled( $fn ) {
	AI_Site_Connector_Permissions::set_disabled( true, 'test' );
	try {
		return $fn();
	} finally {
		AI_Site_Connector_Permissions::set_disabled( false, 'test' );
	}
}

asc_it(
	'switch: disabled blocks every plugin route except health, including MCP',
	function () {
		asc_it_while_disabled(
			function () {
				foreach ( array( '/diagnostics/self-test', '/export/content-inventory', '/site-info', '/posts', '/tools', '/me/capabilities', '/export/bundle' ) as $route ) {
					$res = asc_it_rest( 'GET', $route );
					asc_assert_same( 503, $res->get_status(), "{$route} while disabled" );
					// Case variants route to the same handler in WordPress.
					$res = rest_do_request( new WP_REST_Request( 'GET', '/' . strtoupper( AI_SITE_CONNECTOR_REST_NAMESPACE ) . strtoupper( $route ) ) );
					asc_assert_same( 503, $res->get_status(), "{$route} while disabled" );
					asc_assert_same( 'ai_site_connector_disabled', $res->get_data()['code'], "{$route} error code" );
				}
				$health = asc_it_rest( 'GET', '/health' );
				asc_assert_same( 200, $health->get_status(), 'health stays reachable' );
				asc_assert_same( false, $health->get_data()['enabled'], 'health reports disabled' );

				$mcp = asc_it_mcp( 'tools/list' );
				asc_assert( isset( $mcp['code'] ) && 'ai_site_connector_disabled' === $mcp['code'], 'MCP endpoint not blocked: ' . wp_json_encode( $mcp ) );

				$req = new WP_REST_Request( 'POST', '/AI-Site-Connector/v1/mcp' );
				$req->set_header( 'content-type', 'application/json' );
				$req->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );
				asc_assert_same( 503, rest_do_request( $req )->get_status(), 'mixed-case MCP route while disabled' );

				asc_assert_same( false, AI_Site_Connector_Permissions::can( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS ), 'can() denies' );
				$allow = static function () {
					return true;
				};
				add_filter( 'ai_site_connector_can_execute_tool', $allow );
				$filtered = AI_Site_Connector_Permissions::can( AI_Site_Connector_Permissions::TOOL_VIEW_DIAGNOSTICS );
				remove_filter( 'ai_site_connector_can_execute_tool', $allow );
				asc_assert_same( false, $filtered, 'filter overrode the disabled switch' );
				$deny = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_READ_CONTENT );
				asc_assert( is_wp_error( $deny ), 'require_permission denies' );

				// Core WordPress routes are not this plugin's to block.
				asc_assert_same( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) )->get_status(), 'core REST unaffected' );
			}
		);
		asc_assert_same( 200, asc_it_rest( 'GET', '/diagnostics/self-test' )->get_status(), 'enabled again' );
		asc_assert_same( true, asc_it_rest( 'GET', '/health' )->get_data()['enabled'], 'health reports enabled' );
	}
);

asc_it(
	'switch: state changes are audited and idempotent',
	function () {
		global $wpdb;
		$table  = AI_Site_Connector_Audit_Log::table_name();
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE action IN ('connector_disabled','connector_enabled')" ); // phpcs:ignore WordPress.DB
		try {
			asc_assert_same( true, AI_Site_Connector_Permissions::set_disabled( true, 'test' ), 'first disable changes state' );
			asc_assert_same( false, AI_Site_Connector_Permissions::set_disabled( true, 'test' ), 'second disable is a no-op' );
		} finally {
			$enabled = AI_Site_Connector_Permissions::set_disabled( false, 'test' );
		}
		asc_assert_same( true, $enabled, 'enable changes state' );
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE action IN ('connector_disabled','connector_enabled')" ); // phpcs:ignore WordPress.DB
		asc_assert_same( $before + 2, $after, 'two audit rows (no row for the no-op)' );
	}
);
