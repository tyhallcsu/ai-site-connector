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
		add_action( 'admin_post_ai_site_connector_test_rest', array( __CLASS__, 'handle_test_rest' ) );
		add_action( 'admin_post_ai_site_connector_prune_log', array( __CLASS__, 'handle_prune_log' ) );
		add_action( 'admin_post_ai_site_connector_save_uninstall_pref', array( __CLASS__, 'handle_save_uninstall_pref' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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

		$res = AI_Site_Connector_Application_Passwords::create_for_user( $user_id, $app_name );
		if ( is_wp_error( $res ) ) {
			self::flash( $res->get_error_message(), 'error' );
			self::redirect_back( 'credentials' );
		}

		$pack      = self::build_connection_pack( $user_id, $res );
		$preflight = self::run_preflight_check( $pack );

		self::flash(
			__( 'Application Password generated. Copy it now — it will not be shown again.', 'ai-site-connector' ),
			'success',
			array(
				'connection_pack' => $pack,
				'preflight'       => $preflight,
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
		$tabs['wizard']      = __( 'Setup Wizard', 'ai-site-connector' );
		$tabs['credentials'] = __( 'Credentials', 'ai-site-connector' );
		$tabs['audit']       = __( 'Audit Log', 'ai-site-connector' );
		$tabs['api']         = __( 'API Explorer', 'ai-site-connector' );
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
					<?php if ( ! empty( $flash['extra']['connection_pack'] ) ) : ?>
						<?php self::render_connection_pack( $flash['extra']['connection_pack'] ); ?>
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
				case 'wizard':
					self::render_wizard();
					break;
				case 'credentials':
					self::render_credentials();
					break;
				case 'audit':
					self::render_audit();
					break;
				case 'api':
					self::render_api_explorer();
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
		$rows           = AI_Site_Connector_Audit_Log::recent( 100 );
		$retention_days = AI_Site_Connector_Audit_Log::retention_days();
		$next_run       = wp_next_scheduled( AI_Site_Connector_Audit_Log::CRON_HOOK );
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
			<h2><?php esc_html_e( 'Recent audit events', 'ai-site-connector' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr><th><?php esc_html_e( 'When', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Action', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Actor', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Target user', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'IP', 'ai-site-connector' ); ?></th><th><?php esc_html_e( 'Message', 'ai-site-connector' ); ?></th></tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No events yet.', 'ai-site-connector' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$actor_user  = $r->actor_user_id ? get_userdata( $r->actor_user_id ) : null;
						$target_user = $r->target_user_id ? get_userdata( $r->target_user_id ) : null;
						?>
						<tr>
							<td><?php echo esc_html( $r->created_at ); ?> UTC</td>
							<td><code><?php echo esc_html( $r->action ); ?></code></td>
							<td><?php echo $actor_user ? esc_html( $actor_user->user_login ) : '—'; ?></td>
							<td><?php echo $target_user ? esc_html( $target_user->user_login ) : '—'; ?></td>
							<td><?php echo esc_html( $r->ip ); ?></td>
							<td><?php echo esc_html( $r->message ); ?></td>
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
				<li><a href="https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/" target="_blank" rel="noopener">WordPress core: Application Passwords integration guide</a></li>
			</ul>
		</div>
		<?php
	}
}
