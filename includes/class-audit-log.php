<?php
/**
 * Audit log: custom DB table for plugin events.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Audit_Log {

	const DB_VERSION_OPTION = 'ai_site_connector_db_version';
	const DB_VERSION        = '1';

	public static function register_hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ai_site_connector_log';
	}

	public static function maybe_upgrade() {
		global $wpdb;
		$version_ok = get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION;

		// Self-heal: verify the audit table actually exists, not just that
		// the version option is set. The option can drift if a previous
		// dbDelta run failed silently (some MySQL strict-mode configurations
		// reject schema variants), or if the database was restored from a
		// backup that predates plugin activation. Without this check, the
		// audit log silently disappears.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time install/upgrade check.
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table_name() ) );

		if ( ! $version_ok || ! $table_exists ) {
			self::install_table();
		}
	}

	public static function install_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive — keep the format below stable.
		// `action` is intentionally not a reserved word in MySQL 8.0; quoting
		// it would actually break dbDelta's column-rename detection.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			action VARCHAR(64) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_action (action),
			KEY idx_actor (actor_user_id),
			KEY idx_target (target_user_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify the table actually landed before bumping the version option.
		// dbDelta returns silently on failure under some MySQL configurations;
		// gating the version bump on real existence keeps maybe_upgrade() honest.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time install/upgrade check.
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table_exists ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	public static function record( $action, $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'actor_user_id'  => get_current_user_id(),
				'target_user_id' => 0,
				'message'        => '',
			)
		);

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'     => current_time( 'mysql', true ),
				'action'         => substr( sanitize_key( $action ), 0, 64 ),
				'actor_user_id'  => (int) $args['actor_user_id'],
				'target_user_id' => (int) $args['target_user_id'],
				'ip'             => $ip,
				'user_agent'     => $user_agent,
				'message'        => wp_kses_post( $args['message'] ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 500, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}
}
