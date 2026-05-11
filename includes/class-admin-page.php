<?php
/**
 * Tools → AI Site Connector admin page.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Admin_Page {

	const PAGE_SLUG    = 'ai-site-connector';
	const NONCE_ACTION = 'ai_site_connector_action';
	const NONCE_FIELD  = 'ai_site_connector_nonce';
	const FLASH_OPTION = 'ai_site_connector_flash';

	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_ai_site_connector_create_user', array( __CLASS__, 'handle_create_user' ) );
		add_action( 'admin_post_ai_site_connector_generate_password', array( __CLASS__, 'handle_generate_password' ) );
		add_action( 'admin_post_ai_site_connector_revoke_password', array( __CLASS__, 'handle_revoke_password' ) );
		add_action( 'admin_post_ai_site_connector_rotate_password', array( __CLASS__, 'handle_rotate_password' ) );
		add_action( 'admin_post_ai_site_connector_test_rest', array( __CLASS__, 'handle_test_rest' ) );
		add_action( 'admin_post_ai_site_connector_prune_log', array( __CLASS__, 'handle_prune_log' ) );
		add_action( 'admin_post_ai_site_connector_save_uninstall_pref', array( __CLASS__, 'handle_save_uninstall_pref' ) );
		add_action( 'admin_post_ai_site_connector_export_write', array( __CLASS__, 'handle_export_write' ) );
		add_action( 'admin_post_ai_site_connector_export_audit_csv', array( __CLASS__, 'handle_export_audit_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Public alias for {@see flash()}. Lets sister classes (Permissions,
	 * Cache, Export) post a flash message without duplicating the
	 * transient handling.
	 */
	public static function flash_public( $msg, $type = 'success', $extra = array() ) {
		self::flash( $msg, $type, $extra );
	}

	/** Public alias for {@see redirect_back()}. */
	public static function redirect_back_public( $tab = 'overview' ) {
		self::redirect_back( $tab );
	}

	public static function register_menu() {
		add_management_page(
			__( 'AI Site Connector', 'ai-site-connector' ),
			__( 'AI Site Connector', 'ai-site-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ai-site-connector-admin', AI_SITE_CONNECTOR_URL . 'assets/admin.css', array(), AI_SITE_CONNECTOR_VERSION );
		wp_enqueue_script( 'ai-site-connector-admin', AI_SITE_CONNECTOR_URL . 'assets/admin.js', array(), AI_SITE_CONNECTOR_VERSION, true );
	}

	private static function flash( $msg, $type = 'success', $extra = array() ) {
		set_transient(
			self::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => (string) $msg,
				'type'  => (string) $type,
				'extra' => $extra,
			),
			60
		);
	}

	private static function consume_flash() {
		$key  = self::FLASH_OPTION . '_' . get_current_user_id();
		$data = get_transient( $key );
		if ( $data ) {
			delete_transient( $key );
			return $data;
		}
		return null;
	}

	private static function redirect_back( $tab = 'overview' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => sanitize_key( $tab ),
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	const ADMIN_CONFIRM_PHRASE = 'I UNDERSTAND THIS GRANTS FULL SITE ACCESS';

	public static function handle_create_user() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$username = isset( $_POST['ai_username'] ) ? sanitize_user( wp_unslash( $_POST['ai_username'] ), true ) : '';
		$email    = isset( $_POST['ai_email'] ) ? sanitize_email( wp_unslash( $_POST['ai_email'] ) ) : '';
		$role     = isset( $_POST['ai_role'] ) ? sanitize_key( wp_unslash( $_POST['ai_role'] ) ) : AI_SITE_CONNECTOR_OPERATOR_ROLE;
		$display  = isset( $_POST['ai_display'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_display'] ) ) : 'AI Agent';
		$confirm  = isset( $_POST['ai_admin_confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ai_admin_confirm'] ) ) ) : '';

		if ( 'administrator' === $role && self::ADMIN_CONFIRM_PHRASE !== $confirm ) {
			self::flash(
				sprintf(
					/* translators: %s: required confirmation phrase */
					__( 'Administrator role refused. Type the exact phrase "%s" in the confirmation field to proceed.', 'ai-site-connector' ),
					self::ADMIN_CONFIRM_PHRASE
				),
				'error'
			);
			AI_Site_Connector_Audit_Log::record(
				'ai_user_admin_refused',
				array(
					'message' => sprintf( 'Refused to create AI user with Administrator role for username "%s" — typed confirmation missing or wrong.', $username ),
				)
			);
			self::redirect_back( 'wizard' );
		}

		$result = AI_Site_Connector_User_Manager::create_user(
			array(
				'username' => $username,
				'email'    => $email,
				'role'     => $role,
				'display'  => $display,
			)
		);
		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message(), 'error' );
		} else {
			$message = sprintf(
				/* translators: 1: AI username, 2: WordPress user ID. */
				__( 'AI user "%1$s" (id=%2$d) created.', 'ai-site-connector' ),
				$username,
				$result
			);
			self::flash( $message, 'success' );
		}
		self::redirect_back( 'wizard' );
	}

	public static function handle_generate_password() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$user_id  = isset( $_POST['ai_user_id'] ) ? (int) $_POST['ai_user_id'] : 0;
		$app_name = isset( $_POST['ai_app_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_app_name'] ) ) : AI_Site_Connector_Application_Passwords::suggested_name();

		// Optional extras: scopes (checkbox tree), IP allowlist (textarea),
		// expiry (datetime-local input). Parsed before mint so we can refuse
		// past-expiry values without creating an unusable credential.
		$scopes_raw     = isset( $_POST['ai_scopes'] ) ? wp_unslash( $_POST['ai_scopes'] ) : array();
		$ip_raw         = isset( $_POST['ai_ip_allowlist'] ) ? (string) wp_unslash( $_POST['ai_ip_allowlist'] ) : '';
		$expires_raw    = isset( $_POST['ai_expires_at'] ) ? trim( (string) wp_unslash( $_POST['ai_expires_at'] ) ) : '';
		$scopes_parsed  = self::parse_scopes_input( is_array( $scopes_raw ) ? $scopes_raw : array() );
		$ip_parsed      = self::parse_ip_allowlist_input( $ip_raw );
		$expires_parsed = self::parse_expires_input( $expires_raw );
		if ( is_wp_error( $expires_parsed ) ) {
			self::flash( $expires_parsed->get_error_message(), 'error' );
			self::redirect_back( 'credentials' );
		}

		$res = AI_Site_Connector_Application_Passwords::create_for_user( $user_id, $app_name );
		if ( is_wp_error( $res ) ) {
			self::flash( $res->get_error_message(), 'error' );
			self::redirect_back( 'credentials' );
		}

		// Persist extras now that we have the new UUID.
		if ( class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			$extras = array( 'created_by' => get_current_user_id() );
			if ( ! empty( $scopes_parsed ) ) {
				$extras['scopes'] = $scopes_parsed;
			}
			if ( ! empty( $ip_parsed ) ) {
				$extras['ip_allowlist'] = $ip_parsed;
			}
			if ( null !== $expires_parsed ) {
				$extras['expires_at'] = $expires_parsed;
			}
			if ( count( $extras ) > 1 ) { // more than just created_by
				AI_Site_Connector_App_Password_Meta::set_extras( $user_id, $res['uuid'], $extras );
			}
		}

		$pack      = self::build_connection_pack( $user_id, $res );
		$preflight = self::run_preflight_check( $pack );

		// Mint a one-time download token so the admin can DM a single-use
		// URL instead of pasting the pack JSON into a chat.
		$download_url = null;
		if ( class_exists( 'AI_Site_Connector_Connection_Pack_Token' ) ) {
			$token        = AI_Site_Connector_Connection_Pack_Token::mint( $user_id, $res['uuid'], $pack );
			$download_url = AI_Site_Connector_Connection_Pack_Token::build_url( $token );
		}

		self::flash(
			__( 'Application Password generated. Copy it now — it will not be shown again.', 'ai-site-connector' ),
			'success',
			array(
				'connection_pack' => $pack,
				'preflight'       => $preflight,
				'download_url'    => $download_url,
				'download_ttl'    => AI_Site_Connector_Connection_Pack_Token::TTL_SECONDS,
			)
		);

		AI_Site_Connector_Audit_Log::record(
			'connection_pack_generated',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'Connection pack generated for user id %d.', $user_id ),
			)
		);

		if ( is_array( $preflight ) ) {
			AI_Site_Connector_Audit_Log::record(
				'pass' === $preflight['status'] ? 'pre_flight_passed' : 'pre_flight_failed',
				array(
					'target_user_id' => (int) $user_id,
					'message'        => sprintf(
						/* translators: 1: status, 2: HTTP code, 3: detail. */
						__( 'Pre-flight %1$s (code %2$s): %3$s', 'ai-site-connector' ),
						$preflight['status'],
						(string) $preflight['code'],
						$preflight['hint']
					),
				)
			);
		}

		self::redirect_back( 'credentials' );
	}

	/**
	 * Default scope presets shown as checkboxes on the Credentials form.
	 * Mirrors the AI Site Operator role's natural capability surface so the
	 * defaults are sensible for the typical AI agent. Filterable.
	 *
	 * @return array<int, array{method:string, route:string, label:string}>
	 */
	private static function scope_presets() {
		$presets = array(
			array( 'method' => 'GET',  'route' => '/wp/v2/posts',     'label' => __( 'Read posts', 'ai-site-connector' ) ),
			array( 'method' => 'POST', 'route' => '/wp/v2/posts',     'label' => __( 'Create/update posts', 'ai-site-connector' ) ),
			array( 'method' => 'GET',  'route' => '/wp/v2/pages',     'label' => __( 'Read pages', 'ai-site-connector' ) ),
			array( 'method' => 'POST', 'route' => '/wp/v2/pages',     'label' => __( 'Create/update pages', 'ai-site-connector' ) ),
			array( 'method' => 'GET',  'route' => '/wp/v2/media',     'label' => __( 'Read media library', 'ai-site-connector' ) ),
			array( 'method' => 'POST', 'route' => '/wp/v2/media',     'label' => __( 'Upload media', 'ai-site-connector' ) ),
			array( 'method' => 'GET',  'route' => '/wp/v2/users/me',  'label' => __( 'Read self', 'ai-site-connector' ) ),
			array( 'method' => '*',    'route' => '/ai-site-connector/v1/*', 'label' => __( 'All plugin routes', 'ai-site-connector' ) ),
		);
		/**
		 * Filter the scope presets shown on the Credentials tab.
		 *
		 * @param array $presets
		 */
		return (array) apply_filters( 'ai_site_connector_scope_presets', $presets );
	}

	/**
	 * Parse the scope checkbox/textarea input from the Credentials form.
	 *
	 * Accepts either an array of strings shaped like "METHOD:/route" (from
	 * checkboxes) OR a JSON blob (from the advanced textarea).
	 *
	 * @param array|string $raw
	 * @return array Array of { method, route } scope entries; empty = no restriction.
	 */
	private static function parse_scopes_input( $raw ) {
		$out = array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
			}
		}
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $item ) {
			if ( is_array( $item ) && isset( $item['route'] ) ) {
				$out[] = array(
					'method' => isset( $item['method'] ) ? strtoupper( sanitize_text_field( $item['method'] ) ) : '*',
					'route'  => '/' . ltrim( sanitize_text_field( $item['route'] ), '/' ),
				);
				continue;
			}
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			if ( false !== strpos( $item, ':' ) ) {
				list( $method, $route ) = explode( ':', $item, 2 );
				$out[] = array(
					'method' => strtoupper( sanitize_text_field( $method ) ),
					'route'  => '/' . ltrim( sanitize_text_field( $route ), '/' ),
				);
			} else {
				$out[] = array(
					'method' => '*',
					'route'  => '/' . ltrim( sanitize_text_field( $item ), '/' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Parse a textarea of CIDR ranges (one per line). Returns array of valid CIDRs.
	 */
	private static function parse_ip_allowlist_input( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Parse the datetime-local input. Returns:
	 *   - null     if blank (no expiry — current behavior)
	 *   - int      Unix timestamp if valid
	 *   - WP_Error if invalid or in the past
	 */
	private static function parse_expires_input( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return new WP_Error( 'invalid_expires', __( 'Expiration date is not a valid date/time.', 'ai-site-connector' ) );
		}
		if ( $ts <= time() ) {
			return new WP_Error( 'invalid_expires', __( 'Expiration date must be in the future.', 'ai-site-connector' ) );
		}
		return $ts;
	}

	/**
	 * Run a server-side authentication probe with freshly-minted credentials.
	 *
	 * Hits /wp/v2/users/me using the new Application Password and reports
	 * whether the host accepted it. Catches "host strips Authorization
	 * header", "REST disabled", "WAF blocks Basic Auth" before the operator
	 * pastes the pack into an AI tool.
	 *
	 * @param array $pack Connection pack just generated.
	 * @return array|null {status:'pass'|'fail'|'skipped', code:int|string, hint:string}
	 */
	private static function run_preflight_check( array $pack ) {
		/**
		 * Filter to skip the pre-flight loopback check. Useful for hosts
		 * where the WP install cannot make HTTP requests to itself.
		 *
		 * @param bool  $skip Default false.
		 * @param array $pack Connection pack.
		 */
		if ( apply_filters( 'ai_site_connector_skip_preflight', false, $pack ) ) {
			return array(
				'status' => 'skipped',
				'code'   => 'filter',
				'hint'   => __( 'Pre-flight check skipped by ai_site_connector_skip_preflight filter.', 'ai-site-connector' ),
			);
		}

		$user = isset( $pack['username'] ) ? (string) $pack['username'] : '';
		$pass = isset( $pack['application_password'] ) ? (string) $pack['application_password'] : '';
		if ( '' === $user || '' === $pass ) {
			return null;
		}

		$response = wp_remote_get(
			rest_url( 'wp/v2/users/me' ),
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 'fail',
				'code'   => $response->get_error_code(),
				'hint'   => sprintf(
					/* translators: %s: WP error message. */
					__( 'Could not reach REST API: %s', 'ai-site-connector' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array(
				'status' => 'pass',
				'code'   => 200,
				'hint'   => __( 'REST API accepts the new Application Password.', 'ai-site-connector' ),
			);
		}

		$hints = array(
			401 => __( 'The most common cause is your host stripping the Authorization header (some shared hosts and security plugins do this). See SECURITY.md and scripts/diagnose-hosting-auth.sh for fixes.', 'ai-site-connector' ),
			403 => __( 'The user may lack the required REST capability, or a security plugin / WAF is blocking REST access.', 'ai-site-connector' ),
			404 => __( 'The REST API may be disabled, or pretty permalinks are off. Settings → Permalinks → save without changes.', 'ai-site-connector' ),
			500 => __( 'WordPress returned a server error. Check the PHP error log.', 'ai-site-connector' ),
		);
		$hint = isset( $hints[ $code ] ) ? $hints[ $code ] : __( 'Unexpected response. Review the server log and security plugin settings.', 'ai-site-connector' );

		return array(
			'status' => 'fail',
			'code'   => $code,
			'hint'   => $hint,
		);
	}

	public static function handle_revoke_password() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$user_id = isset( $_POST['ai_user_id'] ) ? (int) $_POST['ai_user_id'] : 0;
		$uuid    = isset( $_POST['ai_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_uuid'] ) ) : '';

		$res = AI_Site_Connector_Application_Passwords::revoke( $user_id, $uuid );
		if ( is_wp_error( $res ) ) {
			self::flash( $res->get_error_message(), 'error' );
		} else {
			self::flash( __( 'Application Password revoked.', 'ai-site-connector' ), 'success' );
		}
		self::redirect_back( 'credentials' );
	}

	public static function handle_rotate_password() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$user_id = isset( $_POST['ai_user_id'] ) ? (int) $_POST['ai_user_id'] : 0;
		$uuid    = isset( $_POST['ai_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_uuid'] ) ) : '';

		$res = AI_Site_Connector_Application_Passwords::rotate( $user_id, $uuid );
		if ( is_wp_error( $res ) ) {
			self::flash( $res->get_error_message(), 'error' );
			self::redirect_back( 'credentials' );
		}

		// Re-emit a full connection pack for the new password so the operator
		// sees the same format-picker UI they got on initial creation.
		$pack = self::build_connection_pack( $user_id, $res );
		self::flash(
			sprintf(
				/* translators: %s: new UUID. */
				__( 'Application Password rotated. New UUID: %s. Copy the new password now — it will not be shown again.', 'ai-site-connector' ),
				$res['uuid']
			),
			'success',
			array(
				'connection_pack' => $pack,
			)
		);
		self::redirect_back( 'credentials' );
	}

	public static function handle_save_uninstall_pref() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$wipe = ! empty( $_POST['ai_wipe_on_uninstall'] );
		update_option( 'ai_site_connector_wipe_on_uninstall', $wipe ? 1 : 0 );

		AI_Site_Connector_Audit_Log::record(
			'uninstall_preference_changed',
			array(
				'message' => $wipe
					? 'Operator opted IN: uninstall will drop the audit log table, remove the operator role, and delete plugin options.'
					: 'Operator opted OUT: uninstall will preserve all plugin data (default).',
			)
		);

		self::flash(
			$wipe
				? __( 'Saved: uninstall will wipe plugin data (audit table + role + options). AI user and Application Passwords are still preserved.', 'ai-site-connector' )
				: __( 'Saved: uninstall will preserve all plugin data (default).', 'ai-site-connector' ),
			'success'
		);
		self::redirect_back( 'audit' );
	}

	public static function handle_prune_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$deleted = AI_Site_Connector_Audit_Log::prune();
		if ( false === $deleted ) {
			self::flash( __( 'Audit log prune failed. See server error log for details.', 'ai-site-connector' ), 'error' );
		} else {
			self::flash(
				sprintf(
					/* translators: %d: number of rows deleted */
					_n( 'Pruned %d audit log row.', 'Pruned %d audit log rows.', (int) $deleted, 'ai-site-connector' ),
					(int) $deleted
				),
				'success'
			);
		}
		self::redirect_back( 'audit' );
	}

	public static function handle_test_rest() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// Internal REST dispatch — runs in-process with the current admin's
		// capabilities. No HTTP loopback, no $_COOKIE forwarding, no WAF/SSL surprises.
		$req = new WP_REST_Request( 'GET', '/wp/v2/users/me' );
		$res = rest_do_request( $req );

		if ( is_wp_error( $res ) ) {
			self::flash( __( 'REST test failed: ', 'ai-site-connector' ) . $res->get_error_message(), 'error' );
		} else {
			$code = $res->get_status();
			$msg  = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'REST test (internal dispatch) responded with HTTP %d.', 'ai-site-connector' ),
				$code
			);
			self::flash( $msg, $code < 400 ? 'success' : 'error' );
		}
		self::redirect_back( 'overview' );
	}

	private static function build_connection_pack( $user_id, $cred ) {
		$user = get_userdata( (int) $user_id );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return array(
			'site_name'           => get_bloginfo( 'name' ),
			'site_url'            => home_url(),
			'rest_api_base'       => trailingslashit( rest_url() ),
			'auth_method'         => 'basic_auth_application_password',
			'username'            => $user ? $user->user_login : '',
			'application_password' => isset( $cred['password'] ) ? $cred['password'] : '',
			'app_password_uuid'   => isset( $cred['uuid'] ) ? $cred['uuid'] : '',
			'app_password_name'   => isset( $cred['name'] ) ? $cred['name'] : '',
			'test_endpoint'       => trailingslashit( rest_url() ) . 'wp/v2/users/me',
			'plugin_health_endpoint' => trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health',
			'site_host'           => $host,
			'generated_at'        => gmdate( 'c' ),
			'notes'               => 'Use HTTP Basic Auth: header "Authorization: Basic base64(username:application_password)". Save in a password manager — this password is shown only once.',
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only tab routing; no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$flash = self::consume_flash();
		$tabs  = array();
		// Onboarding tab is only present until the operator dismisses it.
		if ( class_exists( 'AI_Site_Connector_Onboarding' ) && ! AI_Site_Connector_Onboarding::is_completed() ) {
			$tabs['onboarding'] = __( 'Get Started', 'ai-site-connector' );
		}
		$tabs['overview']    = __( 'Overview', 'ai-site-connector' );
		$tabs['connection']  = __( 'Connection Test', 'ai-site-connector' );
		$tabs['wizard']      = __( 'Setup Wizard', 'ai-site-connector' );
		$tabs['credentials'] = __( 'Credentials', 'ai-site-connector' );
		$tabs['permissions'] = __( 'Permissions', 'ai-site-connector' );
		$tabs['audit']       = __( 'Audit Log', 'ai-site-connector' );
		$tabs['api']         = __( 'API Explorer', 'ai-site-connector' );
		$tabs['diagnostics'] = __( 'Diagnostics', 'ai-site-connector' );
		$tabs['export']      = __( 'Export', 'ai-site-connector' );
		$tabs['docs']        = __( 'Docs', 'ai-site-connector' );
		?>
		<div class="wrap ai-site-connector-wrap">
			<div class="asc-page-header">
				<img class="asc-page-logo" src="<?php echo esc_url( AI_SITE_CONNECTOR_URL . 'assets/brand/ai-site-connector-mark.svg' ); ?>" alt="" width="64" height="64" />
				<div>
					<h1><?php esc_html_e( 'AI Site Connector', 'ai-site-connector' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Connect Claude / Codex / AI agents to this WordPress site over the REST API using Application Passwords. WordPress.com is not required.', 'ai-site-connector' ); ?></p>
				</div>
			</div>

			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === $flash['type'] ? 'success' : 'error' ); ?>">
					<p><?php echo esc_html( $flash['msg'] ); ?></p>
					<?php if ( ! empty( $flash['extra']['preflight'] ) ) : ?>
						<?php self::render_preflight_result( $flash['extra']['preflight'] ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $flash['extra']['download_url'] ) ) : ?>
						<?php self::render_pack_download_url( $flash['extra']['download_url'], isset( $flash['extra']['download_ttl'] ) ? (int) $flash['extra']['download_ttl'] : 300 ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $flash['extra']['connection_pack'] ) ) : ?>
						<?php self::render_connection_pack( $flash['extra']['connection_pack'] ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $flash['extra']['cache_report'] ) ) : ?>
						<?php self::render_cache_report( $flash['extra']['cache_report'] ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $flash['extra']['export_result'] ) ) : ?>
						<?php self::render_export_result( $flash['extra']['export_result'] ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'tools.php' ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'onboarding':
					if ( class_exists( 'AI_Site_Connector_Onboarding' ) ) {
						AI_Site_Connector_Onboarding::render_view();
					} else {
						self::render_overview();
					}
					break;
				case 'connection':
					self::render_connection_test();
					break;
				case 'wizard':
					self::render_wizard();
					break;
				case 'credentials':
					self::render_credentials();
					break;
				case 'permissions':
					self::render_permissions();
					break;
				case 'audit':
					self::render_audit();
					break;
				case 'api':
					self::render_api_explorer();
					break;
				case 'diagnostics':
					self::render_diagnostics();
					break;
				case 'export':
					self::render_export();
					break;
				case 'docs':
					self::render_docs();
					break;
				case 'overview':
				default:
					self::render_overview();
					break;
			}
			?>
		</div>
		<?php
	}

	private static function status_badge( $ok, $label_ok, $label_bad ) {
		$class = $ok ? 'asc-ok' : 'asc-bad';
		$label = $ok ? $label_ok : $label_bad;
		return '<span class="asc-badge ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function render_overview() {
		$user = wp_get_current_user();
		?>
		<div class="asc-grid">
			<div class="asc-card">
				<h3><?php esc_html_e( 'Site', 'ai-site-connector' ); ?></h3>
				<table class="asc-kv">
					<tr><th><?php esc_html_e( 'Site URL', 'ai-site-connector' ); ?></th><td><code><?php echo esc_html( home_url() ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'REST API base', 'ai-site-connector' ); ?></th><td><code><?php echo esc_html( rest_url() ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'WordPress version', 'ai-site-connector' ); ?></th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'PHP version', 'ai-site-connector' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Active theme', 'ai-site-connector' ); ?></th><td><?php $t = wp_get_theme(); echo esc_html( $t ? $t->get( 'Name' ) : '' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Multisite?', 'ai-site-connector' ); ?></th><td><?php echo is_multisite() ? esc_html__( 'Yes', 'ai-site-connector' ) : esc_html__( 'No', 'ai-site-connector' ); ?></td></tr>
				</table>
			</div>

			<div class="asc-card">
				<h3><?php esc_html_e( 'Connectivity checks', 'ai-site-connector' ); ?></h3>
				<table class="asc-kv">
					<tr><th><?php esc_html_e( 'HTTPS', 'ai-site-connector' ); ?></th><td><?php echo wp_kses_post( self::status_badge( AI_Site_Connector_Plugin::is_https(), __( 'Enabled', 'ai-site-connector' ), __( 'Plain HTTP — not recommended', 'ai-site-connector' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'REST API reachable', 'ai-site-connector' ); ?></th><td><?php echo wp_kses_post( self::status_badge( AI_Site_Connector_Plugin::rest_reachable(), __( 'OK', 'ai-site-connector' ), __( 'Not reachable', 'ai-site-connector' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Application Passwords', 'ai-site-connector' ); ?></th><td><?php echo wp_kses_post( self::status_badge( AI_Site_Connector_Plugin::app_passwords_available(), __( 'Available', 'ai-site-connector' ), __( 'Disabled or unavailable', 'ai-site-connector' ) ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Authenticated as', 'ai-site-connector' ); ?></th><td><?php echo esc_html( $user->user_login ); ?> (<?php echo esc_html( implode( ', ', (array) $user->roles ) ); ?>)</td></tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="action" value="ai_site_connector_test_rest" />
					<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Test REST API', 'ai-site-connector' ); ?></button></p>
				</form>
			</div>

			<div class="asc-card">
				<h3><?php esc_html_e( 'Recommended next steps', 'ai-site-connector' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Use the Setup Wizard to create a dedicated AI user (recommended role: AI Site Operator).', 'ai-site-connector' ); ?></li>
					<li><?php esc_html_e( 'Open the Credentials tab and generate an Application Password.', 'ai-site-connector' ); ?></li>
					<li><?php esc_html_e( 'Copy the connection pack into a password manager and hand it to your AI tool.', 'ai-site-connector' ); ?></li>
					<li><?php esc_html_e( 'Revoke the password from this page when access is no longer needed.', 'ai-site-connector' ); ?></li>
				</ol>
			</div>

			<?php self::render_updates_card(); ?>
		</div>
		<?php
	}

	private static function render_updates_card() {
		// Defensive: bail cleanly if the updater class isn't loaded (e.g. an upgrade
		// in flight, or a host that surgically disables the file).
		if ( ! class_exists( 'AI_Site_Connector_Updater' ) ) {
			?>
			<div class="asc-card">
				<h3><?php esc_html_e( 'Updates', 'ai-site-connector' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Update checker is not loaded. Reinstall the plugin if this persists.', 'ai-site-connector' ); ?></p>
			</div>
			<?php
			return;
		}
		// First-visit auto-populate: if the transient is empty (no cached release,
		// no recent error), trigger a synchronous check now so the card shows real
		// status instead of "Not checked yet" until WP's update_plugins cron fires.
		AI_Site_Connector_Updater::ensure_check();
		$remote      = AI_Site_Connector_Updater::cached_release();
		$error       = AI_Site_Connector_Updater::cached_error();
		$disabled    = AI_Site_Connector_Updater::is_disabled();
		$prerelease  = AI_Site_Connector_Updater::include_prerelease();
		$can_update  = current_user_can( 'update_plugins' );
		$available   = AI_Site_Connector_Updater::update_available();
		?>
		<div class="asc-card">
			<h3><?php esc_html_e( 'Updates', 'ai-site-connector' ); ?></h3>
			<table class="asc-kv">
				<tr>
					<th><?php esc_html_e( 'Installed version', 'ai-site-connector' ); ?></th>
					<td><code><?php echo esc_html( AI_SITE_CONNECTOR_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'ai-site-connector' ); ?></th>
					<td>
						<?php
						if ( $disabled ) {
							echo '<span class="asc-badge asc-warn">' . esc_html__( 'Disabled by constant', 'ai-site-connector' ) . '</span>';
						} elseif ( $available && $remote ) {
							echo '<span class="asc-badge asc-warn">' . sprintf(
								/* translators: %s: new version. */
								esc_html__( 'Update available: %s', 'ai-site-connector' ),
								esc_html( $remote['version'] )
							) . '</span>';
						} elseif ( $remote ) {
							echo '<span class="asc-badge asc-ok">' . esc_html__( 'Up to date', 'ai-site-connector' ) . '</span>';
						} elseif ( $error ) {
							echo '<span class="asc-badge asc-bad">' . sprintf(
								/* translators: %s: error code. */
								esc_html__( 'Check failed (%s)', 'ai-site-connector' ),
								esc_html( (string) $error['error'] )
							) . '</span>';
						} else {
							echo '<span class="asc-badge">' . esc_html__( 'Not checked yet', 'ai-site-connector' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Source', 'ai-site-connector' ); ?></th>
					<td>
						<a href="<?php echo esc_url( 'https://github.com/tyhallcsu/ai-site-connector/releases' ); ?>" target="_blank" rel="noopener">github.com/tyhallcsu/ai-site-connector</a>
						<?php if ( $prerelease ) : ?>
							<br /><span class="asc-badge asc-warn"><?php esc_html_e( 'Pre-release channel', 'ai-site-connector' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $remote && ! empty( $remote['published_at'] ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Latest release', 'ai-site-connector' ); ?></th>
					<td>
						<?php
						$published = strtotime( $remote['published_at'] );
						if ( $published ) {
							echo esc_html( gmdate( 'Y-m-d', $published ) );
						}
						?>
						<?php if ( ! empty( $remote['html_url'] ) ) : ?>
							 — <a href="<?php echo esc_url( $remote['html_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'release notes', 'ai-site-connector' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! $disabled && $can_update ) : ?>
				<div class="asc-updates-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'ai_site_connector_check_updates' ); ?>
						<input type="hidden" name="action" value="ai_site_connector_check_updates" />
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check for updates now', 'ai-site-connector' ); ?></button>
					</form>
					<?php if ( $available ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ai_site_connector_run_update' ); ?>
							<input type="hidden" name="action" value="ai_site_connector_run_update" />
							<button type="submit" class="button button-primary"><?php
								/* translators: %s: new version. */
								echo esc_html( sprintf( __( 'Update now to %s', 'ai-site-connector' ), $remote['version'] ) );
							?></button>
						</form>
					<?php endif; ?>
				</div>
				<?php $backups = AI_Site_Connector_Backup_Manager::available_backups(); ?>
				<?php if ( ! empty( $backups ) ) : ?>
					<details class="asc-rollback">
						<summary><?php
							printf(
								/* translators: %d: number of backups. */
								esc_html( _n( '%d backup available for rollback', '%d backups available for rollback', count( $backups ), 'ai-site-connector' ) ),
								(int) count( $backups )
							);
						?></summary>
						<p class="description"><?php esc_html_e( 'A snapshot of the previous plugin folder is kept after each update. Click below to swap a backup back in. The plugin is deactivated and reactivated automatically.', 'ai-site-connector' ); ?></p>
						<?php foreach ( $backups as $bk ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asc-rollback-row" onsubmit="return confirm('<?php
								/* translators: %s: version. */
								echo esc_js( sprintf( __( 'Rollback to v%s now? The plugin will be deactivated and reactivated.', 'ai-site-connector' ), $bk['version'] ) );
							?>');">
								<?php wp_nonce_field( 'ai_site_connector_rollback' ); ?>
								<input type="hidden" name="action" value="ai_site_connector_rollback" />
								<input type="hidden" name="to_version" value="<?php echo esc_attr( $bk['version'] ); ?>" />
								<button type="submit" class="button button-secondary"><?php
									/* translators: 1: version, 2: relative time. */
									echo esc_html( sprintf( __( 'Rollback to v%1$s (saved %2$s ago)', 'ai-site-connector' ),
										$bk['version'],
										human_time_diff( $bk['modified'], time() )
									) );
								?></button>
							</form>
						<?php endforeach; ?>
					</details>
				<?php endif; ?>
			<?php elseif ( $disabled ) : ?>
				<p class="description"><?php
					printf(
						/* translators: %s: constant name. */
						esc_html__( 'Self-update is disabled by the %s constant in wp-config.php.', 'ai-site-connector' ),
						'<code>AI_SITE_CONNECTOR_UPDATE_DISABLE</code>'
					);
				?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'You do not have the update_plugins capability — only super admins / admins can update plugins.', 'ai-site-connector' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_wizard() {
		$roles = AI_Site_Connector_User_Manager::allowed_roles();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Create a dedicated AI user', 'ai-site-connector' ); ?></h2>
			<p><?php esc_html_e( 'Application Passwords inherit the user\'s permissions. We recommend "AI Site Operator" for least privilege. Avoid Administrator unless absolutely required.', 'ai-site-connector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_create_user" />
				<table class="form-table">
					<tr><th><label for="ai_username"><?php esc_html_e( 'Username', 'ai-site-connector' ); ?></label></th>
						<td><input type="text" id="ai_username" name="ai_username" value="ai-agent" class="regular-text" required /></td></tr>
					<tr><th><label for="ai_email"><?php esc_html_e( 'Email', 'ai-site-connector' ); ?></label></th>
						<td><input type="email" id="ai_email" name="ai_email" value="" class="regular-text" required placeholder="ai-agent@<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>" /></td></tr>
					<tr><th><label for="ai_display"><?php esc_html_e( 'Display name', 'ai-site-connector' ); ?></label></th>
						<td><input type="text" id="ai_display" name="ai_display" value="AI Agent" class="regular-text" /></td></tr>
					<tr><th><label for="ai_role"><?php esc_html_e( 'Role', 'ai-site-connector' ); ?></label></th>
						<td>
							<select id="ai_role" name="ai_role" data-asc-role>
								<?php foreach ( $roles as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, AI_SITE_CONNECTOR_OPERATOR_ROLE ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'AI Site Operator is the recommended least-privilege option.', 'ai-site-connector' ); ?></p>
						</td></tr>
					<tr id="asc-admin-warn-row" style="display:none">
						<th><label for="ai_admin_confirm" style="color:#b3261e"><?php esc_html_e( 'Confirm Administrator', 'ai-site-connector' ); ?></label></th>
						<td>
							<div class="notice notice-error inline" style="margin:0 0 8px"><p><strong><?php esc_html_e( 'DANGER:', 'ai-site-connector' ); ?></strong> <?php esc_html_e( 'Administrator gives the AI agent full control of this site, including user creation, plugin install, theme switching, and file editing. The Application Password inherits ALL of those abilities.', 'ai-site-connector' ); ?></p></div>
							<p><?php
								/* translators: %s: required confirmation phrase */
								echo esc_html( sprintf( __( 'To proceed, type the exact phrase below into this field: %s', 'ai-site-connector' ), self::ADMIN_CONFIRM_PHRASE ) );
							?></p>
							<input type="text" id="ai_admin_confirm" name="ai_admin_confirm" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( self::ADMIN_CONFIRM_PHRASE ); ?>" />
						</td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create AI user', 'ai-site-connector' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function render_credentials() {
		$users = AI_Site_Connector_User_Manager::list_candidate_users();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Generate Application Password', 'ai-site-connector' ); ?></h2>
			<?php if ( ! AI_Site_Connector_Plugin::app_passwords_available() ) : ?>
				<p class="notice notice-error"><?php esc_html_e( 'Application Passwords are not available on this site. Check WP version, security plugins, or filters disabling the feature.', 'ai-site-connector' ); ?></p>
			<?php endif; ?>
			<?php if ( ! AI_Site_Connector_Plugin::require_https() ) : ?>
				<p class="notice notice-warning"><?php esc_html_e( 'HTTPS is recommended. Plain HTTP is allowed only if WP_DEBUG or AI_SITE_CONNECTOR_ALLOW_HTTP is true.', 'ai-site-connector' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_generate_password" />
				<table class="form-table">
					<tr><th><label for="ai_user_id"><?php esc_html_e( 'User', 'ai-site-connector' ); ?></label></th>
						<td>
							<select id="ai_user_id" name="ai_user_id" required>
								<option value=""><?php esc_html_e( '— Select a user —', 'ai-site-connector' ); ?></option>
								<?php foreach ( $users as $u ) : ?>
									<option value="<?php echo esc_attr( $u->ID ); ?>"><?php echo esc_html( sprintf( '%s (id=%d, %s)', $u->user_login, $u->ID, $u->user_email ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td></tr>
					<tr><th><label for="ai_app_name"><?php esc_html_e( 'App Password name', 'ai-site-connector' ); ?></label></th>
						<td><input type="text" id="ai_app_name" name="ai_app_name" value="<?php echo esc_attr( AI_Site_Connector_Application_Passwords::suggested_name() ); ?>" class="regular-text" required /></td></tr>
					<tr><th><label for="ai_expires_at"><?php esc_html_e( 'Expires (optional)', 'ai-site-connector' ); ?></label></th>
						<td>
							<input type="datetime-local" id="ai_expires_at" name="ai_expires_at" value="" />
							<p class="description"><?php esc_html_e( 'When set, the password is auto-revoked on this date by a daily cron. A reminder email is sent 7 days before expiry. Leave blank for no expiry.', 'ai-site-connector' ); ?></p>
						</td></tr>
					<tr><th><label for="ai_ip_allowlist"><?php esc_html_e( 'IP allowlist (optional)', 'ai-site-connector' ); ?></label></th>
						<td>
							<textarea id="ai_ip_allowlist" name="ai_ip_allowlist" rows="3" class="regular-text" placeholder="<?php echo esc_attr__( 'One CIDR per line, e.g. 192.0.2.0/24 or 2001:db8::/32', 'ai-site-connector' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'Requests from non-matching IPs are rejected at REST auth. Leave blank to allow any IP. IPv4 + IPv6 supported.', 'ai-site-connector' ); ?></p>
						</td></tr>
					<tr><th><?php esc_html_e( 'REST scopes (optional)', 'ai-site-connector' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Allowed REST routes for this password', 'ai-site-connector' ); ?></legend>
								<?php
								$scope_presets = self::scope_presets();
								foreach ( $scope_presets as $preset ) :
									$value = $preset['method'] . ':' . $preset['route'];
									?>
									<label style="display:block; margin: 2px 0;">
										<input type="checkbox" name="ai_scopes[]" value="<?php echo esc_attr( $value ); ?>" />
										<code><?php echo esc_html( $preset['method'] . ' ' . $preset['route'] ); ?></code>
										<span class="description">— <?php echo esc_html( $preset['label'] ); ?></span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Leave all unchecked = no scope restriction (password works on any route the user can access). Check one or more to limit this password to only those routes.', 'ai-site-connector' ); ?></p>
						</td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate Connection Pack', 'ai-site-connector' ); ?></button></p>
			</form>
		</div>

		<div class="asc-card">
			<h2><?php esc_html_e( 'Existing Application Passwords (managed users)', 'ai-site-connector' ); ?></h2>
			<?php
			$any = false;
			foreach ( $users as $u ) :
				$pwds = AI_Site_Connector_Application_Passwords::list_for_user( $u->ID );
				if ( ! $pwds ) {
					continue;
				}
				$any = true;
				?>
				<h3><?php echo esc_html( $u->user_login ); ?> <span class="description">(id=<?php echo (int) $u->ID; ?>)</span></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Name', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'UUID', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Created', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Last used', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Action', 'ai-site-connector' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $pwds as $p ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $p['name'] ) ? $p['name'] : '' ); ?></td>
							<td><code><?php echo esc_html( isset( $p['uuid'] ) ? $p['uuid'] : '' ); ?></code></td>
							<td><?php echo esc_html( isset( $p['created'] ) ? gmdate( 'Y-m-d H:i:s', (int) $p['created'] ) : '' ); ?></td>
							<td><?php echo esc_html( ! empty( $p['last_used'] ) ? gmdate( 'Y-m-d H:i:s', (int) $p['last_used'] ) : '—' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right: 6px;">
									<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
									<input type="hidden" name="action" value="ai_site_connector_rotate_password" />
									<input type="hidden" name="ai_user_id" value="<?php echo (int) $u->ID; ?>" />
									<input type="hidden" name="ai_uuid" value="<?php echo esc_attr( isset( $p['uuid'] ) ? $p['uuid'] : '' ); ?>" />
									<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Rotate this Application Password? A new one will be minted with the same scopes/IP/expiry; AI tools must use the new password going forward.', 'ai-site-connector' ) ); ?>');"><?php esc_html_e( 'Rotate', 'ai-site-connector' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
									<input type="hidden" name="action" value="ai_site_connector_revoke_password" />
									<input type="hidden" name="ai_user_id" value="<?php echo (int) $u->ID; ?>" />
									<input type="hidden" name="ai_uuid" value="<?php echo esc_attr( isset( $p['uuid'] ) ? $p['uuid'] : '' ); ?>" />
									<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke this Application Password? AI tools using it will lose access immediately.', 'ai-site-connector' ) ); ?>');"><?php esc_html_e( 'Revoke', 'ai-site-connector' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			endforeach;
			if ( ! $any ) :
				?>
				<p><?php esc_html_e( 'No Application Passwords found for any visible user yet.', 'ai-site-connector' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_api_explorer() {
		$routes = AI_Site_Connector_API_Explorer::discoverable_routes();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'REST API Explorer', 'ai-site-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Browse the REST routes most relevant to AI tools and "Try it" inline. Requests are dispatched in-process via rest_do_request() — no HTTP loopback, so WAF / SSL / Authorization-stripping issues never apply here.', 'ai-site-connector' ); ?>
			</p>
			<table class="widefat striped asc-explorer-table">
				<thead>
					<tr>
						<th style="width: 110px;"><?php esc_html_e( 'Namespace', 'ai-site-connector' ); ?></th>
						<th style="width: 240px;"><?php esc_html_e( 'Methods', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Route', 'ai-site-connector' ); ?></th>
						<th style="width: 1%; white-space: nowrap;"><?php esc_html_e( 'Action', 'ai-site-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $routes as $row ) : ?>
						<tr class="asc-explorer-row" data-route="<?php echo esc_attr( $row['route'] ); ?>" data-methods="<?php echo esc_attr( implode( ',', $row['methods'] ) ); ?>">
							<td><span class="asc-badge <?php echo 'ai-site-connector' === $row['namespace'] ? 'asc-ok' : ''; ?>"><?php echo esc_html( $row['namespace'] ); ?></span></td>
							<td><code><?php echo esc_html( implode( ' ', $row['methods'] ) ); ?></code></td>
							<td><code><?php echo esc_html( $row['route'] ); ?></code><?php if ( ! empty( $row['description'] ) ) : ?><br><span class="description"><?php echo esc_html( $row['description'] ); ?></span><?php endif; ?></td>
							<td><button type="button" class="button button-secondary asc-try-it"><?php esc_html_e( 'Try it', 'ai-site-connector' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div id="asc-try-it-result" class="asc-try-it-result" hidden>
				<h3><?php esc_html_e( 'Response', 'ai-site-connector' ); ?></h3>
				<p class="description"><span id="asc-try-it-meta"></span></p>
				<pre class="asc-codeblock" id="asc-try-it-body"></pre>
			</div>
			<script type="text/javascript">
			(function () {
				var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var nonce   = <?php echo wp_json_encode( wp_create_nonce( AI_Site_Connector_API_Explorer::NONCE ) ); ?>;
				var action  = <?php echo wp_json_encode( AI_Site_Connector_API_Explorer::AJAX_ACTION ); ?>;

				document.querySelectorAll('.asc-try-it').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var row     = btn.closest('.asc-explorer-row');
						var route   = row.dataset.route;
						var methods = (row.dataset.methods || 'GET').split(',');
						var method  = methods.indexOf('GET') !== -1 ? 'GET' : methods[0];

						var meta = document.getElementById('asc-try-it-meta');
						var body = document.getElementById('asc-try-it-body');
						var wrap = document.getElementById('asc-try-it-result');
						wrap.hidden = false;
						meta.textContent = method + ' ' + route + ' — running…';
						body.textContent = '';

						var form = new FormData();
						form.append('action', action);
						form.append('nonce', nonce);
						form.append('method', method);
						form.append('route', route);

						fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
							.then(function (r) { return r.json(); })
							.then(function (j) {
								if (j && j.success) {
									meta.textContent = method + ' ' + route + ' — HTTP ' + (j.data && j.data.status);
									body.textContent = JSON.stringify(j.data && j.data.data, null, 2);
								} else {
									meta.textContent = method + ' ' + route + ' — error';
									body.textContent = JSON.stringify(j, null, 2);
								}
							})
							.catch(function (err) {
								meta.textContent = method + ' ' + route + ' — network error';
								body.textContent = String(err);
							});
					});
				});
			})();
			</script>
		</div>
		<?php
	}

	private static function render_audit_digest_card() {
		$cadence    = AI_Site_Connector_Audit_Digest::cadence();
		$recipients = (string) get_option( AI_Site_Connector_Audit_Digest::RECIPIENTS_OPTION, '' );
		$next_send  = wp_next_scheduled( AI_Site_Connector_Audit_Digest::CRON_HOOK );
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Email digest', 'ai-site-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Optional periodic summary of audit events sent by email. Lighter-weight alternative to a real-time webhook. Empty windows (no events) are skipped automatically.', 'ai-site-connector' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_save_digest_settings" />
				<table class="asc-kv">
					<tr>
						<th><label for="digest_cadence"><?php esc_html_e( 'Cadence', 'ai-site-connector' ); ?></label></th>
						<td>
							<select name="digest_cadence" id="digest_cadence">
								<option value="off" <?php selected( $cadence, 'off' ); ?>><?php esc_html_e( 'Off (no email)', 'ai-site-connector' ); ?></option>
								<option value="daily" <?php selected( $cadence, 'daily' ); ?>><?php esc_html_e( 'Daily', 'ai-site-connector' ); ?></option>
								<option value="weekly" <?php selected( $cadence, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'ai-site-connector' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="digest_recipients"><?php esc_html_e( 'Recipients', 'ai-site-connector' ); ?></label></th>
						<td>
							<input type="text" name="digest_recipients" id="digest_recipients" class="regular-text" value="<?php echo esc_attr( $recipients ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated emails. Defaults to the WordPress admin email if blank.', 'ai-site-connector' ); ?></p>
						</td>
					</tr>
					<?php if ( $next_send ) : ?>
					<tr>
						<th><?php esc_html_e( 'Next send', 'ai-site-connector' ); ?></th>
						<td>
							<?php
							echo esc_html(
								human_time_diff( time(), (int) $next_send ) . ' (' . gmdate( 'Y-m-d H:i', (int) $next_send ) . ' UTC)'
							);
							?>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save digest settings', 'ai-site-connector' ); ?></button>
				</p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_send_test_digest" />
				<p>
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Send test digest now', 'ai-site-connector' ); ?></button>
					<span class="description"><?php esc_html_e( 'Sends a one-off email covering the last 7 days to the configured recipients.', 'ai-site-connector' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_pack_download_url( $download_url, $ttl_seconds ) {
		if ( empty( $download_url ) ) {
			return;
		}
		$minutes = max( 1, (int) round( $ttl_seconds / 60 ) );
		?>
		<div class="asc-pack-download" style="margin: 10px 0; padding: 10px 14px; background: #f0f6fc; border: 1px solid #c3d4e0; border-radius: 4px;">
			<strong><?php esc_html_e( 'One-time-token download:', 'ai-site-connector' ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: minutes until expiry. */
					_n(
						'this signed URL returns the pack JSON exactly once and self-revokes after %d minute. DM it to a teammate without pasting the password into chat.',
						'this signed URL returns the pack JSON exactly once and self-revokes after %d minutes. DM it to a teammate without pasting the password into chat.',
						$minutes,
						'ai-site-connector'
					),
					$minutes
				)
			);
			?>
			<br />
			<pre class="asc-codeblock" data-copy style="margin-top: 6px;"><?php echo esc_html( $download_url ); ?></pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy download URL', 'ai-site-connector' ); ?></button>
		</div>
		<?php
	}

	private static function render_preflight_result( $preflight ) {
		if ( ! is_array( $preflight ) || empty( $preflight['status'] ) ) {
			return;
		}
		$status = $preflight['status'];
		$code   = isset( $preflight['code'] ) ? $preflight['code'] : '';
		$hint   = isset( $preflight['hint'] ) ? $preflight['hint'] : '';
		$badge_class = 'pass' === $status ? 'asc-ok' : ( 'skipped' === $status ? 'asc-warn' : 'asc-bad' );
		$badge_label = 'pass' === $status
			? __( 'Pre-flight ✓ verified', 'ai-site-connector' )
			: ( 'skipped' === $status
				? __( 'Pre-flight skipped', 'ai-site-connector' )
				: sprintf(
					/* translators: %s: HTTP status code or error code. */
					__( 'Pre-flight ✗ failed (%s)', 'ai-site-connector' ),
					(string) $code
				) );
		?>
		<p class="asc-preflight">
			<span class="asc-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
			<span class="asc-preflight-hint"><?php echo esc_html( $hint ); ?></span>
		</p>
		<?php
	}

	private static function render_connection_pack( $pack ) {
		$formats = AI_Site_Connector_Connection_Formats::all( $pack );
		if ( empty( $formats ) ) {
			return;
		}
		// Unique radio-group name per render so multiple packs on one page don't collide.
		$group = 'asc-fmt-' . substr( md5( wp_json_encode( $pack ) . microtime( true ) ), 0, 8 );
		?>
		<div class="asc-pack asc-format-picker">
			<h3><?php esc_html_e( 'Connection pack — copy now, you will not see this password again', 'ai-site-connector' ); ?></h3>
			<p class="description"><strong><?php esc_html_e( 'Save this in a password manager. Do not commit it to git.', 'ai-site-connector' ); ?></strong></p>

			<?php
			// Radio inputs come first so the :checked ~ .panels selectors work without JS.
			foreach ( $formats as $idx => $fmt ) :
				$radio_id = $group . '-' . $fmt['id'];
				?>
				<input
					type="radio"
					name="<?php echo esc_attr( $group ); ?>"
					id="<?php echo esc_attr( $radio_id ); ?>"
					class="asc-fmt-radio asc-fmt-radio-<?php echo esc_attr( $fmt['id'] ); ?>"
					<?php checked( 0, $idx ); ?>
				/>
			<?php endforeach; ?>

			<div class="asc-fmt-tabs" role="tablist">
				<?php foreach ( $formats as $fmt ) :
					$radio_id = $group . '-' . $fmt['id'];
					?>
					<label
						for="<?php echo esc_attr( $radio_id ); ?>"
						class="asc-fmt-tab asc-fmt-tab-<?php echo esc_attr( $fmt['id'] ); ?>"
						role="tab"
					><?php echo esc_html( $fmt['label'] ); ?></label>
				<?php endforeach; ?>
			</div>

			<div class="asc-fmt-panels">
				<?php foreach ( $formats as $fmt ) : ?>
					<section
						class="asc-fmt-panel asc-fmt-panel-<?php echo esc_attr( $fmt['id'] ); ?>"
						role="tabpanel"
						aria-label="<?php echo esc_attr( $fmt['label'] ); ?>"
					>
						<p class="description"><?php echo esc_html( $fmt['hint'] ); ?></p>
						<pre class="asc-codeblock" data-copy><?php echo esc_html( $fmt['code'] ); ?></pre>
						<button type="button" class="button" data-asc-copy="prev"><?php
							/* translators: %s: format label, e.g. "Claude Desktop (MCP)". */
							echo esc_html( sprintf( __( 'Copy %s', 'ai-site-connector' ), $fmt['label'] ) );
						?></button>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_audit() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$filters = array(
			'action' => isset( $_GET['filter_action'] ) ? sanitize_key( wp_unslash( $_GET['filter_action'] ) ) : '',
			'tool'   => isset( $_GET['filter_tool'] ) ? sanitize_key( wp_unslash( $_GET['filter_tool'] ) ) : '',
			'status' => isset( $_GET['filter_status'] ) ? sanitize_key( wp_unslash( $_GET['filter_status'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$rows           = AI_Site_Connector_Audit_Log::recent( 100, $filters );
		$retention_days = AI_Site_Connector_Audit_Log::retention_days();
		$next_run       = wp_next_scheduled( AI_Site_Connector_Audit_Log::CRON_HOOK );
		$tools          = AI_Site_Connector_Audit_Log::distinct_tools();
		self::render_audit_digest_card();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Retention', 'ai-site-connector' ); ?></h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: retention window in days, 2: minimum number of rows always preserved */
						__( 'Audit log entries older than %1$d days are auto-pruned daily, except the most recent %2$d rows are always kept for debugging.', 'ai-site-connector' ),
						(int) $retention_days,
						(int) AI_Site_Connector_Audit_Log::MIN_KEEP_ROWS
					)
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Override programmatically with the ai_site_connector_log_retention_days filter, or disable pruning with ai_site_connector_log_skip_prune.', 'ai-site-connector' ); ?>
				<?php if ( $next_run ) : ?>
					<br>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time-from-now until next prune */
							__( 'Next scheduled prune: %s.', 'ai-site-connector' ),
							human_time_diff( time(), (int) $next_run ) . ' (' . gmdate( 'Y-m-d H:i', (int) $next_run ) . ' UTC)'
						)
					);
					?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_prune_log" />
				<p>
					<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Prune audit log entries older than the retention window now? Recent rows are preserved.', 'ai-site-connector' ) ); ?>');">
						<?php esc_html_e( 'Prune now', 'ai-site-connector' ); ?>
					</button>
				</p>
			</form>
		</div>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Plugin removal', 'ai-site-connector' ); ?></h2>
			<?php
			$wipe_on_uninstall = (bool) get_option( 'ai_site_connector_wipe_on_uninstall', false );
			$constant_force    = defined( 'AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL' ) && AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL;
			?>
			<p class="description">
				<?php esc_html_e( 'By default, deleting this plugin preserves the audit log table, the AI Site Operator role, the dedicated AI user, and any Application Passwords. Tick the box below if you want a clean wipe of the data this plugin owns when the plugin is deleted.', 'ai-site-connector' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_save_uninstall_pref" />
				<p>
					<label>
						<input type="checkbox" name="ai_wipe_on_uninstall" value="1" <?php checked( $wipe_on_uninstall || $constant_force ); ?> <?php disabled( $constant_force ); ?> />
						<?php esc_html_e( 'On uninstall, drop the audit log table, remove the AI Site Operator role, and delete plugin options.', 'ai-site-connector' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'Even when ticked, the AI user and any Application Passwords are NOT deleted automatically. Remove those separately via the Users screen or `wp user delete` if you want them gone.', 'ai-site-connector' ); ?>
					<?php if ( $constant_force ) : ?>
						<br><strong><?php esc_html_e( 'Locked ON by the AI_SITE_CONNECTOR_WIPE_ON_UNINSTALL constant in wp-config.php.', 'ai-site-connector' ); ?></strong>
					<?php endif; ?>
				</p>
				<?php if ( ! $constant_force ) : ?>
					<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Save uninstall preference', 'ai-site-connector' ); ?></button></p>
				<?php endif; ?>
			</form>
		</div>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Filter & export', 'ai-site-connector' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="asc-filter-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="audit" />
				<label>
					<?php esc_html_e( 'Action', 'ai-site-connector' ); ?>
					<input type="text" name="filter_action" value="<?php echo esc_attr( $filters['action'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. cache_purged', 'ai-site-connector' ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Tool', 'ai-site-connector' ); ?>
					<select name="filter_tool">
						<option value=""><?php esc_html_e( '(any)', 'ai-site-connector' ); ?></option>
						<?php foreach ( $tools as $tool_slug ) : ?>
							<option value="<?php echo esc_attr( $tool_slug ); ?>" <?php selected( $filters['tool'], $tool_slug ); ?>><?php echo esc_html( $tool_slug ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Status', 'ai-site-connector' ); ?>
					<select name="filter_status">
						<option value=""><?php esc_html_e( '(any)', 'ai-site-connector' ); ?></option>
						<?php foreach ( array( 'success', 'failure', 'denied', 'info' ) as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filters['status'], $s ); ?>><?php echo esc_html( $s ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ai-site-connector' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG . '&tab=audit' ) ); ?>"><?php esc_html_e( 'Clear', 'ai-site-connector' ); ?></a>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_export_audit_csv" />
				<input type="hidden" name="filter_action" value="<?php echo esc_attr( $filters['action'] ); ?>" />
				<input type="hidden" name="filter_tool" value="<?php echo esc_attr( $filters['tool'] ); ?>" />
				<input type="hidden" name="filter_status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Download filtered rows as CSV', 'ai-site-connector' ); ?></button>
			</form>
		</div>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Recent audit events', 'ai-site-connector' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Action', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Tool', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Target', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Summary / Message', 'ai-site-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No events match the current filter.', 'ai-site-connector' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$actor_user  = $r->actor_user_id ? get_userdata( $r->actor_user_id ) : null;
						$summary     = '' !== (string) ( isset( $r->summary ) ? $r->summary : '' ) ? $r->summary : $r->message;
						$target_lbl  = '';
						if ( ! empty( $r->target_type ) && ! empty( $r->target_id ) ) {
							$target_lbl = $r->target_type . '#' . $r->target_id;
						} elseif ( ! empty( $r->target_user_id ) ) {
							$tu = get_userdata( (int) $r->target_user_id );
							$target_lbl = $tu ? 'user:' . $tu->user_login : 'user#' . (int) $r->target_user_id;
						}
						?>
						<tr>
							<td><?php echo esc_html( $r->created_at ); ?> UTC</td>
							<td><code><?php echo esc_html( $r->action ); ?></code></td>
							<td><?php echo $r->tool ? '<code>' . esc_html( $r->tool ) . '</code>' : '—'; ?></td>
							<td><?php echo $r->status ? '<code>' . esc_html( $r->status ) . '</code>' : '—'; ?></td>
							<td><?php echo $actor_user ? esc_html( $actor_user->user_login ) : '—'; ?></td>
							<td><?php echo $target_lbl ? esc_html( $target_lbl ) : '—'; ?></td>
							<td><?php echo esc_html( $summary ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_docs() {
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Documentation', 'ai-site-connector' ); ?></h2>
			<ul>
				<li><a href="https://github.com/tyhallcsu/ai-site-connector/blob/main/README.md" target="_blank" rel="noopener">README</a></li>
				<li><a href="https://github.com/tyhallcsu/ai-site-connector/blob/main/docs/CLAUDE_CONNECTION_GUIDE.md" target="_blank" rel="noopener">Claude / Codex connection guide</a></li>
				<li><a href="https://github.com/tyhallcsu/ai-site-connector/blob/main/docs/SECURITY_MODEL.md" target="_blank" rel="noopener">Security model</a></li>
				<li><a href="https://github.com/tyhallcsu/ai-site-connector/blob/main/docs/FEATURES.md" target="_blank" rel="noopener">Features (v0.2.0)</a></li>
				<li><a href="https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/" target="_blank" rel="noopener">WordPress core: Application Passwords integration guide</a></li>
			</ul>
		</div>
		<?php
	}

	// === New tabs (v0.2.0) ====================================================

	/**
	 * Connection Test tab — verifies every link in the MCP chain and shows
	 * pass/fail badges plus a copyable Claude/Codex agent prompt.
	 */
	private static function render_connection_test() {
		$diag = AI_Site_Connector_Diagnostics::generate();
		$last = (string) get_option( AI_Site_Connector_REST_Controller::LAST_REQUEST_OPTION, '' );
		$tools = AI_Site_Connector_REST_Controller::tools_catalog();
		$user  = wp_get_current_user();
		$base  = trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE;

		$checks = array(
			array(
				'label'   => __( 'HTTPS', 'ai-site-connector' ),
				'ok'      => (bool) $diag['wordpress']['https'],
				'ok_label'  => __( 'Enabled', 'ai-site-connector' ),
				'bad_label' => __( 'Plain HTTP — not recommended', 'ai-site-connector' ),
			),
			array(
				'label'   => __( 'REST API reachable', 'ai-site-connector' ),
				'ok'      => (bool) $diag['rest_mcp']['rest_reachable'],
				'ok_label'  => __( 'OK', 'ai-site-connector' ),
				'bad_label' => __( 'Not reachable', 'ai-site-connector' ),
			),
			array(
				'label'   => __( 'Application Passwords available', 'ai-site-connector' ),
				'ok'      => (bool) $diag['wordpress']['app_passwords_available'],
				'ok_label'  => __( 'Yes', 'ai-site-connector' ),
				'bad_label' => __( 'No (security plugin or filter disabled them)', 'ai-site-connector' ),
			),
			array(
				'label'   => __( 'MCP namespace registered', 'ai-site-connector' ),
				'ok'      => ! empty( $diag['rest_mcp']['registered_routes'] ),
				'ok_label'  => sprintf( __( '%d route(s)', 'ai-site-connector' ), count( $diag['rest_mcp']['registered_routes'] ) ),
				'bad_label' => __( 'No routes registered — plugin boot failed?', 'ai-site-connector' ),
			),
			array(
				'label'   => __( 'Read-only mode', 'ai-site-connector' ),
				'ok'      => ! $diag['rest_mcp']['read_only_mode'],
				'ok_label'  => __( 'Off (writes allowed by individual settings)', 'ai-site-connector' ),
				'bad_label' => __( 'ON — every non-read tool is currently denied', 'ai-site-connector' ),
			),
			array(
				'label'   => __( 'Last successful MCP request', 'ai-site-connector' ),
				'ok'      => '' !== $last,
				'ok_label'  => $last,
				'bad_label' => __( 'Never (no request has hit /ai-site-connector/v1/* since plugin activation)', 'ai-site-connector' ),
			),
		);
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Connection checks', 'ai-site-connector' ); ?></h2>
			<table class="asc-kv">
				<?php foreach ( $checks as $c ) : ?>
					<tr>
						<th><?php echo esc_html( $c['label'] ); ?></th>
						<td><?php echo wp_kses_post( self::status_badge( (bool) $c['ok'], $c['ok_label'], $c['bad_label'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: username, 2: comma-separated role list, 3: REST namespace root URL */
						__( 'Signed in as %1$s (roles: %2$s). MCP base URL: %3$s', 'ai-site-connector' ),
						$user->user_login,
						implode( ', ', (array) $user->roles ),
						$base
					)
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_test_rest" />
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Run REST self-test', 'ai-site-connector' ); ?></button>
			</form>
		</div>

		<div class="asc-card">
			<h2><?php esc_html_e( 'Available tools', 'ai-site-connector' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Allow / deny state is per the Permissions tab. WP capability checks still apply on top.', 'ai-site-connector' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tool', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Method', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Route', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Permission key', 'ai-site-connector' ); ?></th>
						<th><?php esc_html_e( 'Status for you', 'ai-site-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tools as $t ) : ?>
						<?php $allowed = AI_Site_Connector_Permissions::can( (string) $t['permission'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $t['name'] ); ?></strong><br><span class="description"><?php echo esc_html( $t['description'] ); ?></span></td>
							<td><code><?php echo esc_html( $t['method'] ); ?></code></td>
							<td><code><?php echo esc_html( $base . $t['route'] ); ?></code></td>
							<td><code><?php echo esc_html( $t['permission'] ); ?></code></td>
							<td><?php echo wp_kses_post( self::status_badge( (bool) $allowed, __( 'Allowed', 'ai-site-connector' ), __( 'Denied', 'ai-site-connector' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="asc-card">
			<h2><?php esc_html_e( 'Copy-paste Claude/Codex prompt', 'ai-site-connector' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Generate an Application Password in the Credentials tab, then paste this prompt into your AI agent (replacing the password placeholder). No secret is included in this snippet.', 'ai-site-connector' ); ?></p>
			<pre class="asc-codeblock" data-copy>You can manage this WordPress site via its AI Site Connector REST API.

Base URL: <?php echo esc_html( $base ); ?>

Auth: HTTP Basic — header "Authorization: Basic base64(<?php echo esc_html( $user->user_login ); ?>:APPLICATION_PASSWORD)"

Available tools (gated by per-tool whitelist in wp-admin → Tools → AI Site Connector → Permissions):
<?php foreach ( $tools as $t ) :
echo '  - ' . esc_html( $t['name'] ) . '  (' . esc_html( $t['method'] ) . ' ' . esc_html( $t['route'] ) . ")\n";
endforeach; ?>

Probe the connection first by calling: GET <?php echo esc_html( $base ); ?>/tools

Do not commit the Application Password to git.</pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy prompt', 'ai-site-connector' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Permissions tab — checkbox grid for the tool whitelist and read-only toggle.
	 */
	private static function render_permissions() {
		$perms     = AI_Site_Connector_Permissions::get_all();
		$read_only = AI_Site_Connector_Permissions::is_read_only();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Tool whitelist', 'ai-site-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Each tool consults this list BEFORE executing. WP capability checks still apply on top — disabling a tool here cannot grant access, only deny it.', 'ai-site-connector' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_save_permissions" />
				<p>
					<label>
						<input type="checkbox" name="ai_site_connector_read_only_mode" value="1" <?php checked( $read_only ); ?> />
						<strong><?php esc_html_e( 'Global read-only mode', 'ai-site-connector' ); ?></strong> —
						<?php esc_html_e( 'when on, every non-read tool is denied regardless of the per-tool settings below.', 'ai-site-connector' ); ?>
					</label>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'ai-site-connector' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'ai-site-connector' ); ?></th>
							<th><?php esc_html_e( 'Category', 'ai-site-connector' ); ?></th>
							<th><?php esc_html_e( 'WP capability required', 'ai-site-connector' ); ?></th>
							<th><?php esc_html_e( 'Default', 'ai-site-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $perms as $key => $row ) : ?>
							<tr>
								<td><input type="checkbox" name="ai_site_connector_perms[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $row['enabled'] ); ?> /></td>
								<td>
									<strong><?php echo esc_html( $row['label'] ); ?></strong> <code><?php echo esc_html( $key ); ?></code><br>
									<span class="description"><?php echo esc_html( $row['description'] ); ?></span>
								</td>
								<td><code><?php echo esc_html( $row['category'] ); ?></code></td>
								<td><code><?php echo esc_html( $row['wp_cap'] ); ?></code></td>
								<td><?php echo $row['default'] ? esc_html__( 'on', 'ai-site-connector' ) : esc_html__( 'off', 'ai-site-connector' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save permissions', 'ai-site-connector' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Diagnostics tab — renders the capability report + a cache purge button. */
	private static function render_diagnostics() {
		$report = AI_Site_Connector_Diagnostics::generate();
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Cache purge', 'ai-site-connector' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Flushes WP object cache plus every supported cache plugin that is active. Cloudflare runs only if both API token and zone are set in plugin options.', 'ai-site-connector' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_purge_cache" />
				<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Purge all caches now', 'ai-site-connector' ); ?></button></p>
			</form>
		</div>

		<div class="asc-card">
			<h2><?php esc_html_e( 'Site capability report', 'ai-site-connector' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Same payload returned by GET /diagnostics/site-report. No secrets are included — safe to paste into a support thread.', 'ai-site-connector' ); ?></p>
			<pre class="asc-codeblock" data-copy><?php echo esc_html( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy JSON', 'ai-site-connector' ); ?></button>
		</div>
		<?php
	}

	/** Export tab — write JSON manifests to wp-content/uploads/ai-site-connector/exports/. */
	private static function render_export() {
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Repo-sync exports', 'ai-site-connector' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Each button writes a JSON snapshot under wp-content/uploads/ai-site-connector/exports/. The same data is available via REST under /export/* for an AI agent to fetch directly.', 'ai-site-connector' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="ai_site_connector_export_write" />
				<p>
					<label>
						<input type="radio" name="export_kind" value="media-manifest" checked />
						<?php esc_html_e( 'Media manifest (attachments + alt/caption/sha256)', 'ai-site-connector' ); ?>
					</label><br>
					<label>
						<input type="radio" name="export_kind" value="recent-changes" />
						<?php esc_html_e( 'Recent changes (posts + pages, last 50)', 'ai-site-connector' ); ?>
					</label><br>
					<label>
						<input type="radio" name="export_kind" value="site-manifest" />
						<?php esc_html_e( 'Site manifest (counts + recent + detected plugins)', 'ai-site-connector' ); ?>
					</label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Write export', 'ai-site-connector' ); ?></button></p>
			</form>
		</div>

		<div class="asc-card">
			<h2><?php esc_html_e( 'REST routes for AI agents', 'ai-site-connector' ); ?></h2>
			<?php $base = trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Method', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'URL', 'ai-site-connector' ); ?></th></tr></thead>
				<tbody>
					<tr><td><code>GET</code></td><td><code><?php echo esc_html( $base . '/export/media-manifest' ); ?></code></td></tr>
					<tr><td><code>GET</code></td><td><code><?php echo esc_html( $base . '/export/recent-changes' ); ?></code></td></tr>
					<tr><td><code>GET</code></td><td><code><?php echo esc_html( $base . '/export/page/{id}' ); ?></code></td></tr>
					<tr><td><code>GET</code></td><td><code><?php echo esc_html( $base . '/export/site-manifest' ); ?></code></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Helper to render the cache report stored on a flash $extra. */
	private static function render_cache_report( $report ) {
		?>
		<div class="asc-pack">
			<h4><?php esc_html_e( 'Cache purge report', 'ai-site-connector' ); ?></h4>
			<table class="asc-kv">
				<tr><th><?php esc_html_e( 'Purged', 'ai-site-connector' ); ?></th><td><code><?php echo esc_html( implode( ', ', (array) $report['purged'] ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Skipped', 'ai-site-connector' ); ?></th><td><code><?php echo esc_html( implode( ', ', (array) $report['skipped'] ) ); ?></code></td></tr>
				<?php if ( ! empty( $report['warnings'] ) ) : ?>
					<tr><th><?php esc_html_e( 'Warnings', 'ai-site-connector' ); ?></th><td><pre class="asc-codeblock"><?php echo esc_html( implode( "\n", (array) $report['warnings'] ) ); ?></pre></td></tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/** Helper to render an export-result link on a flash $extra. */
	private static function render_export_result( $r ) {
		?>
		<div class="asc-pack">
			<h4><?php esc_html_e( 'Export written', 'ai-site-connector' ); ?></h4>
			<p>
				<?php echo esc_html( sprintf( __( 'Kind: %s · Bytes: %d', 'ai-site-connector' ), $r['kind'], (int) $r['bytes'] ) ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Disk path:', 'ai-site-connector' ); ?>
				<code><?php echo esc_html( (string) $r['path'] ); ?></code>
			</p>
			<p>
				<?php esc_html_e( 'URL:', 'ai-site-connector' ); ?>
				<a href="<?php echo esc_url( (string) $r['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $r['url'] ); ?></a>
			</p>
			<p class="description">
				<?php esc_html_e( 'The exports directory is browsable on most hosts. A noindex .htaccess is dropped automatically; commit the file to your repo and delete it from the live server when no longer needed.', 'ai-site-connector' ); ?>
			</p>
		</div>
		<?php
	}

	public static function handle_export_write() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST );
		if ( is_wp_error( $check ) ) {
			self::flash( $check->get_error_message(), 'error' );
			self::redirect_back( 'export' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kind = isset( $_POST['export_kind'] ) ? sanitize_key( wp_unslash( $_POST['export_kind'] ) ) : '';
		switch ( $kind ) {
			case 'media-manifest':
				$data = AI_Site_Connector_Export::media_manifest();
				break;
			case 'recent-changes':
				$data = AI_Site_Connector_Export::recent_changes();
				break;
			case 'site-manifest':
				$data = AI_Site_Connector_Export::site_manifest();
				break;
			default:
				self::flash( __( 'Unknown export kind.', 'ai-site-connector' ), 'error' );
				self::redirect_back( 'export' );
		}

		$res = AI_Site_Connector_Export::write_to_disk( $kind, $data );
		if ( is_wp_error( $res ) ) {
			self::flash( $res->get_error_message(), 'error' );
		} else {
			self::flash(
				sprintf(
					/* translators: 1: kind, 2: bytes */
					__( 'Export written: %1$s (%2$d bytes).', 'ai-site-connector' ),
					$kind,
					(int) $res['bytes']
				),
				'success',
				array( 'export_result' => $res )
			);
		}
		self::redirect_back( 'export' );
	}

	public static function handle_export_audit_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$filters = array(
			'action' => isset( $_POST['filter_action'] ) ? sanitize_key( wp_unslash( $_POST['filter_action'] ) ) : '',
			'tool'   => isset( $_POST['filter_tool'] ) ? sanitize_key( wp_unslash( $_POST['filter_tool'] ) ) : '',
			'status' => isset( $_POST['filter_status'] ) ? sanitize_key( wp_unslash( $_POST['filter_status'] ) ) : '',
		);

		$csv = AI_Site_Connector_Audit_Log::export_csv( $filters );
		$filename = 'ai-site-connector-audit-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV stream.
		exit;
	}
}
