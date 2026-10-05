<?php
/**
 * Content inventory (#63): fields, CPTs, terms, SEO, filters, pagination,
 * empty results, JSON/CSV validity, object-level access, read-only.
 *
 * @package AI_Site_Connector_Tests
 */

const ASC_IT_CPT = 'asc_it_book';
const ASC_IT_TAX = 'asc_it_genre';

function asc_it_with_cpt( $fn ) {
	register_post_type(
		ASC_IT_CPT,
		array(
			'public'  => true,
			'show_ui' => true,
			'label'   => 'IT Books',
		)
	);
	register_taxonomy( ASC_IT_TAX, ASC_IT_CPT, array( 'show_ui' => true ) );
	try {
		return $fn();
	} finally {
		unregister_taxonomy( ASC_IT_TAX );
		unregister_post_type( ASC_IT_CPT );
	}
}

function asc_it_inventory( $args ) {
	$res = AI_Site_Connector_Content_Inventory::query( $args );
	asc_assert( ! is_wp_error( $res ), 'inventory error: ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
	return $res;
}

asc_it(
	'inventory: posts and pages carry every required field',
	function () {
		$parent = asc_it_post( array( 'post_type' => 'page', 'post_title' => 'Parent page' ) );
		$page   = asc_it_post( array( 'post_type' => 'page', 'post_title' => 'Child page', 'post_parent' => $parent, 'menu_order' => 3 ) );
		$post   = asc_it_post( array( 'post_title' => 'A post', 'post_excerpt' => 'Short excerpt' ) );
		try {
			$res = asc_it_inventory( array( 'post_type' => 'post,page', 'limit' => 500 ) );
			$by  = array_column( $res['items'], null, 'id' );
			asc_assert( isset( $by[ $page ], $by[ $post ] ), 'post or page missing' );
			$keys = array( 'id', 'post_type', 'title', 'slug', 'status', 'author_id', 'created_gmt', 'modified_gmt', 'permalink', 'excerpt', 'featured_image', 'parent_id', 'menu_order', 'terms', 'seo' );
			asc_assert_same( $keys, array_keys( $by[ $page ] ), 'item keys' );
			asc_assert_same( $parent, $by[ $page ]['parent_id'], 'parent id' );
			asc_assert_same( 3, $by[ $page ]['menu_order'], 'menu order' );
			asc_assert_same( 'Short excerpt', $by[ $post ]['excerpt'], 'excerpt' );
			asc_assert_same( get_permalink( $post ), $by[ $post ]['permalink'], 'permalink' );
			asc_assert_same( array( 'id' => 0, 'url' => '' ), $by[ $post ]['featured_image'], 'no featured image' );
			$order  = array_column( $res['items'], 'id' );
			$sorted = $order;
			sort( $sorted );
			asc_assert_same( $sorted, $order, 'ordered by id' );
		} finally {
			foreach ( array( $page, $parent, $post ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}
);

asc_it(
	'inventory: custom post type, custom taxonomy, categories and tags',
	function () {
		asc_it_with_cpt(
			function () {
				$book = asc_it_post( array( 'post_type' => ASC_IT_CPT, 'post_title' => 'Book' ) );
				$term = wp_insert_term( 'Sci Fi', ASC_IT_TAX );
				wp_set_object_terms( $book, array( (int) $term['term_id'] ), ASC_IT_TAX );
				$cat  = wp_insert_term( 'ASC IT Cat', 'category' );
				$post = asc_it_post( array( 'post_category' => array( (int) $cat['term_id'] ), 'tags_input' => array( 'asc-it-tag' ) ) );
				try {
					asc_assert( in_array( ASC_IT_CPT, AI_Site_Connector_Content_Inventory::allowed_post_types(), true ), 'CPT not allowed' );
					$res = asc_it_inventory( array( 'post_type' => ASC_IT_CPT ) );
					asc_assert_same( 1, $res['total'], 'cpt total' );
					$terms = (array) $res['items'][0]['terms'];
					asc_assert_same( 'sci-fi', $terms[ ASC_IT_TAX ][0]['slug'], 'custom term' );

					$res   = asc_it_inventory( array( 'post_type' => 'post', 'limit' => 500 ) );
					$item  = array_column( $res['items'], null, 'id' )[ $post ];
					$terms = (array) $item['terms'];
					asc_assert_same( 'asc-it-cat', $terms['category'][0]['slug'], 'category' );
					asc_assert_same( 'asc-it-tag', $terms['post_tag'][0]['slug'], 'tag' );
				} finally {
					wp_delete_post( $book, true );
					wp_delete_post( $post, true );
					wp_delete_term( (int) $term['term_id'], ASC_IT_TAX );
					wp_delete_term( (int) $cat['term_id'], 'category' );
					$tag = get_term_by( 'slug', 'asc-it-tag', 'post_tag' );
					if ( $tag ) {
						wp_delete_term( $tag->term_id, 'post_tag' );
					}
				}
			}
		);
	}
);

asc_it(
	'inventory: pagination walks every row exactly once; empty result is clean',
	function () {
		asc_it_with_cpt(
			function () {
				$empty = asc_it_inventory( array( 'post_type' => ASC_IT_CPT ) );
				asc_assert_same( 0, $empty['total'], 'empty total' );
				asc_assert_same( array(), $empty['items'], 'empty items' );
				asc_assert_same( null, $empty['next_offset'], 'empty next_offset' );

				$ids = array();
				for ( $i = 0; $i < 5; $i++ ) {
					$ids[] = asc_it_post( array( 'post_type' => ASC_IT_CPT, 'post_title' => "Book {$i}" ) );
				}
				try {
					$seen   = array();
					$offset = 0;
					$pages  = 0;
					while ( null !== $offset ) {
						$res = asc_it_inventory( array( 'post_type' => ASC_IT_CPT, 'limit' => 2, 'offset' => $offset ) );
						asc_assert_same( 5, $res['total'], 'total per page' );
						$seen   = array_merge( $seen, array_column( $res['items'], 'id' ) );
						$offset = $res['next_offset'];
						asc_assert( ++$pages <= 3, 'too many pages' );
					}
					asc_assert_same( $ids, $seen, 'all rows once, in id order' );
				} finally {
					foreach ( $ids as $id ) {
						wp_delete_post( $id, true );
					}
				}
			}
		);
	}
);

asc_it(
	'inventory: status and modified_after/before filters',
	function () {
		$old   = asc_it_post( array( 'post_title' => 'Old one' ) );
		$draft = asc_it_post( array( 'post_title' => 'Draft one', 'post_status' => 'draft' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2001-01-01 00:00:00', 'post_modified' => '2001-01-01 00:00:00' ), array( 'ID' => $old ) );
		clean_post_cache( $old );
		try {
			$drafts = asc_it_inventory( array( 'post_type' => 'post', 'status' => 'draft', 'limit' => 500 ) );
			$ids    = array_column( $drafts['items'], 'id' );
			asc_assert( in_array( $draft, $ids, true ) && ! in_array( $old, $ids, true ), 'status filter' );

			$recent = asc_it_inventory( array( 'post_type' => 'post', 'modified_after' => '2010-01-01T00:00:00Z', 'limit' => 500 ) );
			asc_assert( ! in_array( $old, array_column( $recent['items'], 'id' ), true ), 'modified_after excluded old post' );
			asc_assert( in_array( $draft, array_column( $recent['items'], 'id' ), true ), 'modified_after kept a never-published draft (zero GMT date)' );
			$ancient = asc_it_inventory( array( 'post_type' => 'post', 'modified_before' => '2002-01-01 00:00:00', 'limit' => 500 ) );
			asc_assert_same( array( $old ), array_column( $ancient['items'], 'id' ), 'modified_before' );
			$d = array_column( $drafts['items'], null, 'id' )[ $draft ];
			asc_assert( '0000-00-00 00:00:00' !== $d['modified_gmt'] && '0000-00-00 00:00:00' !== $d['created_gmt'], 'draft GMT dates derived, not zero' );
		} finally {
			wp_delete_post( $old, true );
			wp_delete_post( $draft, true );
		}
	}
);

asc_it(
	'inventory: invalid input rejected (service and REST)',
	function () {
		foreach ( array(
			array( 'post_type' => 'attachment' ),
			array( 'post_type' => 'no_such_type' ),
			array( 'status' => 'bogus' ),
			array( 'limit' => 0 ),
			array( 'limit' => 501 ),
			array( 'offset' => -1 ),
			array( 'modified_after' => 'not a date' ),
		) as $bad ) {
			$res = AI_Site_Connector_Content_Inventory::query( $bad );
			asc_assert( is_wp_error( $res ), 'accepted ' . wp_json_encode( $bad ) );
			asc_assert_same( 400, $res->get_error_data()['status'], 'error status for ' . wp_json_encode( $bad ) );
		}
		asc_assert_same( 400, asc_it_rest( 'GET', '/export/content-inventory', array( 'limit' => 501 ) )->get_status(), 'REST limit' );
		asc_assert_same( 400, asc_it_rest( 'GET', '/export/content-inventory', array( 'post_type' => 'nope' ) )->get_status(), 'REST post_type' );
		asc_assert_same( 400, asc_it_rest( 'GET', '/export/content-inventory', array( 'format' => 'xml' ) )->get_status(), 'REST format' );
	}
);

asc_it(
	'inventory: SEO fields come from the abstraction',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, '_yoast_wpseo_title', 'Yoast T' );
		update_post_meta( $post, '_yoast_wpseo_meta-robots-noindex', '1' );
		try {
			$res = asc_it_with_filter(
				'ai_site_connector_seo_plugin',
				static function () {
					return 'yoast';
				},
				function () {
					return asc_it_inventory( array( 'post_type' => 'post', 'limit' => 500 ) );
				}
			);
			$item = array_column( $res['items'], null, 'id' )[ $post ];
			asc_assert_same( 'yoast', $res['seo_plugin'], 'plugin' );
			asc_assert_same( 'Yoast T', $item['seo']['title'], 'seo title' );
			asc_assert_same( true, $item['seo']['noindex'], 'noindex' );

			$lean = asc_it_inventory( array( 'post_type' => 'post', 'limit' => 500, 'include_seo' => false, 'include_terms' => false ) );
			$item = array_column( $lean['items'], null, 'id' )[ $post ];
			asc_assert( ! isset( $item['seo'] ) && ! isset( $item['terms'] ), 'include flags honoured' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'inventory: contributor sees only own posts; others are counted, not leaked',
	function () {
		$contrib = asc_it_user( 'contributor' );
		$theirs  = asc_it_post( array( 'post_author' => $contrib, 'post_status' => 'draft', 'post_title' => 'Contributor draft' ) );
		$secret  = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'ASC IT admin secret draft' ) );
		try {
			$res = asc_it_as_user( $contrib, function () {
				return asc_it_rest( 'GET', '/export/content-inventory', array( 'post_type' => 'post', 'limit' => 500 ) );
			} );
			asc_assert_same( 200, $res->get_status(), 'contributor status' );
			$data = $res->get_data();
			$ids  = array_column( $data['items'], 'id' );
			asc_assert( in_array( $theirs, $ids, true ), 'own draft missing' );
			asc_assert( ! in_array( $secret, $ids, true ), 'admin draft leaked' );
			asc_assert( false === strpos( wp_json_encode( $data ), 'admin secret draft' ), 'admin draft title leaked' );
			asc_assert_same( 0, $data['omitted_forbidden'], 'query is scoped to own posts, so nothing is counted' );
			asc_assert_same( 1, $data['total'], 'total reveals only own posts' );

			$anon = asc_it_as_user( 0, function () {
				return asc_it_rest( 'GET', '/export/content-inventory' );
			} );
			asc_assert_same( 401, $anon->get_status(), 'anonymous' );
		} finally {
			wp_delete_post( $theirs, true );
			wp_delete_post( $secret, true );
			asc_it_delete_user( $contrib );
		}
	}
);

asc_it(
	'inventory: JSON + CSV are valid, CSV neutralises formulas, export is read-only',
	function () {
		$post = asc_it_post( array( 'post_title' => '=HYPERLINK("http://evil.test","x")', 'post_excerpt' => "Line \"quoted\", comma\nnewline" ) );
		try {
			$before = asc_it_db_fingerprint();
			$json   = asc_it_rest( 'GET', '/export/content-inventory', array( 'post_type' => 'post', 'limit' => 500 ) );
			$csv    = asc_it_rest( 'GET', '/export/content-inventory', array( 'post_type' => 'post', 'limit' => 500, 'format' => 'csv' ) );
			asc_assert_same( $before, asc_it_db_fingerprint(), 'inventory mutated the DB' );

			$decoded = json_decode( wp_json_encode( $json->get_data() ), true );
			asc_assert( is_array( $decoded ) && isset( $decoded['items'] ), 'JSON round trip' );

			$data = $csv->get_data();
			asc_assert_same( 'csv', $data['format'], 'csv envelope' );
			asc_assert( ! isset( $data['items'] ), 'items removed from csv envelope' );
			$fh = fopen( 'php://temp', 'r+' );
			fwrite( $fh, $data['csv'] );
			rewind( $fh );
			$rows = array();
			while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
				$rows[] = $row;
			}
			fclose( $fh );
			asc_assert_same( AI_Site_Connector_Content_Inventory::CSV_COLUMNS, $rows[0], 'csv header' );
			foreach ( $rows as $i => $row ) {
				asc_assert_same( count( $rows[0] ), count( $row ), "csv row {$i} width" );
			}
			$mine = null;
			foreach ( $rows as $row ) {
				if ( (string) $post === $row[0] ) {
					$mine = $row;
				}
			}
			asc_assert( null !== $mine, 'fixture row missing from CSV' );
			asc_assert_same( "'" . '=HYPERLINK("http://evil.test","x")', $mine[2], 'formula neutralised' );
			asc_assert_same( "Line \"quoted\", comma\nnewline", $mine[9], 'quotes/commas/newlines round-trip' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'inventory: MCP tool returns JSON and CSV',
	function () {
		$json = asc_it_mcp_call( 'wp_content_inventory', array( 'post_type' => 'page', 'limit' => 5 ) );
		asc_assert_same( false, $json['is_error'], 'isError' );
		asc_assert( array_key_exists( 'next_offset', $json['data'] ), 'next_offset present' );
		$csv = asc_it_mcp_call( 'wp_content_inventory', array( 'format' => 'csv', 'include_seo' => false ) );
		asc_assert( 0 === strpos( $csv['data']['csv'], '"id","post_type"' ), 'csv via MCP' );
		$bad = asc_it_mcp_call( 'wp_content_inventory', array( 'post_type' => 'nope' ) );
		asc_assert_same( true, $bad['is_error'], 'invalid input is a tool error' );
	}
);
