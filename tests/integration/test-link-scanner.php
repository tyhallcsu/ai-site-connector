<?php
/**
 * Broken internal link scanner (#66).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'links: valid, broken, anchor, external, malformed and non-http links classified',
	function () {
		$target  = asc_it_post( array( 'post_title' => 'Link target', 'post_name' => 'asc-it-link-target' ) );
		$page    = asc_it_post( array( 'post_type' => 'page', 'post_title' => 'Target page', 'post_name' => 'asc-it-target-page' ) );
		$draft   = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'Draft target' ) );
		$trashed = asc_it_post( array( 'post_title' => 'Trashed target' ) );
		wp_trash_post( $trashed );
		$home    = home_url( '/' );
		$content = implode(
			"\n",
			array(
				'<a href="' . esc_url( get_permalink( $target ) ) . '">Good post</a>',
				'<a href="/asc-it-target-page/">Good <strong>page</strong></a>',
				'<a href="' . esc_url( get_permalink( $page ) ) . '#section">Page with fragment</a>',
				'<a href="#top">Anchor only</a>',
				'<a href="' . $home . '?p=' . $draft . '">Draft by id</a>',
				'<a href="' . $home . '?p=' . $trashed . '">Trashed by id</a>',
				'<a href="' . $home . '?p=999999999">Missing by id</a>',
				'<a href="/no-such-page-asc-it/">Dead path</a>',
				'<a href="https://external.example.org/x">External</a>',
				'<a href="mailto:someone@example.test">Mail</a>',
				'<a href="http://">Malformed</a>',
				'<a href="/wp-admin/edit.php">Admin</a>',
				'<a href="/category/uncategorized/">Category archive</a>',
				'<a href="/category/no-such-category-asc-it/">Missing category</a>',
			)
		);
		$source = asc_it_post( array( 'post_title' => 'Link source', 'post_content' => $content ) );
		try {
			$res = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'post', 'limit' => 200, 'only_broken' => false ) );
			$mine = array_values(
				array_filter(
					$res['items'],
					static function ( $i ) use ( $source ) {
						return $i['source_post_id'] === $source;
					}
				)
			);
			$by = array();
			foreach ( $mine as $row ) {
				$by[ $row['link_text'] ] = $row;
			}
			asc_assert_same( 'ok', $by['Good post']['status'], 'good post' );
			asc_assert_same( $target, $by['Good post']['target_post_id'], 'target id' );
			asc_assert_same( 'ok', $by['Good page']['status'], 'relative page link (and nested tag text)' );
			asc_assert_same( 'ok', $by['Page with fragment']['status'], 'fragment stripped' );
			asc_assert_same( 'anchor', $by['Anchor only']['reason'], 'anchor' );
			asc_assert_same( 'post_not_published', $by['Draft by id']['reason'], 'draft' );
			asc_assert_same( 'post_trashed', $by['Trashed by id']['reason'], 'trashed' );
			asc_assert_same( 'post_missing', $by['Missing by id']['reason'], 'missing id' );
			asc_assert_same( 'broken', $by['Dead path']['status'], 'dead path' );
			asc_assert( ! isset( $by['External'] ) && ! isset( $by['Mail'] ), 'external/mailto must not be rows' );
			asc_assert_same( 'invalid', $by['Malformed']['status'], 'malformed' );
			asc_assert_same( 'skipped', $by['Admin']['status'], 'system path' );
			asc_assert_same( 'ok', $by['Category archive']['status'], 'category archive' );
			asc_assert_same( 'term_not_found', $by['Missing category']['reason'], 'missing category' );
			asc_assert( $res['external_ignored'] >= 1 && $res['non_http_ignored'] >= 1, 'ignored counters' );

			$only = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'post', 'limit' => 200 ) );
			foreach ( $only['items'] as $row ) {
				asc_assert( in_array( $row['status'], array( 'broken', 'invalid' ), true ), 'only_broken default returned ' . $row['status'] );
			}
		} finally {
			foreach ( array( $source, $target, $page, $draft, $trashed ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'links: uploads links resolve against the filesystem only inside uploads',
	function () {
		$uploads = wp_upload_dir();
		wp_mkdir_p( trailingslashit( $uploads['basedir'] ) . 'asc-it-links' );
		file_put_contents( trailingslashit( $uploads['basedir'] ) . 'asc-it-links/present.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$base   = trailingslashit( $uploads['baseurl'] ) . 'asc-it-links/';
		$source = asc_it_post(
			array(
				'post_content' => '<a href="' . $base . 'present.pdf">present</a><a href="' . $base . 'absent.pdf">absent</a><a href="' . $base . '../../../wp-config.php">escape</a>',
			)
		);
		try {
			$res = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'post', 'limit' => 200, 'only_broken' => false ) );
			$by  = array_column(
				array_filter(
					$res['items'],
					static function ( $i ) use ( $source ) {
						return $i['source_post_id'] === $source;
					}
				),
				null,
				'link_text'
			);
			asc_assert( isset( $by['present'], $by['absent'], $by['escape'] ), 'upload rows missing' );
			asc_assert_same( 'ok', $by['present']['status'], 'existing upload' );
			asc_assert_same( 'missing_upload', $by['absent']['reason'], 'missing upload' );
			asc_assert( 'ok' !== $by['escape']['status'], 'path traversal treated as ok' );
			asc_assert_same( 'invalid', AI_Site_Connector_Link_Scanner::classify( $base . "a\0b.pdf" )['status'], 'NUL byte rejected' );
		} finally {
			wp_delete_post( $source, true );
			unlink( trailingslashit( $uploads['basedir'] ) . 'asc-it-links/present.pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			rmdir( trailingslashit( $uploads['basedir'] ) . 'asc-it-links' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
);

asc_it(
	'links: no outbound HTTP, read-only, limits and pagination respected',
	function () {
		$ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = asc_it_post( array( 'post_type' => 'page', 'post_content' => str_repeat( '<a href="/asc-it-dead-' . $i . '/">x</a>', 3 ) ) );
		}
		$http = 0;
		$spy  = static function ( $pre ) use ( &$http ) {
			++$http;
			return $pre;
		};
		try {
			$before = asc_it_db_fingerprint();
			$res    = asc_it_with_filter(
				'pre_http_request',
				$spy,
				function () {
					return AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'page', 'limit' => 200, 'only_broken' => false ) );
				}
			);
			asc_assert_same( 0, $http, 'scanner made HTTP requests' );
			asc_assert_same( $before, asc_it_db_fingerprint(), 'scanner mutated the DB' );

			$page1 = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'page', 'limit' => 2 ) );
			asc_assert_same( 2, $page1['posts_scanned'], 'limit respected' );
			asc_assert_same( 2, $page1['next_offset'], 'next_offset' );

			$capped = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => 'page', 'limit' => 200, 'max_links' => 4, 'only_broken' => false ) );
			asc_assert( $capped['links_examined'] <= 4 || 1 === $capped['posts_scanned'], 'max_links respected' );
			asc_assert_same( true, $capped['truncated'], 'truncated flag' );
			asc_assert( null !== $capped['next_offset'], 'resumable after truncation' );

			foreach ( array( array( 'limit' => 0 ), array( 'limit' => 201 ), array( 'offset' => -1 ), array( 'max_links' => 0 ), array( 'post_type' => 'attachment' ), array( 'status' => 'nope' ) ) as $bad ) {
				asc_assert( is_wp_error( AI_Site_Connector_Link_Scanner::scan( $bad ) ), 'accepted ' . wp_json_encode( $bad ) );
			}
		} finally {
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'links: extract_links handles quoting styles, attributes and malformed markup',
	function () {
		$links = AI_Site_Connector_Link_Scanner::extract_links( '<a class="x" href=\'/single\'>One</a> <A HREF=/bare>Two</A> <a href="/a?x=1&amp;y=2" target="_blank">Three &amp; more</a> <a name="noref">none</a> <a href="/unclosed">' );
		asc_assert_same( array( '/single', '/bare', '/a?x=1&y=2', '/unclosed' ), array_column( $links, 'href' ), 'hrefs (an unclosed anchor is still a link)' );
		asc_assert_same( 'Three & more', $links[2]['text'], 'decoded text' );
		asc_assert_same( array(), AI_Site_Connector_Link_Scanner::extract_links( 'no links here' ), 'no links' );
	}
);

asc_it(
	'links: REST + MCP access control and tool exposure',
	function () {
		$contrib = asc_it_user( 'contributor' );
		$other   = asc_it_post( array( 'post_content' => '<a href="/asc-it-secret-dead/">secret</a>' ) );
		try {
			asc_assert_same( 200, asc_it_rest( 'GET', '/content/broken-links' )->get_status(), 'admin' );
			$anon = asc_it_as_user( 0, function () {
				return asc_it_rest( 'GET', '/content/broken-links' );
			} );
			asc_assert_same( 401, $anon->get_status(), 'anonymous' );
			$data = asc_it_as_user( $contrib, function () {
				return asc_it_rest( 'GET', '/content/broken-links', array( 'post_type' => 'post', 'status' => 'any', 'limit' => 200 ) )->get_data();
			} );
			asc_assert_same( 0, $data['total_posts'], 'contributor with no posts sees a zero total' );
			asc_assert_same( array(), $data['items'], 'contributor sees others\' links' );
			asc_assert_same( 400, asc_it_rest( 'GET', '/content/broken-links', array( 'limit' => 999 ) )->get_status(), 'REST bound' );

			$call = asc_it_mcp_call( 'wp_broken_links', array( 'limit' => 10 ) );
			asc_assert_same( false, $call['is_error'], 'MCP isError' );
			asc_assert( isset( $call['data']['summary']['broken'] ), 'MCP payload' );
		} finally {
			wp_delete_post( $other, true );
			asc_it_delete_user( $contrib );
		}
	}
);

asc_it(
	'links review fixes: robust extraction on hostile markup',
	function () {
		$l = AI_Site_Connector_Link_Scanner::extract_links( '<a href="/one">first <a href="/two">second</a>' );
		asc_assert_same( array( '/one', '/two' ), array_column( $l, 'href' ), 'unclosed anchor swallowed the next link' );
		$l = AI_Site_Connector_Link_Scanner::extract_links( '<a data-href="/dead" href="/live">x</a>' );
		asc_assert_same( array( '/live' ), array_column( $l, 'href' ), 'data-href matched' );
		$huge = str_repeat( '<a href="/ok">ok</a> ', 1000 ) . '<a href="/unclosed">' . str_repeat( 'lorem ipsum ', 100000 );
		$l    = AI_Site_Connector_Link_Scanner::extract_links( $huge );
		asc_assert( is_array( $l ) && 1001 === count( $l ), 'large hostile content lost links: ' . ( is_array( $l ) ? count( $l ) : 'null' ) );
	}
);

asc_it(
	'links review fixes: pagination, feeds, relative hrefs, permalink front',
	function () {
		$parent = asc_it_post( array( 'post_type' => 'page', 'post_name' => 'asc-it-services' ) );
		$child  = asc_it_post( array( 'post_type' => 'page', 'post_name' => 'roofing', 'post_parent' => $parent ) );
		$leaf   = asc_it_post( array( 'post_type' => 'page', 'post_name' => 'repairs', 'post_parent' => $child ) );
		$base   = get_permalink( $child );
		try {
			asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/page/2/' )['status'], 'root pagination' );
			asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/category/uncategorized/feed/' )['status'], 'term feed' );
			asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/category/uncategorized/page/2/' )['status'], 'term pagination' );
			asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( 'repairs/', $base )['status'], 'relative to source document' );
			asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '../roofing/', $base )['status'], 'dot segments' );
			asc_assert_same( 'broken', AI_Site_Connector_Link_Scanner::classify( 'not-a-child/', $base )['status'], 'relative miss still broken' );

			global $wp_rewrite;
			$prev = get_option( 'permalink_structure' );
			$wp_rewrite->set_permalink_structure( '/blog/%postname%/' );
			flush_rewrite_rules( false );
			try {
				asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/blog/category/uncategorized/' )['status'], 'term archive under permalink front' );
				asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/blog/author/admin/' )['status'], 'author archive under permalink front' );
				asc_assert_same( 'ok', AI_Site_Connector_Link_Scanner::classify( '/blog/2024/05/' )['status'], 'date archive under permalink front' );
			} finally {
				$wp_rewrite->set_permalink_structure( $prev );
				flush_rewrite_rules( false );
			}
		} finally {
			foreach ( array( $leaf, $child, $parent ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'links review fixes: first post over max_links is capped and skipped past',
	function () {
		asc_it_with_cpt(
			function () {
				$big   = asc_it_post( array( 'post_type' => ASC_IT_CPT, 'post_content' => str_repeat( '<a href="/asc-it-x/">x</a>', 50 ) ) );
				$small = asc_it_post( array( 'post_type' => ASC_IT_CPT, 'post_content' => '<a href="/asc-it-y/">y</a>' ) );
				try {
					$res = AI_Site_Connector_Link_Scanner::scan( array( 'post_type' => ASC_IT_CPT, 'max_links' => 10, 'only_broken' => false ) );
					asc_assert_same( 10, $res['links_examined'], 'capped at max_links' );
					asc_assert_same( array( $big ), $res['partial_posts'], 'partial post reported' );
					asc_assert_same( true, $res['truncated'], 'truncated' );
					asc_assert_same( 1, $res['next_offset'], 'resume moves past the capped post' );
				} finally {
					wp_delete_post( $big, true );
					wp_delete_post( $small, true );
				}
			}
		);
	}
);
