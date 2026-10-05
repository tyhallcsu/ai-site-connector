<?php
/**
 * Media SEO audit (#64) and duplicate media (#65).
 *
 * Fixtures are real files written into a dedicated uploads subdirectory and
 * removed afterwards. Image metadata is set directly so no image library is
 * needed.
 *
 * @package AI_Site_Connector_Tests
 */

/**
 * Create an attachment backed by a real file in uploads/asc-it/.
 *
 * @return int Attachment ID.
 */
function asc_it_attachment( $filename, $bytes, $args = array() ) {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'asc-it/' . ( isset( $args['subdir'] ) ? $args['subdir'] . '/' : '' );
	wp_mkdir_p( $dir );
	$path = $dir . $filename;
	file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$relative = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $path ), '/' );
	$id       = wp_insert_attachment(
		array(
			'post_title'     => isset( $args['title'] ) ? $args['title'] : 'Descriptive title',
			'post_excerpt'   => isset( $args['caption'] ) ? $args['caption'] : 'A caption',
			'post_content'   => isset( $args['description'] ) ? $args['description'] : 'A description',
			'post_mime_type' => isset( $args['mime'] ) ? $args['mime'] : 'image/jpeg',
			'post_status'    => 'inherit',
		),
		$path,
		isset( $args['parent'] ) ? $args['parent'] : 0
	);
	update_post_meta( $id, '_wp_attached_file', $relative );
	if ( ! isset( $args['alt'] ) || '' !== $args['alt'] ) {
		update_post_meta( $id, '_wp_attachment_image_alt', isset( $args['alt'] ) ? $args['alt'] : 'Alt text' );
	}
	wp_update_attachment_metadata(
		$id,
		array(
			'width'    => isset( $args['width'] ) ? $args['width'] : 800,
			'height'   => isset( $args['height'] ) ? $args['height'] : 600,
			'file'     => $relative,
			'filesize' => strlen( $bytes ),
		)
	);
	return $id;
}

