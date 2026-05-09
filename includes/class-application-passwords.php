<?php
/**
 * Wrapper around WP core Application Passwords.
 *
 * Stores only metadata (uuid, name, created, last_used) — never the plaintext.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Application_Passwords {

	/**
	 * Generate an Application Password for a user.
	 *
	 * @param int    $user_id Target WP user ID.
	 * @param string $app_name Human-readable app name.
	 * @return array|WP_Error { uuid, app_id, name, password (plaintext - display once) } on success.
	 */
	public static function create_for_user( $user_id, $app_name ) {
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			return new WP_Error( 'app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'ai-site-connector' ) );
		}
		if ( ! AI_Site_Connector_Plugin::require_https() ) {
			return new WP_Error( 'https_required', __( 'Refusing to create an Application Password over plain HTTP. Enable HTTPS or define AI_SITE_CONNECTOR_ALLOW_HTTP for local dev only.', 'ai-site-connector' ) );
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'User not found.', 'ai-site-connector' ) );
		}
		$app_name = sanitize_text_field( $app_name );
		if ( empty( $app_name ) ) {
			return new WP_Error( 'invalid_name', __( 'Application Password name is required.', 'ai-site-connector' ) );
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => $app_name,
				'app_id' => 'ai-site-connector',
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		list( $plaintext, $item ) = $created;

		AI_Site_Connector_Audit_Log::record(
			'application_password_created',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'Application Password "%s" generated for user "%s" (uuid=%s).', $app_name, $user->user_login, isset( $item['uuid'] ) ? $item['uuid'] : '' ),
			)
		);

		return array(
			'uuid'     => isset( $item['uuid'] ) ? $item['uuid'] : '',
			'app_id'   => isset( $item['app_id'] ) ? $item['app_id'] : '',
			'name'     => isset( $item['name'] ) ? $item['name'] : $app_name,
			'created'  => isset( $item['created'] ) ? $item['created'] : time(),
			'password' => $plaintext,
			'username' => $user->user_login,
		);
	}

	public static function list_for_user( $user_id ) {
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			return array();
		}
		$items = WP_Application_Passwords::get_user_application_passwords( (int) $user_id );
		if ( ! is_array( $items ) ) {
			return array();
		}
		return $items;
	}

	public static function revoke( $user_id, $uuid ) {
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			return new WP_Error( 'app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'ai-site-connector' ) );
		}
		$result = WP_Application_Passwords::delete_application_password( (int) $user_id, sanitize_text_field( $uuid ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		AI_Site_Connector_Audit_Log::record(
			'application_password_revoked',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'Application Password (uuid=%s) revoked for user id %d.', $uuid, $user_id ),
			)
		);
		return true;
	}

	public static function suggested_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'site';
		return sprintf( 'Claude AI Connector - %s - %s', $host, gmdate( 'Y-m-d' ) );
	}
}
