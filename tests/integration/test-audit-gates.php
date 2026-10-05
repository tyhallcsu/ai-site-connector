<?php
/**
 * Cross-cutting gates found by the v0.12.0 release audit.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'audit: MCP wp_create_post / wp_update_post require write_content and honour read-only mode',
	function () {
		$post = asc_it_post( array( 'post_title' => 'MCP gate' ) );
		try {
			$before = asc_it_db_fingerprint();
			$upd    = asc_it_mcp_call( 'wp_update_post', array( 'id' => $post, 'title' => 'Changed via MCP' ) );
			asc_assert_same( true, $upd['is_error'], 'update without write_content allowed' );
			asc_assert_same( 'whitelist_off', $upd['data']['reason'], 'denial reason' );
			$new = asc_it_mcp_call( 'wp_create_post', array( 'title' => 'ASC IT MCP create', 'content' => 'x' ) );
			asc_assert_same( true, $new['is_error'], 'create without write_content allowed' );
			asc_assert_same( $before, asc_it_db_fingerprint(), 'denied MCP write mutated the DB' );

			asc_it_with_permissions(
				array( 'write_content' => true ),
				function () use ( $post ) {
					update_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION, 1 );
					try {
						$ro = asc_it_mcp_call( 'wp_update_post', array( 'id' => $post, 'title' => 'Read-only bypass' ) );
						asc_assert_same( true, $ro['is_error'], 'read-only mode bypassed via MCP' );
						asc_assert_same( 'read_only_mode', $ro['data']['reason'], 'read-only reason' );
					} finally {
						delete_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION );
					}
					$ok = asc_it_mcp_call( 'wp_update_post', array( 'id' => $post, 'title' => 'Allowed via MCP' ) );
					asc_assert_same( false, $ok['is_error'], 'permitted MCP update failed' );
				}
			);
			asc_assert_same( 'Allowed via MCP', get_post_field( 'post_title', $post ), 'permitted update not applied' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'audit: discovery reports disabled; snapshots route needs read_content',
	function () {
		AI_Site_Connector_Permissions::set_disabled( true, 'test' );
		try {
			$payload = AI_Site_Connector_Discovery::build_payload();
			asc_assert_same( 'disabled', $payload['status'], 'discovery still says active' );
		} finally {
			AI_Site_Connector_Permissions::set_disabled( false, 'test' );
		}
		asc_assert_same( 'active', AI_Site_Connector_Discovery::build_payload()['status'], 'discovery after enable' );

		$post = asc_it_post();
		try {
			asc_it_with_permissions(
				array( 'read_content' => false ),
				function () use ( $post ) {
					asc_assert_same( 403, asc_it_rest( 'GET', '/content/snapshots/' . $post )->get_status(), 'snapshots without read_content' );
				}
			);
			asc_assert_same( 200, asc_it_rest( 'GET', '/content/snapshots/' . $post )->get_status(), 'snapshots with read_content' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);
