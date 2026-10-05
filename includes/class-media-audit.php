<?php
/**
 * Media SEO audit (#64) and duplicate media detection (#65).
 *
 * Read-only. Never deletes, moves or edits media. Shared by REST, MCP and
 * WP-CLI.
 *
 * Visibility follows AI_Site_Connector_Export::can_read_attachment(): attached
 * media follows its parent post; unattached media needs upload_files.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Media_Audit {

	const MAX_LIMIT     = 500;
	const DEFAULT_LIMIT = 100;

	/** Issue codes reported by audit(), in a stable order. */
	const ISSUES = array(
		'missing_alt',
		'missing_title',
		'title_is_filename',
		'missing_caption',
		'missing_description',
		'unattached',
		'oversized_dimensions',
		'oversized_file',
		'suspicious_filename',
		'missing_file',
	);

	/** Duplicate scan bounds. */
	const DUP_DEFAULT_SCAN    = 5000;
	const DUP_MAX_SCAN        = 20000;
	const DUP_MAX_FILE_BYTES  = 52428800;   // 50 MB per file hashed.
	const DUP_HASH_BUDGET     = 524288000;  // 500 MB hashed per call.

	/**
	 * Audit attachments for SEO/hygiene problems.
	 *
	 * @param array $args {
	 *   @type int    $limit          1..500, default 100.
	 *   @type int    $offset         >= 0.
	 *   @type string $mime           'image' (default) | 'all'.
	 *   @type bool   $only_issues    Default true: omit clean attachments from items.
	 *   @type int    $max_dimension  px, default 2560 (WordPress big-image threshold).
	 *   @type int    $max_bytes      default 1048576 (1 MB) for images.
	 * }
	 * @return array|WP_Error
	 */
	public static function audit( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'         => self::DEFAULT_LIMIT,
				'offset'        => 0,
				'mime'          => 'image',
				'only_issues'   => true,
				'max_dimension' => self::default_max_dimension(),
				'max_bytes'     => 1048576,
			)
		);
		$limit  = (int) $args['limit'];
		$offset = (int) $args['offset'];
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			return self::invalid( 'limit', sprintf( 'limit must be between 1 and %d.', self::MAX_LIMIT ) );
		}
		if ( $offset < 0 ) {
			return self::invalid( 'offset', 'offset must be >= 0.' );
		}
		if ( ! in_array( $args['mime'], array( 'image', 'all' ), true ) ) {
			return self::invalid( 'mime', 'mime must be "image" or "all".' );
		}
		$max_dim   = max( 0, (int) $args['max_dimension'] );
		$max_bytes = max( 0, (int) $args['max_bytes'] );
		$only      = rest_sanitize_boolean( $args['only_issues'] );

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image' === $args['mime'] ? 'image' : '',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);

		$items   = array();
		$summary = array_fill_keys( self::ISSUES, 0 );
		$omitted = 0;
		$clean   = 0;
		foreach ( $query->posts as $att ) {
			if ( ! AI_Site_Connector_Export::can_read_attachment( $att ) ) {
				++$omitted;
				continue;
			}
			$item = self::audit_item( $att, $max_dim, $max_bytes );
			foreach ( $item['issues'] as $code ) {
				++$summary[ $code ];
			}
			if ( empty( $item['issues'] ) ) {
				++$clean;
				if ( $only ) {
					continue;
				}
			}
			$items[] = $item;
		}

		$scanned = count( $query->posts );
		$total   = (int) $query->found_posts;

		return array(
			'generated_at'      => gmdate( 'c' ),
			'thresholds'        => array(
				'max_dimension' => $max_dim,
				'max_bytes'     => $max_bytes,
			),
			'total'             => $total,
			'scanned'           => $scanned,
			'clean'             => $clean,
			'omitted_forbidden' => $omitted,
			'limit'             => $limit,
			'offset'            => $offset,
			'next_offset'       => $offset + $scanned < $total ? $offset + $scanned : null,
			'summary'           => $summary,
			'items'             => $items,
		);
	}

	/**
	 * WordPress' big-image threshold, or 2560 when a site disables scaling
	 * (those are exactly the sites that keep oversized originals).
	 */
	private static function default_max_dimension() {
		$threshold = (int) apply_filters( 'big_image_size_threshold', 2560, array(), '', 0 );
		return $threshold > 0 ? $threshold : 2560;
	}

	private static function audit_item( WP_Post $att, $max_dim, $max_bytes ) {
		$id       = (int) $att->ID;
		$mime     = (string) $att->post_mime_type;
		$is_image = 0 === strpos( $mime, 'image/' );
		$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
		$filename = '' !== $relative ? wp_basename( $relative ) : wp_basename( (string) wp_get_attachment_url( $id ) );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : array();
		$width    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height   = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$path     = self::safe_path( $id );
		$exists   = '' !== $path && is_file( $path );
		$size     = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( $exists ? (int) filesize( $path ) : 0 );

		$alt         = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$title       = trim( (string) $att->post_title );
		$caption     = trim( (string) $att->post_excerpt );
		$description = trim( (string) $att->post_content );
		// Compare against the name WordPress started from: the stored file may
		// carry -scaled / -N suffixes the default title does not.
		$stems = array_unique(
			array_filter(
				array(
					strtolower( (string) pathinfo( $filename, PATHINFO_FILENAME ) ),
					strtolower( (string) pathinfo( self::variant_key( strtolower( $filename ) ), PATHINFO_FILENAME ) ),
					isset( $meta['original_image'] ) ? strtolower( (string) pathinfo( (string) $meta['original_image'], PATHINFO_FILENAME ) ) : '',
				)
			)
		);

		$issues = array();
		if ( $is_image && '' === $alt ) {
			$issues[] = 'missing_alt';
		}
		if ( '' === $title ) {
			$issues[] = 'missing_title';
		} else {
			foreach ( $stems as $stem ) {
				if ( self::normalize_name( $title ) === self::normalize_name( $stem ) ) {
					$issues[] = 'title_is_filename';
					break;
				}
			}
		}
		if ( '' === $caption ) {
			$issues[] = 'missing_caption';
		}
		if ( '' === $description ) {
			$issues[] = 'missing_description';
		}
		if ( 0 === (int) $att->post_parent ) {
			$issues[] = 'unattached';
		}
		if ( $is_image && $max_dim > 0 && ( $width > $max_dim || $height > $max_dim ) ) {
			$issues[] = 'oversized_dimensions';
		}
		if ( $is_image && $max_bytes > 0 && $size > $max_bytes ) {
			$issues[] = 'oversized_file';
		}
		foreach ( $stems ? $stems : array( '' ) as $stem ) {
			if ( self::is_suspicious_filename( $stem ) ) {
				$issues[] = 'suspicious_filename';
				break;
			}
		}
		if ( ! $exists ) {
			$issues[] = 'missing_file';
		}

		return array(
			'attachment_id'    => $id,
			'url'              => (string) wp_get_attachment_url( $id ),
			'filename'         => $filename,
			'mime_type'        => $mime,
			'file_size'        => $size,
			'width'            => $width,
			'height'           => $height,
			'uploaded_gmt'     => ( '' === (string) $att->post_date_gmt || '0000-00-00 00:00:00' === $att->post_date_gmt ) ? get_gmt_from_date( $att->post_date ) : (string) $att->post_date_gmt,
			'attached_post_id' => (int) $att->post_parent,
			'has_alt'          => '' !== $alt,
			'has_title'        => '' !== $title,
			'has_caption'      => '' !== $caption,
			'has_description'  => '' !== $description,
			'issues'           => array_values( array_intersect( self::ISSUES, $issues ) ),
		);
	}

	/**
	 * Camera/phone/screenshot defaults, bare hashes, and "image (1)" copies.
	 */
	public static function is_suspicious_filename( $stem ) {
		$stem = strtolower( (string) $stem );
		if ( '' === $stem ) {
			return true;
		}
		$patterns = array(
			'/^(img|dsc|dscn|dscf|dcim|pxl|mvimg|gopr)[-_ ]?\d{3,}([-_ ]\d+)*$/',
			'/^(screenshot|screen[-_ ]shot|screen[-_ ]recording)([-_ ].*)?$/',
			'/^(image|photo|picture|untitled|unnamed|download|file|whatsapp[-_ ]image)([-_ ]?\(?\d*\)?)?$/',
			'/^[0-9a-f]{16,}$/',
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
			'/^\d{8,}([-_]\d+)*$/',
		);
		foreach ( $patterns as $re ) {
			if ( preg_match( $re, $stem ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_name( $s ) {
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $s ) ) );
	}

	/**
	 * Absolute path of the attachment file, only when it resolves inside the
	 * uploads directory. Guards hashing/stat against tampered meta.
	 */
	private static function safe_path( $attachment_id ) {
		$path = (string) get_attached_file( $attachment_id, true );
		if ( '' === $path ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;
		$real    = realpath( $path );
		if ( false === $base || false === $real ) {
			// Missing file: report the intended path as absent, never stat
			// outside uploads.
			return false === $base ? '' : ( 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( trailingslashit( $base ) ) ) ? $path : '' );
		}
		return 0 === strpos( wp_normalize_path( $real ), wp_normalize_path( trailingslashit( $base ) ) ) ? $real : '';
	}

	/** Resumable duplicate scans: state option prefix and lifetime. */
	const DUP_STATE_PREFIX = 'ai_site_connector_dupscan_';
	const DUP_STATE_TTL    = DAY_IN_SECONDS;
	const DUP_MAX_LIBRARY  = 200000;
	const DUP_FILES_PER_CALL = 5000;

	/**
	 * Find duplicate media across the whole library, by filename and by
	 * SHA-256 content hash. Read-only; never deletes anything.
	 *
	 * Resumable and bounded per call (#88): each call stats at most
	 * `max_scan` attachments, or hashes at most the per-call byte budget,
	 * and stores its progress server-side under `scan_id` (owned by the
	 * caller, expires after a day). Repeat the call with the returned
	 * `scan_id` until `complete` is true; only then are `by_filename` and
	 * `by_hash` populated, and they cover every attachment the caller can
	 * see — duplicates are paired across the whole library, not per window.
	 *
	 * Filenames: identical basename (case-insensitive) — `exact` — or a
	 * WordPress re-upload (`-1`, `-scaled`) of an existing original —
	 * `suffix_variant`. Hashes: only files whose byte size collides with
	 * another file anywhere in the library are hashed.
	 *
	 * @param array $args {
	 *   @type string $scan_id        Continue this scan (omit to start one).
	 *   @type int    $max_scan       Attachments stat'ed per call, 1..20000 (default 5000; non-admins ≤ 5000).
	 *   @type int    $max_file_bytes Skip hashing files larger than this (default 50 MB).
	 *   @type int    $after_id       No longer supported (use scan_id); > 0 is rejected.
	 * }
	 * @return array|WP_Error
	 */
	public static function duplicates( $args = array() ) {
		$args     = wp_parse_args(
			$args,
			array(
				'scan_id'        => '',
				'max_scan'       => self::DUP_DEFAULT_SCAN,
				'max_file_bytes' => self::DUP_MAX_FILE_BYTES,
				'after_id'       => 0,
			)
		);
		$is_admin = current_user_can( 'manage_options' );
		$scan_cap = $is_admin ? self::DUP_MAX_SCAN : self::DUP_DEFAULT_SCAN;
		$hash_cap = $is_admin ? self::DUP_HASH_BUDGET : (int) ( self::DUP_HASH_BUDGET / 5 );
		$max_scan = (int) $args['max_scan'];
		$max_file = (int) $args['max_file_bytes'];
		if ( (int) $args['after_id'] > 0 ) {
			return self::invalid( 'after_id', 'after_id is no longer supported: duplicate scans are library-wide; continue with scan_id.' );
		}
		if ( $max_scan < 1 || $max_scan > $scan_cap ) {
			return self::invalid( 'max_scan', sprintf( 'max_scan must be between 1 and %d.', $scan_cap ) );
		}
		if ( $max_file < 1 || $max_file > self::DUP_MAX_FILE_BYTES ) {
			return self::invalid( 'max_file_bytes', sprintf( 'max_file_bytes must be between 1 and %d.', self::DUP_MAX_FILE_BYTES ) );
		}

		$scan_id = (string) $args['scan_id'];
		if ( '' === $scan_id ) {
			self::prune_scans( get_current_user_id() );
			$scan_id = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false ) . '-u' . get_current_user_id();
			$state   = array(
				'user_id'   => get_current_user_id(),
				'created'   => time(),
				'max_file'  => $max_file,
				'phase'     => 'stat',
				'last_id'   => 0,
				'scanned'   => 0,
				'omitted'   => 0,
				'names'     => array(),
				'sizes'     => array(),
				'unread'    => array(),
				'queue'     => array(),
				'qpos'      => 0,
				'hashes'    => array(),
				'large'     => array(),
				'hashed_b'  => 0,
				'calls'     => 0,
			);
		} else {
			$state = self::load_scan( $scan_id );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$max_file = (int) $state['max_file'];
		}
		++$state['calls'];

		if ( 'stat' === $state['phase'] ) {
			$more = self::scan_stat_window( $state, $max_scan );
			if ( is_wp_error( $more ) ) {
				delete_option( self::DUP_STATE_PREFIX . $scan_id );
				return $more;
			}
			if ( $more ) {
				$saved = self::save_scan( $scan_id, $state );
				return is_wp_error( $saved ) ? $saved : self::scan_response( $scan_id, $state, false );
			}
			// All sizes known: queue every file whose size collides anywhere.
			ksort( $state['sizes'] );
			foreach ( $state['sizes'] as $size => $ids ) {
				if ( count( $ids ) < 2 ) {
					continue;
				}
				foreach ( $ids as $id ) {
					if ( $size > $max_file ) {
						$state['large'][] = (int) $id;
					} else {
						$state['queue'][] = array( (int) $id, (int) $size );
					}
				}
			}
			$state['phase'] = 'hash';
			$state['qpos']  = 0;
			unset( $state['sizes'] ); // No longer needed; keeps the state small.
			// This call already did a full stat window; hash in the next one.
			$saved = self::save_scan( $scan_id, $state );
			return is_wp_error( $saved ) ? $saved : self::scan_response( $scan_id, $state, false );
		}

		/**
		 * Bytes hashed per duplicate-scan call (filterable for constrained hosts).
		 *
		 * @param int $hash_cap Default 500 MB for administrators, 100 MB otherwise.
		 */
		$hash_cap   = max( 1, (int) apply_filters( 'ai_site_connector_duplicate_hash_budget', $hash_cap ) );
		$call_bytes = 0;
		$call_files = 0;
		$qlen       = count( $state['queue'] );
		while ( $state['qpos'] < $qlen ) {
			list( $id, $size ) = $state['queue'][ $state['qpos'] ];
			if ( $call_files > 0 && ( $call_bytes + $size > $hash_cap || $call_files >= self::DUP_FILES_PER_CALL ) ) {
				break; // Budget for this call used; continue next call.
			}
			++$state['qpos'];
			++$call_files;
			$path = self::safe_path( $id );
			$h    = ( '' !== $path && is_file( $path ) && is_readable( $path ) ) ? hash_file( 'sha256', $path ) : false;
			if ( ! is_string( $h ) ) {
				$state['unread'][ $id ] = 'unreadable';
				continue;
			}
			$state['hashes'][ $id ] = array( $h, $size );
			$call_bytes            += $size;
			$state['hashed_b']     += $size;
		}
		if ( $state['qpos'] < $qlen ) {
			$saved = self::save_scan( $scan_id, $state );
			return is_wp_error( $saved ) ? $saved : self::scan_response( $scan_id, $state, false );
		}

		delete_option( self::DUP_STATE_PREFIX . $scan_id );
		return self::scan_response( $scan_id, $state, true );
	}

	/**
	 * Stat the next window of attachments into the scan state.
	 *
	 * @return bool|WP_Error True when more attachments remain.
	 */
	private static function scan_stat_window( array &$state, $max_scan ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_parent, MIN(m.meta_value) AS file
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.ID > %d
				 GROUP BY p.ID, p.post_parent
				 ORDER BY p.ID ASC
				 LIMIT %d",
				(int) $state['last_id'],
				$max_scan + 1
			),
			ARRAY_A
		);
		$more = count( $rows ) > $max_scan;
		if ( $more ) {
			array_pop( $rows );
		}
		if ( $state['scanned'] + count( $rows ) > self::DUP_MAX_LIBRARY ) {
			return self::invalid( 'max_scan', sprintf( 'Libraries above %d attachments are not supported by this scan.', self::DUP_MAX_LIBRARY ) );
		}
		$prime = array();
		foreach ( $rows as $row ) {
			$prime[] = (int) $row['ID'];
			if ( (int) $row['post_parent'] > 0 ) {
				$prime[] = (int) $row['post_parent'];
			}
		}
		if ( $prime ) {
			_prime_post_caches( array_values( array_unique( $prime ) ), false, false );
		}
		foreach ( $rows as $row ) {
			$id               = (int) $row['ID'];
			$state['last_id'] = $id;
			++$state['scanned'];
			$att = get_post( $id );
			if ( ! $att || ! AI_Site_Connector_Export::can_read_attachment( $att ) ) {
				++$state['omitted'];
				continue;
			}
			$file = (string) $row['file'];
			if ( '' === $file ) {
				$state['unread'][ $id ] = 'no_file_meta';
				continue;
			}
			$state['names'][ $id ] = strtolower( wp_basename( $file ) );
			$path                  = self::safe_path( $id );
			if ( '' === $path || ! is_file( $path ) ) {
				$state['unread'][ $id ] = 'missing_file';
				continue;
			}
			if ( ! is_readable( $path ) ) {
				$state['unread'][ $id ] = 'unreadable';
				continue;
			}
			$size = (int) filesize( $path );
			if ( $size > 0 ) {
				$state['sizes'][ $size ][] = $id;
			}
		}
		return $more;
	}

	/**
	 * Build the response. Groups are only reported once the scan is complete.
	 */
	private static function scan_response( $scan_id, array $state, $complete ) {
		$filename_groups = array();
		$hash_groups     = array();
		if ( $complete ) {
			// Visibility and existence are rechecked now: the scan may have
			// started under broader permissions, and attachments may have been
			// deleted since they were stat'ed.
			$ids = array_unique( array_merge( array_keys( $state['names'] ), array_keys( $state['hashes'] ), array_keys( $state['unread'] ) ) );
			if ( $ids ) {
				_prime_post_caches( array_map( 'intval', $ids ), false, false );
			}
			foreach ( $ids as $id ) {
				$att = get_post( (int) $id );
				if ( ! $att || 'attachment' !== $att->post_type ) {
					unset( $state['names'][ $id ], $state['hashes'][ $id ] );
					$state['unread'][ $id ] = 'deleted';
				} elseif ( ! AI_Site_Connector_Export::can_read_attachment( $att ) ) {
					unset( $state['names'][ $id ], $state['hashes'][ $id ], $state['unread'][ $id ] );
					++$state['omitted'];
				}
			}
			$by_exact   = array();
			$by_variant = array();
			foreach ( $state['names'] as $id => $base ) {
				$by_exact[ $base ][]                        = (int) $id;
				$by_variant[ self::variant_key( $base ) ][] = (int) $id;
			}
			$seen = array();
			foreach ( $by_exact as $name => $ids ) {
				if ( count( $ids ) > 1 ) {
					sort( $ids );
					$filename_groups[]             = array(
						'filename'       => (string) $name,
						'match'          => 'exact',
						'attachment_ids' => $ids,
					);
					$seen[ implode( ',', $ids ) ] = true;
				}
			}
			foreach ( $by_variant as $key => $ids ) {
				sort( $ids );
				// Only a real re-upload pattern: the unsuffixed original must
				// be present, so slide-1/slide-2 series are never flagged.
				if ( count( $ids ) > 1 && isset( $by_exact[ $key ] ) && ! isset( $seen[ implode( ',', $ids ) ] ) ) {
					$filename_groups[] = array(
						'filename'       => (string) $key,
						'match'          => 'suffix_variant',
						'attachment_ids' => $ids,
					);
				}
			}
			usort( $filename_groups, array( __CLASS__, 'cmp_groups' ) );

			$by_hash = array();
			foreach ( $state['hashes'] as $id => $pair ) {
				$by_hash[ $pair[0] ]['size_bytes'] = (int) $pair[1];
				$by_hash[ $pair[0] ]['ids'][]      = (int) $id;
			}
			foreach ( $by_hash as $h => $g ) {
				if ( count( $g['ids'] ) > 1 ) {
					sort( $g['ids'] );
					$hash_groups[] = array(
						'sha256'         => $h,
						'size_bytes'     => $g['size_bytes'],
						'attachment_ids' => $g['ids'],
					);
				}
			}
			usort( $hash_groups, array( __CLASS__, 'cmp_groups' ) );
		}

		$unreadable = array();
		foreach ( $state['unread'] as $id => $reason ) {
			$unreadable[] = array(
				'attachment_id' => (int) $id,
				'reason'        => $reason,
			);
		}
		usort(
			$unreadable,
			static function ( $a, $b ) {
				return $a['attachment_id'] - $b['attachment_id'];
			}
		);
		$large = array_values( array_unique( array_map( 'intval', $state['large'] ) ) );
		sort( $large );
		$pending  = isset( $state['qpos'] ) ? array_slice( $state['queue'], (int) $state['qpos'] ) : $state['queue'];
		$unhashed = array();
		if ( $complete ) {
			foreach ( $pending as $q ) {
				$unhashed[] = (int) $q[0];
			}
			sort( $unhashed );
		}

		return array(
			'generated_at'          => gmdate( 'c' ),
			'scan_id'               => $complete ? '' : $scan_id,
			'complete'              => (bool) $complete,
			'phase'                 => $complete ? 'done' : $state['phase'],
			'calls'                 => (int) $state['calls'],
			'scanned'               => (int) $state['scanned'],
			'omitted_forbidden'     => (int) $state['omitted'],
			'truncated'             => ! $complete,
			'next_after_id'         => null,
			'hashed_files'          => count( $state['hashes'] ),
			'hashed_bytes'          => (int) $state['hashed_b'],
			'hash_budget_exhausted' => ! $complete && 'hash' === $state['phase'],
			'unhashed_count'        => count( $pending ),
			'unhashed'              => $unhashed,
			'skipped_large'         => $large,
			'scope'                 => $complete
				? 'Library-wide: every attachment visible to you was considered; files whose size collides with another file were hashed.'
				: 'Scan in progress: repeat the call with scan_id until complete is true; groups are reported only when complete.',
			'by_filename'           => $filename_groups,
			'by_hash'               => $hash_groups,
			'unreadable'            => $unreadable,
		);
	}

	/**
	 * @return array|WP_Error
	 */
	private static function load_scan( $scan_id ) {
		if ( ! preg_match( '/^[0-9]{14}-[A-Za-z0-9]{8}-u[0-9]+$/', $scan_id ) ) {
			return self::invalid( 'scan_id', 'Invalid scan_id.' );
		}
		$state = get_option( self::DUP_STATE_PREFIX . $scan_id, null );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'asc_scan_not_found', 'Unknown or finished scan_id; start a new scan.', array( 'status' => 404 ) );
		}
		if ( (int) $state['user_id'] !== get_current_user_id() ) {
			// Scan state reflects one user's visibility; never share it.
			return new WP_Error( 'asc_scan_forbidden', 'This scan belongs to another user.', array( 'status' => 403 ) );
		}
		if ( time() - (int) $state['created'] > self::DUP_STATE_TTL ) {
			delete_option( self::DUP_STATE_PREFIX . $scan_id );
			return new WP_Error( 'asc_scan_expired', 'This scan expired; start a new scan.', array( 'status' => 410 ) );
		}
		return $state;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function save_scan( $scan_id, array $state ) {
		$key = self::DUP_STATE_PREFIX . $scan_id;
		$ok  = false === get_option( $key, false ) ? add_option( $key, $state, '', 'no' ) : update_option( $key, $state, false );
		$chk = $ok ? get_option( $key, null ) : null;
		if ( ! is_array( $chk ) || (int) $chk['last_id'] !== (int) $state['last_id'] || ( isset( $state['qpos'] ) && (int) $chk['qpos'] !== (int) $state['qpos'] ) ) {
			delete_option( $key );
			return new WP_Error( 'asc_scan_state_failed', 'Could not store the duplicate scan progress (the library may be too large for this database configuration).', array( 'status' => 507 ) );
		}
		return true;
	}

	/**
	 * Drop the state of an unfinished scan (e.g. when a caller gives up).
	 */
	public static function abandon_scan( $scan_id ) {
		if ( preg_match( '/^[0-9]{14}-[A-Za-z0-9]{8}-u[0-9]+$/', (string) $scan_id ) ) {
			delete_option( self::DUP_STATE_PREFIX . $scan_id );
		}
	}

	/**
	 * Remove expired scan states.
	 */
	/**
	 * Remove expired scan states (age read from the option name, without
	 * loading the state) and any earlier scan by the same user: one live scan
	 * per user bounds storage.
	 */
	private static function prune_scans( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::DUP_STATE_PREFIX ) . '%' ) );
		foreach ( (array) $names as $name ) {
			$id      = substr( (string) $name, strlen( self::DUP_STATE_PREFIX ) );
			$created = strtotime( substr( $id, 0, 4 ) . '-' . substr( $id, 4, 2 ) . '-' . substr( $id, 6, 2 ) . ' ' . substr( $id, 8, 2 ) . ':' . substr( $id, 10, 2 ) . ':' . substr( $id, 12, 2 ) . ' UTC' );
			if ( ! $created || time() - $created > self::DUP_STATE_TTL || '-u' . (int) $user_id === substr( $id, strrpos( $id, '-u' ) ) ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * Strip WordPress' de-duplication suffixes: photo-1.jpg, photo-scaled.jpg,
	 * photo-1-scaled.jpg → photo.jpg.
	 */
	private static function variant_key( $basename ) {
		$ext  = pathinfo( $basename, PATHINFO_EXTENSION );
		$stem = pathinfo( $basename, PATHINFO_FILENAME );
		$stem = preg_replace( '/-scaled$/', '', $stem );
		$stem = preg_replace( '/-\d+$/', '', $stem );
		return '' === $ext ? $stem : $stem . '.' . $ext;
	}

	private static function cmp_groups( $a, $b ) {
		return $a['attachment_ids'][0] - $b['attachment_ids'][0];
	}

	private static function invalid( $param, $message ) {
		return new WP_Error(
			'asc_invalid_param',
			$message,
			array(
				'status' => 400,
				'param'  => $param,
			)
		);
	}
}
