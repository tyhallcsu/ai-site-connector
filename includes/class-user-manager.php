<?php
/**
 * AI/service user creation and lookup helpers.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_User_Manager {

	const META_KEY_AI_USER = 'ai_site_connector_managed';

	public static function allowed_roles() {
		$wp_roles = wp_roles();
		$allowed  = array();
		foreach ( $wp_roles->roles as $slug => $info ) {
			if ( in_array( $slug, array( 'administrator', 'editor', AI_SITE_CONNECTOR_OPERATOR_ROLE ), true ) ) {
				$allowed[ $slug ] = $info['name'];
			}
		}
		return $allowed;
	}

	/**
	 * Create a dedicated AI/service user.
	 *
	 * @param array $args {
	 *   @type string $username Required.
	 *   @type string $email    Required.
	 *   @type string $role     Required.
	 *   @type string $display  Optional display name.
	 * }
	 * @return int|WP_Error User ID on success.
	 */
	public static function create_user( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'username' => 'ai-agent',
				'email'    => '',
				'role'     => AI_SITE_CONNECTOR_OPERATOR_ROLE,
				'display'  => 'AI Agent',
			)
		);

		$username = sanitize_user( $args['username'], true );
		$email    = sanitize_email( $args['email'] );
		$role     = sanitize_key( $args['role'] );
		$display  = sanitize_text_field( $args['display'] );

		if ( empty( $username ) ) {
			return new WP_Error( 'invalid_username', __( 'A valid username is required.', 'ai-site-connector' ) );
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'ai-site-connector' ) );
		}
		if ( username_exists( $username ) ) {
			return new WP_Error( 'username_exists', __( 'A user with that username already exists. Use the "select existing user" option instead.', 'ai-site-connector' ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_exists', __( 'A user with that email address already exists.', 'ai-site-connector' ) );
		}
		$allowed = self::allowed_roles();
		if ( ! isset( $allowed[ $role ] ) ) {
			return new WP_Error( 'invalid_role', __( 'Selected role is not permitted by AI Site Connector.', 'ai-site-connector' ) );
		}

		$password = wp_generate_password( 32, true, true );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $display,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::META_KEY_AI_USER, 1 );

		AI_Site_Connector_Audit_Log::record(
			'ai_user_created',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'AI user "%s" (id=%d) created with role "%s".', $username, $user_id, $role ),
			)
		);

		return (int) $user_id;
	}

	public static function list_candidate_users() {
		$users = get_users(
			array(
				'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'orderby' => 'login',
				'order'   => 'ASC',
				'number'  => 200,
			)
		);
		return $users;
	}

	public static function is_administrator( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user && in_array( 'administrator', (array) $user->roles, true );
	}
}
