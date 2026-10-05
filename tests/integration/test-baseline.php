<?php
/**
 * Baseline integration cases for routes that predate the diagnostics queue.
 * Proves the harness can authenticate, deny, and detect mutation.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'baseline: anonymous site-report is rejected',
	function () {
		$res = asc_it_as_user( 0, function () {
			return asc_it_rest( 'GET', '/diagnostics/site-report' );
		} );
		asc_assert( in_array( $res->get_status(), array( 401, 403 ), true ), 'expected 401/403, got ' . $res->get_status() );
	}
);

asc_it(
	'baseline: editor cannot read admin-only site-report',
	function () {
		$editor = wp_insert_user(
			array(
				'user_login' => 'asc_it_editor_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'asc-it-editor-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => 'editor',
			)
		);
		asc_assert( is_int( $editor ), 'could not create editor fixture' );
		try {
			$res = asc_it_as_user( $editor, function () {
				return asc_it_rest( 'GET', '/diagnostics/site-report' );
			} );
			asc_assert_same( 403, $res->get_status(), 'editor site-report status' );
		} finally {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $editor );
		}
	}
);

asc_it(
	'baseline: admin site-manifest export is read-only',
	function () {
		$before = asc_it_db_fingerprint();
		$res    = asc_it_rest( 'GET', '/export/site-manifest' );
		asc_assert_same( 200, $res->get_status(), 'site-manifest status' );
		asc_assert_same( $before, asc_it_db_fingerprint(), 'site-manifest mutated the database' );
	}
);
