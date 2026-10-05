<?php
/**
 * Export bundle (#73) and deterministic manifests (#74).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'bundle: all eight manifests present, valid JSON, deterministic, no timestamps',
	function () {
		$post = asc_it_post();
		try {
			$a = AI_Site_Connector_Export_Bundle::build();
			$b = AI_Site_Connector_Export_Bundle::build();
			asc_assert_same( AI_Site_Connector_Export_Bundle::FILES, array_keys( $a['files'] ), 'file set and order' );
			foreach ( AI_Site_Connector_Export_Bundle::FILES as $file ) {
				asc_assert_same( true, $a['index'][ $file ]['ok'], "{$file} ok" );
				$enc = AI_Site_Connector_Export_Bundle::encode( $a['files'][ $file ] );
				asc_assert( is_array( json_decode( $enc, true ) ), "{$file} is valid JSON" );
				asc_assert_same( $enc, AI_Site_Connector_Export_Bundle::encode( $b['files'][ $file ] ), "{$file} differs between runs" );
				asc_assert_same( hash( 'sha256', $enc ), $a['index'][ $file ]['sha256'], "{$file} index hash" );
				asc_assert( false === strpos( $enc, '"generated_at"' ), "{$file} contains a timestamp" );
				asc_assert( "\n" === substr( $enc, -1 ), "{$file} trailing newline" );
			}
			$idx = AI_Site_Connector_Export_Bundle::encode( AI_Site_Connector_Export_Bundle::index_document( $a ) );
			asc_assert_same( $idx, AI_Site_Connector_Export_Bundle::encode( AI_Site_Connector_Export_Bundle::index_document( $b ) ), 'index deterministic' );
			asc_assert( false === strpos( $idx, (string) gmdate( 'Y-m-d' ) ), 'index carries a timestamp' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'bundle: empty sections keep their shape; limits respected and reported',
	function () {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = asc_it_post( array( 'post_title' => "Bundle {$i}" ) );
		}
		try {
			$res = AI_Site_Connector_Export_Bundle::build( array( 'max_items' => 2 ) );
			$inv = $res['files']['site-inventory.json'];
			asc_assert_same( 2, count( $inv['items'] ), 'inventory capped' );
			asc_assert_same( true, $inv['truncated'], 'inventory truncated flag' );
			asc_assert_same( true, $res['index']['site-inventory.json']['truncated'], 'index truncated flag' );
			asc_assert( $inv['total'] >= 5, 'total still reports the full count' );

			$redirects = AI_Site_Connector_Export_Bundle::encode( $res['files']['redirects.json'] );
			asc_assert( false !== strpos( $redirects, '"redirects": []' ), 'empty redirects list stays a JSON array' );
			$dups = AI_Site_Connector_Export_Bundle::encode( $res['files']['duplicate-media.json'] );
			asc_assert( false !== strpos( $dups, '"by_hash": []' ), 'empty duplicate list stays a JSON array' );

			foreach ( array( array( 'max_items' => 0 ), array( 'max_items' => 5001 ), array( 'sections' => array( 'nope.json' ) ) ) as $bad ) {
				asc_assert( is_wp_error( AI_Site_Connector_Export_Bundle::build( $bad ) ), 'accepted ' . wp_json_encode( $bad ) );
			}
			$subset = AI_Site_Connector_Export_Bundle::build( array( 'sections' => array( 'rest-routes.json', 'redirects.json' ) ) );
			asc_assert_same( array( 'redirects.json', 'rest-routes.json' ), array_keys( $subset['files'] ), 'section subset' );
		} finally {
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'bundle: a failing section is reported without killing the bundle',
	function () {
		$boom = static function () {
			throw new RuntimeException( 'simulated SEO failure' );
		};
		$res = asc_it_with_filter(
			'ai_site_connector_seo_plugin',
			$boom,
			function () {
				return AI_Site_Connector_Export_Bundle::build();
			}
		);
		asc_assert_same( false, $res['index']['site-inventory.json']['ok'], 'inventory failed' );
		asc_assert( false !== strpos( $res['index']['site-inventory.json']['error'], 'simulated SEO failure' ), 'error message kept' );
		asc_assert( ! isset( $res['files']['site-inventory.json'] ), 'failed file omitted' );
		asc_assert_same( true, $res['index']['rest-routes.json']['ok'], 'other sections still built' );
		asc_assert_same( true, $res['index']['redirects.json']['ok'], 'redirects still built' );
	}
);

asc_it(
	'bundle: no secrets, no server paths, no outbound HTTP, read-only',
	function () {
		$http = 0;
		$spy  = static function ( $pre ) use ( &$http ) {
			++$http;
			return $pre;
		};
		$before = asc_it_db_fingerprint();
		$res    = asc_it_with_filter(
			'pre_http_request',
			$spy,
			function () {
				return AI_Site_Connector_Export_Bundle::build();
			}
		);
		asc_assert_same( 0, $http, 'bundle made HTTP requests' );
		asc_assert_same( $before, asc_it_db_fingerprint(), 'bundle mutated the DB' );
		$json    = wp_json_encode( array( $res['files'], AI_Site_Connector_Export_Bundle::index_document( $res ) ) );
		$secrets = array( get_option( 'admin_email' ), untrailingslashit( ABSPATH ), WP_CONTENT_DIR );
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'DB_PASSWORD' ) as $const ) {
			if ( defined( $const ) && strlen( (string) constant( $const ) ) >= 8 ) {
				$secrets[] = (string) constant( $const );
			}
		}
		foreach ( $secrets as $secret ) {
			asc_assert( '' === $secret || false === strpos( $json, $secret ), 'bundle leaked a secret or server path' );
		}
		// Route argument *names* such as "password" (wp/v2/users) appear in
		// rest-routes.json with schema objects as values; a credential would
		// be a string value.
		asc_assert( ! preg_match( '/"(password|application_password|secret|token|api_key)"\s*:\s*"/i', $json ), 'credential-like key with a string value present' );
		asc_assert( false !== strpos( $json, '"password":{' ), 'sanity: route arg names are present as schema objects' );
	}
);

asc_it(
	'bundle: REST + MCP access control and payload',
	function () {
		$editor = asc_it_user( 'editor' );
		try {
			$ok = asc_it_rest( 'GET', '/export/bundle', array( 'sections' => 'rest-routes.json,mcp-self-test.json' ) );
			asc_assert_same( 200, $ok->get_status(), 'admin status' );
			asc_assert_same( array( 'rest-routes.json', 'mcp-self-test.json' ), array_keys( $ok->get_data()['files'] ), 'sections param' );
			asc_assert( isset( $ok->get_data()['manifest_index'] ), 'manifest index present' );
			asc_assert_same( 400, asc_it_rest( 'GET', '/export/bundle', array( 'max_items' => 0 ) )->get_status(), 'bounds' );
			$ed = asc_it_as_user( $editor, function () {
				return asc_it_rest( 'GET', '/export/bundle' );
			} );
			asc_assert_same( 403, $ed->get_status(), 'editor denied' );
			$anon = asc_it_as_user( 0, function () {
				return asc_it_rest( 'GET', '/export/bundle' );
			} );
			asc_assert_same( 401, $anon->get_status(), 'anonymous denied' );
			$call = asc_it_mcp_call( 'wp_export_bundle', array( 'sections' => 'redirects.json' ) );
			asc_assert_same( false, $call['is_error'], 'MCP isError' );
			asc_assert_same( array( 'redirects.json' ), array_keys( $call['data']['files'] ), 'MCP sections' );
		} finally {
			asc_it_delete_user( $editor );
		}
	}
);

asc_it(
	'bundle review fixes: nested data kept, portable self-test, published-only inventory',
	function () {
		$draft     = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'ASC IT bundle draft' ) );
		$protected = asc_it_post( array( 'post_password' => 'pw', 'post_excerpt' => 'ASC IT secret excerpt' ) );
		try {
			$res    = AI_Site_Connector_Export_Bundle::build();
			$routes = array_column( $res['files']['rest-routes.json']['routes'], null, 'route' );
			asc_assert( isset( ( (array) $routes['/wp/v2/posts']['args'] )['offset'] ), 'route arg named offset was stripped' );
			asc_assert( isset( $res['files']['duplicate-media.json']['scope'] ), 'duplicate scope caveat stripped' );
			asc_assert( ! isset( $res['files']['site-inventory.json']['offset'] ), 'top-level cursor kept' );

			$st = $res['files']['mcp-self-test.json'];
			foreach ( $st['checks'] as $c ) {
				asc_assert_same( array( 'name', 'status' ), array_keys( $c ), 'portable check shape' );
				asc_assert( ! in_array( $c['name'], AI_Site_Connector_Export_Bundle::CONTEXT_CHECKS, true ), 'context-dependent check present' );
			}
			$editor_view = AI_Site_Connector_Export_Bundle::encode( $st );
			$admin2      = asc_it_user( 'administrator' );
			try {
				$other = asc_it_as_user( $admin2, function () {
					return AI_Site_Connector_Export_Bundle::build( array( 'sections' => array( 'mcp-self-test.json' ) ) );
				} );
				asc_assert_same( $editor_view, AI_Site_Connector_Export_Bundle::encode( $other['files']['mcp-self-test.json'] ), 'self-test manifest differs by admin user' );
			} finally {
				asc_it_delete_user( $admin2 );
			}

			$inv = array_column( $res['files']['site-inventory.json']['items'], null, 'id' );
			asc_assert( ! isset( $inv[ $draft ] ), 'draft in committed inventory' );
			asc_assert_same( '', $inv[ $protected ]['excerpt'], 'protected excerpt exported' );
			asc_assert( false === strpos( wp_json_encode( $res['files'] ), 'ASC IT secret excerpt' ), 'protected excerpt leaked' );
		} finally {
			wp_delete_post( $draft, true );
			wp_delete_post( $protected, true );
		}
	}
);

asc_it(
	'bundle review fixes: scan bounded by rows scanned; path-free error text',
	function () {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = asc_it_post( array( 'post_content' => 'no links here' ) );
		}
		try {
			$res = AI_Site_Connector_Export_Bundle::build( array( 'max_items' => 2, 'sections' => array( 'broken-links.json' ) ) );
			$bl  = $res['files']['broken-links.json'];
			asc_assert( $bl['posts_scanned'] <= 2, 'scanned more posts than max_items: ' . $bl['posts_scanned'] );
			asc_assert_same( true, $bl['truncated'], 'truncated when more posts remain' );

			$boom = static function () {
				throw new Error( 'boom in /var/www/html/wp-content/plugins/x/file.php on line 3' );
			};
			$res  = asc_it_with_filter(
				'ai_site_connector_redirect_plugins',
				$boom,
				function () {
					return AI_Site_Connector_Export_Bundle::build( array( 'sections' => array( 'redirects.json' ) ) );
				}
			);
			$err  = $res['index']['redirects.json']['error'];
			asc_assert( false === strpos( $err, '/var/www' ) && false !== strpos( $err, '[path]' ), 'path not stripped: ' . $err );
			asc_assert( 0 === strpos( $err, 'Error:' ), 'class name prefix' );
		} finally {
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);
