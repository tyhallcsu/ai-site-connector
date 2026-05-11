<?php
/**
 * Backup-before-update + one-click rollback for the self-updater.
 *
 * Before WordPress's Plugin_Upgrader replaces the plugin folder, copy the
 * current contents to wp-content/upgrade-backups/ai-site-connector/{version}/.
 * If a new version breaks something, the operator clicks "Rollback to X.Y.Z"
 * on the Updates card and the previous folder is swapped back in.
 *
 * Keeps the last 3 backups, prunes older.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Backup_Manager {

	const KEEP_BACKUPS = 3;

	public static function register_hooks() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'pre_install' ), 10, 2 );
		add_action( 'admin_post_ai_site_connector_rollback', array( __CLASS__, 'handle_rollback' ) );
	}

	public static function backup_base_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'upgrade-backups/ai-site-connector';
	}

	/**
	 * Hooked on upgrader_pre_install. Snapshot the current plugin folder
	 * before WordPress overwrites it. Only acts on this plugin.
	 *
	 * @param true|WP_Error $return     Default true (continue). Anything else aborts the upgrade.
	 * @param array         $hook_extra Upgrader context.
	 * @return true|WP_Error
	 */
	public static function pre_install( $return, $hook_extra ) {
		if ( is_wp_error( $return ) ) {
			return $return;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return $return;
		}
		if ( AI_SITE_CONNECTOR_BASENAME !== $hook_extra['plugin'] ) {
			return $return;
		}

		$version = AI_SITE_CONNECTOR_VERSION;
		$src     = trailingslashit( WP_PLUGIN_DIR ) . 'ai-site-connector';
		$dest    = trailingslashit( self::backup_base_dir() ) . $version;

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			AI_Site_Connector_Audit_Log::record(
				'update_backup_skipped',
				array( 'message' => __( 'WP_Filesystem unavailable; skipped pre-update backup.', 'ai-site-connector' ) )
			);
			return $return;
		}

		// Ensure base dir exists.
		$base = self::backup_base_dir();
		if ( ! $wp_filesystem->exists( $base ) ) {
			$wp_filesystem->mkdir( $base, FS_CHMOD_DIR );
		}

		// Wipe any pre-existing backup for this exact version (shouldn't normally happen).
		if ( $wp_filesystem->exists( $dest ) ) {
			$wp_filesystem->delete( $dest, true );
		}
		$wp_filesystem->mkdir( $dest, FS_CHMOD_DIR );

		if ( ! $wp_filesystem->exists( $src ) ) {
			AI_Site_Connector_Audit_Log::record(
				'update_backup_skipped',
				array( 'message' => sprintf(
					/* translators: %s: plugin source path. */
					__( 'Plugin source directory missing at %s; skipped pre-update backup.', 'ai-site-connector' ),
					$src
				) )
			);
			return $return;
		}

		$ok = copy_dir( $src, $dest );
		if ( is_wp_error( $ok ) ) {
			AI_Site_Connector_Audit_Log::record(
				'update_backup_failed',
				array( 'message' => sprintf(
					/* translators: 1: version, 2: error message. */
					__( 'Pre-update backup failed for v%1$s: %2$s', 'ai-site-connector' ),
					$version,
					$ok->get_error_message()
				) )
			);
			// Don't block the upgrade — log and continue.
			return $return;
		}

		AI_Site_Connector_Audit_Log::record(
			'update_backup_created',
			array( 'message' => sprintf(
				/* translators: 1: version, 2: backup path. */
				__( 'Pre-update backup created for v%1$s at %2$s', 'ai-site-connector' ),
				$version,
				$dest
			) )
		);

		self::prune_old_backups();
		return $return;
	}

	/**
	 * List available backup versions, most recent first.
	 *
	 * @return array<int, array{version:string, path:string, modified:int}>
	 */
	public static function available_backups() {
		$base = self::backup_base_dir();
		if ( ! is_dir( $base ) ) {
			return array();
		}
		$out = array();
		$dh  = @opendir( $base );
		if ( ! $dh ) {
			return array();
		}
		while ( false !== ( $entry = readdir( $dh ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = trailingslashit( $base ) . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			// Skip the currently-installed version — never a useful rollback target.
			if ( $entry === AI_SITE_CONNECTOR_VERSION ) {
				continue;
			}
			$out[] = array(
				'version'  => $entry,
				'path'     => $path,
				'modified' => (int) @filemtime( $path ),
			);
		}
		closedir( $dh );
		usort( $out, static function ( $a, $b ) {
			return $b['modified'] - $a['modified'];
		} );
		return $out;
	}

	private static function prune_old_backups() {
		$base = self::backup_base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		// Include the currently-installed version's backup (if any) in the
		// retention count — but never delete it.
		$all = array();
		$dh  = @opendir( $base );
		if ( ! $dh ) {
			return;
		}
		while ( false !== ( $entry = readdir( $dh ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = trailingslashit( $base ) . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$all[] = array( 'version' => $entry, 'path' => $path, 'modified' => (int) @filemtime( $path ) );
		}
		closedir( $dh );
		usort( $all, static function ( $a, $b ) {
			return $b['modified'] - $a['modified'];
		} );
		$kept = 0;
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		foreach ( $all as $entry ) {
			$kept++;
			if ( $kept <= self::KEEP_BACKUPS ) {
				continue;
			}
			if ( $entry['version'] === AI_SITE_CONNECTOR_VERSION ) {
				continue;
			}
			if ( $wp_filesystem ) {
				$wp_filesystem->delete( $entry['path'], true );
			}
		}
	}

	public static function handle_rollback() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions to rollback plugin.', 'ai-site-connector' ) );
		}
		check_admin_referer( 'ai_site_connector_rollback' );

		$to_version_raw = isset( $_POST['to_version'] ) ? sanitize_text_field( wp_unslash( $_POST['to_version'] ) ) : '';
		// Tight whitelist — only chars semver tags use.
		if ( ! preg_match( '/^[A-Za-z0-9.\-_]+$/', $to_version_raw ) ) {
			self::redirect_with_flash( __( 'Invalid rollback target.', 'ai-site-connector' ), 'error' );
		}
		$to_version = $to_version_raw;
		$src        = trailingslashit( self::backup_base_dir() ) . $to_version;
		$dest       = trailingslashit( WP_PLUGIN_DIR ) . 'ai-site-connector';
		$from       = AI_SITE_CONNECTOR_VERSION;

		if ( ! is_dir( $src ) ) {
			AI_Site_Connector_Audit_Log::record(
				'update_rollback_failed',
				array( 'message' => sprintf(
					/* translators: %s: requested version. */
					__( 'Rollback target v%s missing on disk.', 'ai-site-connector' ),
					$to_version
				) )
			);
			self::redirect_with_flash(
				sprintf(
					/* translators: %s: requested version. */
					__( 'Rollback target v%s is missing on disk.', 'ai-site-connector' ),
					$to_version
				),
				'error'
			);
		}

		AI_Site_Connector_Audit_Log::record(
			'update_rollback_started',
			array( 'message' => sprintf(
				/* translators: 1: current version, 2: target version. */
				__( 'Rollback started: v%1$s → v%2$s.', 'ai-site-connector' ),
				$from,
				$to_version
			) )
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			self::redirect_with_flash( __( 'Filesystem unavailable; rollback aborted.', 'ai-site-connector' ), 'error' );
		}

		// Deactivate plugin (silent — do not run deactivation hooks that may rely on the new version).
		$was_active = is_plugin_active( AI_SITE_CONNECTOR_BASENAME );
		if ( $was_active ) {
			deactivate_plugins( AI_SITE_CONNECTOR_BASENAME, true ); // silent = true
		}

		// Save a snapshot of the version we're rolling AWAY from, so the operator can re-apply if needed.
		$rollaway_dest = trailingslashit( self::backup_base_dir() ) . $from;
		if ( ! $wp_filesystem->exists( $rollaway_dest ) ) {
			$wp_filesystem->mkdir( $rollaway_dest, FS_CHMOD_DIR );
			copy_dir( $dest, $rollaway_dest );
		}

		// Replace plugin folder with the backup.
		$wp_filesystem->delete( $dest, true );
		$wp_filesystem->mkdir( $dest, FS_CHMOD_DIR );
		$copy = copy_dir( $src, $dest );
		if ( is_wp_error( $copy ) ) {
			AI_Site_Connector_Audit_Log::record(
				'update_rollback_failed',
				array( 'message' => sprintf(
					/* translators: 1: target version, 2: error. */
					__( 'Rollback to v%1$s failed during copy: %2$s', 'ai-site-connector' ),
					$to_version,
					$copy->get_error_message()
				) )
			);
			self::redirect_with_flash(
				sprintf(
					/* translators: %s: target version. */
					__( 'Rollback to v%s failed; check error log. The plugin may need manual restoration.', 'ai-site-connector' ),
					$to_version
				),
				'error'
			);
		}

		// Reactivate.
		if ( $was_active ) {
			$activate = activate_plugin( AI_SITE_CONNECTOR_BASENAME, '', false, true );
			if ( is_wp_error( $activate ) ) {
				AI_Site_Connector_Audit_Log::record(
					'update_rollback_failed',
					array( 'message' => sprintf(
						/* translators: 1: target version, 2: error. */
						__( 'Rollback to v%1$s copy OK but reactivation failed: %2$s', 'ai-site-connector' ),
						$to_version,
						$activate->get_error_message()
					) )
				);
			}
		}

		AI_Site_Connector_Audit_Log::record(
			'update_rollback_completed',
			array( 'message' => sprintf(
				/* translators: 1: previous version, 2: target version. */
				__( 'Rollback completed: v%1$s → v%2$s.', 'ai-site-connector' ),
				$from,
				$to_version
			) )
		);

		// Clear the updater's cached release so the Updates card refreshes.
		delete_site_transient( 'ai_site_connector_remote_release' );
		delete_site_transient( 'update_plugins' );

		self::redirect_with_flash(
			sprintf(
				/* translators: 1: previous version, 2: target version. */
				__( 'Rolled back from v%1$s to v%2$s.', 'ai-site-connector' ),
				$from,
				$to_version
			),
			'success'
		);
	}

	private static function redirect_with_flash( $msg, $type ) {
		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => (string) $msg,
				'type'  => (string) $type,
				'extra' => array(),
			),
			60
		);
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'overview' ),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
