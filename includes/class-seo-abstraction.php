<?php
/**
 * SEO plugin abstraction layer.
 *
 * One internal surface for reading SEO metadata regardless of which SEO
 * plugin is active (Rank Math, Yoast, AIOSEO, SEOPress, or native fallback).
 *
 * Support matrix (what is actually implemented, not merely detected):
 *
 *   plugin    | read                                  | write (guarded)
 *   ----------+---------------------------------------+---------------------------------
 *   rankmath  | all fields (post meta)                | title, description, canonical, og_*
 *   yoast     | all fields (post meta)                | title, description, canonical, og_*
 *   seopress  | all fields (post meta)                | title, description, canonical, og_*
 *   aioseo    | aioseo_posts table, legacy meta       | none (custom table; reported as skipped)
 *   none      | native fallbacks (title/excerpt/link) | none
 *
 * `noindex` is read-only everywhere: each plugin encodes robots directives
 * differently (Rank Math stores a serialized array), so a plain string write
 * would corrupt them.
 *
 * Writes default to dry-run. A real write requires `$dry_run = false`, the
 * `update_seo` tool permission (default OFF), and `edit_post` on the target.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_SEO {

	/**
	 * Canonical, plugin-neutral field keys returned by `get_seo_meta()` and
	 * accepted by `update_seo_meta()`.
	 */
	const FIELDS = array(
		'title',
		'description',
		'canonical',
		'og_title',
		'og_description',
		'og_image',
		'noindex',
	);

	/** Fields that hold URLs and are validated with esc_url_raw() on write. */
	const URL_FIELDS = array( 'canonical', 'og_image' );

	public static function register_hooks() {
		// Pure service class — no hooks to register. Method here for symmetry
		// with other modules so class-plugin.php can call it without
		// special-casing.
	}

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string One of 'rankmath' | 'yoast' | 'aioseo' | 'seopress' | 'none'.
	 */
	public static function detect_seo_plugin() {
		$detected = 'none';
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$detected = 'rankmath';
		} elseif ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$detected = 'yoast';
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$detected = 'aioseo';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$detected = 'seopress';
		}

		/**
		 * Override SEO plugin detection — e.g. to pick the authoritative
		 * plugin when several are active. Unknown values fall back to 'none'.
		 *
		 * @param string $detected One of rankmath|yoast|aioseo|seopress|none.
		 */
		$filtered = (string) apply_filters( 'ai_site_connector_seo_plugin', $detected );
		return in_array( $filtered, array( 'rankmath', 'yoast', 'aioseo', 'seopress', 'none' ), true ) ? $filtered : 'none';
	}

	/**
	 * Fields this abstraction can write for a plugin.
	 *
	 * @param string $plugin Plugin slug from detect_seo_plugin().
	 * @return string[]
	 */
	public static function writable_fields( $plugin ) {
		return array_keys( array_filter( self::write_map( $plugin ) ) );
	}

	/**
	 * Read SEO metadata for a post in plugin-neutral form. Callers are
	 * responsible for authorising access to the post.
	 *
	 * @param int $post_id Post / page / CPT ID.
	 * @return array {
	 *   @type string $plugin           Detected plugin name (see detect_seo_plugin()).
	 *   @type int    $post_id          Echoed back.
	 *   @type array  $fields           Canonical field => string value. Missing fields are ''.
	 *                                  `noindex` is '1' when the post is set to noindex, else ''.
	 *   @type array  $source_meta_keys Canonical field => where the value came from.
	 * }
	 */
	public static function get_seo_meta( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		$plugin = self::detect_seo_plugin();
		$fields = array_fill_keys( self::FIELDS, '' );
		$source = array_fill_keys( self::FIELDS, '' );

		if ( ! $post ) {
			return array(
				'plugin'           => $plugin,
				'post_id'          => $post_id,
				'fields'           => $fields,
				'source_meta_keys' => $source,
				'error'            => 'post_not_found',
			);
		}

		if ( 'aioseo' === $plugin ) {
			$row = self::aioseo_row( $post_id );
			if ( null !== $row ) {
				$columns = array(
					'title'          => 'title',
					'description'    => 'description',
					'canonical'      => 'canonical_url',
					'og_title'       => 'og_title',
					'og_description' => 'og_description',
					'og_image'       => 'og_image_custom_url',
					'noindex'        => 'robots_noindex',
				);
				foreach ( $columns as $field => $column ) {
					if ( array_key_exists( $column, $row ) ) {
						$fields[ $field ] = self::scalar( $row[ $column ] );
						$source[ $field ] = 'aioseo_posts.' . $column;
					}
				}
				$fields['noindex'] = self::truthy_flag( $fields['noindex'] ) ? '1' : '';
			}
		}

		$map = self::read_map( $plugin );
		foreach ( self::FIELDS as $field ) {
			if ( '' !== $fields[ $field ] || empty( $map[ $field ] ) ) {
				continue;
			}
			$raw              = get_post_meta( $post_id, $map[ $field ], true );
			$fields[ $field ] = 'noindex' === $field ? self::normalize_noindex( $plugin, $raw ) : self::scalar( $raw );
			$source[ $field ] = $map[ $field ];
		}

		if ( 'none' === $plugin ) {
			// Native fallback — what WordPress itself would render.
			$fields['title']       = (string) get_the_title( $post_id );
			$fields['description'] = (string) $post->post_excerpt;
			$fields['canonical']   = (string) get_permalink( $post_id );
			$source['title']       = 'post_title';
			$source['description'] = 'post_excerpt';
			$source['canonical']   = 'permalink';
		}

		return array(
			'plugin'           => $plugin,
			'post_id'          => $post_id,
			'fields'           => $fields,
			'source_meta_keys' => $source,
		);
	}

	/**
	 * Write SEO metadata (guarded). Defaults to dry-run.
	 *
	 * Behaviour:
	 *  - `$dry_run = true` (default): never mutates. Returns the diff that
	 *    *would* be written.
	 *  - `$dry_run = false`: writes only when the `update_seo` permission is
	 *    enabled AND the current user can `edit_post` the target.
	 *  - Fields the active plugin cannot safely store, unknown fields, and
	 *    invalid URLs are reported in `skipped` and never written.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $data    Canonical field => new value map.
	 * @param bool  $dry_run Default true. Set false to actually write (still gated).
	 * @return array {
	 *   @type bool   $applied     true only when a real mutation happened.
	 *   @type bool   $dry_run     Echoed back.
	 *   @type bool   $blocked     true when a gate refused.
	 *   @type string $reason      'dry_run' | 'permission_denied' | 'forbidden_post' |
	 *                             'post_not_found' | 'no_op' | 'ok'.
	 *   @type array  $would_write field => { meta_key, old, new } for every field that changes.
	 *   @type array  $skipped     field => reason ('unknown_field' | 'unsupported_field' | 'invalid_url').
	 *   @type string $plugin      Detected SEO plugin.
	 * }
	 */
	public static function update_seo_meta( $post_id, array $data, $dry_run = true ) {
		$post_id  = (int) $post_id;
		$plugin   = self::detect_seo_plugin();
		$response = array(
			'applied'     => false,
			'dry_run'     => (bool) $dry_run,
			'blocked'     => false,
			'reason'      => '',
			'would_write' => array(),
			'skipped'     => array(),
			'plugin'      => $plugin,
		);

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$response['reason']  = 'post_not_found';
			$response['blocked'] = true;
			return $response;
		}

		// Object-level gate applies to dry runs too: a dry run discloses the
		// current values, so it must not reveal posts the caller cannot edit.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			$response['reason']  = 'forbidden_post';
			$response['blocked'] = true;
			return $response;
		}

		$map = self::write_map( $plugin );
		foreach ( $data as $field => $new_value ) {
			$field = (string) $field;
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				$response['skipped'][ $field ] = 'unknown_field';
				continue;
			}
			if ( empty( $map[ $field ] ) ) {
				$response['skipped'][ $field ] = 'unsupported_field';
				continue;
			}
			$new = self::sanitize_value( $field, $new_value );
			if ( null === $new ) {
				$response['skipped'][ $field ] = 'invalid_url';
				continue;
			}
			$meta_key = $map[ $field ];
			$old      = self::scalar( get_post_meta( $post_id, $meta_key, true ) );
			if ( $old !== $new ) {
				$response['would_write'][ $field ] = array(
					'meta_key' => $meta_key,
					'old'      => $old,
					'new'      => $new,
				);
			}
		}

		if ( empty( $response['would_write'] ) ) {
			$response['reason'] = 'no_op';
			return $response;
		}

		if ( $dry_run ) {
			$response['reason'] = 'dry_run';
			return $response;
		}

		// `can()` honours read-only mode, the tool's WP capability, the
		// per-tool whitelist (update_seo defaults OFF) and the override filter.
		if ( ! class_exists( 'AI_Site_Connector_Permissions' )
			|| ! AI_Site_Connector_Permissions::can( AI_Site_Connector_Permissions::TOOL_UPDATE_SEO, array( 'post_id' => $post_id ) ) ) {
			$response['blocked'] = true;
			$response['reason']  = 'permission_denied';
			return $response;
		}

		foreach ( $response['would_write'] as $row ) {
			if ( '' === $row['new'] ) {
				delete_post_meta( $post_id, $row['meta_key'] );
			} else {
				// wp_slash: update_post_meta() unslashes its input.
				update_post_meta( $post_id, $row['meta_key'], wp_slash( $row['new'] ) );
			}
		}
		$response['applied'] = true;
		$response['reason']  = 'ok';
		return $response;
	}

	/**
	 * Canonical field → post-meta key used for reads.
	 */
	private static function read_map( $plugin ) {
		switch ( $plugin ) {
			case 'rankmath':
				return array(
					'title'          => 'rank_math_title',
					'description'    => 'rank_math_description',
					'canonical'      => 'rank_math_canonical_url',
					'og_title'       => 'rank_math_facebook_title',
					'og_description' => 'rank_math_facebook_description',
					'og_image'       => 'rank_math_facebook_image',
					'noindex'        => 'rank_math_robots',
				);
			case 'yoast':
				return array(
					'title'          => '_yoast_wpseo_title',
					'description'    => '_yoast_wpseo_metadesc',
					'canonical'      => '_yoast_wpseo_canonical',
					'og_title'       => '_yoast_wpseo_opengraph-title',
					'og_description' => '_yoast_wpseo_opengraph-description',
					'og_image'       => '_yoast_wpseo_opengraph-image',
					'noindex'        => '_yoast_wpseo_meta-robots-noindex',
				);
			case 'aioseo':
				// Legacy (AIOSEO 3.x) post meta — only consulted when the
				// aioseo_posts table has no value for the field.
				return array(
					'title'       => '_aioseop_title',
					'description' => '_aioseop_description',
					'canonical'   => '_aioseop_custom_link',
					'noindex'     => '_aioseop_noindex',
				);
			case 'seopress':
				return array(
					'title'          => '_seopress_titles_title',
					'description'    => '_seopress_titles_desc',
					'canonical'      => '_seopress_robots_canonical',
					'og_title'       => '_seopress_social_fb_title',
					'og_description' => '_seopress_social_fb_desc',
					'og_image'       => '_seopress_social_fb_img',
					'noindex'        => '_seopress_robots_index',
				);
			default:
				return array();
		}
	}

	/**
	 * Canonical field → post-meta key used for writes. Only plain-string
	 * meta values the plugin reads back verbatim are listed.
	 */
	private static function write_map( $plugin ) {
		$map = self::read_map( $plugin );
		if ( 'aioseo' === $plugin ) {
			// AIOSEO 4 reads from its custom table; legacy meta writes would
			// be silently ignored, so nothing is writable.
			return array();
		}
		unset( $map['noindex'] );
		return $map;
	}

	/**
	 * Normalise each plugin's robots encoding to '1' (noindex) or ''.
	 */
	private static function normalize_noindex( $plugin, $raw ) {
		switch ( $plugin ) {
			case 'rankmath':
				// Serialized array of directives, e.g. array( 'noindex', 'nofollow' ).
				return is_array( $raw ) && in_array( 'noindex', $raw, true ) ? '1' : '';
			case 'yoast':
				// '1' = noindex, '2' = explicitly index, '' = default.
				return '1' === self::scalar( $raw ) ? '1' : '';
			case 'seopress':
				return 'yes' === self::scalar( $raw ) ? '1' : '';
			default:
				return self::truthy_flag( self::scalar( $raw ) ) ? '1' : '';
		}
	}

	private static function truthy_flag( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * Coerce a stored meta value to a string without leaking "Array".
	 */
	private static function scalar( $value ) {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * @return string|null Sanitised value, or null when a URL field is invalid.
	 */
	private static function sanitize_value( $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( in_array( $field, self::URL_FIELDS, true ) ) {
			if ( '' === $value ) {
				return '';
			}
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			return '' === $url ? null : $url;
		}
		return sanitize_text_field( $value );
	}

	/**
	 * Fetch the AIOSEO 4 per-post row, or null when the table/row is absent.
	 */
	private static function aioseo_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( (string) $exists !== (string) $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
