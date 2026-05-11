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
	const DB_VERSION           = '2';
	const RETENTION_OPTION     = 'ai_site_connector_log_retention_days';
	const DEFAULT_RETENTION    = 90;
	const MIN_KEEP_ROWS        = 100;
	const CRON_HOOK            = 'ai_site_connector_audit_log_prune';

	const STATUS_SUCCESS = 'success';
	const STATUS_FAILURE = 'failure';
	const STATUS_DENIED  = 'denied';
	const STATUS_INFO    = 'info';

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
		// Schema v2 (Nov 2026): added tool, target_type, target_id, status,
		// summary, request_hash, ip_hash, meta columns to support per-tool
		// audit attribution. dbDelta will ALTER the existing v1 table in
		// place — pre-existing rows get default values for the new columns.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			action VARCHAR(64) NOT NULL,
			tool VARCHAR(64) NOT NULL DEFAULT '',
			target_type VARCHAR(64) NOT NULL DEFAULT '',
			target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT '',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(64) NOT NULL DEFAULT '',
			ip_hash VARCHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			message TEXT NOT NULL,
			summary TEXT NULL,
			request_hash VARCHAR(64) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_action (action),
			KEY idx_tool (tool),
			KEY idx_status (status),
			KEY idx_actor (actor_user_id),
			KEY idx_target (target_user_id),
			KEY idx_target_obj (target_type, target_id),
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

	/**
	 * SHA-256 hash of an IP address, keyed by the site's auth salt. Same IP
	 * on a different WP install hashes to a different value — prevents
	 * cross-site IP correlation from a stolen audit dump.
	 *
	 * @param string $ip Raw IP.
	 * @return string 64-char hex hash, or '' for empty input.
	 */
	public static function hash_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Record an audit-log entry.
	 *
	 * Accepts both the legacy v1 args ({ actor_user_id, target_user_id,
	 * message }) and the v2 tool-attribution args ({ tool, target_type,
	 * target_id, status, summary, request_hash, meta }). Unknown args are
	 * silently ignored — forward-compatible callers can pass extra keys
	 * without checking the running plugin version.
	 *
	 * No secrets are stored. `meta` is JSON-encoded but the caller is
	 * responsible for redacting before passing — wrap-handlers in
	 * class-rest-controller / class-media / class-cache strip headers,
	 * cookies, and credential payloads before invoking this method.
	 *
	 * @param string $action Action slug (sanitize_key()'d, truncated to 64).
	 * @param array  $args   See body for full list.
	 */
	public static function record( $action, $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'actor_user_id'  => get_current_user_id(),
				'target_user_id' => 0,
				'message'        => '',
				'tool'           => '',
				'target_type'    => '',
				'target_id'      => 0,
				'status'         => '',
				'summary'        => '',
				'request_hash'   => '',
				'meta'           => null,
			)
		);

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$ip_hash    = self::hash_ip( $ip );

		// Backward compat: by default we keep the raw IP for old admin
		// callers that pre-date the hash column. Sites that want stricter
		// privacy can filter to '' to store only the hash.
		/**
		 * Filter the raw IP that's about to be persisted.
		 *
		 * Return an empty string to store only the hashed form. Useful for
		 * stricter privacy regimes (GDPR-leaning sites that want no
		 * recoverable IP at rest).
		 *
		 * @param string $ip      Raw IP.
		 * @param string $action  Action slug being recorded.
		 */
		$ip = (string) apply_filters( 'ai_site_connector_log_raw_ip', $ip, $action );

		$meta = $args['meta'];
		if ( null !== $meta && ! is_string( $meta ) ) {
			$encoded = wp_json_encode( $meta );
			$meta    = false === $encoded ? '' : $encoded;
		}
		if ( is_string( $meta ) && strlen( $meta ) > 65535 ) {
			// Truncation guard — we never want a single oversize meta blob
			// to break audit-log writes for every tool call after it.
			$meta = substr( $meta, 0, 65500 ) . '"…truncated"';
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'     => current_time( 'mysql', true ),
				'action'         => substr( sanitize_key( $action ), 0, 64 ),
				'tool'           => substr( sanitize_key( (string) $args['tool'] ), 0, 64 ),
				'target_type'    => substr( sanitize_key( (string) $args['target_type'] ), 0, 64 ),
				'target_id'      => (int) $args['target_id'],
				'status'         => substr( sanitize_key( (string) $args['status'] ), 0, 32 ),
				'actor_user_id'  => (int) $args['actor_user_id'],
				'target_user_id' => (int) $args['target_user_id'],
				'ip'             => $ip,
				'ip_hash'        => $ip_hash,
				'user_agent'     => $user_agent,
				'message'        => wp_kses_post( (string) $args['message'] ),
				'summary'        => wp_kses_post( (string) $args['summary'] ),
				'request_hash'   => substr( preg_replace( '/[^a-f0-9]/i', '', (string) $args['request_hash'] ), 0, 64 ),
				'meta'           => $meta,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function recent( $limit = 50, $filters = array() ) {
		global $wpdb;
		$table   = self::table_name();
		$limit   = max( 1, min( 500, (int) $limit ) );
		$where   = array( '1=1' );
		$prepare = array();

		if ( ! empty( $filters['action'] ) ) {
			$where[]   = 'action = %s';
			$prepare[] = substr( sanitize_key( (string) $filters['action'] ), 0, 64 );
		}
		if ( ! empty( $filters['tool'] ) ) {
			$where[]   = 'tool = %s';
			$prepare[] = substr( sanitize_key( (string) $filters['tool'] ), 0, 64 );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = substr( sanitize_key( (string) $filters['status'] ), 0, 32 );
		}
		if ( ! empty( $filters['actor_user_id'] ) ) {
			$where[]   = 'actor_user_id = %d';
			$prepare[] = (int) $filters['actor_user_id'];
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]   = 'created_at >= %s';
			$prepare[] = (string) $filters['since'];
		}

		$where_sql = implode( ' AND ', $where );
		$prepare[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, $prepare ) );
	}

	/**
	 * Distinct tool slugs ever recorded — drives the audit-tab filter dropdown.
	 *
	 * @return string[]
	 */
	public static function distinct_tools() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching -- UI dropdown, no caching.
		$rows = $wpdb->get_col( "SELECT DISTINCT tool FROM {$table} WHERE tool <> '' ORDER BY tool ASC LIMIT 50" );
		return is_array( $rows ) ? array_values( array_filter( array_map( 'strval', $rows ) ) ) : array();
	}

	/**
	 * CSV export of audit rows — streamed via admin_post handler.
	 *
	 * @param array $filters See recent().
	 * @return string CSV bytes.
	 */
	public static function export_csv( $filters = array() ) {
		$rows = self::recent( 500, $filters );
		$fh   = fopen( 'php://temp', 'r+' );
		if ( false === $fh ) {
			return '';
		}
		fputcsv( $fh, array( 'id', 'created_at_utc', 'action', 'tool', 'status', 'actor_user_id', 'target_type', 'target_id', 'target_user_id', 'ip_hash', 'user_agent', 'summary', 'message' ) );
		foreach ( $rows as $r ) {
			fputcsv(
				$fh,
				array(
					$r->id,
					$r->created_at,
					$r->action,
					$r->tool,
					$r->status,
					$r->actor_user_id,
					$r->target_type,
					$r->target_id,
					$r->target_user_id,
					$r->ip_hash,
					$r->user_agent,
					$r->summary,
					$r->message,
				)
			);
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return is_string( $csv ) ? $csv : '';
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
