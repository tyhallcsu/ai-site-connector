<?php
/**
 * Backup integrity and safe rollback (#114). Fixture backups use 0.0.x
 * versions and are removed by the case that creates them. The round-trip
 * case swaps the installed plugin for a byte-identical copy and back.
 *
 * @package AI_Site_Connector_Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

/** Installed plugin directory. */
function asc_it_plugin_dir() {
	return trailingslashit( WP_PLUGIN_DIR ) . 'ai-site-connector';
}

/**
 * Copy the installed plugin to $dest and set its header version to $version.
 *
 * @param string $dest    Target directory (created).
 * @param string $version Header version to write.
 */
function asc_it_copy_plugin_as( $dest, $version ) {
	$src   = asc_it_plugin_dir();
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	wp_mkdir_p( $dest );
	foreach ( $files as $file ) {
		$target = $dest . '/' . substr( $file->getPathname(), strlen( $src ) + 1 );
		if ( $file->isDir() ) {
			wp_mkdir_p( $target );
		} else {
			copy( $file->getPathname(), $target );
		}
	}
	$main = $dest . '/ai-site-connector.php';
	$code = (string) file_get_contents( $main );
	file_put_contents( $main, preg_replace( '/^(\s*\*\s*Version:\s*)\S+/m', '${1}' . $version, $code, 1 ) );
}

/** Remove a fixture directory tree. */
function asc_it_rmtree( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

/** Hidden staging or retired directories left in a directory. */
function asc_it_leftovers( $dir, $prefix ) {
	return glob( trailingslashit( $dir ) . $prefix . '*', GLOB_ONLYDIR ) ?: array();
}

/** Backup versions offered for rollback that belong to these fixtures. */
function asc_it_fixture_backups() {
	$versions = wp_list_pluck( AI_Site_Connector_Backup_Manager::available_backups(), 'version' );
	$versions = array_values( array_filter( $versions, static function ( $v ) {
		return 0 === strpos( $v, '0.0.' );
	} ) );
	sort( $versions );
	return $versions;
}

asc_it(
	'backup: only complete backups are offered for rollback (#114)',
	function () {
		$base = AI_Site_Connector_Backup_Manager::backup_base_dir();
		$dirs = array( $base . '/0.0.1', $base . '/0.0.2', $base . '/.partial-0.0.3-asc', $base . '/0.0.4' );
		try {
			wp_mkdir_p( $dirs[0] );                       // Empty: a copy that never started.
			asc_it_copy_plugin_as( $dirs[1], '0.0.2' );   // Partial: main file only.
			foreach ( glob( $dirs[1] . '/includes/*.php' ) as $f ) {
				unlink( $f );
			}
			asc_it_copy_plugin_as( $dirs[2], '0.0.3' );   // Complete but still staging.
			asc_it_copy_plugin_as( $dirs[3], '0.0.4' );   // Complete legacy backup (no manifest).
			asc_assert_same( array( '0.0.4' ), asc_it_fixture_backups(), 'offered backups' );
			asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::validate_backup( $dirs[0], '0.0.1' ) ), 'empty backup validates' );
			asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::validate_backup( $dirs[1], '0.0.2' ) ), 'partial backup validates' );
			asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::validate_backup( $dirs[3], '9.9.9' ) ), 'version mismatch validates' );
		} finally {
			array_map( 'asc_it_rmtree', $dirs );
		}
	}
);

asc_it(
	'backup: create_backup verifies the copy, writes a manifest and leaves nothing behind on failure (#114)',
	function () {
		$base = AI_Site_Connector_Backup_Manager::backup_base_dir();
		$src  = trailingslashit( get_temp_dir() ) . 'asc-it-src-' . wp_generate_password( 6, false );
		$dest = $base . '/0.0.5';
		try {
			asc_it_copy_plugin_as( $src, '0.0.5' );
			$partial_copier = static function ( $from, $to ) {
				copy( $from . '/ai-site-connector.php', $to . '/ai-site-connector.php' );
				return true; // Reports success but copied one file.
			};
			$failing_copier = static function ( $from, $to ) {
				copy( $from . '/ai-site-connector.php', $to . '/ai-site-connector.php' );
				return new WP_Error( 'copy_failed', 'injected copy failure' );
			};
			foreach ( array( $partial_copier, $failing_copier ) as $copier ) {
				$result = AI_Site_Connector_Backup_Manager::create_backup( $src, '0.0.5', $copier );
				asc_assert( is_wp_error( $result ), 'failed copy reported success' );
				asc_assert( ! file_exists( $dest ), 'failed copy left a backup directory' );
				asc_assert_same( array(), asc_it_leftovers( $base, '.partial-0.0.5' ), 'failed copy left staging' );
			}

			asc_assert_same( true, AI_Site_Connector_Backup_Manager::create_backup( $src, '0.0.5' ), 'real backup' );
			asc_assert( file_exists( $dest . '/' . AI_Site_Connector_Backup_Manager::MANIFEST ), 'manifest written' );
			asc_assert_same( true, AI_Site_Connector_Backup_Manager::validate_backup( $dest, '0.0.5' ), 'fresh backup validates' );
			asc_assert_same( array( '0.0.5' ), asc_it_fixture_backups(), 'fresh backup offered' );

			file_put_contents( $dest . '/README.md', "\nchanged", FILE_APPEND );
			asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::validate_backup( $dest, '0.0.5' ) ), 'changed file still validates' );
			asc_assert_same( array(), asc_it_fixture_backups(), 'changed backup still offered' );
		} finally {
			asc_it_rmtree( $src );
			asc_it_rmtree( $dest );
		}
	}
);

