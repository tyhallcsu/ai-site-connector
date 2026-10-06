<?php
/**
 * Backup-before-update + one-click rollback for the self-updater.
 *
 * Before WordPress's Plugin_Upgrader replaces the plugin folder, copy the
 * current contents to wp-content/upgrade-backups/ai-site-connector/{version}/.
 * If a new version breaks something, the operator clicks "Rollback to X.Y.Z"
 * on the Updates card and the previous folder is swapped back in.
 *
 * Integrity (#114): a backup is built in a hidden staging directory, compared
 * file by file with its source, given a manifest and only then moved into
 * place, so an interrupted copy never looks like a backup. Rollback validates
 * its target, saves a verified copy of the installed version and stages the
 * replacement before the installed plugin is touched, then swaps directories
 * by rename and restores the original if the swap fails.
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

	/** Written into each verified backup; lists every file's sha256. */
	const MANIFEST = '.asc-backup-manifest.json';

	public static function register_hooks() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'pre_install' ), 10, 2 );
		add_action( 'admin_post_ai_site_connector_rollback', array( __CLASS__, 'handle_rollback' ) );
	}

	public static function backup_base_dir() {
		return trailingslashit( WP_CONTENT_DIR ) . 'upgrade-backups/ai-site-connector';
	}

	/**
	 * Hooked on upgrader_pre_install. Snapshot the current plugin folder
	 * before WordPress overwrites it. Only acts on this plugin. A failed
	 * backup is logged and never blocks the upgrade.
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

		$src = trailingslashit( WP_PLUGIN_DIR ) . 'ai-site-connector';
		if ( ! is_dir( $src ) ) {
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

		if ( true === self::create_backup( $src, AI_SITE_CONNECTOR_VERSION ) ) {
			self::prune_old_backups();
		}
		return $return;
	}

	/**
	 * Copy $src into a verified backup for $version.
	 *
	 * The copy goes to a hidden staging directory, is compared file by file
	 * with $src, gets a manifest, and only then replaces
	 * backup_base_dir()/$version. On any failure the staging directory is
	 * removed and an existing backup for $version is left untouched.
	 *
	 * @param string        $src     Directory to back up.
	 * @param string        $version Version label (backup directory name).
	 * @param callable|null $copier  Copy function ($from, $to) returning true|WP_Error; defaults to copy_dir(). Tests inject failures here.
	 * @return true|WP_Error
	 */
	public static function create_backup( $src, $version, $copier = null ) {
		$fs = self::filesystem();
		if ( ! $fs ) {
			return self::backup_failed( $version, __( 'WP_Filesystem unavailable.', 'ai-site-connector' ) );
		}
		$base = self::backup_base_dir();
		if ( ! $fs->exists( $base ) && ! wp_mkdir_p( $base ) ) {
			return self::backup_failed( $version, __( 'Could not create the backup directory.', 'ai-site-connector' ) );
		}

		$expected = self::file_hashes( $src );
		if ( empty( $expected ) ) {
			return self::backup_failed( $version, __( 'The source directory has no readable files.', 'ai-site-connector' ) );
		}

		$staging = trailingslashit( $base ) . '.partial-' . $version . '-' . wp_generate_password( 8, false );
		if ( ! $fs->mkdir( $staging, FS_CHMOD_DIR ) ) {
			return self::backup_failed( $version, __( 'Could not create a staging directory.', 'ai-site-connector' ) );
		}

		$copy = call_user_func( $copier ? $copier : 'copy_dir', $src, $staging );
		if ( is_wp_error( $copy ) || self::file_hashes( $staging ) !== $expected ) {
			$fs->delete( $staging, true );
			return self::backup_failed(
				$version,
				is_wp_error( $copy ) ? $copy->get_error_message() : __( 'The copy does not match the installed files.', 'ai-site-connector' )
			);
		}

		$manifest = array(
			'version'    => (string) $version,
			'created_at' => gmdate( 'c' ),
			'files'      => $expected,
		);
		if ( ! $fs->put_contents( trailingslashit( $staging ) . self::MANIFEST, wp_json_encode( $manifest ), FS_CHMOD_FILE ) ) {
			$fs->delete( $staging, true );
			return self::backup_failed( $version, __( 'Could not write the backup manifest.', 'ai-site-connector' ) );
		}

		$dest = trailingslashit( $base ) . $version;
		if ( $fs->exists( $dest ) ) {
			$fs->delete( $dest, true );
		}
		if ( ! $fs->move( $staging, $dest ) ) {
			$fs->delete( $staging, true );
			return self::backup_failed( $version, __( 'Could not move the verified backup into place.', 'ai-site-connector' ) );
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
		return true;
	}

	/**
	 * Whether $dir is a complete, usable copy of the plugin at $version.
	 *
	 * Backups with a manifest must match it file for file. Every backup, with
	 * or without one, must be bootable: the main file declares $version and
	 * every file it requires exists and is non-empty. Older releases wrote no
	 * manifest, so the bootable check alone covers their backups.
	 *
	 * @param string $dir     Backup directory.
	 * @param string $version Expected plugin version.
	 * @return true|WP_Error
	 */
	public static function validate_backup( $dir, $version ) {
		$dir = untrailingslashit( (string) $dir );
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'backup_missing', __( 'The backup directory does not exist.', 'ai-site-connector' ) );
		}

		$manifest_file = $dir . '/' . self::MANIFEST;
		if ( file_exists( $manifest_file ) ) {
			$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
			if ( ! is_array( $manifest ) || ! isset( $manifest['version'], $manifest['files'] ) || ! is_array( $manifest['files'] ) || (string) $manifest['version'] !== (string) $version ) {
				return new WP_Error( 'backup_manifest_invalid', __( 'The backup manifest is unreadable or for another version.', 'ai-site-connector' ) );
			}
			if ( self::file_hashes( $dir ) !== $manifest['files'] ) {
				return new WP_Error( 'backup_incomplete', __( 'The backup no longer matches its manifest (files missing or changed).', 'ai-site-connector' ) );
			}
		}

		$main = $dir . '/ai-site-connector.php';
		if ( ! is_readable( $main ) ) {
			return new WP_Error( 'backup_incomplete', __( 'The backup has no main plugin file.', 'ai-site-connector' ) );
		}
		$header = get_file_data( $main, array( 'Version' => 'Version' ) );
		if ( (string) $header['Version'] !== (string) $version ) {
			return new WP_Error( 'backup_version_mismatch', __( 'The backup main file declares a different version.', 'ai-site-connector' ) );
		}
		preg_match_all( "/require_once\s+AI_SITE_CONNECTOR_DIR\s*\.\s*'([^']+)'/", (string) file_get_contents( $main ), $requires );
		if ( empty( $requires[1] ) ) {
			return new WP_Error( 'backup_incomplete', __( 'The backup main file loads no plugin classes.', 'ai-site-connector' ) );
		}
		foreach ( $requires[1] as $relative ) {
			$file = $dir . '/' . ltrim( $relative, '/' );
			if ( ! is_file( $file ) || 0 === (int) filesize( $file ) ) {
				return new WP_Error(
					'backup_incomplete',
					sprintf(
						/* translators: %s: relative file path. */
						__( 'The backup is missing %s.', 'ai-site-connector' ),
						$relative
					)
				);
			}
		}
		return true;
	}

	/**
	 * sha256 of every file under $dir keyed by relative path (sorted), except
	 * a top-level backup manifest.
	 *
	 * @param string $dir Directory.
	 * @return array<string, string>
	 */
	public static function file_hashes( $dir ) {
		$dir = untrailingslashit( (string) $dir );
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$hashes = array();
		$files  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $files as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
			if ( self::MANIFEST === $relative ) {
				continue;
			}
			$hashes[ $relative ] = (string) hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $hashes, SORT_STRING );
		return $hashes;
	}

	/**
	 * Valid backup versions, most recent first. Incomplete backups and hidden
	 * staging directories are never offered (#114).
	 *
	 * @return array<int, array{version:string, path:string, modified:int}>
	 */
	public static function available_backups() {
		$out = array();
		foreach ( self::backup_dirs() as $entry ) {
			// Skip the currently-installed version — never a useful rollback target.
			if ( AI_SITE_CONNECTOR_VERSION === $entry['version'] ) {
				continue;
			}
			if ( true !== self::validate_backup( $entry['path'], $entry['version'] ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Visible backup directories, newest first.
	 *
	 * @return array<int, array{version:string, path:string, modified:int}>
	 */
	private static function backup_dirs() {
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
			// Hidden entries are staging directories or not ours.
			if ( '.' === substr( $entry, 0, 1 ) ) {
				continue;
			}
			$path = trailingslashit( $base ) . $entry;
			if ( ! is_dir( $path ) ) {
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
		// The currently-installed version's backup (if any) counts toward
		// retention but is never deleted.
		$fs   = self::filesystem();
		$kept = 0;
		foreach ( self::backup_dirs() as $entry ) {
			$kept++;
			if ( $kept <= self::KEEP_BACKUPS ) {
				continue;
			}
			if ( AI_SITE_CONNECTOR_VERSION === $entry['version'] ) {
				continue;
			}
			if ( $fs ) {
				$fs->delete( $entry['path'], true );
			}
		}
	}

	public static function handle_rollback() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions to rollback plugin.', 'ai-site-connector' ) );
		}
		check_admin_referer( 'ai_site_connector_rollback' );

		$to_version = isset( $_POST['to_version'] ) ? sanitize_text_field( wp_unslash( $_POST['to_version'] ) ) : '';
		$result     = self::rollback_to( $to_version );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_flash( $result->get_error_message(), 'error' );
		}
		self::redirect_with_flash(
			sprintf(
				/* translators: 1: previous version, 2: target version. */
				__( 'Rolled back from v%1$s to v%2$s.', 'ai-site-connector' ),
				$result['from'],
				$result['to']
			),
			'success'
		);
	}

	/**
	 * Replace the installed plugin with the backup for $to_version.
	 *
	 * Order: validate the target; save a verified copy of the installed
	 * version; stage and verify the replacement beside the installed plugin;
	 * swap the directories by rename, restoring the original on failure. The
	 * installed plugin is only touched in the swap step.
	 *
	 * @param string        $to_version Backup version to restore.
	 * @param callable|null $copier     Copy function ($from, $to) returning true|WP_Error; defaults to copy_dir(). Tests inject failures here.
	 * @return array{from:string, to:string}|WP_Error
	 */
	public static function rollback_to( $to_version, $copier = null ) {
		$to_version = (string) $to_version;
		// Tight whitelist — only chars semver tags use; never a hidden entry.
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9.\-_]*$/', $to_version ) ) {
			return new WP_Error( 'rollback_invalid_target', __( 'Invalid rollback target.', 'ai-site-connector' ) );
		}
		$copier = $copier ? $copier : 'copy_dir';
		$src    = trailingslashit( self::backup_base_dir() ) . $to_version;
		$dest   = trailingslashit( WP_PLUGIN_DIR ) . 'ai-site-connector';
		$from   = AI_SITE_CONNECTOR_VERSION;

		if ( is_link( $dest ) ) {
			// Renaming and deleting would act on the link target (often a developer checkout).
			return self::rollback_failed( $to_version, __( 'The plugin directory is a symbolic link; rollback refused so the link target is never modified. Change versions with your deployment tool instead.', 'ai-site-connector' ) );
		}

		$valid = self::validate_backup( $src, $to_version );
		if ( is_wp_error( $valid ) ) {
			return self::rollback_failed(
				$to_version,
				sprintf(
					/* translators: 1: target version, 2: reason. */
					__( 'Rollback to v%1$s refused: %2$s The installed plugin was not changed.', 'ai-site-connector' ),
					$to_version,
					$valid->get_error_message()
				)
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

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$fs = self::filesystem();
		if ( ! $fs ) {
			return self::rollback_failed( $to_version, __( 'Filesystem unavailable; rollback aborted. The installed plugin was not changed.', 'ai-site-connector' ) );
		}

		// 1. A verified copy of the version we roll away from, so the operator can re-apply it.
		$rollaway = trailingslashit( self::backup_base_dir() ) . $from;
		if ( true !== self::validate_backup( $rollaway, $from ) ) {
			$saved = self::create_backup( $dest, $from, $copier );
			if ( is_wp_error( $saved ) ) {
				return self::rollback_failed(
					$to_version,
					sprintf(
						/* translators: 1: current version, 2: reason. */
						__( 'Could not save a verified copy of v%1$s (%2$s); rollback aborted. The installed plugin was not changed.', 'ai-site-connector' ),
						$from,
						$saved->get_error_message()
					)
				);
			}
		}

		// 2. Stage the replacement beside the installed plugin and verify it.
		// Hidden names keep WordPress from listing the directories as plugins.
		$suffix   = wp_generate_password( 8, false );
		$staged   = trailingslashit( WP_PLUGIN_DIR ) . '.ai-site-connector-rollback-' . $suffix;
		$retired  = trailingslashit( WP_PLUGIN_DIR ) . '.ai-site-connector-replaced-' . $suffix;
		$expected = self::file_hashes( $src );
		$copy     = $fs->mkdir( $staged, FS_CHMOD_DIR ) ? call_user_func( $copier, $src, $staged ) : new WP_Error( 'mkdir_failed', __( 'Could not create a staging directory.', 'ai-site-connector' ) );
		if ( is_wp_error( $copy ) || self::file_hashes( $staged ) !== $expected ) {
			$fs->delete( $staged, true );
			return self::rollback_failed(
				$to_version,
				sprintf(
					/* translators: 1: target version, 2: reason. */
					__( 'Could not stage v%1$s (%2$s); rollback aborted. The installed plugin was not changed.', 'ai-site-connector' ),
					$to_version,
					is_wp_error( $copy ) ? $copy->get_error_message() : __( 'the staged copy does not match the backup', 'ai-site-connector' )
				)
			);
		}
		$fs->delete( trailingslashit( $staged ) . self::MANIFEST );

		// 3. Swap by rename. Deactivate silently so no hooks of either version run mid-swap.
		// Record the scope so a network activation is restored as such (#130).
		$activation = AI_Site_Connector_Plugin::activation_state();
		$was_active = $activation['active'];
		if ( $was_active ) {
			deactivate_plugins( AI_SITE_CONNECTOR_BASENAME, true, $activation['network'] ? true : null );
		}
		$swapped  = false;
		$restored = true;
		if ( $fs->move( $dest, $retired ) ) {
			$swapped = $fs->move( $staged, $dest );
			if ( ! $swapped ) {
				$restored = $fs->move( $retired, $dest );
			}
		}
		if ( ! $swapped ) {
			$fs->delete( $staged, true );
			if ( ! $restored ) {
				return self::rollback_failed(
					$to_version,
					sprintf(
						/* translators: 1: path of the original plugin copy, 2: plugin directory. */
						__( 'Rollback aborted, and the original plugin could not be moved back automatically. Move %1$s to %2$s to restore it.', 'ai-site-connector' ),
						$retired,
						$dest
					)
				);
			}
			AI_Site_Connector_Plugin::restore_activation( $activation, true );
			return self::rollback_failed(
				$to_version,
				sprintf(
					/* translators: %s: current version. */
					__( 'Could not swap the plugin directories; rollback aborted and v%s left in place.', 'ai-site-connector' ),
					$from
				)
			);
		}
		$fs->delete( $retired, true );

		if ( $was_active ) {
			$activate = AI_Site_Connector_Plugin::restore_activation( $activation, true );
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

		return array(
			'from' => $from,
			'to'   => $to_version,
		);
	}

	/**
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem ? $wp_filesystem : null;
	}

	private static function backup_failed( $version, $reason ) {
		AI_Site_Connector_Audit_Log::record(
			'update_backup_failed',
			array( 'message' => sprintf(
				/* translators: 1: version, 2: error message. */
				__( 'Pre-update backup failed for v%1$s: %2$s', 'ai-site-connector' ),
				$version,
				$reason
			) )
		);
		return new WP_Error( 'backup_failed', (string) $reason );
	}

	private static function rollback_failed( $to_version, $message ) {
		AI_Site_Connector_Audit_Log::record( 'update_rollback_failed', array( 'message' => (string) $message ) );
		return new WP_Error( 'rollback_failed', (string) $message );
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
