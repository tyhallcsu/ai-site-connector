<?php
/**
 * Generated MCP client snippets must target the plugin's MCP route (#119).
 *
 * @package AI_Site_Connector_Tests
 */

/**
 * The mcp-remote URL from a generated Claude Desktop or Cursor snippet.
 *
 * @param array  $pack Connection pack.
 * @param string $id   Format id.
 * @return string
 */
function asc_it_snippet_mcp_url( array $pack, $id ) {
	foreach ( AI_Site_Connector_Connection_Formats::all( $pack ) as $format ) {
		if ( $id === $format['id'] ) {
			$config = json_decode( $format['code'], true );
			$server = reset( $config['mcpServers'] );
			asc_assert_same( 'mcp-remote', $server['args'][1], "{$id} runs mcp-remote" );
			return (string) $server['args'][2];
		}
	}
	asc_assert( false, "format {$id} not generated" );
	return '';
}

asc_it(
	'connection formats: generated MCP snippets reach the registered MCP route and initialize (#119)',
	function () {
		$build = new ReflectionMethod( 'AI_Site_Connector_Admin_Page', 'build_connection_pack' );
		if ( PHP_VERSION_ID < 80100 ) {
			$build->setAccessible( true ); // Needed before PHP 8.1; deprecated no-op later.
		}
		$pack = $build->invoke(
			null,
			get_current_user_id(),
			array(
				'password' => 'synthetic-not-a-real-password',
				'uuid'     => 'asc-it-uuid',
				'name'     => 'asc-it',
			)
		);
		asc_assert_same( rest_url( AI_SITE_CONNECTOR_REST_NAMESPACE . '/mcp' ), $pack['mcp_endpoint'], 'pack mcp_endpoint' );
		asc_assert_same( trailingslashit( rest_url() ), $pack['rest_api_base'], 'REST root is unchanged for core-route snippets' );

		foreach ( array( 'claude_desktop_mcp', 'cursor_mcp' ) as $id ) {
			$url = asc_it_snippet_mcp_url( $pack, $id );
			asc_assert_same( $pack['mcp_endpoint'], $url, "{$id} URL" );

			// Resolve the URL back to a REST route the way WordPress would.
			$parts = wp_parse_url( $url );
			$query = array();
			if ( isset( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
			}
			if ( ! empty( $query['rest_route'] ) ) {
				$route = (string) $query['rest_route'];
			} else {
				$prefix = untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) );
				$route  = '/' . ltrim( substr( (string) $parts['path'], strlen( $prefix ) ), '/' );
			}
			asc_assert( array_key_exists( $route, rest_get_server()->get_routes() ), "{$id} route {$route} is not registered" );

			$req = new WP_REST_Request( 'POST', $route );
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body(
				wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'initialize',
						'params'  => array(
							'protocolVersion' => '2024-11-05',
							'capabilities'    => new stdClass(),
							'clientInfo'      => array(
								'name'    => 'asc-it',
								'version' => '1',
							),
						),
					)
				)
			);
			$res  = rest_do_request( $req );
			$data = (array) $res->get_data();
			asc_assert_same( 200, $res->get_status(), "{$id} initialize status" );
			asc_assert( isset( $data['result']['protocolVersion'] ), "{$id} initialize returned no protocolVersion: " . wp_json_encode( $data ) );
		}
	}
);

asc_it(
	'connection formats: packs without mcp_endpoint still get the namespaced route (#119)',
	function () {
		$cases = array(
			'https://example.com/wp-json/'      => 'https://example.com/wp-json/ai-site-connector/v1/mcp',
			'https://example.com/blog/wp-json/' => 'https://example.com/blog/wp-json/ai-site-connector/v1/mcp',
			'https://example.com/?rest_route=/' => 'https://example.com/?rest_route=/ai-site-connector/v1/mcp',
		);
		foreach ( $cases as $rest_base => $expected ) {
			$pack = array(
				'username'             => 'asc-it',
				'application_password' => 'synthetic',
				'rest_api_base'        => $rest_base,
				'site_host'            => 'example.com',
			);
			asc_assert_same( $expected, asc_it_snippet_mcp_url( $pack, 'claude_desktop_mcp' ), "Claude Desktop URL for {$rest_base}" );
			asc_assert_same( $expected, asc_it_snippet_mcp_url( $pack, 'cursor_mcp' ), "Cursor URL for {$rest_base}" );
		}
	}
);
