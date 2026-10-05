<?php
/**
 * Older export routes: per-item access and draft dates.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'export: recent-changes lists only posts the caller can edit',
	function () {
		$contrib = asc_it_user( 'contributor' );
		$own     = asc_it_post( array( 'post_author' => $contrib, 'post_status' => 'draft', 'post_title' => 'Own draft' ) );
		$other   = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'ASC IT other draft' ) );
		try {
			$res = asc_it_as_user( $contrib, function () {
				return asc_it_rest( 'GET', '/export/recent-changes', array( 'limit' => 500 ) );
			} );
			asc_assert_same( 200, $res->get_status(), 'status' );
			$data = $res->get_data();
			$ids  = array_column( $data['items'], 'id' );
			asc_assert( in_array( $own, $ids, true ), 'own draft missing' );
			asc_assert( ! in_array( $other, $ids, true ), 'other draft listed' );
			asc_assert( $data['omitted_forbidden'] >= 1, 'omitted count' );
		} finally {
			wp_delete_post( $own, true );
			wp_delete_post( $other, true );
			asc_it_delete_user( $contrib );
		}
	}
);

asc_it(
	'export: recent-changes since filter keeps never-published drafts',
	function () {
		$draft = asc_it_post( array( 'post_status' => 'draft' ) );
		try {
			$data = asc_it_rest( 'GET', '/export/recent-changes', array( 'since' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'limit' => 500 ) )->get_data();
			$by   = array_column( $data['items'], null, 'id' );
			asc_assert( isset( $by[ $draft ] ), 'draft missing from since filter' );
			asc_assert( '0000-00-00 00:00:00' !== $by[ $draft ]['modified_gmt'], 'zero GMT date returned' );
		} finally {
			wp_delete_post( $draft, true );
		}
	}
);

asc_it(
	'export: media manifest hides attachments of posts the caller cannot read',
	function () {
		$author  = asc_it_user( 'author' );
		$private = asc_it_post( array( 'post_status' => 'private' ) );
		$public  = asc_it_post();
		$hidden  = wp_insert_attachment( array( 'post_title' => 'ASC IT hidden', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ), false, $private );
		$shown   = wp_insert_attachment( array( 'post_title' => 'ASC IT shown', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ), false, $public );
		try {
			$data = asc_it_as_user( $author, function () {
				return asc_it_rest( 'GET', '/export/media-manifest', array( 'limit' => 5000, 'include_sha256' => false ) )->get_data();
			} );
			$ids = array_column( $data['items'], 'attachment_id' );
			asc_assert( in_array( $shown, $ids, true ), 'public attachment missing' );
			asc_assert( ! in_array( $hidden, $ids, true ), 'attachment of private post listed' );
			asc_assert( $data['omitted_forbidden'] >= 1, 'omitted count' );
		} finally {
			wp_delete_attachment( $hidden, true );
			wp_delete_attachment( $shown, true );
			wp_delete_post( $private, true );
			wp_delete_post( $public, true );
			asc_it_delete_user( $author );
		}
	}
);