asc_it(
	'rollback: incomplete targets and copy failures leave the installed plugin untouched (#114)',
	function () {
		$base     = AI_Site_Connector_Backup_Manager::backup_base_dir();
		$plugin   = asc_it_plugin_dir();
		$rollaway = $base . '/' . AI_SITE_CONNECTOR_VERSION;
		$had_away = file_exists( $rollaway );
		$before   = AI_Site_Connector_Backup_Manager::file_hashes( $plugin );
		$dirs     = array( $base . '/0.0.6', $base . '/0.0.7', $base . '/0.0.8' );
		try {
			wp_mkdir_p( $dirs[0] );
			asc_it_copy_plugin_as( $dirs[1], '0.0.7' );
			unlink( $dirs[1] . '/includes/class-plugin.php' );
			asc_it_copy_plugin_as( $dirs[2], '0.0.8' );

			foreach ( array( '0.0.6', '0.0.7', '../0.0.8', '.partial-x', '' ) as $target ) {
				asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::rollback_to( $target ) ), "rollback to '{$target}' was not refused" );
			}

			// Roll-away snapshot fails: nothing may change.
			if ( ! $had_away ) {
				$fail_rollaway = static function ( $from, $to ) use ( $plugin ) {
					return untrailingslashit( $from ) === $plugin ? new WP_Error( 'copy_failed', 'injected roll-away failure' ) : copy_dir( $from, $to );
				};
				asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::rollback_to( '0.0.8', $fail_rollaway ) ), 'roll-away failure not reported' );
			}
			// Staging the replacement fails: nothing may change.
			$fail_staging = static function ( $from, $to ) use ( $dirs ) {
				return untrailingslashit( $from ) === $dirs[2] ? new WP_Error( 'copy_failed', 'injected staging failure' ) : copy_dir( $from, $to );
			};
			asc_assert( is_wp_error( AI_Site_Connector_Backup_Manager::rollback_to( '0.0.8', $fail_staging ) ), 'staging failure not reported' );

			asc_assert_same( $before, AI_Site_Connector_Backup_Manager::file_hashes( $plugin ), 'installed plugin changed' );
			asc_assert( is_plugin_active( AI_SITE_CONNECTOR_BASENAME ), 'plugin left inactive' );
			asc_assert_same( array(), asc_it_leftovers( WP_PLUGIN_DIR, '.ai-site-connector-' ), 'staging left in plugins dir' );
		} finally {
			array_map( 'asc_it_rmtree', $dirs );
			if ( ! $had_away ) {
				asc_it_rmtree( $rollaway );
			}
		}
	}
);

asc_it(
	'rollback: a valid backup swaps in and the roll-away copy restores the original byte for byte (#114)',
	function () {
		$base     = AI_Site_Connector_Backup_Manager::backup_base_dir();
		$plugin   = asc_it_plugin_dir();
		$rollaway = $base . '/' . AI_SITE_CONNECTOR_VERSION;
		$had_away = file_exists( $rollaway );
		$before   = AI_Site_Connector_Backup_Manager::file_hashes( $plugin );
		$target   = $base . '/0.0.9';
		try {
			asc_it_copy_plugin_as( $target, '0.0.9' );
			$result = AI_Site_Connector_Backup_Manager::rollback_to( '0.0.9' );
			asc_assert( ! is_wp_error( $result ), 'rollback failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			asc_assert_same( array( 'from' => AI_SITE_CONNECTOR_VERSION, 'to' => '0.0.9' ), $result, 'rollback result' );
			asc_assert_same( AI_Site_Connector_Backup_Manager::file_hashes( $target ), AI_Site_Connector_Backup_Manager::file_hashes( $plugin ), 'installed files are the backup' );
			asc_assert_same( true, AI_Site_Connector_Backup_Manager::validate_backup( $rollaway, AI_SITE_CONNECTOR_VERSION ), 'roll-away copy is a verified backup' );
			asc_assert( is_plugin_active( AI_SITE_CONNECTOR_BASENAME ), 'plugin inactive after rollback' );

			$back = AI_Site_Connector_Backup_Manager::rollback_to( AI_SITE_CONNECTOR_VERSION );
			asc_assert( ! is_wp_error( $back ), 'restore failed: ' . ( is_wp_error( $back ) ? $back->get_error_message() : '' ) );
			asc_assert_same( $before, AI_Site_Connector_Backup_Manager::file_hashes( $plugin ), 'restored files differ from the original' );
			asc_assert( is_plugin_active( AI_SITE_CONNECTOR_BASENAME ), 'plugin inactive after restore' );
			asc_assert_same( array(), asc_it_leftovers( WP_PLUGIN_DIR, '.ai-site-connector-' ), 'staging left in plugins dir' );
		} finally {
			// Never leave the test site on the fixture copy.
			if ( AI_Site_Connector_Backup_Manager::file_hashes( $plugin ) !== $before && true === AI_Site_Connector_Backup_Manager::validate_backup( $rollaway, AI_SITE_CONNECTOR_VERSION ) ) {
				AI_Site_Connector_Backup_Manager::rollback_to( AI_SITE_CONNECTOR_VERSION );
			}
			asc_it_rmtree( $target );
			asc_it_rmtree( $base . '/0.0.9' );
			if ( ! $had_away ) {
				asc_it_rmtree( $rollaway );
			}
		}
	}
);
