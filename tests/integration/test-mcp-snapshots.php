<?php
/**
 * MCP snapshot discovery (#123): a fresh session can find a post's
 * snapshots and dry-run a rollback, using the REST route's permissions;
 * stored values are never returned.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'mcp snapshots: discover after an update, dry-run rollback by id, no values leaked (#123)',
	function () {
		$post  = asc_it_post( array( 'post_title' => 'Snapshot original title' ) );
		$empty = asc_it_post( array( 'post_title' => 'Never updated' ) );
		$sub   = asc_it_user( 'subscriber' );
		try {
			$none = asc_it_mcp_call( 'wp_list_content_snapshots', array( 'post_id' => $empty ) );
			asc_assert( ! $none['is_error'], 'listing a post without snapshots failed' );
			asc_assert_same( array(), $none['data']['snapshots'], 'snapshots for an untouched post' );

			$updated = asc_it_with_permissions(
				array( 'write_content' => true ),
				function () use ( $post ) {
					return asc_it_mcp_call( 'wp_update_content', array( 'post_id' => $post, 'changes' => array( 'title' => 'Snapshot changed title' ), 'dry_run' => false ) );
				}
			);
			asc_assert( ! $updated['is_error'], 'update failed: ' . wp_json_encode( $updated['data'] ) );

			// A "fresh session" only knows the post ID.
			$list = asc_it_mcp_call( 'wp_list_content_snapshots', array( 'post_id' => $post ) );
			asc_assert( ! $list['is_error'], 'listing failed: ' . wp_json_encode( $list['data'] ) );
			$snap = $list['data']['snapshots'][0];
			asc_assert_same( $updated['data']['snapshot_id'], $snap['snapshot_id'], 'discovered snapshot id' );
			asc_assert_same( 'applied', $snap['state'], 'snapshot state after update' );
			asc_assert( in_array( 'title', $snap['fields'], true ), 'touched fields not listed' );
			$raw = wp_json_encode( $list['data'] );
			asc_assert( false === strpos( $raw, 'Snapshot original title' ) && false === strpos( $raw, 'Snapshot changed title' ), 'snapshot listing leaked stored values' );

			$dry = asc_it_mcp_call( 'wp_rollback_content', array( 'post_id' => $post, 'snapshot_id' => $snap['snapshot_id'] ) );
			asc_assert( ! $dry['is_error'] && true === $dry['data']['dry_run'], 'dry-run rollback with the discovered id failed' );
			asc_assert_same( 'Snapshot changed title', get_post_field( 'post_title', $post ), 'dry-run rollback wrote' );

			asc_it_with_permissions(
				array( 'write_content' => true ),
				function () use ( $post, $snap ) {
					return asc_it_mcp_call( 'wp_rollback_content', array( 'post_id' => $post, 'snapshot_id' => $snap['snapshot_id'], 'dry_run' => false ) );
				}
			);
			$after = asc_it_mcp_call( 'wp_list_content_snapshots', array( 'post_id' => $post ) );
			asc_assert_same( 'rolled_back', $after['data']['snapshots'][0]['state'], 'snapshot state after rollback' );

			$missing = asc_it_mcp_call( 'wp_list_content_snapshots', array( 'post_id' => 987654321 ) );
			asc_assert( $missing['is_error'] && 404 === $missing['data']['status'], 'missing post not reported as 404' );

			$denied = asc_it_as_user(
				$sub,
				function () use ( $post ) {
					return asc_it_mcp( 'tools/call', array( 'name' => 'wp_list_content_snapshots', 'arguments' => (object) array( 'post_id' => $post ) ) );
				}
			);
			// Refused at the REST layer ({code, message}), as a JSON-RPC error, or as a tool error.
			$denied_ok = isset( $denied['code'] ) || isset( $denied['error'] ) || ! empty( $denied['result']['isError'] );
			asc_assert( $denied_ok, 'a subscriber could list snapshots: ' . wp_json_encode( $denied ) );

			update_option( AI_Site_Connector_Permissions::DISABLED_OPTION, 1, false );
			try {
				$off = asc_it_mcp( 'tools/call', array( 'name' => 'wp_list_content_snapshots', 'arguments' => (object) array( 'post_id' => $post ) ) );
				asc_assert( isset( $off['code'] ) || isset( $off['error'] ), 'listing worked while the connector was disabled: ' . wp_json_encode( $off ) );
			} finally {
				delete_option( AI_Site_Connector_Permissions::DISABLED_OPTION );
			}
		} finally {
			wp_delete_post( $post, true );
			wp_delete_post( $empty, true );
			asc_it_delete_user( $sub );
		}
	}
);
