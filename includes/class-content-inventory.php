<?php
/**
 * Content inventory export (#63).
 *
 * Paginated, filterable inventory of posts, pages and custom post types with
 * taxonomy terms and SEO fields. Read-only. Shared by REST, MCP and WP-CLI.
 *
 * Items are returned only for posts the current user can `edit_post`;
 * others are counted in `omitted_forbidden` so pagination stays consistent.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Content_Inventory {

	const MAX_LIMIT     = 500;
	const DEFAULT_LIMIT = 100;

	/** Flattened CSV columns, in output order. */
	const CSV_COLUMNS = array(
		'id',
		'post_type',
		'title',
		'slug',
		'status',
		'author_id',
		'created_gmt',
		'modified_gmt',
		'permalink',
		'excerpt',
		'featured_image_id',
		'featured_image_url',
		'parent_id',
		'menu_order',
		'terms',
		'seo_plugin',
		'seo_title',
		'seo_description',
		'seo_canonical',
	);

	/**
	 * Post types the inventory may export: public or admin-visible, minus
	 * attachments (see the media tools) and internal types.
	 *
	 * @return string[]
	 */
	public static function allowed_post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'names' );
		unset( $types['attachment'], $types['wp_block'], $types['wp_template'], $types['wp_template_part'], $types['wp_navigation'], $types['wp_global_styles'] );
		$types = array_values( array_map( 'strval', $types ) );
		sort( $types );
		return $types;
	}

	/**
	 * Author restriction for list queries: callers who cannot edit others'
	 * items of every requested type only see their own, so totals and counts
	 * never reveal other users' drafts or private posts.
	 *
	 * @param string[] $types Post types being queried.
	 * @return int[] Value for WP_Query `author__in` (empty = no restriction;
	 *               array( 0 ) matches nothing for an anonymous caller).
	 */
	public static function author_scope( array $types ) {
		foreach ( $types as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj || ! current_user_can( $obj->cap->edit_others_posts ) ) {
				return array( (int) get_current_user_id() );
			}
		}
		return array();
	}

	/**
	 * Run the inventory query.
	 *
	 * @param array $args {
	 *   @type string|string[] $post_type       Default: all allowed types.
	 *   @type string|string[] $status          Default 'any' (excludes trash/auto-draft).
	 *   @type string          $modified_after  ISO-8601 / MySQL datetime, UTC, exclusive.
	 *   @type string          $modified_before ISO-8601 / MySQL datetime, UTC, exclusive.
	 *   @type int             $limit           1..500, default 100.
	 *   @type int             $offset          >= 0.
	 *   @type bool            $include_terms   Default true.
	 *   @type bool            $include_seo     Default true.
	 * }
	 * @return array|WP_Error
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_type'       => array(),
				'status'          => 'any',
				'modified_after'  => '',
				'modified_before' => '',
				'limit'           => self::DEFAULT_LIMIT,
				'offset'          => 0,
				'include_terms'   => true,
				'include_seo'     => true,
			)
		);

		$allowed = self::allowed_post_types();
		$types   = self::list_arg( $args['post_type'] );
		if ( empty( $types ) ) {
			$types = $allowed;
		}
		$bad = array_diff( $types, $allowed );
		if ( ! empty( $bad ) ) {
			return self::invalid( 'post_type', sprintf( 'Unsupported post_type: %s. Allowed: %s.', implode( ',', $bad ), implode( ',', $allowed ) ) );
		}

		$statuses = self::list_arg( $args['status'] );
		if ( empty( $statuses ) || array( 'any' ) === $statuses ) {
			$statuses = 'any';
		} else {
			$valid = array_keys( get_post_stati() );
			$bad   = array_diff( $statuses, $valid );
			if ( ! empty( $bad ) ) {
				return self::invalid( 'status', sprintf( 'Unsupported status: %s.', implode( ',', $bad ) ) );
			}
		}

		$limit  = (int) $args['limit'];
		$offset = (int) $args['offset'];
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			return self::invalid( 'limit', sprintf( 'limit must be between 1 and %d.', self::MAX_LIMIT ) );
		}
		if ( $offset < 0 ) {
			return self::invalid( 'offset', 'offset must be >= 0.' );
		}

		$date_query = array();
		foreach ( array(
			'modified_after'  => 'after',
			'modified_before' => 'before',
		) as $key => $op ) {
			$raw = trim( (string) $args[ $key ] );
			if ( '' === $raw ) {
				continue;
			}
			$ts = strtotime( $raw );
			if ( false === $ts ) {
				return self::invalid( $key, sprintf( '%s is not a valid date/time.', $key ) );
			}
			// Filter on the local column: WordPress leaves post_modified_gmt
			// as 0000-00-00 on never-published drafts, so the GMT column
			// would misplace them.
			$date_query[] = array(
				'column'    => 'post_modified',
				$op         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ) ),
				'inclusive' => false,
			);
		}

		$include_terms = rest_sanitize_boolean( $args['include_terms'] );
		$include_seo   = rest_sanitize_boolean( $args['include_seo'] );

		$query = new WP_Query(
			array(
				'author__in'             => self::author_scope( $types ),
				'post_type'              => $types,
				'post_status'            => $statuses,
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'date_query'             => $date_query,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => $include_seo,
				'update_post_term_cache' => $include_terms,
			)
		);

		$seo_plugin = $include_seo ? AI_Site_Connector_SEO::detect_seo_plugin() : '';
		$items      = array();
		$omitted    = 0;
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				++$omitted;
				continue;
			}
			$items[] = self::item( $post, $include_terms, $include_seo );
		}

		$scanned = count( $query->posts );
		$total   = (int) $query->found_posts;

		return array(
			'generated_at'      => gmdate( 'c' ),
			'site_url'          => home_url(),
			'filters'           => array(
				'post_type'       => $types,
				'status'          => 'any' === $statuses ? array( 'any' ) : $statuses,
				'modified_after'  => (string) $args['modified_after'],
				'modified_before' => (string) $args['modified_before'],
			),
			'seo_plugin'        => $seo_plugin,
			'total'             => $total,
			'count'             => count( $items ),
			'omitted_forbidden' => $omitted,
			'limit'             => $limit,
			'offset'            => $offset,
			'next_offset'       => $offset + $scanned < $total ? $offset + $scanned : null,
			'items'             => $items,
		);
	}

	/**
	 * One inventory row.
	 */
	private static function item( WP_Post $post, $include_terms, $include_seo ) {
		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		$row      = array(
			'id'             => (int) $post->ID,
			'post_type'      => (string) $post->post_type,
			'title'          => (string) $post->post_title,
			'slug'           => (string) $post->post_name,
			'status'         => (string) $post->post_status,
			'author_id'      => (int) $post->post_author,
			'created_gmt'    => self::gmt( $post->post_date_gmt, $post->post_date ),
			'modified_gmt'   => self::gmt( $post->post_modified_gmt, $post->post_modified ),
			'permalink'      => (string) get_permalink( $post ),
			'excerpt'        => (string) $post->post_excerpt,
			'featured_image' => array(
				'id'  => $thumb_id,
				'url' => $thumb_id ? (string) wp_get_attachment_url( $thumb_id ) : '',
			),
			'parent_id'      => (int) $post->post_parent,
			'menu_order'     => (int) $post->menu_order,
		);

		if ( $include_terms ) {
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $tax ) {
				if ( 'post_format' === $tax && ! current_theme_supports( 'post-formats' ) ) {
					continue;
				}
				$list = get_the_terms( $post, $tax );
				if ( ! is_array( $list ) ) {
					continue;
				}
				$out = array();
				foreach ( $list as $term ) {
					$out[] = array(
						'id'   => (int) $term->term_id,
						'slug' => (string) $term->slug,
						'name' => (string) $term->name,
					);
				}
				usort(
					$out,
					static function ( $a, $b ) {
						return $a['id'] - $b['id'];
					}
				);
				$terms[ $tax ] = $out;
			}
			ksort( $terms );
			$row['terms'] = (object) $terms;
		}

		if ( $include_seo ) {
			$seo        = AI_Site_Connector_SEO::get_seo_meta( $post->ID );
			$row['seo'] = array(
				'title'       => $seo['fields']['title'],
				'description' => $seo['fields']['description'],
				'canonical'   => $seo['fields']['canonical'],
				'noindex'     => '1' === $seo['fields']['noindex'],
			);
		}

		return $row;
	}

	/**
	 * GMT timestamp, derived from the local column when WordPress stored the
	 * zero date (unpublished drafts).
	 */
	private static function gmt( $gmt, $local ) {
		$gmt = (string) $gmt;
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return get_gmt_from_date( (string) $local );
		}
		return $gmt;
	}

	/**
	 * Flatten inventory items into CSV text (RFC 4180, formula-injection safe).
	 *
	 * @param array  $items      Items from query().
	 * @param string $seo_plugin Detected SEO plugin, repeated per row.
	 * @return string
	 */
	public static function to_csv( array $items, $seo_plugin = '' ) {
		$lines = array( self::csv_line( self::CSV_COLUMNS ) );
		foreach ( $items as $item ) {
			$terms = array();
			foreach ( isset( $item['terms'] ) ? (array) $item['terms'] : array() as $tax => $list ) {
				foreach ( $list as $t ) {
					$terms[] = $tax . ':' . $t['slug'];
				}
			}
			$lines[] = self::csv_line(
				array(
					$item['id'],
					$item['post_type'],
					$item['title'],
					$item['slug'],
					$item['status'],
					$item['author_id'],
					$item['created_gmt'],
					$item['modified_gmt'],
					$item['permalink'],
					$item['excerpt'],
					$item['featured_image']['id'],
					$item['featured_image']['url'],
					$item['parent_id'],
					$item['menu_order'],
					implode( '|', $terms ),
					$seo_plugin,
					isset( $item['seo'] ) ? $item['seo']['title'] : '',
					isset( $item['seo'] ) ? $item['seo']['description'] : '',
					isset( $item['seo'] ) ? $item['seo']['canonical'] : '',
				)
			);
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function csv_line( array $cells ) {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			// Neutralise spreadsheet formula injection.
			if ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}
		return implode( ',', $out );
	}

	/**
	 * Accept "a,b", array( 'a', 'b' ) or '' and return sanitized keys.
	 *
	 * @return string[]
	 */
	private static function list_arg( $value ) {
		$list = is_array( $value ) ? $value : explode( ',', (string) $value );
		$list = array_filter( array_map( 'sanitize_key', array_map( 'strval', $list ) ) );
		return array_values( array_unique( $list ) );
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
