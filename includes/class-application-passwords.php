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
		/**
		 * Fired after a password is successfully revoked. Used by
		 * AI_Site_Connector_App_Password_Meta to clean up its sidecar metadata
		 * for this UUID, but other listeners can hook this too.
		 *
		 * @param int    $user_id
		 * @param string $uuid
		 */
		do_action( 'ai_site_connector_application_password_revoked', (int) $user_id, sanitize_text_field( $uuid ) );
		return true;
	}

	/**
	 * Atomic rotation: mint a new password preserving any sidecar metadata
	 * (scopes / IP allowlist / expiry), then delete the old one. If the
	 * delete fails, the just-minted password is rolled back so the operator
	 * isn't left with two valid passwords.
	 *
	 * @param int         $user_id
	 * @param string      $uuid     UUID of the password to rotate.
	 * @param string|null $new_name Optional new name; defaults to "old name (rotated <date>)".
	 * @return array|WP_Error Same shape as create_for_user() on success.
	 */
	public static function rotate( $user_id, $uuid, $new_name = null ) {
		if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) {
			return new WP_Error( 'app_passwords_unavailable', __( 'Application Passwords are not available on this site.', 'ai-site-connector' ) );
		}
		$user_id = (int) $user_id;
		$uuid    = sanitize_text_field( $uuid );
		if ( ! $user_id || '' === $uuid ) {
			return new WP_Error( 'invalid_input', __( 'Both user ID and UUID are required.', 'ai-site-connector' ) );
		}

		// Find the existing password's record so we can copy its name + app_id.
		$existing = null;
		$items    = WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( isset( $item['uuid'] ) && $item['uuid'] === $uuid ) {
					$existing = $item;
					break;
				}
			}
		}
		if ( null === $existing ) {
			return new WP_Error( 'password_not_found', __( 'The Application Password to rotate does not exist.', 'ai-site-connector' ) );
		}

		$resolved_name = $new_name ? sanitize_text_field( $new_name )
			: sprintf( '%s (rotated %s)', isset( $existing['name'] ) ? $existing['name'] : 'AI', gmdate( 'Y-m-d' ) );

		// 1. Capture existing extras before we change anything.
		$had_extras = class_exists( 'AI_Site_Connector_App_Password_Meta' );
		$old_extras = $had_extras ? AI_Site_Connector_App_Password_Meta::get_extras( $user_id, $uuid ) : array();

		// 2. Mint the new password.
		$minted = self::create_for_user( $user_id, $resolved_name );
		if ( is_wp_error( $minted ) ) {
			return $minted;
		}
		$new_uuid = isset( $minted['uuid'] ) ? (string) $minted['uuid'] : '';
		if ( '' === $new_uuid ) {
			return new WP_Error( 'rotate_failed', __( 'New password was created but its UUID could not be read.', 'ai-site-connector' ) );
		}

		// 3. Copy extras to the new UUID before deleting the old one.
		if ( $had_extras && ! empty( $old_extras ) ) {
			AI_Site_Connector_App_Password_Meta::set_extras( $user_id, $new_uuid, $old_extras );
		}

		// 4. Delete the old password.
		$deleted = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			// Roll back: delete the just-minted password so we don't end up with two.
			WP_Application_Passwords::delete_application_password( $user_id, $new_uuid );
			if ( $had_extras ) {
				AI_Site_Connector_App_Password_Meta::delete_extras( $user_id, $new_uuid );
			}
			AI_Site_Connector_Audit_Log::record(
				'application_password_rotation_failed',
				array(
					'target_user_id' => $user_id,
					'message'        => sprintf( 'Rotation failed for uuid=%s — new password rolled back.', $uuid ),
				)
			);
			return $deleted;
		}

		// 5. Fire the same revoke signal so meta cleanup + listeners run for the old UUID.
		do_action( 'ai_site_connector_application_password_revoked', $user_id, $uuid );

		AI_Site_Connector_Audit_Log::record(
			'application_password_rotated',
			array(
				'target_user_id' => $user_id,
				'message'        => sprintf( 'Application Password rotated: old=%s → new=%s for user id %d.', $uuid, $new_uuid, $user_id ),
			)
		);
		return $minted;
	}

	public static function suggested_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'site';
		return sprintf( 'Claude AI Connector - %s - %s', $host, gmdate( 'Y-m-d' ) );
	}
}
