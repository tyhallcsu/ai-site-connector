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
			array( 'connector',        AI_Site_Connector_Permissions::is_disabled() ? 'disabled' : 'enabled' ),
			array( 'read_only_mode',   AI_Site_Connector_Permissions::is_read_only() ? 'on' : 'off' ),
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
		if ( '' !== $username ) {
			$user = get_user_by( 'login', $username );
			if ( ! $user ) {
				$add_check( 'credential_round_trip', false, "user not found: {$username}" );
			} elseif ( ! $app_pwds_ok ) {
				$add_check( 'credential_round_trip', false, 'skipped — Application Passwords not available' );
			} else {
				// Shared with the Connection Test live check and the pack
				// pre-flight (#106): temporary password, HTTP probe, revoke.
				$probe = AI_Site_Connector_Diagnostics::credential_round_trip( $user, false );
				if ( 'pass' === $probe['status'] ) {
					$detail = '/wp/v2/users/me returned HTTP 200';
				} elseif ( is_int( $probe['code'] ) ) {
					$detail = '/wp/v2/users/me returned HTTP ' . $probe['code'];
				} else {
					$detail = ( 'mint_failed' === $probe['code'] ? 'mint failed: ' : 'request failed: ' ) . $probe['hint'];
				}
				$add_check( 'credential_round_trip', 'pass' === $probe['status'], $detail );
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

	// === Diagnostics counterparts (#75) — same services as REST/MCP ========

	/**
	 * Run the MCP-surface self-test (pass/warn/fail checks).
	 *
	 * Distinct from `self-test`, which exercises the credential round trip.
	 * Exits 1 when any check fails. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector mcp-self-test --user=admin --format=json
	 */
	public function mcp_self_test( $args, $assoc ) {
		$result = AI_Site_Connector_Diagnostics::self_test();
		if ( 'json' === self::format( $assoc, array( 'table', 'json' ) ) ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $result['checks'], array( 'name', 'status', 'message' ) );
			WP_CLI::log( sprintf( 'overall=%s pass=%d warn=%d fail=%d', $result['overall'], $result['summary']['pass'], $result['summary']['warn'], $result['summary']['fail'] ) );
		}
		if ( 'fail' === $result['overall'] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * List registered REST routes. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--namespace=<namespace>]
	 * : Only routes in this exact namespace, e.g. wp/v2.
	 *
	 * [--format=<format>]
	 * : table|csv|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector routes --namespace=ai-site-connector/v1
	 */
	public function routes( $args, $assoc ) {
		$result = AI_Site_Connector_Diagnostics::rest_routes( array( 'namespace' => isset( $assoc['namespace'] ) ? (string) $assoc['namespace'] : '' ) );
		$format = self::format( $assoc, array( 'table', 'csv', 'json' ) );
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $result['routes'] as $r ) {
			$rows[] = array(
				'namespace'               => $r['namespace'],
				'route'                   => $r['route'],
				'methods'                 => implode( ',', $r['methods'] ),
				'args'                    => implode( ',', array_keys( (array) $r['args'] ) ),
				'has_permission_callback' => $r['has_permission_callback'] ? 'yes' : 'no',
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'namespace', 'route', 'methods', 'args', 'has_permission_callback' ) );
	}

	/**
	 * Detect page builders site-wide and optionally per post. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--post_ids=<ids>]
	 * : Comma-separated post IDs (max 100).
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector page-builder --post_ids=12,34 --format=json
	 */
	public function page_builder( $args, $assoc ) {
		$result = AI_Site_Connector_Diagnostics::page_builder( array( 'post_ids' => isset( $assoc['post_ids'] ) ? (string) $assoc['post_ids'] : '' ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 'json' === self::format( $assoc, array( 'table', 'json' ) ) ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $result['site']['detected'] as $builder => $on ) {
			$rows[] = array(
				'scope'   => 'site',
				'builder' => $builder,
				'present' => $on ? 'yes' : 'no',
			);
		}
		foreach ( isset( $result['per_post'] ) ? $result['per_post'] : array() as $post_id => $info ) {
			$rows[] = array(
				'scope'   => 'post:' . $post_id,
				'builder' => isset( $info['error'] ) ? $info['error'] : implode( ',', array_keys( $info['evidence'] ) ),
				'present' => isset( $info['error'] ) ? '-' : ( empty( $info['evidence'] ) ? 'no' : 'yes' ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'scope', 'builder', 'present' ) );
	}

	/**
	 * Export redirects from the active redirect plugin. Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : 1-1000. Default: 500.
	 *
	 * [--offset=<n>]
	 * : Default: 0.
	 *
	 * [--format=<format>]
	 * : table|csv|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector redirects --format=csv > redirects.csv
	 */
	public function redirects( $args, $assoc ) {
		$result = AI_Site_Connector_Diagnostics::redirects(
			array(
				'limit'  => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 500,
				'offset' => isset( $assoc['offset'] ) ? (int) $assoc['offset'] : 0,
			)
		);
		$format = self::format( $assoc, array( 'table', 'csv', 'json' ) );
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'plugin_detected=%s total=%d', $result['plugin_detected'], $result['total'] ) );
		}
		\WP_CLI\Utils\format_items( $format, $result['redirects'], array( 'id', 'source', 'target', 'status_code', 'match_type', 'enabled', 'plugin' ) );
	}

	/**
	 * Export the content inventory. Read-only.
	 *
	 * Runs as the --user given (posts that user cannot edit are omitted).
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Default: all admin-visible types except attachments.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated statuses or "any". Default: any.
	 *
	 * [--modified_after=<datetime>]
	 * : UTC date/time, exclusive.
	 *
	 * [--modified_before=<datetime>]
	 * : UTC date/time, exclusive.
	 *
	 * [--limit=<n>]
	 * : 1-500. Default: 100.
	 *
	 * [--offset=<n>]
	 * : Default: 0.
	 *
	 * [--all]
	 * : Page through every result (ignores --offset).
	 *
	 * [--format=<format>]
	 * : table|csv|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector content-inventory --user=admin --post_type=page --format=csv --all > pages.csv
	 */
	public function content_inventory( $args, $assoc ) {
		self::require_user_context();
		$format = self::format( $assoc, array( 'table', 'csv', 'json' ) );
		$query  = array(
			'post_type'       => isset( $assoc['post_type'] ) ? (string) $assoc['post_type'] : '',
			'status'          => isset( $assoc['status'] ) ? (string) $assoc['status'] : 'any',
			'modified_after'  => isset( $assoc['modified_after'] ) ? (string) $assoc['modified_after'] : '',
			'modified_before' => isset( $assoc['modified_before'] ) ? (string) $assoc['modified_before'] : '',
			'limit'           => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : AI_Site_Connector_Content_Inventory::DEFAULT_LIMIT,
			'offset'          => isset( $assoc['offset'] ) ? (int) $assoc['offset'] : 0,
		);
		$all    = \WP_CLI\Utils\get_flag_value( $assoc, 'all', false );
		if ( $all ) {
			$query['offset'] = 0;
		}

		$items  = array();
		$result = null;
		do {
			$result = AI_Site_Connector_Content_Inventory::query( $query );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$items           = array_merge( $items, $result['items'] );
			$query['offset'] = $result['next_offset'];
		} while ( $all && null !== $result['next_offset'] );

		if ( 'json' === $format ) {
			$result['items'] = $items;
			$result['count'] = count( $items );
			if ( $all ) {
				$result['offset']      = 0;
				$result['next_offset'] = null;
			}
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( 'csv' === $format ) {
			WP_CLI::log( rtrim( AI_Site_Connector_Content_Inventory::to_csv( $items, $result['seo_plugin'] ), "\r\n" ) );
			return;
		}
		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = array(
				'id'           => $item['id'],
				'post_type'    => $item['post_type'],
				'status'       => $item['status'],
				'slug'         => $item['slug'],
				'title'        => $item['title'],
				'modified_gmt' => $item['modified_gmt'],
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'post_type', 'status', 'slug', 'title', 'modified_gmt' ) );
		WP_CLI::log( sprintf( 'total=%d shown=%d omitted_forbidden=%d next_offset=%s', $result['total'], count( $items ), $result['omitted_forbidden'], null === $result['next_offset'] ? 'none' : $result['next_offset'] ) );
	}

	/**
	 * Audit media for SEO/hygiene issues. Read-only.
	 *
	 * Runs as the --user given (media that user cannot access is omitted).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : 1-500. Default: 100.
	 *
	 * [--offset=<n>]
	 * : Default: 0.
	 *
	 * [--mime=<mime>]
	 * : image|all. Default: image.
	 *
	 * [--all-items]
	 * : Include attachments without issues.
	 *
	 * [--format=<format>]
	 * : table|csv|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector media-audit --user=admin --format=csv > media-audit.csv
	 */
	public function media_audit( $args, $assoc ) {
		self::require_user_context();
		$format = self::format( $assoc, array( 'table', 'csv', 'json' ) );
		$result = AI_Site_Connector_Media_Audit::audit(
			array(
				'limit'       => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : AI_Site_Connector_Media_Audit::DEFAULT_LIMIT,
				'offset'      => isset( $assoc['offset'] ) ? (int) $assoc['offset'] : 0,
				'mime'        => isset( $assoc['mime'] ) ? (string) $assoc['mime'] : 'image',
				'only_issues' => ! \WP_CLI\Utils\get_flag_value( $assoc, 'all-items', false ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $result['items'] as $item ) {
			$rows[] = array(
				'attachment_id' => $item['attachment_id'],
				'filename'      => $item['filename'],
				'mime_type'     => $item['mime_type'],
				'width'         => $item['width'],
				'height'        => $item['height'],
				'file_size'     => $item['file_size'],
				'issues'        => implode( ',', $item['issues'] ),
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'attachment_id', 'filename', 'mime_type', 'width', 'height', 'file_size', 'issues' ) );
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'total=%d scanned=%d clean=%d next_offset=%s', $result['total'], $result['scanned'], $result['clean'], null === $result['next_offset'] ? 'none' : $result['next_offset'] ) );
		}
	}

	/**
	 * Find duplicate media by filename and content hash. Read-only; never deletes.
	 *
	 * ## OPTIONS
	 *
	 * [--max_scan=<n>]
	 * : Attachments to scan, 1-20000. Default: 5000.
	 *
	 * [--format=<format>]
	 * : table|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector media-duplicates --user=admin --format=json
	 */
	public function media_duplicates( $args, $assoc ) {
		self::require_user_context();
		$format = self::format( $assoc, array( 'table', 'json' ) );
		$scan_id = '';
		$calls   = 0;
		do {
			if ( ++$calls > 1000 ) {
				AI_Site_Connector_Media_Audit::abandon_scan( $scan_id );
				WP_CLI::error( 'Duplicate scan did not complete within 1000 calls.' );
			}
			$result = AI_Site_Connector_Media_Audit::duplicates(
				array(
					'scan_id'  => $scan_id,
					'max_scan' => isset( $assoc['max_scan'] ) ? (int) $assoc['max_scan'] : AI_Site_Connector_Media_Audit::DUP_DEFAULT_SCAN,
				)
			);
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$scan_id = $result['scan_id'];
		} while ( ! $result['complete'] );
		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $result['by_filename'] as $g ) {
			$rows[] = array(
				'kind'           => 'filename:' . $g['match'],
				'key'            => $g['filename'],
				'attachment_ids' => implode( ',', $g['attachment_ids'] ),
			);
		}
		foreach ( $result['by_hash'] as $g ) {
			$rows[] = array(
				'kind'           => 'sha256',
				'key'            => $g['sha256'],
				'attachment_ids' => implode( ',', $g['attachment_ids'] ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'kind', 'key', 'attachment_ids' ) );
		WP_CLI::log( sprintf( 'library-wide: scanned=%d hashed=%d unreadable=%d calls=%d', $result['scanned'], $result['hashed_files'], count( $result['unreadable'] ), $result['calls'] ) );
	}

	/**
	 * Scan content for broken internal links (offline; no HTTP). Read-only.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Default: all content types.
	 *
	 * [--status=<statuses>]
	 * : Statuses of posts to scan. Default: publish.
	 *
	 * [--limit=<n>]
	 * : Posts per page, 1-200. Default: 50.
	 *
	 * [--all]
	 * : Walk every page of posts.
	 *
	 * [--all-links]
	 * : Include ok/skipped links, not just broken/invalid ones.
	 *
	 * [--format=<format>]
	 * : table|csv|json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector broken-links --user=admin --all --format=csv > broken-links.csv
	 */
	public function broken_links( $args, $assoc ) {
		self::require_user_context();
		$format = self::format( $assoc, array( 'table', 'csv', 'json' ) );
		$query  = array(
			'post_type'   => isset( $assoc['post_type'] ) ? (string) $assoc['post_type'] : '',
			'status'      => isset( $assoc['status'] ) ? (string) $assoc['status'] : 'publish',
			'limit'       => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : AI_Site_Connector_Link_Scanner::DEFAULT_LIMIT,
			'offset'      => 0,
			'only_broken' => ! \WP_CLI\Utils\get_flag_value( $assoc, 'all-links', false ),
		);
		$all     = \WP_CLI\Utils\get_flag_value( $assoc, 'all', false );
		$items   = array();
		$summary = array_fill_keys( AI_Site_Connector_Link_Scanner::STATUSES, 0 );
		$totals  = array();
		$partial = array();
		$counted = array( 'posts_scanned', 'omitted_forbidden', 'links_examined', 'external_ignored', 'non_http_ignored' );
		do {
			$result = AI_Site_Connector_Link_Scanner::scan( $query );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			$items = array_merge( $items, $result['items'] );
			foreach ( $result['summary'] as $k => $v ) {
				$summary[ $k ] += $v;
			}
			foreach ( $counted as $k ) {
				$totals[ $k ] = ( isset( $totals[ $k ] ) ? $totals[ $k ] : 0 ) + (int) $result[ $k ];
			}
			$partial         = array_merge( $partial, $result['partial_posts'] );
			$query['offset'] = $result['next_offset'];
		} while ( $all && null !== $result['next_offset'] );

		if ( 'json' === $format ) {
			$result['items']         = $items;
			$result['summary']       = $summary;
			$result['partial_posts'] = $partial;
			if ( $all ) {
				// Aggregate counters across pages so they match the items.
				$result                = array_merge( $result, $totals );
				$result['offset']      = 0;
				$result['next_offset'] = null;
				$result['truncated']   = ! empty( $partial );
			}
			WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $items, array( 'source_post_id', 'source_post_type', 'url', 'link_text', 'status', 'reason', 'target_post_id' ) );
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( 'ok=%d broken=%d invalid=%d skipped=%d', $summary['ok'], $summary['broken'], $summary['invalid'], $summary['skipped'] ) );
		}
	}

	/**
	 * Build the deterministic manifest bundle. Read-only on the site.
	 *
	 * With --dir, writes the eight manifest files plus manifest-index.json
	 * into that local directory (created if missing; existing files with the
	 * same names are overwritten) — ready to commit from your own checkout.
	 * WordPress never commits or pushes anything.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<path>]
	 * : Directory to write the manifest files into.
	 *
	 * [--max_items=<n>]
	 * : Items per paginated section, 1-5000. Default: 1000.
	 *
	 * [--sections=<files>]
	 * : Comma-separated manifest file names. Default: all.
	 *
	 * [--format=<format>]
	 * : json (print the full bundle) when --dir is not given. Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector export --user=admin --dir=./site-manifests
	 *   wp ai-connector export --user=admin --sections=redirects.json --format=json
	 */
	public function export( $args, $assoc ) {
		self::format( $assoc, array( 'json' ) );
		self::require_admin_context();
		$bundle = AI_Site_Connector_Export_Bundle::build(
			array(
				'max_items' => isset( $assoc['max_items'] ) ? (int) $assoc['max_items'] : AI_Site_Connector_Export_Bundle::DEFAULT_MAX_ITEMS,
				'sections'  => isset( $assoc['sections'] ) ? (string) $assoc['sections'] : '',
			)
		);
		if ( is_wp_error( $bundle ) ) {
			WP_CLI::error( $bundle->get_error_message() );
		}
		$bundle['manifest_index'] = AI_Site_Connector_Export_Bundle::index_document( $bundle );
		$failed                   = array();
		foreach ( $bundle['index'] as $name => $info ) {
			if ( empty( $info['ok'] ) ) {
				$failed[ $name ] = isset( $info['error'] ) ? $info['error'] : 'unknown error';
			}
		}

		if ( empty( $assoc['dir'] ) ) {
			WP_CLI::log( wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $failed ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		$dir = rtrim( (string) $assoc['dir'], '/\\' );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			WP_CLI::error( sprintf( 'Could not create directory %s.', $dir ) );
		}

		// Stage every file first; only when all writes succeed are they moved
		// into place, so a failed run never leaves a mix of old and new.
		$stage = $dir . '/.ai-connector-export-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $stage ) ) {
			WP_CLI::error( sprintf( 'Could not create staging directory in %s.', $dir ) );
		}
		$contents = array();
		foreach ( $bundle['files'] as $name => $data ) {
			if ( in_array( $name, AI_Site_Connector_Export_Bundle::FILES, true ) ) {
				$contents[ $name ] = AI_Site_Connector_Export_Bundle::encode( $data );
			}
		}
		$contents['manifest-index.json'] = AI_Site_Connector_Export_Bundle::encode( $bundle['manifest_index'] );
		foreach ( $contents as $name => $body ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( strlen( $body ) !== file_put_contents( $stage . '/' . $name, $body ) ) {
				self::remove_dir( $stage );
				WP_CLI::error( sprintf( 'Could not write %s; nothing in %s was changed.', $name, $dir ) );
			}
		}
		foreach ( array_merge( AI_Site_Connector_Export_Bundle::FILES, array( 'manifest-index.json' ) ) as $name ) {
			$target = $dir . '/' . $name;
			if ( isset( $contents[ $name ] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				if ( ! rename( $stage . '/' . $name, $target ) ) {
					self::remove_dir( $stage );
					WP_CLI::error( sprintf( 'Could not move %s into %s.', $name, $dir ) );
				}
			} elseif ( file_exists( $target ) ) {
				// A manifest not produced by this run (failed or not selected)
				// is removed so the directory matches manifest-index.json.
				wp_delete_file( $target );
			}
		}
		self::remove_dir( $stage );

		foreach ( $bundle['index'] as $name => $info ) {
			if ( ! empty( $info['ok'] ) && empty( $info['complete'] ) ) {
				WP_CLI::warning( sprintf( '%s is incomplete: %s.', $name, implode( ', ', $info['limitations'] ) ) );
			}
		}
		if ( $failed ) {
			foreach ( $failed as $name => $error ) {
				WP_CLI::warning( sprintf( '%s failed: %s', $name, $error ) );
			}
			WP_CLI::error( sprintf( '%d section(s) failed; see manifest-index.json in %s.', count( $failed ), $dir ) );
		}
		WP_CLI::success( sprintf( 'Wrote %d manifest files + manifest-index.json to %s.', count( $contents ) - 1, $dir ) );
	}

	private static function remove_dir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			wp_delete_file( $f );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}

	/**
	 * Disable AI access through this plugin site-wide.
	 *
	 * Every route in the plugin's REST namespace except /health, including
	 * the MCP endpoint, returns 503 until re-enabled. Application Passwords
	 * are not revoked (use revoke-password for that). Requires an
	 * administrator --user; asks for confirmation unless --yes.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector disable --user=admin
	 */
	public function disable( $args, $assoc ) {
		self::require_admin_context();
		WP_CLI::confirm( 'Disable AI Site Connector for all AI clients on this site?', $assoc );
		if ( ! AI_Site_Connector_Permissions::set_disabled( true, 'wp-cli' ) ) {
			WP_CLI::success( 'AI Site Connector was already disabled.' );
			return;
		}
		WP_CLI::success( sprintf( 'AI Site Connector routes and MCP disabled on %s. Application Passwords still authenticate to core /wp/v2 — run `wp ai-connector revoke-password` to cut access completely. `wp ai-connector enable` restores the plugin routes.', get_site_url() ) );
	}

	/**
	 * Re-enable AI access through this plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector enable --user=admin
	 */
	public function enable( $args, $assoc ) {
		self::require_admin_context();
		WP_CLI::confirm( 'Re-enable AI Site Connector for AI clients on this site?', $assoc );
		if ( ! AI_Site_Connector_Permissions::set_disabled( false, 'wp-cli' ) ) {
			WP_CLI::success( 'AI Site Connector was already enabled.' );
			return;
		}
		WP_CLI::success( sprintf( 'AI Site Connector enabled on %s.', get_site_url() ) );
	}

	/**
	 * Safely update one post: the same service as REST /content/update and
	 * MCP wp_update_content (#108).
	 *
	 * Dry run by default: prints the before/after plan and writes nothing.
	 * --apply writes, which also needs the write_content tool permission
	 * (update_seo for SEO fields) and is refused in read-only mode or while
	 * the site-wide switch is off. A snapshot is stored first; keep the
	 * printed snapshot_id for `wp ai-connector rollback-content`.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID.
	 *
	 * [--title=<title>]
	 * : New title.
	 *
	 * [--excerpt=<excerpt>]
	 * : New excerpt.
	 *
	 * [--content=<content>]
	 * : New post content (HTML or block markup).
	 *
	 * [--content-file=<path>]
	 * : Read the new content from a file; "-" reads STDIN.
	 *
	 * [--slug=<slug>]
	 * : New slug.
	 *
	 * [--status=<status>]
	 * : draft, pending, publish or private.
	 *
	 * [--featured-image=<id>]
	 * : Attachment ID; 0 removes the featured image.
	 *
	 * [--terms=<json>]
	 * : JSON object of taxonomy => list of existing term IDs or slugs.
	 *
	 * [--seo=<json>]
	 * : JSON object of SEO fields (title, description, canonical, og_title, ...).
	 *
	 * [--expected-modified-gmt=<datetime>]
	 * : Refuse if the post was modified after this UTC time.
	 *
	 * [--apply]
	 * : Write the change. Without it nothing is written.
	 *
	 * [--text-diff]
	 * : With a dry run, include a bounded unified diff of the content change.
	 *
	 * [--format=<format>]
	 * : json|yaml. Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector update-content 42 --user=editor --title="New title"
	 *   wp ai-connector update-content 42 --user=editor --content-file=body.html --apply
	 */
	public function update_content( $args, $assoc ) {
		self::require_user_context();
		$changes = array();
		foreach ( array( 'title', 'excerpt', 'content', 'slug', 'status' ) as $field ) {
			if ( isset( $assoc[ $field ] ) ) {
				$changes[ $field ] = (string) $assoc[ $field ];
			}
		}
		if ( isset( $assoc['content-file'] ) ) {
			if ( isset( $changes['content'] ) ) {
				WP_CLI::error( 'Use either --content or --content-file, not both.' );
			}
			$path = (string) $assoc['content-file'];
			$body = '-' === $path ? file_get_contents( 'php://stdin' ) : ( is_readable( $path ) ? file_get_contents( $path ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file or STDIN chosen by the operator.
			if ( false === $body ) {
				WP_CLI::error( sprintf( 'Cannot read --content-file %s.', $path ) );
			}
			$changes['content'] = $body;
		}
		if ( isset( $assoc['featured-image'] ) ) {
			$changes['featured_image'] = (int) $assoc['featured-image'];
		}
		foreach ( array( 'terms', 'seo' ) as $field ) {
			if ( isset( $assoc[ $field ] ) ) {
				$decoded = json_decode( (string) $assoc[ $field ], true );
				if ( ! is_array( $decoded ) ) {
					WP_CLI::error( sprintf( '--%s must be a JSON object.', $field ) );
				}
				$changes[ $field ] = $decoded;
			}
		}
		if ( empty( $changes ) ) {
			WP_CLI::error( 'Nothing to change: pass at least one field, e.g. --title.' );
		}
		$format = self::format( $assoc, array( 'json', 'yaml' ) );
		$result = AI_Site_Connector_Content_Update::update(
			isset( $args[0] ) ? (int) $args[0] : 0,
			$changes,
			array(
				'dry_run'               => empty( $assoc['apply'] ),
				'expected_modified_gmt' => isset( $assoc['expected-modified-gmt'] ) ? (string) $assoc['expected-modified-gmt'] : '',
				'text_diff'             => ! empty( $assoc['text-diff'] ),
			)
		);
		self::print_content_result( $result, $format );
	}

	/**
	 * Roll back an update made with update-content, REST or MCP, by its
	 * snapshot_id (#108). Dry run by default. Restores only the fields the
	 * update touched and refuses (reason: conflict) if any of them changed
	 * afterwards.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Post ID.
	 *
	 * <snapshot_id>
	 * : snapshot_id returned by the update.
	 *
	 * [--apply]
	 * : Restore. Without it nothing is written.
	 *
	 * [--format=<format>]
	 * : json|yaml. Default: json.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ai-connector rollback-content 42 <snapshot_id> --user=editor --apply
	 */
	public function rollback_content( $args, $assoc ) {
		self::require_user_context();
		$format = self::format( $assoc, array( 'json', 'yaml' ) );
		$result = AI_Site_Connector_Content_Update::rollback( (int) $args[0], (string) $args[1], empty( $assoc['apply'] ) );
		self::print_content_result( $result, $format );
	}

	/**
	 * Explain whether a user, optionally through one of their Application
	 * Passwords, could run an operation, and which check would refuse it
	 * (#167): Credentials → Effective access preview (#124) on the command
	 * line. Read-only: nothing is minted, sent, written or run, and no
	 * password is printed.
	 *
	 * Exit code: 0 allowed, 1 denied, 2 depends on the request or cannot be
	 * decided here.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User to evaluate: ID, login or email.
	 *
	 * --operation=<operation>
	 * : mcp:<tool> (e.g. mcp:wp_update_content), rest:<tool> (e.g.
	 * rest:export_page_content) or custom_route with --method and --route.
	 *
	 * [--uuid=<uuid>]
	 * : Application Password UUID. Omit to evaluate the role alone.
	 *
	 * [--method=<method>]
	 * : HTTP method for custom_route.
	 * ---
	 * default: GET
	 * ---
	 *
	 * [--route=<route>]
	 * : REST route for custom_route, e.g. /wp/v2/posts/12.
	 *
	 * [--post=<id>]
	 * : Post ID for post-level checks.
	 *
	 * [--dry-run]
	 * : Preview a dry run of a content update or rollback.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-connector access-preview ai-agent --operation=mcp:wp_update_content --post=12 --user=admin
	 *     wp ai-connector access-preview 5 --uuid=<uuid> --operation=custom_route --method=POST --route=/wp/v2/posts/12 --format=json --user=admin
	 */
	public function access_preview( $args, $assoc ) {
		self::require_admin_context();
		$format = self::format( $assoc, array( 'table', 'json', 'yaml' ) );
		$ref    = (string) $args[0];
		if ( ctype_digit( $ref ) ) {
			$user = get_user_by( 'id', (int) $ref );
		} else {
			$user = is_email( $ref ) ? get_user_by( 'email', $ref ) : get_user_by( 'login', $ref );
		}
		if ( ! $user ) {
			WP_CLI::error( sprintf( 'User not found: %s', $ref ) );
		}
		$result = AI_Site_Connector_Access_Preview::explain(
			array(
				'user_id'   => $user->ID,
				'uuid'      => isset( $assoc['uuid'] ) ? (string) $assoc['uuid'] : '',
				'operation' => isset( $assoc['operation'] ) ? (string) $assoc['operation'] : '',
				'method'    => isset( $assoc['method'] ) ? (string) $assoc['method'] : 'GET',
				'route'     => isset( $assoc['route'] ) ? (string) $assoc['route'] : '',
				'post_id'   => isset( $assoc['post'] ) ? (int) $assoc['post'] : 0,
				'dry_run'   => (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$hint = 'asc_preview_operation' === $result->get_error_code()
				? ' Operations: ' . implode( ', ', array_keys( AI_Site_Connector_Access_Preview::operations() ) ) . ', ' . AI_Site_Connector_Access_Preview::CUSTOM . '.'
				: '';
			WP_CLI::error( $result->get_error_message() . $hint );
		}
		if ( 'table' === $format ) {
			WP_CLI::log( sprintf( '%s: %s, %s', strtoupper( $result['verdict'] ), $result['user'], $result['operation'] ) );
			foreach ( $result['denials'] as $denial ) {
				WP_CLI::log( '  refused by ' . $denial );
			}
			$rows = array();
			foreach ( $result['checks'] as $check ) {
				$rows[] = array(
					'area'   => $check['group'],
					'check'  => $check['label'],
					'result' => $check['status'],
					'why'    => $check['detail'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'area', 'check', 'result', 'why' ) );
		} else {
			WP_CLI::print_value( $result, array( 'format' => $format ) );
		}
		$codes = array(
			'allowed'     => 0,
			'denied'      => 1,
			'conditional' => 2,
		);
		WP_CLI::halt( $codes[ $result['verdict'] ] );
	}

	/**
	 * Print a content update/rollback result, or exit 1 with the error code,
	 * message and any detail (e.g. which fields were refused).
	 *
	 * @param array|WP_Error $result Service result.
	 * @param string         $format json|yaml.
	 */
	private static function print_content_result( $result, $format ) {
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$detail = is_array( $data ) ? array_diff_key( $data, array( 'status' => true ) ) : array();
			WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() . ( $detail ? ' ' . wp_json_encode( $detail ) : '' ) );
		}
		WP_CLI::print_value( $result, array( 'format' => $format ) );
	}

	/**
	 * Per-user results need a user: without --user, WordPress runs as
	 * nobody and these commands would print an empty, plausible-looking result.
	 */
	private static function require_user_context() {
		if ( ! get_current_user_id() ) {
			WP_CLI::error( 'Run with --user=<login>: results are limited to what that user may see.' );
		}
	}

	/**
	 * Commands that act site-wide must run as an administrator (--user=<admin>).
	 */
	private static function require_admin_context() {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run this as an administrator, e.g. --user=admin.' );
		}
	}

	/**
	 * Validate --format against an allow-list.
	 */
	private static function format( $assoc, array $allowed ) {
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : $allowed[0];
		if ( ! in_array( $format, $allowed, true ) ) {
			WP_CLI::error( sprintf( 'Unsupported --format=%s. Use one of: %s.', $format, implode( ', ', $allowed ) ) );
		}
		return $format;
	}
}