function asc_it_cleanup_attachments( array $ids ) {
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'asc-it';
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

function asc_it_audit_by_id( $res ) {
	return array_column( $res['items'], null, 'attachment_id' );
}

asc_it(
	'media-audit: clean image vs missing alt, unattached, oversized, suspicious name',
	function () {
		$post  = asc_it_post();
		$ids   = array();
		$ids[] = $clean = asc_it_attachment( 'harbor-sunset.jpg', 'clean-bytes', array( 'parent' => $post ) );
		$ids[] = $noalt = asc_it_attachment( 'mountain-trail.jpg', 'noalt-bytes', array( 'parent' => $post, 'alt' => '' ) );
		$ids[] = $loose = asc_it_attachment( 'river-bend.jpg', 'loose-bytes' );
		$ids[] = $huge  = asc_it_attachment( 'city-skyline.jpg', str_repeat( 'x', 2048 ), array( 'parent' => $post, 'width' => 6000, 'height' => 4000 ) );
		$ids[] = $cam   = asc_it_attachment( 'IMG_20240101_1234.jpg', 'cam-bytes', array( 'parent' => $post, 'title' => 'IMG_20240101_1234' ) );
		try {
			$res = AI_Site_Connector_Media_Audit::audit( array( 'limit' => 500, 'only_issues' => false, 'max_bytes' => 1024 ) );
			$by  = asc_it_audit_by_id( $res );
			asc_assert_same( array(), $by[ $clean ]['issues'], 'clean image' );
			asc_assert_same( true, $by[ $clean ]['has_alt'], 'has_alt' );
			asc_assert( in_array( 'missing_alt', $by[ $noalt ]['issues'], true ), 'missing alt' );
			asc_assert( in_array( 'unattached', $by[ $loose ]['issues'], true ), 'unattached' );
			asc_assert_same( 0, $by[ $loose ]['attached_post_id'], 'attached id' );
			asc_assert( in_array( 'oversized_dimensions', $by[ $huge ]['issues'], true ), 'oversized dimensions' );
			asc_assert( in_array( 'oversized_file', $by[ $huge ]['issues'], true ), 'oversized file' );
			asc_assert_same( array( 6000, 4000 ), array( $by[ $huge ]['width'], $by[ $huge ]['height'] ), 'dimensions' );
			asc_assert( in_array( 'suspicious_filename', $by[ $cam ]['issues'], true ), 'suspicious filename' );
			asc_assert( in_array( 'title_is_filename', $by[ $cam ]['issues'], true ), 'title is filename' );
			$keys = array( 'attachment_id', 'url', 'filename', 'mime_type', 'file_size', 'width', 'height', 'uploaded_gmt', 'attached_post_id', 'has_alt', 'has_title', 'has_caption', 'has_description', 'issues' );
			asc_assert_same( $keys, array_keys( $by[ $clean ] ), 'item schema' );
			asc_assert( false === strpos( wp_json_encode( $res ), WP_CONTENT_DIR ), 'server path leaked' );

			$only = AI_Site_Connector_Media_Audit::audit( array( 'limit' => 500 ) );
			asc_assert( ! isset( asc_it_audit_by_id( $only )[ $clean ] ), 'only_issues default hides clean items' );
			asc_assert( $only['clean'] >= 1, 'clean counted' );
		} finally {
			asc_it_cleanup_attachments( $ids );
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'media-audit: non-image attachment, missing file, mime filter, pagination',
	function () {
		$ids   = array();
		$ids[] = $pdf  = asc_it_attachment( 'annual-report.pdf', '%PDF-1.4', array( 'mime' => 'application/pdf', 'alt' => '' ) );
		$ids[] = $gone = asc_it_attachment( 'vanished-photo.jpg', 'gone' );
		$uploads = wp_upload_dir();
		unlink( trailingslashit( $uploads['basedir'] ) . 'asc-it/vanished-photo.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = asc_it_attachment( "page-image-{$i}.jpg", "p{$i}" );
		}
		try {
			$images = AI_Site_Connector_Media_Audit::audit( array( 'limit' => 500, 'only_issues' => false ) );
			asc_assert( ! isset( asc_it_audit_by_id( $images )[ $pdf ] ), 'pdf excluded from image audit' );
			$all = asc_it_audit_by_id( AI_Site_Connector_Media_Audit::audit( array( 'limit' => 500, 'mime' => 'all', 'only_issues' => false ) ) );
			asc_assert( ! in_array( 'missing_alt', $all[ $pdf ]['issues'], true ), 'non-image never missing_alt' );
			asc_assert( in_array( 'missing_file', $all[ $gone ]['issues'], true ), 'missing file' );

			$seen   = array();
			$offset = 0;
			$guard  = 0;
			while ( null !== $offset && $guard++ < 50 ) {
				$page   = AI_Site_Connector_Media_Audit::audit( array( 'limit' => 2, 'offset' => $offset, 'only_issues' => false ) );
				$seen   = array_merge( $seen, array_column( $page['items'], 'attachment_id' ) );
				$offset = $page['next_offset'];
			}
			asc_assert_same( count( $seen ), count( array_unique( $seen ) ), 'no duplicates across pages' );
			asc_assert_same( $images['total'], count( $seen ), 'pages cover every image' );
		} finally {
			asc_it_cleanup_attachments( $ids );
		}
	}
);

asc_it(
	'media-audit: invalid input rejected; REST read-only and access-checked',
	function () {
		foreach ( array( array( 'limit' => 0 ), array( 'limit' => 501 ), array( 'offset' => -1 ), array( 'mime' => 'video' ) ) as $bad ) {
			asc_assert( is_wp_error( AI_Site_Connector_Media_Audit::audit( $bad ) ), 'accepted ' . wp_json_encode( $bad ) );
		}
		$id = asc_it_attachment( 'lake-view.jpg', 'lake' );
		try {
			$before = asc_it_db_fingerprint();
			asc_assert_same( 200, asc_it_rest( 'GET', '/media/audit' )->get_status(), 'admin audit' );
			asc_assert_same( 200, asc_it_rest( 'GET', '/media/duplicates' )->get_status(), 'admin duplicates' );
			asc_assert_same( $before, asc_it_db_fingerprint(), 'media tools mutated the DB' );
			asc_assert_same( 400, asc_it_rest( 'GET', '/media/audit', array( 'limit' => 501 ) )->get_status(), 'REST bound' );

			$sub = asc_it_user( 'subscriber' );
			try {
				$res = asc_it_as_user( $sub, function () {
					return asc_it_rest( 'GET', '/media/audit' );
				} );
				asc_assert_same( 403, $res->get_status(), 'subscriber denied' );
			} finally {
				asc_it_delete_user( $sub );
			}
			$anon = asc_it_as_user( 0, function () {
				return asc_it_rest( 'GET', '/media/duplicates' );
			} );
			asc_assert_same( 401, $anon->get_status(), 'anonymous denied' );
		} finally {
			asc_it_cleanup_attachments( array( $id ) );
		}
	}
);

asc_it(
	'duplicates: filename, suffix variant, content hash, missing file; nothing deleted',
	function () {
		$ids   = array();
		$ids[] = $a  = asc_it_attachment( 'logo.png', 'SAME-CONTENT', array( 'subdir' => '2024', 'mime' => 'image/png' ) );
		$ids[] = $b  = asc_it_attachment( 'logo.png', 'other-content', array( 'subdir' => '2025', 'mime' => 'image/png' ) );
		$ids[] = $c  = asc_it_attachment( 'logo-1.png', 'third-bytes!', array( 'subdir' => '2025', 'mime' => 'image/png' ) );
		$ids[] = $d  = asc_it_attachment( 'banner-copy.png', 'SAME-CONTENT', array( 'mime' => 'image/png' ) );
		$ids[] = $e  = asc_it_attachment( 'unique-shot.png', 'unique-bytes-here', array( 'mime' => 'image/png' ) );
		$ids[] = $gone = asc_it_attachment( 'deleted-file.png', 'zzz', array( 'mime' => 'image/png' ) );
		$uploads = wp_upload_dir();
		unlink( trailingslashit( $uploads['basedir'] ) . 'asc-it/deleted-file.png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		try {
			$before = asc_it_db_fingerprint();
			$res    = asc_it_duplicates();
			asc_assert_same( $before, asc_it_db_fingerprint(), 'duplicates mutated the DB' );

			$groups = array();
			foreach ( $res['by_filename'] as $g ) {
				$groups[ $g['match'] . ':' . $g['filename'] ] = $g['attachment_ids'];
			}
			asc_assert_same( array( $a, $b ), $groups['exact:logo.png'], 'exact filename group' );
			asc_assert_same( array( $a, $b, $c ), $groups['suffix_variant:logo.png'], 'suffix variant group' );

			$hash = null;
			foreach ( $res['by_hash'] as $g ) {
				if ( in_array( $a, $g['attachment_ids'], true ) ) {
					$hash = $g;
				}
			}
			asc_assert( null !== $hash, 'hash group missing' );
			asc_assert_same( array( $a, $d ), $hash['attachment_ids'], 'hash group members' );
			asc_assert_same( hash( 'sha256', 'SAME-CONTENT' ), $hash['sha256'], 'sha256 value' );
			foreach ( $res['by_hash'] as $g ) {
				asc_assert( ! in_array( $e, $g['attachment_ids'], true ), 'unique file grouped' );
			}

			$unread = array_column( $res['unreadable'], 'reason', 'attachment_id' );
			asc_assert_same( 'missing_file', $unread[ $gone ], 'missing file reported' );

			foreach ( array( $a, $b, $c, $d, $e ) as $id ) {
				asc_assert( get_post( $id ) instanceof WP_Post, "attachment {$id} deleted" );
				asc_assert( is_file( get_attached_file( $id ) ), "file for {$id} removed" );
			}
		} finally {
			asc_it_cleanup_attachments( $ids );
		}
	}
);

asc_it(
	'duplicates: resumable scan pairs identical files across windows (#88); invalid input rejected',
	function () {
		$ids   = array();
		$ids[] = $a = asc_it_attachment( 'cross-a.png', 'CROSS-WINDOW-SAME', array( 'mime' => 'image/png' ) );
		$ids[] = $c = asc_it_attachment( 'cross-c.png', 'something-else-xx', array( 'mime' => 'image/png' ) );
		$ids[] = $b = asc_it_attachment( 'cross-b.png', 'CROSS-WINDOW-SAME', array( 'mime' => 'image/png' ) );
		try {
			$scan_id = '';
			$last_id = '';
			$calls   = 0;
			$phases  = array();
			$tiny    = static function () {
				return 1; // One file per hash call: forces a resumed hash phase.
			};
			add_filter( 'ai_site_connector_duplicate_hash_budget', $tiny );
			do {
				$res = AI_Site_Connector_Media_Audit::duplicates( array( 'scan_id' => $scan_id, 'max_scan' => 1 ) );
				$phases[] = is_wp_error( $res ) ? 'error' : $res['phase'];
				asc_assert( ! is_wp_error( $res ), 'scan error: ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
				if ( ! $res['complete'] ) {
					asc_assert_same( array(), $res['by_hash'], 'groups reported before the scan completed' );
					asc_assert_same( true, $res['truncated'], 'partial result not flagged' );
					asc_assert( '' !== $res['scan_id'], 'no scan_id to continue' );
				}
				$scan_id = $res['scan_id'];
				if ( '' !== $scan_id ) {
					$last_id = $scan_id;
				}
				asc_assert( ++$calls < 100, 'scan never completed' );
			} while ( ! $res['complete'] );
			remove_filter( 'ai_site_connector_duplicate_hash_budget', $tiny );
			asc_assert( count( array_keys( $phases, 'hash', true ) ) >= 2, 'hash phase never resumed: ' . implode( ',', $phases ) );
			asc_assert( $calls > 2, 'fixture did not span several windows' );
			$pair = null;
			foreach ( $res['by_hash'] as $g ) {
				if ( in_array( $a, $g['attachment_ids'], true ) ) {
					$pair = $g['attachment_ids'];
				}
			}
			asc_assert_same( array( $a, $b ), $pair, 'cross-window duplicate not paired' );
			asc_assert( '' !== $last_id && false === get_option( AI_Site_Connector_Media_Audit::DUP_STATE_PREFIX . $last_id ), 'scan state left behind' );
			asc_assert_same( '', $res['scan_id'], 'finished scan still advertises a scan_id' );

			// Another user cannot continue (or read) someone else's scan.
			$first  = AI_Site_Connector_Media_Audit::duplicates( array( 'max_scan' => 1 ) );
			$editor = asc_it_user( 'editor' );
			try {
				$other = asc_it_as_user( $editor, function () use ( $first ) {
					return AI_Site_Connector_Media_Audit::duplicates( array( 'scan_id' => $first['scan_id'] ) );
				} );
				asc_assert( is_wp_error( $other ) && 'asc_scan_forbidden' === $other->get_error_code(), 'scan shared across users' );
			} finally {
				asc_it_delete_user( $editor );
				AI_Site_Connector_Media_Audit::abandon_scan( $first['scan_id'] );
			}
			foreach ( array( array( 'max_scan' => 0 ), array( 'max_scan' => 20001 ), array( 'after_id' => 5 ), array( 'max_file_bytes' => 0 ), array( 'scan_id' => 'nope' ), array( 'scan_id' => '20990101000000-abcdefgh-u1' ), array( 'scan_id' => '20990101000000-abcdefgh' ) ) as $bad ) {
				asc_assert( is_wp_error( AI_Site_Connector_Media_Audit::duplicates( $bad ) ), 'accepted ' . wp_json_encode( $bad ) );
			}
		} finally {
			asc_it_cleanup_attachments( $ids );
		}
	}
);

asc_it(
	'media: MCP tools and suspicious-filename rules',
	function () {
		$call = asc_it_mcp_call( 'wp_media_audit', array( 'limit' => 5 ) );
		asc_assert_same( false, $call['is_error'], 'audit isError' );
		asc_assert( isset( $call['data']['summary'] ), 'audit payload' );
		$dup = asc_it_mcp_call( 'wp_media_duplicates', array( 'max_scan' => 10 ) );
		asc_assert_same( false, $dup['is_error'], 'duplicates isError' );
		foreach ( array( 'img_1234', 'dsc01234', 'screenshot-2024-01-01', 'image-1', 'unnamed', 'a1b2c3d4e5f6a7b8c9d0' ) as $bad ) {
			asc_assert( AI_Site_Connector_Media_Audit::is_suspicious_filename( $bad ), "{$bad} not flagged" );
		}
		foreach ( array( 'harbor-sunset', 'team-photo-2024', 'logo', 'image-gallery-header' ) as $ok ) {
			asc_assert( ! AI_Site_Connector_Media_Audit::is_suspicious_filename( $ok ), "{$ok} wrongly flagged" );
		}
	}
);

asc_it(
	'media review fixes: numbered series not duplicates; self-duplicate meta; scaled titles; threshold fallback',
	function () {
		$ids   = array();
		$ids[] = $s1 = asc_it_attachment( 'slide-1.png', 'slide-one', array( 'mime' => 'image/png' ) );
		$ids[] = $s2 = asc_it_attachment( 'slide-2.png', 'slide-two', array( 'mime' => 'image/png' ) );
		$ids[] = $dm = asc_it_attachment( 'double-meta.png', 'double-meta-bytes', array( 'mime' => 'image/png' ) );
		add_post_meta( $dm, '_wp_attached_file', 'asc-it/double-meta.png' ); // Importer-style duplicate row.
		$ids[] = $sc = asc_it_attachment( 'harbor-view-scaled.jpg', 'scaled', array( 'title' => 'harbor-view', 'width' => 7000, 'height' => 5000 ) );
		$ids[] = $cp = asc_it_attachment( 'unnamed-scaled.jpg', 'unnamed', array( 'title' => 'Something descriptive' ) );
		try {
			$dup = asc_it_duplicates();
			foreach ( $dup['by_filename'] as $g ) {
				asc_assert( ! in_array( $s1, $g['attachment_ids'], true ), 'numbered series grouped as duplicate' );
				asc_assert( $g['attachment_ids'] === array_values( array_unique( $g['attachment_ids'] ) ), 'attachment listed twice in a group' );
			}
			foreach ( $dup['by_hash'] as $g ) {
				asc_assert( ! in_array( $dm, $g['attachment_ids'], true ), 'duplicate meta row made a self-duplicate' );
			}

			$by = asc_it_audit_by_id(
				asc_it_with_filter(
					'big_image_size_threshold',
					'__return_false',
					function () {
						return AI_Site_Connector_Media_Audit::audit( array( 'limit' => 500, 'only_issues' => false ) );
					}
				)
			);
			asc_assert( in_array( 'title_is_filename', $by[ $sc ]['issues'], true ), 'scaled upload default title' );
			asc_assert( in_array( 'oversized_dimensions', $by[ $sc ]['issues'], true ), 'threshold disabled still flags 7000px' );
			asc_assert( in_array( 'suspicious_filename', $by[ $cp ]['issues'], true ), 'unnamed-scaled flagged' );
		} finally {
			asc_it_cleanup_attachments( $ids );
		}
	}
);

asc_it(
	'media review fixes: descriptive names are not suspicious; author gets lower scan ceiling',
	function () {
		foreach ( array( 'p320-holster-review', 'img-2024-kitchen-remodel', 'sam-500-brochure', 'capture-the-flag-event', 'team-2024' ) as $ok ) {
			asc_assert( ! AI_Site_Connector_Media_Audit::is_suspicious_filename( $ok ), "{$ok} wrongly flagged" );
		}
		foreach ( array( 'img_0001', 'pxl_20240101_123456', 'screen-shot-2024-01-01-at-10' ) as $bad ) {
			asc_assert( AI_Site_Connector_Media_Audit::is_suspicious_filename( $bad ), "{$bad} not flagged" );
		}
		$author = asc_it_user( 'author' );
		try {
			$res = asc_it_as_user( $author, function () {
				return AI_Site_Connector_Media_Audit::duplicates( array( 'max_scan' => 20000 ) );
			} );
			asc_assert( is_wp_error( $res ), 'author allowed admin-sized scan' );
		} finally {
			asc_it_delete_user( $author );
		}
	}
);

asc_it(
	'duplicates review: deleted and no-longer-visible attachments are dropped at completion; one live scan per user',
	function () {
		$author  = asc_it_user( 'author' );
		$post    = asc_it_post( array( 'post_author' => $author ) );
		$ids     = array();
		$ids[]   = $vis = asc_it_attachment( 'vis-a.png', 'VISIBILITY-SAME', array( 'mime' => 'image/png', 'parent' => $post ) );
		$ids[]   = $vis2 = asc_it_attachment( 'vis-b.png', 'VISIBILITY-SAME', array( 'mime' => 'image/png', 'parent' => $post ) );
		$ids[]   = $del = asc_it_attachment( 'deleted-later.png', 'DELETED-SAME-XX', array( 'mime' => 'image/png' ) );
		$ids[]   = $keep = asc_it_attachment( 'deleted-later.png', 'DELETED-SAME-XX', array( 'mime' => 'image/png', 'subdir' => 'k' ) );
		try {
			$res = asc_it_as_user(
				$author,
				function () use ( $post, $del ) {
					$first = AI_Site_Connector_Media_Audit::duplicates( array( 'max_scan' => 1 ) );
					$again = AI_Site_Connector_Media_Audit::duplicates( array( 'max_scan' => 1 ) );
					asc_assert( false === get_option( AI_Site_Connector_Media_Audit::DUP_STATE_PREFIX . $first['scan_id'] ), 'previous scan of the same user not replaced' );
					$scan_id = $again['scan_id'];
					// Mid-scan: the parent becomes private (author can no longer read
					// its media) and one attachment is deleted.
					wp_update_post( array( 'ID' => $post, 'post_status' => 'private', 'post_author' => 1 ) );
					wp_delete_attachment( $del, true );
					$calls = 0;
					do {
						$r       = AI_Site_Connector_Media_Audit::duplicates( array( 'scan_id' => $scan_id, 'max_scan' => 1 ) );
						$scan_id = $r['scan_id'];
						asc_assert( ++$calls < 100, 'scan never completed' );
					} while ( ! $r['complete'] );
					return $r;
				}
			);
			$json = wp_json_encode( array( $res['by_filename'], $res['by_hash'] ) );
			foreach ( array( $vis, $vis2, $del ) as $gone ) {
				asc_assert( false === strpos( $json, '"attachment_ids":[' . $gone ) && ! preg_match( '/[\[,]' . $gone . '[\],]/', $json ), "attachment {$gone} still reported" );
			}
			asc_assert( false === strpos( $json, 'vis-a.png' ), 'filename of a no-longer-visible attachment leaked' );
		} finally {
			asc_it_cleanup_attachments( array_diff( $ids, array( $del ) ) );
			wp_delete_post( $post, true );
			asc_it_delete_user( $author );
		}
	}
);
