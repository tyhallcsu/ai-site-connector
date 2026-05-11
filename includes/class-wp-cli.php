<?php
/**
 * WP-CLI commands.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class AI_Site_Connector_CLI {

	/**
	 * Show plugin status / connectivity diagnostics.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector status
	 */
	public function status() {
		$user = wp_get_current_user();
		$rows = array(
			array( 'site_url',         home_url() ),
			array( 'rest_url',         rest_url() ),
			array( 'wp_version',       get_bloginfo( 'version' ) ),
			array( 'php_version',      PHP_VERSION ),
			array( 'is_https',         AI_Site_Connector_Plugin::is_https() ? 'yes' : 'no' ),
			array( 'app_passwords',    AI_Site_Connector_Plugin::app_passwords_available() ? 'available' : 'unavailable' ),
			array( 'rest_reachable',   AI_Site_Connector_Plugin::rest_reachable() ? 'yes' : 'no' ),
			array( 'plugin_version',   AI_SITE_CONNECTOR_VERSION ),
			array( 'cli_user',         $user && $user->ID ? $user->user_login : '—' ),
		);
		\WP_CLI\Utils\format_items( 'table', array_map( function( $r ) { return array( 'key' => $r[0], 'value' => $r[1] ); }, $rows ), array( 'key', 'value' ) );
	}

	/**
	 * Run a health check (returns JSON).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector health
	 */
	public function health() {
		$req = new WP_REST_Request( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health' );
		$res = rest_do_request( $req );
		WP_CLI::log( wp_json_encode( $res->get_data(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Create a dedicated AI user.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login for the new user.
	 *
	 * [--email=<email>]
	 * : Email; defaults to ai-agent@<host>.
	 *
	 * [--role=<role>]
	 * : One of: administrator, editor, ai_site_operator (default).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector create-user --username=ai-agent --role=ai_site_operator
	 */
	public function create_user( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$role     = isset( $assoc['role'] ) ? $assoc['role'] : AI_SITE_CONNECTOR_OPERATOR_ROLE;
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$email    = isset( $assoc['email'] ) ? $assoc['email'] : ( 'ai-agent@' . $host );

		$res = AI_Site_Connector_User_Manager::create_user(
			array(
				'username' => $username,
				'email'    => $email,
				'role'     => $role,
				'display'  => 'AI Agent',
			)
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Created user %s with id %d.', $username, $res ) );
	}

	/**
	 * Generate an Application Password for a user.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login of the WordPress user that owns the new Application Password.
	 *
	 * [--name=<name>]
	 * : App password name; default: "Claude AI Connector - <host> - <date>".
	 *
	 * [--format=<format>]
	 * : json|table|yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector generate-password --username=ai-agent
	 */
	public function generate_password( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$name     = isset( $assoc['name'] ) ? $assoc['name'] : AI_Site_Connector_Application_Passwords::suggested_name();
		$user     = $username ? get_user_by( 'login', $username ) : null;
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		// Optional extras parsed up front so an invalid expiry refuses to
		// create the credential.
		$expires_at = null;
		if ( ! empty( $assoc['expires'] ) ) {
			$expires_at = strtotime( (string) $assoc['expires'] );
			if ( false === $expires_at || $expires_at <= time() ) {
				WP_CLI::error( '--expires must parse to a future date/time.' );
			}
		}
		$scopes = array();
		if ( ! empty( $assoc['scopes'] ) ) {
			foreach ( explode( ',', (string) $assoc['scopes'] ) as $entry ) {
				$entry = trim( $entry );
				if ( '' === $entry ) {
					continue;
				}
				if ( false !== strpos( $entry, ':' ) ) {
					list( $m, $r ) = explode( ':', $entry, 2 );
					$scopes[] = array( 'method' => strtoupper( trim( $m ) ), 'route' => '/' . ltrim( trim( $r ), '/' ) );
				} else {
					$scopes[] = array( 'method' => '*', 'route' => '/' . ltrim( $entry, '/' ) );
				}
			}
		}
		$ip_allowlist = array();
		if ( ! empty( $assoc['ip-allowlist'] ) ) {
			foreach ( explode( ',', (string) $assoc['ip-allowlist'] ) as $cidr ) {
				$cidr = trim( $cidr );
				if ( '' !== $cidr ) {
					$ip_allowlist[] = $cidr;
				}
			}
		}

		$res = AI_Site_Connector_Application_Passwords::create_for_user( $user->ID, $name );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}

		// Persist extras now that we have the UUID.
		if ( class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			$extras = array( 'created_by' => 0 ); // 0 = CLI/automated.
			if ( ! empty( $scopes ) ) {
				$extras['scopes'] = $scopes;
			}
			if ( ! empty( $ip_allowlist ) ) {
				$extras['ip_allowlist'] = $ip_allowlist;
			}
			if ( null !== $expires_at ) {
				$extras['expires_at'] = $expires_at;
			}
			if ( count( $extras ) > 1 ) {
				AI_Site_Connector_App_Password_Meta::set_extras( $user->ID, $res['uuid'], $extras );
			}
		}

		$pack = array(
			'site_url'             => home_url(),
			'rest_api_base'        => trailingslashit( rest_url() ),
			'username'             => $user->user_login,
			'application_password' => $res['password'],
			'app_password_uuid'    => $res['uuid'],
			'app_password_name'    => $res['name'],
			'test_endpoint'        => trailingslashit( rest_url() ) . 'wp/v2/users/me',
		);
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $pack, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( $format, array( $pack ), array_keys( $pack ) );
		}
		WP_CLI::warning( 'Save the application_password now — it will not be shown again.' );
	}

	/**
	 * Revoke an Application Password by UUID.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login of the WordPress user whose password is being revoked.
	 *
	 * --uuid=<uuid>
	 * : UUID of the Application Password to revoke (from `wp user application-password list` or the plugin UI).
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector revoke-password --username=ai-agent --uuid=abc-123
	 */
	public function revoke_password( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$uuid     = isset( $assoc['uuid'] ) ? $assoc['uuid'] : '';
		$user     = $username ? get_user_by( 'login', $username ) : null;
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}
		$res = AI_Site_Connector_Application_Passwords::revoke( $user->ID, $uuid );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( 'Application Password revoked.' );
	}

	/**
	 * Atomically rotate an Application Password.
	 *
	 * Mints a new password preserving sidecar metadata (scopes, IP allowlist,
	 * expiry), then revokes the old one. If the revoke fails the new password
	 * is rolled back so you're never left with two valid credentials.
	 *
	 * ## OPTIONS
	 *
	 * --username=<username>
	 * : Login of the WordPress user whose password is being rotated.
	 *
	 * --uuid=<uuid>
	 * : UUID of the existing Application Password to rotate.
	 *
	 * [--name=<name>]
	 * : Optional name for the new password. Defaults to "<old name> (rotated <date>)".
	 *
	 * [--format=<format>]
	 * : json|table|yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector rotate-password --username=ai-agent --uuid=abc-123
	 *   wp ai-connector rotate-password --username=ai-agent --uuid=abc-123 --format=json
	 */
	public function rotate_password( $args, $assoc ) {
		$username = isset( $assoc['username'] ) ? $assoc['username'] : '';
		$uuid     = isset( $assoc['uuid'] ) ? $assoc['uuid'] : '';
		$new_name = isset( $assoc['name'] ) ? $assoc['name'] : null;
		$user     = $username ? get_user_by( 'login', $username ) : null;
		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}
		$res = AI_Site_Connector_Application_Passwords::rotate( $user->ID, $uuid, $new_name );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		$pack = array(
			'site_url'             => home_url(),
			'rest_api_base'        => trailingslashit( rest_url() ),
			'username'             => $user->user_login,
			'application_password' => $res['password'],
			'app_password_uuid'    => $res['uuid'],
			'app_password_name'    => $res['name'],
		);
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $pack, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( $format, array( $pack ), array_keys( $pack ) );
		}
		WP_CLI::success( sprintf( 'Application Password rotated for %s. Save the new password — it will not be shown again.', $user->user_login ) );
	}

	/**
	 * Run an end-to-end self-test of the plugin install.
	 *
	 * Exits 0 if every check passes, non-zero on any failure. Designed for use
	 * in CI / Ansible / Terraform / cron health probes — give it a username
	 * and it will mint a temporary Application Password, hit a known endpoint,
	 * and revoke the credential before returning.
	 *
	 * The temporary password is NEVER printed. The revoke runs in a try/finally
	 * pattern so even an interrupted call cleans up after itself.
	 *
	 * ## OPTIONS
	 *
	 * [--username=<username>]
	 * : If supplied, also mints a temporary Application Password for this user,
	 *   uses it against /wp-json/wp/v2/users/me via internal REST dispatch,
	 *   and revokes it. Skipping this flag runs the checks that don't require
	 *   credential mint authority.
	 *
	 * [--format=<format>]
	 * : human|json. Default: human. Use json for machine-readable output.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector self-test
	 *   wp ai-connector self-test --username=ai-agent
	 *   wp ai-connector self-test --username=ai-agent --format=json
	 */
	public function self_test( $args, $assoc ) {
		$format        = isset( $assoc['format'] ) && 'json' === $assoc['format'] ? 'json' : 'human';
		$username      = isset( $assoc['username'] ) ? sanitize_user( $assoc['username'], true ) : '';
		$checks        = array();
		$temp_user_id  = 0;
		$temp_uuid     = '';

		$add_check = function ( $name, $ok, $detail = '' ) use ( &$checks ) {
			$checks[] = array(
				'name'   => $name,
				'ok'     => (bool) $ok,
				'detail' => (string) $detail,
			);
		};

		// 1. Plugin active. If we're running this, the answer is yes by definition.
		$add_check( 'plugin_active', true, 'AI Site Connector v' . AI_SITE_CONNECTOR_VERSION );

		// 2. ai_site_operator role exists with the documented default caps.
		$role        = get_role( AI_SITE_CONNECTOR_OPERATOR_ROLE );
		$role_ok     = (bool) $role;
		$role_detail = $role ? 'role exists' : 'role missing';
		if ( $role ) {
			$expected_true  = array( 'read', 'edit_posts', 'edit_pages', 'upload_files', 'moderate_comments' );
			$expected_false = array( 'manage_options', 'install_plugins', 'edit_files', 'list_users', 'edit_others_posts', 'delete_posts' );
			foreach ( $expected_true as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role_ok     = false;
					$role_detail = "missing required cap: {$cap}";
					break;
				}
			}
			if ( $role_ok ) {
				foreach ( $expected_false as $cap ) {
					if ( $role->has_cap( $cap ) ) {
						$role_ok     = false;
						$role_detail = "unexpected cap granted: {$cap}";
						break;
					}
				}
			}
		}
		$add_check( 'operator_role', $role_ok, $role_detail );

		// 3. Audit log table exists.
		global $wpdb;
		$audit_table = AI_Site_Connector_Audit_Log::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time install/upgrade check.
		$audit_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) );
		$add_check( 'audit_table', $audit_exists, $audit_exists ? $audit_table : 'audit table missing' );

		// 4. Application Passwords available (HTTPS / WP_ENVIRONMENT_TYPE / app-pwd filter).
		$app_pwds_ok = AI_Site_Connector_Plugin::app_passwords_available();
		$add_check( 'app_passwords_available', $app_pwds_ok, $app_pwds_ok ? 'available' : 'WP core reports unavailable (HTTPS / environment / filter)' );

		// 5. /v1/health unauth payload contains only the minimal keys.
		$health_req  = new WP_REST_Request( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health' );
		$health_res  = rest_do_request( $health_req );
		$health_data = is_object( $health_res ) ? (array) $health_res->get_data() : array();
		$leaks       = array();
		foreach ( array( 'wp_version', 'php_version', 'active_theme', 'active_plugin_count', 'is_multisite', 'user' ) as $forbidden ) {
			if ( array_key_exists( $forbidden, $health_data ) ) {
				$leaks[] = $forbidden;
			}
		}
		$health_ok = empty( $leaks ) && isset( $health_data['plugin'] ) && 'ai-site-connector' === $health_data['plugin'];
		$add_check( 'health_endpoint', $health_ok, $health_ok ? '/v1/health unauth payload is minimal' : ( 'leaks: ' . implode( ',', $leaks ) ) );

		// 6. Optional: round-trip a temporary credential through Basic Auth.
		// The plaintext stays in $temp_pwd which is unset() before any failure path.
		// We also schedule revoke via register_shutdown_function so a fatal error
		// during the test still triggers cleanup.
		if ( '' !== $username ) {
			$user = get_user_by( 'login', $username );
			if ( ! $user ) {
				$add_check( 'credential_round_trip', false, "user not found: {$username}" );
			} elseif ( ! $app_pwds_ok ) {
				$add_check( 'credential_round_trip', false, 'skipped — Application Passwords not available' );
			} else {
				$temp_name = 'AI Site Connector Self-Test - ' . gmdate( 'Y-m-d H:i:s' );
				$created   = AI_Site_Connector_Application_Passwords::create_for_user( $user->ID, $temp_name );
				if ( is_wp_error( $created ) ) {
					$add_check( 'credential_round_trip', false, 'mint failed: ' . $created->get_error_message() );
				} else {
					$temp_user_id = (int) $user->ID;
					$temp_uuid    = isset( $created['uuid'] ) ? (string) $created['uuid'] : '';
					$temp_pwd     = isset( $created['password'] ) ? (string) $created['password'] : '';

					// Cleanup safety net — if anything below fatals, this still
					// runs at shutdown and the credential is revoked.
					register_shutdown_function(
						static function () use ( $temp_user_id, $temp_uuid ) {
							if ( $temp_user_id && $temp_uuid ) {
								AI_Site_Connector_Application_Passwords::revoke( $temp_user_id, $temp_uuid );
							}
						}
					);

					$auth_header = 'Basic ' . base64_encode( $user->user_login . ':' . $temp_pwd );
					unset( $temp_pwd ); // Drop plaintext from this scope ASAP.

					$ping = wp_remote_get(
						rest_url( 'wp/v2/users/me' ),
						array(
							'timeout'   => 8,
							'sslverify' => false,
							'headers'   => array( 'Authorization' => $auth_header ),
						)
					);
					unset( $auth_header );

					if ( is_wp_error( $ping ) ) {
						$add_check( 'credential_round_trip', false, 'request failed: ' . $ping->get_error_message() );
					} else {
						$code = (int) wp_remote_retrieve_response_code( $ping );
						$add_check( 'credential_round_trip', 200 === $code, '/wp/v2/users/me returned HTTP ' . $code );
					}

					// Eager revoke (in addition to the shutdown safety net).
					AI_Site_Connector_Application_Passwords::revoke( $temp_user_id, $temp_uuid );
					$temp_uuid    = '';
					$temp_user_id = 0;
				}
			}
		}

		// Tally + audit.
		$total  = count( $checks );
		$passed = 0;
		foreach ( $checks as $c ) {
			if ( $c['ok'] ) {
				++$passed;
			}
		}
		$ok = $passed === $total;

		AI_Site_Connector_Audit_Log::record(
			'self_test_run',
			array(
				'message' => sprintf( 'wp ai-connector self-test: %d/%d checks passed.', $passed, $total ),
			)
		);

		if ( 'json' === $format ) {
			WP_CLI::log(
				wp_json_encode(
					array(
						'ok'        => $ok,
						'passed'    => $passed,
						'total'     => $total,
						'checks'    => $checks,
						'timestamp' => gmdate( 'c' ),
					),
					JSON_PRETTY_PRINT
				)
			);
		} else {
			WP_CLI::log( 'AI Site Connector — self-test' );
			foreach ( $checks as $c ) {
				$mark = $c['ok'] ? '  PASS' : '  FAIL';
				$line = $mark . ' ' . str_pad( $c['name'], 26 );
				if ( '' !== $c['detail'] ) {
					$line .= ' — ' . $c['detail'];
				}
				WP_CLI::log( $line );
			}
			WP_CLI::log( sprintf( '%s — %d/%d checks', $ok ? 'PASS' : 'FAIL', $passed, $total ) );
		}

		if ( ! $ok ) {
			WP_CLI::halt( 1 );
		}
	}
}
