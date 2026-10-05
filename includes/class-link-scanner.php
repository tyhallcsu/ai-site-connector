<?php
/**
 * Broken internal link scanner (#66).
 *
 * Read-only and offline: links are resolved against the WordPress database
 * and the uploads directory only. No HTTP request is ever made, so the scan
 * cannot hammer the live site or be turned into an SSRF probe. External
 * links are counted and ignored.
 *
 * Statuses:
 *   ok      — resolves to published content, an archive, the home page, or an existing upload.
 *   broken  — points at missing/trashed/unpublished content or a missing upload.
 *   invalid — the href could not be parsed.
 *   skipped — internal but not checkable offline (wp-admin, REST, feeds, query-only URLs).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Link_Scanner {

	const MAX_LIMIT       = 200;
	const DEFAULT_LIMIT   = 50;
	const MAX_LINKS       = 5000;
	const STATUSES        = array( 'ok', 'broken', 'invalid', 'skipped' );
	const SYSTEM_PREFIXES = array( 'wp-admin', 'wp-login.php', 'wp-json', 'wp-content/plugins', 'wp-content/themes', 'wp-includes', 'xmlrpc.php', 'feed', 'comments/feed' );

	/** @var array<string,array> Per-call resolution cache keyed by normalised URL. */
	private static $cache = array();

	/**
	 * Scan post content for internal links.
	 *
	 * @param array $args {
	 *   @type string|string[] $post_type   Default: all content-inventory post types.
	 *   @type string|string[] $status      Default 'publish'.
	 *   @type int             $limit       Posts per call, 1..200, default 50.
	 *   @type int             $offset      >= 0.
	 *   @type int             $max_links   Link rows examined per call, 1..5000 (default 5000).
	 *   @type bool            $only_broken Default true: return only broken/invalid rows.
	 * }
	 * @return array|WP_Error
	 */
	public static function scan( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_type'   => array(),
				'status'      => 'publish',
				'limit'       => self::DEFAULT_LIMIT,
				'offset'      => 0,
				'max_links'   => self::MAX_LINKS,
				'only_broken' => true,
			)
		);

		$limit     = (int) $args['limit'];
		$offset    = (int) $args['offset'];
		$max_links = (int) $args['max_links'];
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			return self::invalid( 'limit', sprintf( 'limit must be between 1 and %d.', self::MAX_LIMIT ) );
		}
		if ( $offset < 0 ) {
			return self::invalid( 'offset', 'offset must be >= 0.' );
		}
		if ( $max_links < 1 || $max_links > self::MAX_LINKS ) {
			return self::invalid( 'max_links', sprintf( 'max_links must be between 1 and %d.', self::MAX_LINKS ) );
		}

		$allowed = AI_Site_Connector_Content_Inventory::allowed_post_types();
		$types   = self::list_arg( $args['post_type'] );
		if ( empty( $types ) ) {
			$types = $allowed;
		}
		$bad = array_diff( $types, $allowed );
		if ( ! empty( $bad ) ) {
			return self::invalid( 'post_type', sprintf( 'Unsupported post_type: %s.', implode( ',', $bad ) ) );
		}
		$statuses = self::list_arg( $args['status'] );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}
		$bad = array_diff( $statuses, array_keys( get_post_stati() ) );
		if ( ! empty( $bad ) && array( 'any' ) !== $statuses ) {
			return self::invalid( 'status', sprintf( 'Unsupported status: %s.', implode( ',', $bad ) ) );
		}
		$only_broken = rest_sanitize_boolean( $args['only_broken'] );

		$query = new WP_Query(
			array(
				'author__in'             => AI_Site_Connector_Content_Inventory::author_scope( $types ),
				'post_type'              => $types,
				'post_status'            => array( 'any' ) === $statuses ? 'any' : $statuses,
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		self::$cache = array();
		$summary     = array_fill_keys( self::STATUSES, 0 );
		$external    = 0;
		$non_http    = 0;
		$examined    = 0;
		$scanned     = 0;
		$omitted     = 0;
		$truncated   = false;
		$items       = array();
		$partial     = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				++$omitted;
				++$scanned;
				continue;
			}
			$links = self::extract_links( (string) $post->post_content );
			if ( null === $links ) {
				// Never report a post as link-free when extraction failed.
				++$scanned;
				++$examined;
				++$summary['invalid'];
				$items[] = array(
					'source_post_id'   => (int) $post->ID,
					'source_post_type' => (string) $post->post_type,
					'url'              => '',
					'link_text'        => '',
					'status'           => 'invalid',
					'reason'           => 'extract_failed',
					'target_post_id'   => 0,
				);
				continue;
			}
			if ( $examined + count( $links ) > $max_links ) {
				if ( $scanned > 0 ) {
					// Stop before this post so the caller can resume at it.
					$truncated = true;
					break;
				}
				// A single post larger than the budget: check the first
				// max_links links, flag it, and move past it.
				$links     = array_slice( $links, 0, $max_links );
				$truncated = true;
				$partial[] = (int) $post->ID;
			}
			++$scanned;
			$base = (string) get_permalink( $post );
			foreach ( $links as $link ) {
				$res = self::classify( $link['href'], $base );
				if ( 'external' === $res['status'] ) {
					++$external;
					continue;
				}
				if ( 'non_http' === $res['status'] ) {
					++$non_http;
					continue;
				}
				++$examined;
				++$summary[ $res['status'] ];
				if ( $only_broken && ! in_array( $res['status'], array( 'broken', 'invalid' ), true ) ) {
					continue;
				}
				$items[] = array(
					'source_post_id'   => (int) $post->ID,
					'source_post_type' => (string) $post->post_type,
					'url'              => $link['href'],
					'link_text'        => $link['text'],
					'status'           => $res['status'],
					'reason'           => $res['reason'],
					'target_post_id'   => $res['target_post_id'],
				);
			}
		}

		$total = (int) $query->found_posts;
		$next  = $offset + $scanned < $total ? $offset + $scanned : null;

		return array(
			'generated_at'      => gmdate( 'c' ),
			'total_posts'       => $total,
			'posts_scanned'     => $scanned,
			'omitted_forbidden' => $omitted,
			'links_examined'    => $examined,
			'external_ignored'  => $external,
			'non_http_ignored'  => $non_http,
			'truncated'         => $truncated,
			'partial_posts'     => $partial,
			'limit'             => $limit,
			'offset'            => $offset,
			'next_offset'       => $next,
			'summary'           => $summary,
			'items'             => $items,
		);
	}

	/**
	 * Pull <a href> links (and link text) out of HTML.
	 *
	 * Linear-time: opening tags are matched with `<a\b[^>]*>` (no
	 * backtracking across content); link text runs to the next `</a` or the
	 * next `<a`, so an unclosed anchor cannot swallow the following link.
	 * Behaves the same on every supported WordPress version.
	 *
	 * @return array<int,array{href:string,text:string}>|null Null when PCRE fails.
	 */
	public static function extract_links( $html ) {
		$html = (string) $html;
		if ( '' === $html || false === stripos( $html, '<a' ) ) {
			return array();
		}
		if ( false === preg_match_all( '/<a\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tags = $m[0];
		$out  = array();
		$len  = strlen( $html );
		foreach ( $tags as $i => $tag ) {
			list( $tag_html, $start ) = $tag;
			if ( ! preg_match( '/(?<![\w-])href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag_html, $h ) ) {
				continue;
			}
			$href = isset( $h[3] ) && '' !== $h[3] ? $h[3] : ( isset( $h[2] ) && '' !== $h[2] ? $h[2] : $h[1] );

			$text_start = $start + strlen( $tag_html );
			$close      = stripos( $html, '</a', $text_start );
			$next_open  = isset( $tags[ $i + 1 ] ) ? $tags[ $i + 1 ][1] : $len;
			$text_end   = min( false === $close ? $len : $close, $next_open, $text_start + 4000 );
			$text       = substr( $html, $text_start, max( 0, $text_end - $text_start ) );
			$text       = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );

			$out[] = array(
				'href' => trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) ),
				'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 ),
			);
		}
		return $out;
	}

	/**
	 * Classify one href.
	 *
	 * @return array{status:string,reason:string,target_post_id:int}
	 */
	public static function classify( $href, $base = '' ) {
		$href = (string) $href;
		if ( false !== strpos( $href, "\0" ) ) {
			return self::result( 'invalid', 'malformed_url' );
		}
		if ( '' === $href ) {
			return self::result( 'invalid', 'empty_href' );
		}
		if ( '#' === $href[0] ) {
			return self::result( 'ok', 'anchor' );
		}
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $href, $s ) && ! in_array( strtolower( $s[1] ), array( 'http', 'https' ), true ) ) {
			return self::result( 'non_http', strtolower( $s[1] ) );
		}

		$parts = wp_parse_url( $href );
		if ( false === $parts ) {
			return self::result( 'invalid', 'malformed_url' );
		}
		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		if ( isset( $parts['host'] ) && '' !== $parts['host'] ) {
			$host = strtolower( $parts['host'] );
			if ( $host !== $home_host && 'www.' . $host !== $home_host && $host !== 'www.' . $home_host ) {
				return self::result( 'external', 'external_host' );
			}
		} elseif ( isset( $parts['scheme'] ) ) {
			return self::result( 'invalid', 'malformed_url' );
		} elseif ( ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			// Query-only ("?a=b") hrefs depend on request context.
			return self::result( 'skipped', 'query_only' );
		}

		$path      = isset( $parts['path'] ) ? $parts['path'] : '/';
		$home_path = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $path && '/' !== $path[0] ) {
			// Relative path: resolve against the linking document, as a
			// browser would, then normalise dot segments.
			$base_path = (string) wp_parse_url( '' !== $base ? $base : home_url( '/' ), PHP_URL_PATH );
			$dir       = '/' === substr( $base_path, -1 ) ? $base_path : dirname( $base_path ) . '/';
			$path      = $dir . $path;
		}
		$path = self::remove_dot_segments( $path );
		if ( '' !== $home_path && $path !== $home_path && 0 !== strpos( $path, $home_path . '/' ) ) {
			return self::result( 'skipped', 'outside_site_path' );
		}
		$rel = ltrim( (string) substr( $path, strlen( $home_path ) ), '/' );

		$key = $rel . '?' . ( isset( $parts['query'] ) ? $parts['query'] : '' );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		self::$cache[ $key ] = self::resolve( $rel, isset( $parts['query'] ) ? $parts['query'] : '' );
		return self::$cache[ $key ];
	}

	/**
	 * Resolve a site-relative path (no leading slash) and query string.
	 */
	private static function resolve( $rel, $query ) {
		foreach ( self::SYSTEM_PREFIXES as $prefix ) {
			if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) || 0 === strpos( $rel, $prefix . '?' ) ) {
				return self::result( 'skipped', 'system_path' );
			}
		}

		$uploads = wp_upload_dir( null, false );
		$up_url  = isset( $uploads['baseurl'] ) ? wp_parse_url( $uploads['baseurl'] ) : array();
		$up_rel  = isset( $up_url['path'] ) ? ltrim( (string) substr( $up_url['path'], strlen( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ) ), '/' ) : '';
		if ( '' !== $up_rel && 0 === strpos( $rel, trailingslashit( $up_rel ) ) ) {
			$file = rawurldecode( substr( $rel, strlen( trailingslashit( $up_rel ) ) ) );
			$base = realpath( (string) $uploads['basedir'] );
			if ( false === $base || false !== strpos( $file, '..' ) || false !== strpos( $file, "\0" ) || false !== strpos( $file, '\\' ) ) {
				return self::result( 'invalid', 'bad_upload_path' );
			}
			return is_file( trailingslashit( $base ) . $file )
				? self::result( 'ok', 'upload_exists' )
				: self::result( 'broken', 'missing_upload' );
		}

		parse_str( (string) $query, $q );
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $param ) {
			if ( isset( $q[ $param ] ) && is_numeric( $q[ $param ] ) ) {
				return self::post_result( (int) $q[ $param ], 'query_id' );
			}
		}

		if ( '' === trim( $rel, '/' ) ) {
			return self::result( 'ok', 'home' );
		}

		$url = home_url( '/' . $rel );
		$id  = url_to_postid( $url );
		if ( $id > 0 ) {
			return self::post_result( $id, 'permalink' );
		}

		// Archive-style suffixes and the permalink front (e.g. /blog/) are
		// not part of the term/author/date identity.
		$trimmed = trim( $rel, '/' );
		$trimmed = (string) preg_replace( '#(^|/)(feed(/(rss2?|rdf|atom))?|embed|page/\d+|comment-page-\d+)$#', '', $trimmed );
		$trimmed = trim( $trimmed, '/' );
		global $wp_rewrite;
		$front = $wp_rewrite ? trim( (string) $wp_rewrite->front, '/' ) : '';
		if ( '' !== $front && 0 === strpos( $trimmed . '/', $front . '/' ) ) {
			$trimmed = trim( (string) substr( $trimmed, strlen( $front ) ), '/' );
		}
		if ( '' === $trimmed ) {
			return self::result( 'ok', 'home_paged' );
		}
		if ( $trimmed !== trim( $rel, '/' ) ) {
			$id = url_to_postid( home_url( '/' . $trimmed . '/' ) );
			if ( $id > 0 ) {
				return self::post_result( $id, 'permalink' );
			}
		}
		$segs = explode( '/', $trimmed );

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$archive = get_post_type_archive_link( $pt->name );
			if ( $archive && ltrim( (string) substr( trim( (string) wp_parse_url( $archive, PHP_URL_PATH ), '/' ), strlen( trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ) ), '/' ) === $trimmed ) {
				return self::result( 'ok', 'post_type_archive' );
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$slug = is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ? trim( $tax->rewrite['slug'], '/' ) : '';
			if ( '' === $slug || 0 !== strpos( $trimmed . '/', $slug . '/' ) ) {
				continue;
			}
			$term_slug = end( $segs );
			return get_term_by( 'slug', sanitize_title( $term_slug ), $tax->name )
				? self::result( 'ok', 'term_archive' )
				: self::result( 'broken', 'term_not_found' );
		}

		$author_base = $wp_rewrite && $wp_rewrite->author_base ? $wp_rewrite->author_base : 'author';
		if ( 2 <= count( $segs ) && $author_base === $segs[0] ) {
			return get_user_by( 'slug', $segs[1] ) ? self::result( 'ok', 'author_archive' ) : self::result( 'broken', 'author_not_found' );
		}
		if ( preg_match( '#^\d{4}(/\d{2}(/\d{2})?)?$#', $trimmed ) ) {
			return self::result( 'ok', 'date_archive' );
		}

		// A path that matches no post, page, attachment, archive or term.
		return self::result( 'broken', 'not_found' );
	}

	/**
	 * RFC 3986 §5.2.4 dot-segment removal for a path.
	 */
	private static function remove_dot_segments( $path ) {
		$out = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
			} elseif ( '.' !== $seg ) {
				$out[] = $seg;
			}
		}
		$joined = implode( '/', $out );
		if ( preg_match( '#/\.\.?$#', $path ) ) {
			$joined .= '/';
		}
		return '' === $joined ? '/' : $joined;
	}

	private static function post_result( $id, $via ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return self::result( 'broken', 'post_missing', $id );
		}
		if ( 'trash' === $post->post_status ) {
			return current_user_can( 'edit_post', $id ) ? self::result( 'broken', 'post_trashed', $id ) : self::result( 'broken', 'target_not_accessible' );
		}
		if ( 'attachment' === $post->post_type || 'publish' === $post->post_status ) {
			return self::result( 'ok', $via, $id );
		}
		if ( ! current_user_can( 'read_post', $id ) ) {
			// Do not confirm that a non-public post the caller cannot read exists.
			return self::result( 'broken', 'target_not_accessible' );
		}
		return self::result( 'broken', 'post_not_published', $id );
	}

	private static function result( $status, $reason, $target = 0 ) {
		return array(
			'status'         => $status,
			'reason'         => $reason,
			'target_post_id' => (int) $target,
		);
	}

	private static function list_arg( $value ) {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $list ) ) ) ) );
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
