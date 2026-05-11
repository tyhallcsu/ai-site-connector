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

	const DB_VERSION_OPTION    = 'ai_site_connector_db_version';
	const DB_VERSION           = '1';
	const RETENTION_OPTION     = 'ai_site_connector_log_retention_days';
	const DEFAULT_RETENTION    = 90;
	const MIN_KEEP_ROWS        = 100;
	const CRON_HOOK            = 'ai_site_connector_audit_log_prune';

	public static function register_hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_schedule_cron' ) );
	}

	/**
	 * Effective retention window in days. Filterable. Hard floor of 1 day, hard
	 * ceiling of 3650 days (~10 years) so a hostile filter can't disable the
	 * pruner by returning a negative or absurd value.
	 */
	public static function retention_days() {
		$days = (int) get_option( self::RETENTION_OPTION, self::DEFAULT_RETENTION );
		if ( $days <= 0 ) {
			$days = self::DEFAULT_RETENTION;
		}

		/**
		 * Filter the audit-log retention window in days.
		 *
		 * Returning <=0 falls back to the default (90). Values are clamped to [1, 3650].
		 *
		 * @param int $days Retention in days.
		 */
		$days = (int) apply_filters( 'ai_site_connector_log_retention_days', $days );
		return max( 1, min( 3650, $days ) );
	}

	/**
	 * Daily cron registration. Idempotent — re-runs on every load are cheap.
	 */
	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Delete audit-log rows older than the retention window, while ALWAYS
	 * preserving the most recent MIN_KEEP_ROWS rows so debugging is possible
	 * even on misconfigured retention.
	 *
	 * Returns the number of rows deleted (0 if nothing to do, false on error).
	 */
	public static function prune() {
		/**
		 * Filter to disable the pruner entirely. Returning true keeps every row.
		 *
		 * @param bool $skip Default false.
		 */
		if ( apply_filters( 'ai_site_connector_log_skip_prune', false ) ) {
			return 0;
		}

		global $wpdb;
		$table = self::table_name();
		$days  = self::retention_days();

		// Floor: the id of the (MIN_KEEP_ROWS)th most recent row. Anything with
		// a smaller id is older than the floor and eligible for pruning, but
		// only if it ALSO predates the retention cutoff.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance query, no caching.
		$floor_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MIN_KEEP_ROWS - 1 ) );
		if ( ! $floor_id ) {
			// Fewer than MIN_KEEP_ROWS rows exist — nothing to prune.
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance query, no caching.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id < %d AND created_at < %s",
				(int) $floor_id,
				$cutoff
			)
		);

		if ( false === $deleted ) {
			return false;
		}
		$deleted = (int) $deleted;

		if ( $deleted > 0 ) {
			self::record(
				'audit_log_pruned',
				array(
					'message' => sprintf(
						'Pruned %d audit log row(s) older than %d days. Floor row id preserved: %d.',
						$deleted,
						$days,
						(int) $floor_id
					),
				)
			);
		}

		return $deleted;
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

	/**
	 * Fetch audit rows newer than $cutoff_unix_timestamp.
	 *
	 * @param int $cutoff_unix_timestamp Unix epoch. Rows with `created_at` strictly after this are returned.
	 * @param int $limit                 Max rows. Clamped to [1, 5000].
	 * @return array Rows (most recent first).
	 */
	public static function since( $cutoff_unix_timestamp, $limit = 1000 ) {
		global $wpdb;
		$table  = self::table_name();
		$limit  = max( 1, min( 5000, (int) $limit ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) $cutoff_unix_timestamp );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id DESC LIMIT %d", $cutoff, $limit )
		);
	}
}
