<?php
/**
 * MCP catalog contract and write-tool guidance (#109). The tool set is
 * frozen here so removing or renaming a tool is a deliberate, visible
 * change; agents are pointed at the safe update tool without breaking the
 * legacy ones.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'mcp catalog: tool names are unchanged and the legacy write tools point agents to wp_update_content (#109)',
	function () {
		$res   = asc_it_mcp( 'tools/list' );
		$tools = array();
		foreach ( $res['result']['tools'] as $tool ) {
			$tools[ $tool['name'] ] = $tool;
		}
		$names = array_keys( $tools );
		sort( $names );
		$expected = array(
			'wp_broken_links', 'wp_content_inventory', 'wp_create_post', 'wp_export_bundle', 'wp_get_post',
			'wp_health', 'wp_list_content_snapshots', 'wp_list_pages', 'wp_list_plugins', 'wp_list_posts', 'wp_list_themes',
			'wp_media_audit', 'wp_media_duplicates', 'wp_page_builder', 'wp_redirects', 'wp_rest_routes',
			'wp_rollback_content', 'wp_self_test', 'wp_site_info', 'wp_update_content', 'wp_update_post',
		);
		asc_assert_same( $expected, $names, 'MCP tool names' );

		$update = $tools['wp_update_post']['description'];
		asc_assert( false !== strpos( $update, 'wp_update_content' ), 'wp_update_post does not name wp_update_content' );
		asc_assert( false !== strpos( $update, 'no rollback' ), 'wp_update_post does not say it cannot be rolled back' );
		asc_assert( false !== strpos( $tools['wp_create_post']['description'], 'wp_update_content' ), 'wp_create_post does not name wp_update_content' );

		$pack = array(
			'username'             => 'asc-it',
			'application_password' => 'synthetic',
			'rest_api_base'        => 'https://example.com/wp-json/',
		);
		$instructions = '';
		foreach ( AI_Site_Connector_Connection_Formats::all( $pack ) as $format ) {
			if ( 'agent_instructions' === $format['id'] ) {
				$instructions = $format['code'];
			}
		}
		asc_assert( false !== strpos( $instructions, 'wp_update_content' ), 'agent instructions do not name wp_update_content' );
		asc_assert( false !== strpos( $instructions, 'https://example.com/wp-json/ai-site-connector/v1/content/update' ), 'agent instructions lack the REST route' );
	}
);
