<?php
/**
 * Export bundle (#73) and deterministic GitHub-ready manifests (#74).
 *
 * Aggregates the read-only services into eight manifest files:
 *
 *   site-inventory.json            AI_Site_Connector_Content_Inventory
 *   media-seo-audit.json           AI_Site_Connector_Media_Audit::audit()
 *   duplicate-media.json           AI_Site_Connector_Media_Audit::duplicates()
 *   broken-links.json              AI_Site_Connector_Link_Scanner
 *   redirects.json                 AI_Site_Connector_Diagnostics::redirects()
 *   plugin-builder-detection.json  plugins, theme, builders, SEO/cache detection
 *   rest-routes.json               AI_Site_Connector_Diagnostics::rest_routes()
 *   mcp-self-test.json             AI_Site_Connector_Diagnostics::self_test()
 *
 * Determinism: volatile fields (generated_at, per-call cursors) are removed,
 * associative keys are sorted recursively, lists keep their service order
 * (already ID/route sorted), and files are encoded identically every time.
 * Two builds of an unchanged site produce byte-identical files; the only
 * timestamp lives in the response envelope, never in a manifest file.
 *
 * Read-only. Nothing is written to disk by this class and no secrets are
 * included (no credentials, admin email, server paths or option values).
 * Each section runs in isolation: a failing section is reported in the
 * index and does not abort the bundle.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Export_Bundle {

	const FILES = array(
		'site-inventory.json',
		'media-seo-audit.json',
		'duplicate-media.json',
		'broken-links.json',
		'redirects.json',
		'plugin-builder-detection.json',
		'rest-routes.json',
		'mcp-self-test.json',
	);

	/** Bounds for driving the resumable duplicate scan in one export. */
	const DUP_MAX_CALLS   = 20;
	const DUP_MAX_SECONDS = 20;

	/** Default and maximum items collected per paginated section. */
	const DEFAULT_MAX_ITEMS = 1000;
	const MAX_ITEMS         = 5000;

	/**
	 * Section-level keys removed so output does not change between runs.
	 * Applied to the top level of each manifest only — nested data (e.g. a
	 * route argument named "offset") is never touched.
	 */
	const VOLATILE_KEYS = array( 'generated_at', 'limit', 'offset', 'next_offset', 'after_id', 'next_after_id' );

	/** Self-test checks that depend on the caller or the PHP process user. */
	const CONTEXT_CHECKS = array( 'authenticated_user', 'uploads_writable', 'export_dir_writable', 'temp_dir_writable', 'seo_dry_run_invariant' );

	/**
	 * Build every manifest.
	 *
	 * @param array $args {
	 *   @type int      $max_items Items collected per paginated section, 1..5000 (default 1000).
	 *   @type string[] $sections  Subset of FILES to build (default all).
	 * }
	 * @return array|WP_Error {
	 *   @type string $generated_at Envelope-only timestamp.
	 *   @type array  $index        Deterministic per-file status: { file: { ok, truncated, items, sha256, error? } }.
	 *   @type array  $files        file name => decoded manifest data.
	 * }
	 */
	public static function build( $args = array() ) {
		$args      = wp_parse_args(
			$args,
			array(
				'max_items' => self::DEFAULT_MAX_ITEMS,
				'sections'  => self::FILES,
			)
		);
		$max_items = (int) $args['max_items'];
		if ( $max_items < 1 || $max_items > self::MAX_ITEMS ) {
			return new WP_Error( 'asc_invalid_param', sprintf( 'max_items must be between 1 and %d.', self::MAX_ITEMS ), array( 'status' => 400, 'param' => 'max_items' ) );
		}
		$sections = is_array( $args['sections'] ) ? array_values( array_map( 'strval', $args['sections'] ) ) : array_filter( array_map( 'trim', explode( ',', (string) $args['sections'] ) ) );
		if ( empty( $sections ) ) {
			$sections = self::FILES;
		}
		$unknown = array_diff( $sections, self::FILES );
		if ( ! empty( $unknown ) ) {
			return new WP_Error( 'asc_invalid_param', sprintf( 'Unknown section(s): %s.', implode( ',', $unknown ) ), array( 'status' => 400, 'param' => 'sections' ) );
		}

		$files = array();
		$index = array();
		foreach ( self::FILES as $file ) {
			if ( ! in_array( $file, $sections, true ) ) {
				continue;
			}
			try {
				$res = self::section( $file, $max_items );
				if ( is_wp_error( $res ) ) {
					throw new RuntimeException( $res->get_error_code() . ': ' . $res->get_error_message() );
				}
				$data           = self::normalize( self::strip_volatile( $res['data'] ) );
				$files[ $file ] = $data;
				$index[ $file ] = array(
					'ok'        => true,
					'truncated' => (bool) $res['truncated'],
					'items'     => (int) $res['items'],
					'sha256'    => hash( 'sha256', self::encode( $data ) ),
				);
				// A successful export is not proof of a complete audit: say
				// what this file covers and what it could not check.
				$coverage       = self::coverage( $file, $res['data'], (bool) $res['truncated'] );
				$index[ $file ] = array_merge( $index[ $file ], $coverage );
			} catch ( Throwable $e ) {
				$index[ $file ] = array(
					'ok'        => false,
					'truncated' => false,
					'items'     => 0,
					'error'     => self::safe_error( $e ),
				);
			}
		}

		return array(
			'generated_at'   => gmdate( 'c' ),
			'plugin_version' => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? AI_SITE_CONNECTOR_VERSION : '',
			'site_url'       => home_url(),
			'max_items'      => $max_items,
			'index'          => $index,
			'files'          => $files,
		);
	}

	/**
	 * Encode one manifest exactly as it should be written to disk.
	 */
	public static function encode( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/**
	 * The deterministic manifest index file (no timestamps).
	 */
	public static function index_document( array $bundle ) {
		return self::normalize(
			array(
				'plugin_version' => $bundle['plugin_version'],
				'site_url'       => $bundle['site_url'],
				'max_items'      => $bundle['max_items'],
				'files'          => $bundle['index'],
			)
		);
	}

	/**
	 * @return array|WP_Error { data, truncated, items }
	 */
	private static function section( $file, $max_items ) {
		switch ( $file ) {
			case 'site-inventory.json':
				// Published content only: the manifest is meant to be
				// committed, so drafts, private posts and protected excerpts
				// stay out.
				$res = self::collect(
					static function ( $offset, $limit ) {
						return AI_Site_Connector_Content_Inventory::query( array( 'offset' => $offset, 'limit' => $limit, 'status' => 'publish' ) );
					},
					AI_Site_Connector_Content_Inventory::MAX_LIMIT,
					$max_items,
					array( 'omitted_forbidden' )
				);
				if ( ! is_wp_error( $res ) ) {
					foreach ( $res['data']['items'] as &$item ) {
						if ( ! empty( $item['password_protected'] ) ) {
							// The native SEO description falls back to the excerpt.
							$item['excerpt'] = '';
							if ( isset( $item['seo']['description'] ) ) {
								$item['seo']['description'] = '';
							}
						}
					}
					unset( $item );
				}
				return $res;
			case 'media-seo-audit.json':
				return self::collect(
					static function ( $offset, $limit ) {
						return AI_Site_Connector_Media_Audit::audit( array( 'offset' => $offset, 'limit' => $limit, 'mime' => 'all' ) );
					},
					AI_Site_Connector_Media_Audit::MAX_LIMIT,
					$max_items,
					array( 'summary', 'clean', 'scanned', 'omitted_forbidden' )
				);
			case 'broken-links.json':
				return self::collect(
					static function ( $offset, $limit ) {
						return AI_Site_Connector_Link_Scanner::scan( array( 'offset' => $offset, 'limit' => min( $limit, AI_Site_Connector_Link_Scanner::MAX_LIMIT ) ) );
					},
					AI_Site_Connector_Link_Scanner::MAX_LIMIT,
					$max_items,
					array( 'summary', 'posts_scanned', 'links_examined', 'external_ignored', 'non_http_ignored', 'omitted_forbidden', 'partial_posts' )
				);
			case 'duplicate-media.json':
				// Drive the resumable library-wide scan, bounded per export.
				$res     = null;
				$scan_id = '';
				$started = microtime( true );
				for ( $call = 0; $call < self::DUP_MAX_CALLS; $call++ ) {
					$res = AI_Site_Connector_Media_Audit::duplicates( array( 'scan_id' => $scan_id ) );
					if ( is_wp_error( $res ) ) {
						return $res;
					}
					$scan_id = $res['scan_id'];
					if ( $res['complete'] || microtime( true ) - $started > self::DUP_MAX_SECONDS ) {
						break;
					}
				}
				if ( ! $res['complete'] ) {
					AI_Site_Connector_Media_Audit::abandon_scan( $scan_id );
				}
				unset( $res['scan_id'], $res['calls'] );
				return array(
					'data'      => $res,
					'truncated' => (bool) $res['truncated'],
					'items'     => count( $res['by_filename'] ) + count( $res['by_hash'] ),
				);
			case 'redirects.json':
				$res = AI_Site_Connector_Diagnostics::redirects( array( 'limit' => min( $max_items, AI_Site_Connector_Diagnostics::REDIRECTS_MAX_LIMIT ) ) );
				return array(
					'data'      => $res,
					'truncated' => null !== $res['next_offset'],
					'items'     => count( $res['redirects'] ),
				);
			case 'plugin-builder-detection.json':
				return array(
					'data'      => self::detection(),
					'truncated' => false,
					'items'     => 1,
				);
			case 'rest-routes.json':
				$res = AI_Site_Connector_Diagnostics::rest_routes();
				return array(
					'data'      => $res,
					'truncated' => false,
					'items'     => (int) $res['route_count'],
				);
			case 'mcp-self-test.json':
				return array(
					'data'      => self::portable_self_test(),
					'truncated' => false,
					'items'     => 1,
				);
		}
		return new WP_Error( 'asc_unknown_section', $file );
	}

	/**
	 * Page through an offset-paginated service, scanning at most $max_items
	 * underlying rows (posts / attachments), not just result rows, so a
	 * healthy site with few findings still stops after a bounded scan.
	 *
	 * @param callable $fetch     fn( int $offset, int $limit ): array|WP_Error with items/total/next_offset.
	 * @param int      $page_max  Service's maximum page size.
	 * @param int      $max_items Rows scanned for the section.
	 * @param string[] $sum_keys  Counters (numbers, maps of numbers, or lists) to combine across pages.
	 * @return array|WP_Error
	 */
	private static function collect( $fetch, $page_max, $max_items, $sum_keys = array() ) {
		$offset = 0;
		$items  = array();
		$first  = null;
		$sums   = array();
		$next   = 0;
		$guard  = 0;
		while ( null !== $next && $offset < $max_items && $guard++ < 1000 ) {
			$page = $fetch( $offset, min( $page_max, $max_items - $offset ) );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			if ( null === $first ) {
				$first = $page;
			}
			foreach ( $sum_keys as $k ) {
				if ( ! isset( $page[ $k ] ) ) {
					continue;
				}
				if ( is_array( $page[ $k ] ) && array_keys( $page[ $k ] ) === range( 0, count( $page[ $k ] ) - 1 ) ) {
					$sums[ $k ] = array_merge( isset( $sums[ $k ] ) ? $sums[ $k ] : array(), $page[ $k ] );
				} elseif ( is_array( $page[ $k ] ) ) {
					foreach ( $page[ $k ] as $sk => $sv ) {
						$sums[ $k ][ $sk ] = ( isset( $sums[ $k ][ $sk ] ) ? $sums[ $k ][ $sk ] : 0 ) + (int) $sv;
					}
				} else {
					$sums[ $k ] = ( isset( $sums[ $k ] ) ? $sums[ $k ] : 0 ) + (int) $page[ $k ];
				}
			}
			$items = array_merge( $items, $page['items'] );
			$next  = $page['next_offset'];
			if ( null !== $next ) {
				if ( (int) $next <= $offset ) {
					break; // No progress: never loop.
				}
				$offset = (int) $next;
			}
		}
		$truncated = null !== $next;

		$data          = $first;
		$data['items'] = $items;
		$data['count'] = count( $items );
		foreach ( $sums as $k => $v ) {
			$data[ $k ] = $v;
		}
		$data['truncated'] = $truncated;
		return array(
			'data'      => $data,
			'truncated' => $truncated,
			'items'     => count( $items ),
		);
	}

	/**
	 * Coverage of one manifest: { complete, scope, limitations[] }.
	 *
	 * complete is false whenever any limitation applies; scope states what
	 * the file is meant to cover even when complete.
	 */
	private static function coverage( $file, $data, $truncated ) {
		$limits = array();
		if ( $truncated ) {
			$limits[] = 'truncated';
		}
		$scope = '';
		switch ( $file ) {
			case 'site-inventory.json':
				$scope = 'Published posts, pages and public custom post types only; drafts, pending, private and scheduled content are excluded.';
				break;
			case 'media-seo-audit.json':
				$scope = 'Attachments only, offline checks against stored metadata and files.';
				break;
			case 'duplicate-media.json':
				$scope = 'Library-wide duplicate scan (filenames and SHA-256 of size collisions) when complete; an export stops after a bounded number of scan calls and then reports no groups.';
				if ( ! empty( $data['hash_budget_exhausted'] ) ) {
					$limits[] = 'hash_budget_exhausted';
				}
				if ( ! empty( $data['unhashed'] ) ) {
					$limits[] = 'unhashed_files';
				}
				if ( ! empty( $data['skipped_large'] ) ) {
					$limits[] = 'skipped_large_files';
				}
				if ( ! empty( $data['unreadable'] ) ) {
					$limits[] = 'unreadable_files';
				}
				if ( empty( $data['complete'] ) ) {
					$limits[] = 'scan_incomplete';
				}
				break;
			case 'broken-links.json':
				$scope = 'Internal links in published content, resolved offline; external links are not checked and links needing a live request are reported as skipped.';
				if ( ! empty( $data['partial_posts'] ) ) {
					$limits[] = 'partial_posts';
				}
				if ( ! empty( $data['summary']['skipped'] ) ) {
					$limits[] = 'unverifiable_links_skipped';
				}
				break;
			case 'redirects.json':
				$scope = 'Redirects from the first supported redirect plugin that has data.';
				if ( ! empty( $data['data_unavailable'] ) ) {
					$limits[] = 'redirect_plugin_data_unavailable';
				}
				break;
			case 'mcp-self-test.json':
				$scope = 'Checks that do not depend on the caller or PHP process user; run /diagnostics/self-test for the full live report.';
				$limits[] = 'context_checks_omitted';
				break;
			case 'rest-routes.json':
				$scope = 'Routes registered at export time.';
				break;
			case 'plugin-builder-detection.json':
				$scope = 'Detection from active plugins and theme; not proof that a builder is used on any page.';
				break;
		}
		if ( isset( $data['omitted_forbidden'] ) && (int) $data['omitted_forbidden'] > 0 ) {
			$limits[] = 'items_omitted_for_access';
		}
		$limits = array_values( array_unique( $limits ) );
		sort( $limits );
		return array(
			'complete'    => empty( $limits ),
			'scope'       => $scope,
			'limitations' => $limits,
		);
	}

	/**
	 * Self-test reduced to checks whose result does not depend on who runs
	 * it or which PHP process user runs it, so REST and WP-CLI exports of the
	 * same site produce the same file. Use /diagnostics/self-test for the
	 * full live report.
	 */
	private static function portable_self_test() {
		$full    = AI_Site_Connector_Diagnostics::self_test();
		$checks  = array();
		$summary = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		);
		foreach ( $full['checks'] as $c ) {
			if ( in_array( $c['name'], self::CONTEXT_CHECKS, true ) ) {
				continue;
			}
			$checks[] = array(
				'name'   => $c['name'],
				'status' => $c['status'],
			);
			++$summary[ $c['status'] ];
		}
		return array(
			'checks'          => $checks,
			'summary'         => $summary,
			'overall'         => $summary['fail'] > 0 ? 'fail' : ( $summary['warn'] > 0 ? 'warn' : 'pass' ),
			'omitted_checks'  => self::CONTEXT_CHECKS,
		);
	}

	/**
	 * Error text safe to commit: exception class + message with filesystem
	 * paths removed.
	 */
	private static function safe_error( Throwable $e ) {
		$msg = wp_strip_all_tags( $e->getMessage() );
		$msg = str_replace( array( untrailingslashit( ABSPATH ), untrailingslashit( WP_CONTENT_DIR ) ), '[path]', $msg );
		$msg = (string) preg_replace( '#(?:[A-Za-z]:)?(?:[\\/][^\s\\/:]+)+\.(?:php|inc)#', '[path]', $msg );
		return get_class( $e ) . ': ' . $msg;
	}

	/**
	 * Remove VOLATILE_KEYS from the top level of a section's data.
	 */
	private static function strip_volatile( $data ) {
		if ( is_array( $data ) ) {
			foreach ( self::VOLATILE_KEYS as $k ) {
				unset( $data[ $k ] );
			}
		}
		return $data;
	}

	/**
	 * Plugin/theme/builder/SEO/cache detection without credentials, admin
	 * email, server paths or option values.
	 */
	private static function detection() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( $all as $file => $data ) {
			$plugins[] = array(
				'file'    => (string) $file,
				'name'    => isset( $data['Name'] ) ? wp_strip_all_tags( (string) $data['Name'] ) : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'  => in_array( $file, $active, true ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
			);
		}
		usort(
			$plugins,
			static function ( $a, $b ) {
				return strcmp( $a['file'], $b['file'] );
			}
		);
		$theme    = wp_get_theme();
		$builder  = AI_Site_Connector_Diagnostics::page_builder();
		$detected = AI_Site_Connector_Diagnostics::detected_plugins(); // No loopback HTTP, unlike generate().
		return array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'theme'             => array(
				'name'     => $theme ? (string) $theme->get( 'Name' ) : '',
				'version'  => $theme ? (string) $theme->get( 'Version' ) : '',
				'template' => $theme ? (string) $theme->get_template() : '',
			),
			'plugins'           => $plugins,
			'page_builders'     => $builder['site']['detected'],
			'seo_plugin'        => AI_Site_Connector_SEO::detect_seo_plugin(),
			'seo_writable'      => AI_Site_Connector_SEO::writable_fields( AI_Site_Connector_SEO::detect_seo_plugin() ),
			'seo_detected'      => $detected['seo'],
			'cache_detected'    => $detected['cache'],
		);
	}

	/**
	 * Sort associative keys recursively. Lists keep their order (services
	 * already return them in a stable order).
	 */
	public static function normalize( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
			if ( empty( $value ) ) {
				return new stdClass();
			}
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array() === $value ) {
			return array();
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			return array_map( array( __CLASS__, 'normalize' ), $value );
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ (string) $k ] = self::normalize( $v );
		}
		ksort( $out, SORT_STRING );
		return empty( $out ) ? new stdClass() : $out;
	}
}
