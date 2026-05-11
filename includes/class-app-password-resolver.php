<?php
/**
 * Resolve the Application Password UUID active in the current request.
 *
 * Used by scope enforcement (#12), IP allowlist (#15), and usage tracking
 * (#19) to attribute a request to a specific Application Password (not
 * just to a user — one user can have N passwords with N different scopes).
 *
 * Hooks `application_password_did_authenticate` (WP 5.7+, released
 * March 2021), which fires right after a successful App Password auth
 * and passes the matched password item including its UUID. We stash it
 * in a static for the rest of the request.
 *
 * Pre-5.7 installs return null from current_uuid() — the per-password
 * features (scopes / IP allowlist / expiry) degrade to "no restriction"
 * on those installs, which matches the existing behavior pre-α anyway.
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
		// Mark resolved up front so any future calls in this request don't
		// re-enter through the auth hook handler.
		self::$cache['resolved'] = true;
		return self::$cache['uuid']; // null unless capture_uuid() populated it.
	}

	/**
	 * For tests and the daily sweep cron — clear the per-request cache.
	 */
	public static function reset_cache() {
		self::$cache = array( 'resolved' => false, 'uuid' => null );
	}
}
