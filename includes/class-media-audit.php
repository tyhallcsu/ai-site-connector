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

	/**
	 * Find duplicate media by filename and by content hash. Read-only.
	 *
	 * Filenames: attachments whose file basename is identical
	 * (case-insensitive) — `exact` — or identical after stripping WordPress
	 * de-duplication suffixes (`-1`, `-2`, `-scaled`) — `suffix_variant`.
	 *
	 * Hashes: SHA-256, computed only for files whose byte size collides with
	 * another scanned file, within per-file and per-call byte budgets
	 * (non-administrators get lower scan/hash ceilings). Files left unhashed
	 * by the budget are listed in `unhashed`.
	 *
	 * Groups are computed within one call's scan window; duplicates whose
	 * members fall in different windows are not paired.
	 *
	 * @param array $args {
	 *   @type int $max_scan       Attachments scanned, 1..20000 (default 5000), lowest IDs first.
	 *   @type int $after_id       Resume after this attachment ID (default 0).
	 *   @type int $max_file_bytes Skip hashing files larger than this (default 50 MB).
	 * }
	 * @return array|WP_Error
	 */
	public static function duplicates( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'max_scan'       => self::DUP_DEFAULT_SCAN,
				'after_id'       => 0,
				'max_file_bytes' => self::DUP_MAX_FILE_BYTES,
			)
		);
		// Lower ceilings for non-administrators: the scan is reachable by any
		// upload_files user and costs disk I/O.
		$is_admin   = current_user_can( 'manage_options' );
		$scan_cap   = $is_admin ? self::DUP_MAX_SCAN : self::DUP_DEFAULT_SCAN;
		$hash_cap   = $is_admin ? self::DUP_HASH_BUDGET : (int) ( self::DUP_HASH_BUDGET / 5 );
		$max_scan = (int) $args['max_scan'];
		$after_id = (int) $args['after_id'];
		$max_file = (int) $args['max_file_bytes'];
		if ( $max_scan < 1 || $max_scan > $scan_cap ) {
			return self::invalid( 'max_scan', sprintf( 'max_scan must be between 1 and %d.', $scan_cap ) );
		}
		if ( $after_id < 0 ) {
			return self::invalid( 'after_id', 'after_id must be >= 0.' );
		}
		if ( $max_file < 1 || $max_file > self::DUP_MAX_FILE_BYTES ) {
			return self::invalid( 'max_file_bytes', sprintf( 'max_file_bytes must be between 1 and %d.', self::DUP_MAX_FILE_BYTES ) );
		}

		// One bounded query: ID, parent and relative file path.
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
				$after_id,
				$max_scan + 1
			),
			ARRAY_A
		);
		$truncated = count( $rows ) > $max_scan;
		if ( $truncated ) {
			array_pop( $rows );
		}
		// Prime attachment + parent caches: one query instead of 2-3 per row.
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

		$by_exact   = array();
		$by_variant = array();
		$by_size    = array();
		$unreadable = array();
		$omitted    = 0;
		$last_id    = $after_id;
		foreach ( $rows as $row ) {
			$id      = (int) $row['ID'];
			$last_id = $id;
			$att     = get_post( $id );
			if ( ! $att || ! AI_Site_Connector_Export::can_read_attachment( $att ) ) {
				++$omitted;
				continue;
			}
			$file = (string) $row['file'];
			if ( '' === $file ) {
				$unreadable[] = array(
					'attachment_id' => $id,
					'reason'        => 'no_file_meta',
				);
				continue;
			}
			$base = strtolower( wp_basename( $file ) );
			$by_exact[ $base ][] = $id;
			$by_variant[ self::variant_key( $base ) ][] = $id;

			$path = self::safe_path( $id );
			if ( '' === $path || ! is_file( $path ) ) {
				$unreadable[] = array(
					'attachment_id' => $id,
					'reason'        => 'missing_file',
				);
				continue;
			}
			if ( ! is_readable( $path ) ) {
				$unreadable[] = array(
					'attachment_id' => $id,
					'reason'        => 'unreadable',
				);
				continue;
			}
			$size = (int) filesize( $path );
			if ( $size > 0 ) {
				$by_size[ $size ][] = array( $id, $path );
			}
		}

		$filename_groups = array();
		$seen_exact      = array();
		foreach ( $by_exact as $name => $ids ) {
			if ( count( $ids ) > 1 ) {
				sort( $ids );
				$filename_groups[]                  = array(
					'filename'       => $name,
					'match'          => 'exact',
					'attachment_ids' => $ids,
				);
				$seen_exact[ implode( ',', $ids ) ] = true;
			}
		}
		foreach ( $by_variant as $key => $ids ) {
			sort( $ids );
			// Only a real WordPress re-upload pattern: the unsuffixed original
			// (key) must be among the members, so slide-1/slide-2 or
			// team-2023/team-2024 are never reported as duplicates.
			if ( count( $ids ) > 1 && isset( $by_exact[ $key ] ) && ! isset( $seen_exact[ implode( ',', $ids ) ] ) ) {
				$filename_groups[] = array(
					'filename'       => $key,
					'match'          => 'suffix_variant',
					'attachment_ids' => $ids,
				);
			}
		}
		usort( $filename_groups, array( __CLASS__, 'cmp_groups' ) );

		$by_hash       = array();
		$hashed        = 0;
		$hashed_bytes  = 0;
		$skipped_large = array();
		$unhashed      = array();
		$budget_hit    = false;
		ksort( $by_size );
		foreach ( $by_size as $size => $files ) {
			if ( count( $files ) < 2 ) {
				continue;
			}
			foreach ( $files as $f ) {
				list( $id, $path ) = $f;
				if ( $size > $max_file ) {
					$skipped_large[] = $id;
					continue;
				}
				if ( $hashed_bytes + $size > $hash_cap ) {
					$budget_hit = true;
					$unhashed[] = $id;
					continue;
				}
				$h = hash_file( 'sha256', $path );
				if ( ! is_string( $h ) ) {
					$unreadable[] = array(
						'attachment_id' => $id,
						'reason'        => 'unreadable',
					);
					continue;
				}
				++$hashed;
				$hashed_bytes                 += $size;
				$by_hash[ $h ]['size_bytes']   = $size;
				$by_hash[ $h ]['ids'][]        = $id;
			}
		}
		$hash_groups = array();
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
		usort(
			$unreadable,
			static function ( $a, $b ) {
				return $a['attachment_id'] - $b['attachment_id'];
			}
		);
		sort( $skipped_large );
		sort( $unhashed );

		return array(
			'generated_at'          => gmdate( 'c' ),
			'scanned'               => count( $rows ),
			'omitted_forbidden'     => $omitted,
			'after_id'              => $after_id,
			'next_after_id'         => $truncated ? $last_id : null,
			'truncated'             => $truncated,
			'hashed_files'          => $hashed,
			'hashed_bytes'          => $hashed_bytes,
			'hash_budget_exhausted' => $budget_hit,
			'unhashed'              => $unhashed,
			'skipped_large'         => $skipped_large,
			'scope'                 => 'Groups cover this page of the scan only; use a max_scan at least the library size for complete results.',
			'by_filename'           => $filename_groups,
			'by_hash'               => $hash_groups,
			'unreadable'            => $unreadable,
		);
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
