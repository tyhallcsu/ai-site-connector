<?php
/**
 * Readable content diff in safe-update previews (#122): opt-in, dry runs
 * only, built from the values that would be stored, bounded.
 *
 * @package AI_Site_Connector_Tests
 */

/**
 * Dry-run a content change over REST with text_diff=true.
 *
 * @param int    $post    Post ID.
 * @param string $content New content.
 * @param bool   $text    Request the diff.
 * @return array Response data.
 */
function asc_it_preview_content( $post, $content, $text = true ) {
	$res = asc_it_rest(
		'POST',
		'/content/update',
		array(
			'post_id'   => $post,
			'changes'   => array( 'content' => $content ),
			'text_diff' => $text,
		)
	);
	asc_assert_same( 200, $res->get_status(), 'preview status: ' . wp_json_encode( $res->get_data() ) );
	return (array) $res->get_data();
}

asc_it(
	'content diff: unified preview of insertions, deletions, Unicode and block markup; nothing stored (#122)',
	function () {
		$before = "<!-- wp:paragraph -->\n<p>first line</p>\n<!-- /wp:paragraph -->\nsecond line\nthird line\nhéllo wörld";
		$post   = asc_it_post( array( 'post_content' => $before ) );
		try {
			$plain = asc_it_preview_content( $post, $before . "\nmore", false );
			asc_assert( ! isset( $plain['diff']['content']['text_diff'] ), 'text_diff returned without being requested' );

			$after = "<!-- wp:paragraph -->\n<p>first line, edited</p>\n<!-- /wp:paragraph -->\nsecond line\nhéllo wörld 🌍\nnew last line";
			$data  = asc_it_preview_content( $post, $after );
			asc_assert_same( 'dry_run', $data['reason'], 'preview is a dry run' );
			$diff = $data['diff']['content']['text_diff'];
			asc_assert_same( 'unified', $diff['format'], 'diff format' );
			asc_assert_same( false, $diff['truncated'], 'small diff truncated' );
			asc_assert_same( null, $diff['omitted'], 'small diff omitted' );
			foreach ( array( '-<p>first line</p>', '+<p>first line, edited</p>', '-third line', '+héllo wörld 🌍', '+new last line', ' second line' ) as $expected ) {
				asc_assert( false !== strpos( $diff['text'], $expected . "\n" ) || substr( $diff['text'], -strlen( $expected ) ) === $expected, "diff lacks line: {$expected}" );
			}
			asc_assert( 0 === strpos( $diff['text'], '@@ -' ), 'diff does not start with a hunk header' );
			asc_assert( isset( $data['diff']['content']['after']['sha256'] ), 'hash summary missing' );
			asc_assert_same( $before, get_post_field( 'post_content', $post ), 'preview changed the post' );
			asc_assert_same( array(), AI_Site_Connector_Content_Update::snapshots( $post )['snapshots'], 'preview stored a snapshot' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'content diff: shows the filtered value that would be stored, and stays bounded (#122)',
	function () {
		$author = asc_it_user( 'author' );
		$post   = asc_it_post( array( 'post_content' => '<p>safe</p>', 'post_author' => $author ) );
		try {
			$filtered = asc_it_as_user(
				$author,
				function () use ( $post ) {
					return asc_it_preview_content( $post, "<p>safe</p>\n<script>alert(1)</script>" );
				}
			);
			$text = $filtered['diff']['content']['text_diff']['text'];
			asc_assert( false === strpos( $text, '<script>' ), 'diff shows markup that would be filtered out: ' . $text );

			$big  = asc_it_preview_content( $post, str_repeat( "x\n", 110000 ) );
			$diff = $big['diff']['content']['text_diff'];
			asc_assert_same( array( 'content_too_large', '' ), array( $diff['omitted'], $diff['text'] ), 'oversized content not omitted' );
			asc_assert( isset( $big['diff']['content']['after']['sha256'] ), 'oversized preview lost its hash summary' );

			$many  = '';
			for ( $i = 0; $i < 1000; $i++ ) {
				$many .= "changed line {$i}\n";
			}
			$long  = asc_it_preview_content( $post, $many );
			$ldiff = $long['diff']['content']['text_diff'];
			asc_assert_same( true, $ldiff['truncated'], 'long diff not truncated' );
			$rows = array_filter(
				explode( "\n", $ldiff['text'] ),
				static function ( $line ) {
					return 0 !== strpos( $line, '@@' );
				}
			);
			asc_assert( count( $rows ) <= 400, 'truncated diff exceeds 400 lines: ' . count( $rows ) );
		} finally {
			wp_delete_post( $post, true );
			asc_it_delete_user( $author );
		}
	}
);
