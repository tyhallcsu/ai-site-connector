<?php
/**
 * Resolve the Application Password UUID active in the current request.
 *
 * Used by scope enforcement (#12), IP allowlist (#15), and usage tracking
 * (#19) to attribute a request to a specific Application Password (not
 * just to a user — one user can have N passwords with N different scopes).
 *
 * Primary path: WordPress fires `application_password_did_authenticate`
 * (WP 5.7+) right after a successful App Password auth, passing the
 * matched password item including its UUID. We stash it in a static.
 *
 * Fallback path: parse the `Authorization: Basic` header manually and
 * match against the user's stored App Password hashes via wp_check_password.
 * Cheap when there are few passwords; expensive if a user has dozens.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_App_Password_Resolver {

	/**
	 * Per-request cache. Reset on each request via PHP's normal globals lifecycle.
	 *
	 * @var array{resolved:bool, uuid:?string}
	 */
	private static $cache = array( 'resolved' => false, 'uuid' => null );

	public static function register_hooks() {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'capture_uuid' ), 10, 2 );
	}

	/**
	 * WP 5.7+ action callback. $item contains 'uuid' field per WP core.
	 */
	public static function capture_uuid( $user, $item ) {
		unset( $user );
		if ( is_array( $item ) && ! empty( $item['uuid'] ) ) {
			self::$cache = array( 'resolved' => true, 'uuid' => (string) $item['uuid'] );
		}
	}

	/**
	 * Return the UUID of the App Password used to authenticate the current
	 * request, or null if the request wasn't authed via App Password.
	 *
	 * @return string|null
	 */
	public static function current_uuid() {
		if ( self::$cache['resolved'] ) {
			return self::$cache['uuid'];
		}
		// Mark resolved up front so a fallback failure doesn't loop on retries.
		self::$cache['resolved'] = true;

		// Need a logged-in user (App Password auth produces one).
		$user_id = get_current_user_id();
		if ( ! $user_id || ! class_exists( 'WP_Application_Passwords' ) ) {
			return null;
		}

		// Parse Authorization header. PHP-FPM commonly drops it; cover both
		// the canonical and the fallback environment variables WordPress
		// itself reads in WP_Application_Passwords.
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		$auth = trim( $auth );
		if ( 0 !== stripos( $auth, 'Basic ' ) ) {
			return null;
		}
		$decoded = base64_decode( substr( $auth, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return null;
		}
		list( , $password ) = explode( ':', $decoded, 2 );
		$password           = preg_replace( '/[^A-Za-z0-9]/', '', $password ); // WP strips spaces before hashing.
		if ( '' === $password ) {
			return null;
		}

		$items = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( ! is_array( $items ) ) {
			return null;
		}
		foreach ( $items as $item ) {
			if ( empty( $item['password'] ) || empty( $item['uuid'] ) ) {
				continue;
			}
			if ( wp_check_password( $password, $item['password'], $user_id ) ) {
				self::$cache['uuid'] = (string) $item['uuid'];
				return self::$cache['uuid'];
			}
		}
		return null;
	}

	/**
	 * For tests and the daily sweep cron — clear the per-request cache.
	 */
	public static function reset_cache() {
		self::$cache = array( 'resolved' => false, 'uuid' => null );
	}
}
