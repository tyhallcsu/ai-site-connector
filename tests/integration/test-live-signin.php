<?php
/**
 * Live sign-in check (#106): a real HTTP Basic-auth round trip with a
 * temporary Application Password that is always revoked, plus actionable
 * results for a stripped Authorization header and a blocked loopback.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'live sign-in check: passes over HTTP, explains failures, never leaves a password behind (#106)',
	function () {
		$user_id = asc_it_user( AI_SITE_CONNECTOR_OPERATOR_ROLE );
		$count   = static function () use ( $user_id ) {
			return count( (array) WP_Application_Passwords::get_user_application_passwords( $user_id ) );
		};
		$check   = static function () use ( $user_id ) {
			return AI_Site_Connector_Diagnostics::credential_round_trip( get_userdata( $user_id ) );
		};
		try {
			$before = $count();

			$ok = $check();
			asc_assert_same( 'pass', $ok['status'], 'live check: ' . wp_json_encode( $ok ) );
			asc_assert_same( 200, $ok['code'], 'live check HTTP status' );
			asc_assert_same( $before, $count(), 'temporary password left behind after a pass' );

			// A host or proxy that strips the Authorization header.
			$stripped = asc_it_with_filter(
				'http_request_args',
				static function ( $args ) {
					unset( $args['headers']['Authorization'] );
					return $args;
				},
				$check
			);
			asc_assert_same( array( 'fail', 401 ), array( $stripped['status'], $stripped['code'] ), 'stripped Authorization header' );
			asc_assert( false !== strpos( $stripped['hint'], 'Authorization header' ), 'the 401 hint does not explain header stripping' );
			asc_assert_same( $before, $count(), 'temporary password left behind after a 401' );

			// A site that cannot call itself.
			$blocked = asc_it_with_filter(
				'pre_http_request',
				static function () {
					return new WP_Error( 'http_request_failed', 'loopback blocked by test' );
				},
				$check
			);
			asc_assert_same( array( 'fail', 'http_request_failed' ), array( $blocked['status'], $blocked['code'] ), 'blocked loopback' );
			asc_assert_same( $before, $count(), 'temporary password left behind after a transport failure' );

			$skipped = asc_it_with_filter( 'ai_site_connector_skip_preflight', '__return_true', $check );
			asc_assert_same( 'skipped', $skipped['status'], 'skip filter honoured' );
			asc_assert_same( $before, $count(), 'a skipped check minted a password' );

			// Admin hooks only register inside wp-admin (is_admin()); check the handler exists.
			asc_assert( is_callable( array( 'AI_Site_Connector_Admin_Page', 'handle_live_signin_check' ) ), 'Connection Test handler missing' );
		} finally {
			asc_it_delete_user( $user_id );
		}
	}
);
