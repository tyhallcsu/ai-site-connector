<?php
/**
 * SEO plugin abstraction layer.
 *
 * One internal surface for reading SEO metadata regardless of which SEO
 * plugin is active (Rank Math, Yoast, AIOSEO, SEOPress, or native fallback).
 *
 * Write side is intentionally guarded: `update_seo_meta()` defaults to
 * `$dry_run = true` and consults `AI_Site_Connector_Permissions::tool_allowed()`
 * before performing any real mutation. Real writes require both
 * `$dry_run = false` AND the `update_seo` permission enabled (default OFF).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_SEO {

	/**
	 * Canonical field keys returned from `get_seo_meta()` and accepted by
	 * `update_seo_meta()`. Pure-plugin-neutral field names — the abstraction
	 * is responsible for mapping these to per-plugin meta keys.
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
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return 'none';
	}

	/**
	 * Read SEO metadata for a post in plugin-neutral form.
	 *
	 * @param int $post_id Post / page / CPT ID.
	 * @return array {
	 *   @type string $plugin           Detected plugin name (see detect_seo_plugin()).
	 *   @type int    $post_id          Echoed back.
	 *   @type array  $fields           Canonical field => value map. Missing fields are '' (not omitted).
	 *   @type array  $source_meta_keys Per-canonical-field source meta key, for transparency.
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

		$map = self::meta_key_map( $plugin );
		foreach ( self::FIELDS as $field ) {
			$key = isset( $map[ $field ] ) ? $map[ $field ] : '';
			if ( '' !== $key ) {
				$value = (string) get_post_meta( $post_id, $key, true );
			} else {
				$value = '';
			}
			if ( '' === $value && 'none' === $plugin ) {
				// Native fallback — use post fields where reasonable.
				if ( 'title' === $field ) {
					$value = (string) get_the_title( $post_id );
				} elseif ( 'description' === $field ) {
					$value = (string) $post->post_excerpt;
				} elseif ( 'canonical' === $field ) {
					$value = (string) get_permalink( $post_id );
				}
			}
			$fields[ $field ] = $value;
			$source[ $field ] = $key;
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
	 *  - `$dry_run = false` AND the `update_seo` permission is enabled:
	 *    performs the write via update_post_meta().
	 *  - `$dry_run = false` but the permission is OFF: blocked, returns an
	 *    error and does not mutate.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $data    Canonical field => new value map. Unknown fields ignored.
	 * @param bool  $dry_run Default true. Set false to actually write (still gated by permission).
	 * @return array {
	 *   @type bool   $applied      true only when a real mutation happened.
	 *   @type bool   $dry_run      Echoed back.
	 *   @type bool   $blocked      true when the permission gate refused.
	 *   @type string $reason       'dry_run' | 'permission_denied' | 'post_not_found' | 'no_op' | 'ok'.
	 *   @type array  $would_write  field => array(old, new) for every field that *would* change.
	 *   @type string $plugin       Detected SEO plugin.
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
			'plugin'      => $plugin,
		);

		if ( ! get_post( $post_id ) ) {
			$response['reason']  = 'post_not_found';
			$response['blocked'] = true;
			return $response;
		}

		// Compute the proposed diff first — used for both dry-run output AND
		// the actual write path. Skips fields whose canonical key is unknown
		// for the detected plugin (no native write target).
		$map = self::meta_key_map( $plugin );
		foreach ( $data as $field => $new_value ) {
			if ( ! in_array( $field, self::FIELDS, true ) ) {
				continue;
			}
			if ( empty( $map[ $field ] ) ) {
				continue;
			}
			$meta_key = $map[ $field ];
			$old      = (string) get_post_meta( $post_id, $meta_key, true );
			$new      = (string) $new_value;
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

		// Real write requested. Permission gate — same pattern as the other
		// write tools (cache purge, media sideload). `can()` honours
		// read-only mode, WP capability, and the per-tool whitelist setting.
		if ( ! class_exists( 'AI_Site_Connector_Permissions' )
			|| ! AI_Site_Connector_Permissions::can( AI_Site_Connector_Permissions::TOOL_UPDATE_SEO ) ) {
			$response['blocked'] = true;
			$response['reason']  = 'permission_denied';
			return $response;
		}

		foreach ( $response['would_write'] as $field => $row ) {
			update_post_meta( $post_id, $row['meta_key'], $row['new'] );
		}
		$response['applied'] = true;
		$response['reason']  = 'ok';
		return $response;
	}

	/**
	 * Canonical-field → per-plugin meta-key map. Returned per detection call
	 * (cheap, no caching needed). Returns empty strings for fields that have
	 * no native write target on the detected plugin (e.g. AIOSEO stores most
	 * data in custom tables, not post meta, so writing via post meta is not
	 * safe and we deliberately leave those slots empty).
	 */
	private static function meta_key_map( $plugin ) {
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
				// AIOSEO's post-level fields live in the aioseo_posts custom
				// table, not post meta. The legacy `_aioseop_*` post-meta
				// keys are still readable on older posts; leave them as
				// READ-ONLY hints. Returning empty here means update_seo_meta
				// will skip the field rather than write to the wrong place.
				return array(
					'title'          => '_aioseop_title',
					'description'    => '_aioseop_description',
					'canonical'      => '_aioseop_custom_link',
					'og_title'       => '_aioseop_opengraph_settings',
					'og_description' => '',
					'og_image'       => '',
					'noindex'        => '_aioseop_noindex',
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
			case 'none':
			default:
				return array(
					'title'          => '',
					'description'    => '',
					'canonical'      => '',
					'og_title'       => '',
					'og_description' => '',
					'og_image'       => '',
					'noindex'        => '',
				);
		}
	}
}
