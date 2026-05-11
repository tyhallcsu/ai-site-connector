<?php
/**
 * First-run onboarding — welcome notice + guided 5-step setup view.
 *
 * Visible only until the operator either completes onboarding or
 * explicitly dismisses the welcome. State stored in the option
 * `ai_site_connector_onboarding_completed` (bool).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Onboarding {

	const OPTION = 'ai_site_connector_onboarding_completed';

	public static function register_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_welcome' ) );
		add_action( 'admin_post_ai_site_connector_dismiss_onboarding', array( __CLASS__, 'handle_dismiss' ) );
	}

	public static function mark_completed() {
		update_option( self::OPTION, 1, false );
		AI_Site_Connector_Audit_Log::record(
			'onboarding_completed',
			array( 'message' => __( 'First-run onboarding marked complete.', 'ai-site-connector' ) )
		);
	}

	public static function is_completed() {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * Best-effort heuristics for the auto-detect step badges.
	 *
	 * @return array{has_role:bool, has_ai_user:bool, has_password:bool}
	 */
	public static function detect_progress() {
		$has_role     = get_role( AI_SITE_CONNECTOR_OPERATOR_ROLE ) instanceof WP_Role;
		$users        = get_users(
			array(
				'role'   => AI_SITE_CONNECTOR_OPERATOR_ROLE,
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$has_ai_user = ! empty( $users );
		$has_password = false;
		if ( $has_ai_user && class_exists( 'AI_Site_Connector_Application_Passwords' ) ) {
			$uid = (int) $users[0];
			$pwds = AI_Site_Connector_Application_Passwords::list_for_user( $uid );
			$has_password = ! empty( $pwds );
		}
		return array(
			'has_role'     => $has_role,
			'has_ai_user'  => $has_ai_user,
			'has_password' => $has_password,
		);
	}

	public static function maybe_show_welcome() {
		if ( self::is_completed() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Only on the plugin's own admin page, not network-wide.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'tools_page_' . AI_Site_Connector_Admin_Page::PAGE_SLUG !== $screen->id ) {
			return;
		}
		// Skip if we're already on the onboarding tab — the in-tab view replaces the notice.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['tab'] ) && 'onboarding' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		// phpcs:enable
		$start_url = add_query_arg(
			array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'onboarding' ),
			admin_url( 'tools.php' )
		);
		$dismiss_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'ai_site_connector_dismiss_onboarding' ), admin_url( 'admin-post.php' ) ),
			'ai_site_connector_dismiss_onboarding'
		);
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Welcome to AI Site Connector!', 'ai-site-connector' ); ?></strong>
				<?php esc_html_e( 'Let\'s wire this site up for an AI tool in 5 quick steps.', 'ai-site-connector' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $start_url ); ?>" class="button button-primary"><?php esc_html_e( 'Start setup', 'ai-site-connector' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-link"><?php esc_html_e( 'Skip — I know what I\'m doing', 'ai-site-connector' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( 'ai_site_connector_dismiss_onboarding' );
		self::mark_completed();
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'overview' ),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function render_view() {
		$progress = self::detect_progress();
		$is_https = AI_Site_Connector_Plugin::is_https();
		$rest_ok  = AI_Site_Connector_Plugin::rest_reachable();
		$apps_ok  = AI_Site_Connector_Plugin::app_passwords_available();

		$steps = array(
			array(
				'label' => __( '1. Connectivity', 'ai-site-connector' ),
				'done'  => $is_https && $rest_ok && $apps_ok,
				'hint'  => $is_https && $rest_ok && $apps_ok
					? __( 'HTTPS, REST API, and Application Passwords all look healthy.', 'ai-site-connector' )
					: __( 'One or more connectivity checks failed. Open the Overview tab to investigate.', 'ai-site-connector' ),
				'cta'   => __( 'Review on Overview', 'ai-site-connector' ),
				'tab'   => 'overview',
			),
			array(
				'label' => __( '2. AI Site Operator role', 'ai-site-connector' ),
				'done'  => $progress['has_role'],
				'hint'  => $progress['has_role']
					? __( 'Role exists and ships least-privilege capabilities for AI agents.', 'ai-site-connector' )
					: __( 'Role not found — it should be created automatically on activation. Try deactivating and reactivating the plugin.', 'ai-site-connector' ),
				'cta'   => '',
				'tab'   => '',
			),
			array(
				'label' => __( '3. Create the AI user', 'ai-site-connector' ),
				'done'  => $progress['has_ai_user'],
				'hint'  => $progress['has_ai_user']
					? __( 'An AI user already exists. You can reuse it or create a second one for a separate tool.', 'ai-site-connector' )
					: __( 'Open the Setup Wizard and create a dedicated user (recommended role: AI Site Operator).', 'ai-site-connector' ),
				'cta'   => __( 'Open Setup Wizard', 'ai-site-connector' ),
				'tab'   => 'wizard',
			),
			array(
				'label' => __( '4. Generate a connection pack', 'ai-site-connector' ),
				'done'  => $progress['has_password'],
				'hint'  => $progress['has_password']
					? __( 'At least one Application Password exists for the AI user. You can generate more on the Credentials tab.', 'ai-site-connector' )
					: __( 'On the Credentials tab, click Generate Connection Pack. The pre-flight check verifies the password works before you copy it.', 'ai-site-connector' ),
				'cta'   => __( 'Open Credentials', 'ai-site-connector' ),
				'tab'   => 'credentials',
			),
			array(
				'label' => __( '5. Pick a destination', 'ai-site-connector' ),
				'done'  => $progress['has_password'],
				'hint'  => __( 'When you generate the pack, the tabbed picker emits ready-to-paste snippets for Claude Desktop, Cursor, n8n / Make / Zapier, curl, Python, and Node.', 'ai-site-connector' ),
				'cta'   => __( 'Open Credentials', 'ai-site-connector' ),
				'tab'   => 'credentials',
			),
		);

		$done_count = 0;
		foreach ( $steps as $s ) {
			if ( ! empty( $s['done'] ) ) {
				$done_count++;
			}
		}
		$dismiss_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'ai_site_connector_dismiss_onboarding' ), admin_url( 'admin-post.php' ) ),
			'ai_site_connector_dismiss_onboarding'
		);
		?>
		<div class="asc-card">
			<h2><?php esc_html_e( 'Get started', 'ai-site-connector' ); ?></h2>
			<p class="description">
				<?php
				echo esc_html( sprintf(
					/* translators: 1: done step count, 2: total steps. */
					__( '%1$d of %2$d steps complete. Each step links to the tab that does the work.', 'ai-site-connector' ),
					$done_count,
					count( $steps )
				) );
				?>
			</p>
			<ol class="asc-onboarding-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li class="asc-onboarding-step <?php echo ! empty( $step['done'] ) ? 'asc-step-done' : 'asc-step-todo'; ?>">
						<span class="asc-onboarding-marker"><?php echo ! empty( $step['done'] ) ? '✓' : '○'; ?></span>
						<div>
							<strong><?php echo esc_html( $step['label'] ); ?></strong>
							<p class="description"><?php echo esc_html( $step['hint'] ); ?></p>
							<?php if ( ! empty( $step['cta'] ) && ! empty( $step['tab'] ) ) : ?>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => $step['tab'] ), admin_url( 'tools.php' ) ) ); ?>" class="button button-secondary"><?php echo esc_html( $step['cta'] ); ?></a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-primary"><?php esc_html_e( 'Mark setup complete', 'ai-site-connector' ); ?></a>
				<span class="description"><?php esc_html_e( 'Hides this welcome flow. You can still revisit all tabs from Tools → AI Site Connector.', 'ai-site-connector' ); ?></span>
			</p>
		</div>
		<?php
	}
}
