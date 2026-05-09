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
			self::flash( sprintf( __( 'AI user "%1$s" (id=%2$d) created.', 'ai-site-connector' ), $username, $result ), 'success' );
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

		self::flash(
			__( 'Application Password generated. Copy it now — it will not be shown again.', 'ai-site-connector' ),
			'success',
			array(
				'connection_pack' => self::build_connection_pack( $user_id, $res ),
			)
		);

		AI_Site_Connector_Audit_Log::record(
			'connection_pack_generated',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'Connection pack generated for user id %d.', $user_id ),
			)
		);

		self::redirect_back( 'credentials' );
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
			$msg  = sprintf( __( 'REST test (internal dispatch) responded with HTTP %d.', 'ai-site-connector' ), $code );
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
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$flash = self::consume_flash();
		$tabs  = array(
			'overview'    => __( 'Overview', 'ai-site-connector' ),
			'wizard'      => __( 'Setup Wizard', 'ai-site-connector' ),
			'credentials' => __( 'Credentials', 'ai-site-connector' ),
			'audit'       => __( 'Audit Log', 'ai-site-connector' ),
			'docs'        => __( 'Docs', 'ai-site-connector' ),
		);
		?>
		<div class="wrap ai-site-connector-wrap">
			<h1><?php esc_html_e( 'AI Site Connector', 'ai-site-connector' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Connect Claude / Codex / AI agents to this WordPress site over the REST API using Application Passwords. WordPress.com is not required.', 'ai-site-connector' ); ?></p>

			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'success' === $flash['type'] ? 'success' : 'error' ); ?>">
					<p><?php echo esc_html( $flash['msg'] ); ?></p>
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
				case 'wizard':
					self::render_wizard();
					break;
				case 'credentials':
					self::render_credentials();
					break;
				case 'audit':
					self::render_audit();
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

	private static function render_connection_pack( $pack ) {
		$json   = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$user   = isset( $pack['username'] ) ? $pack['username'] : '';
		$pass   = isset( $pack['application_password'] ) ? $pack['application_password'] : '';
		$base   = isset( $pack['rest_api_base'] ) ? $pack['rest_api_base'] : '';
		$pretty = $pack;
		// Mask password in any echoed-back HTML (we still keep it inside the JSON pre block for the user to copy).
		$pretty['application_password'] = '••••••••••••••••';
		?>
		<div class="asc-pack">
			<h3><?php esc_html_e( 'Connection pack — copy now, you will not see this password again', 'ai-site-connector' ); ?></h3>
			<p class="description"><strong><?php esc_html_e( 'Save this in a password manager. Do not commit it to git.', 'ai-site-connector' ); ?></strong></p>
			<details open>
				<summary><?php esc_html_e( 'connection-pack.json (full, includes plaintext password)', 'ai-site-connector' ); ?></summary>
				<pre class="asc-codeblock" data-copy><?php echo esc_html( $json ); ?></pre>
				<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy JSON', 'ai-site-connector' ); ?></button>
			</details>

			<h4><?php esc_html_e( 'Test with curl', 'ai-site-connector' ); ?></h4>
			<pre class="asc-codeblock" data-copy>curl -u '<?php echo esc_html( $user ); ?>:<?php echo esc_html( $pass ); ?>' '<?php echo esc_html( $base ); ?>wp/v2/users/me'</pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy curl', 'ai-site-connector' ); ?></button>

			<h4><?php esc_html_e( 'Test with Python (requests)', 'ai-site-connector' ); ?></h4>
			<pre class="asc-codeblock" data-copy>import requests
r = requests.get(
    "<?php echo esc_html( $base ); ?>wp/v2/users/me",
    auth=("<?php echo esc_html( $user ); ?>", "<?php echo esc_html( $pass ); ?>"),
    timeout=15,
)
print(r.status_code, r.json())</pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy Python', 'ai-site-connector' ); ?></button>

			<h4><?php esc_html_e( 'Test with JavaScript (fetch)', 'ai-site-connector' ); ?></h4>
			<pre class="asc-codeblock" data-copy>const auth = "Basic " + btoa("<?php echo esc_html( $user ); ?>:<?php echo esc_html( $pass ); ?>");
const r = await fetch("<?php echo esc_html( $base ); ?>wp/v2/users/me", { headers: { Authorization: auth } });
console.log(r.status, await r.json());</pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy JS', 'ai-site-connector' ); ?></button>

			<h4><?php esc_html_e( 'Claude Code instructions', 'ai-site-connector' ); ?></h4>
			<pre class="asc-codeblock" data-copy>You can authenticate to this WordPress site using HTTP Basic Auth.
- REST base: <?php echo esc_html( $base ); ?>

- Username: <?php echo esc_html( $user ); ?>

- Password: (Application Password from the connection pack)
Add header: Authorization: Basic base64(username:application_password)
Plugin health endpoint: <?php echo esc_html( isset( $pack['plugin_health_endpoint'] ) ? $pack['plugin_health_endpoint'] : '' ); ?>

Do not commit this password to git.</pre>
			<button type="button" class="button" data-asc-copy="prev"><?php esc_html_e( 'Copy instructions', 'ai-site-connector' ); ?></button>
		</div>
		<?php
	}

	private static function render_audit() {
		$rows = AI_Site_Connector_Audit_Log::recent( 100 );
		?>
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
