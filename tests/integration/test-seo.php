<?php
/**
 * SEO abstraction (#68): detection, plugin-neutral reads, guarded writes.
 *
 * Plugins are simulated through the `ai_site_connector_seo_plugin` filter
 * plus their real meta keys / table shapes, so no third-party code runs.
 *
 * @package AI_Site_Connector_Tests
 */

function asc_it_as_seo_plugin( $plugin, $fn ) {
	return asc_it_with_filter(
		'ai_site_connector_seo_plugin',
		static function () use ( $plugin ) {
			return $plugin;
		},
		$fn
	);
}

asc_it(
	'seo: clean install detects none and reads native fallbacks',
	function () {
		$post = asc_it_post( array( 'post_title' => 'Native Title', 'post_excerpt' => 'Native excerpt' ) );
		try {
			asc_assert_same( 'none', AI_Site_Connector_SEO::detect_seo_plugin(), 'detected plugin' );
			$meta = AI_Site_Connector_SEO::get_seo_meta( $post );
			asc_assert_same( 'Native Title', $meta['fields']['title'], 'fallback title' );
			asc_assert_same( 'Native excerpt', $meta['fields']['description'], 'fallback description' );
			asc_assert_same( get_permalink( $post ), $meta['fields']['canonical'], 'fallback canonical' );
			asc_assert_same( array(), AI_Site_Connector_SEO::writable_fields( 'none' ), 'none is not writable' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: unknown filter value falls back to none',
	function () {
		asc_it_as_seo_plugin(
			'not-a-plugin',
			function () {
				asc_assert_same( 'none', AI_Site_Connector_SEO::detect_seo_plugin(), 'invalid filter value' );
			}
		);
	}
);

asc_it(
	'seo: rank math meta keys read, robots array normalised to noindex flag',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, 'rank_math_title', 'RM title' );
		update_post_meta( $post, 'rank_math_description', 'RM desc' );
		update_post_meta( $post, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$meta = AI_Site_Connector_SEO::get_seo_meta( $post );
					asc_assert_same( 'rankmath', $meta['plugin'], 'plugin' );
					asc_assert_same( 'RM title', $meta['fields']['title'], 'title' );
					asc_assert_same( 'RM desc', $meta['fields']['description'], 'description' );
					asc_assert_same( '1', $meta['fields']['noindex'], 'noindex from robots array' );
					asc_assert( false === strpos( wp_json_encode( $meta ), 'Array' ), 'array meta leaked as "Array"' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: yoast meta keys read; noindex=2 (explicit index) is not noindex',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, '_yoast_wpseo_title', 'Y title' );
		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Y desc' );
		update_post_meta( $post, '_yoast_wpseo_meta-robots-noindex', '2' );
		try {
			asc_it_as_seo_plugin(
				'yoast',
				function () use ( $post ) {
					$meta = AI_Site_Connector_SEO::get_seo_meta( $post );
					asc_assert_same( 'Y title', $meta['fields']['title'], 'title' );
					asc_assert_same( 'Y desc', $meta['fields']['description'], 'description' );
					asc_assert_same( '', $meta['fields']['noindex'], 'noindex' );
					asc_assert_same( '_yoast_wpseo_metadesc', $meta['source_meta_keys']['description'], 'source key' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: aioseo reads from aioseo_posts table and refuses writes',
	function () {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		$post  = asc_it_post();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table} (id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL, title text, description text, canonical_url text, og_title text, og_description text, og_image_custom_url text, robots_noindex tinyint(1) DEFAULT 0, PRIMARY KEY (id))" );
		$wpdb->insert( $table, array( 'post_id' => $post, 'title' => 'AIO title', 'description' => 'AIO desc', 'robots_noindex' => 1 ) );
		try {
			asc_it_as_seo_plugin(
				'aioseo',
				function () use ( $post ) {
					$meta = AI_Site_Connector_SEO::get_seo_meta( $post );
					asc_assert_same( 'AIO title', $meta['fields']['title'], 'title' );
					asc_assert_same( 'AIO desc', $meta['fields']['description'], 'description' );
					asc_assert_same( '1', $meta['fields']['noindex'], 'noindex' );
					asc_assert_same( 'aioseo_posts.title', $meta['source_meta_keys']['title'], 'source' );

					$before = asc_it_db_fingerprint();
					$res    = asc_it_with_permissions(
						array( 'update_seo' => true ),
						function () use ( $post ) {
							return AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => 'new' ), false );
						}
					);
					asc_assert_same( false, $res['applied'], 'aioseo write applied' );
					asc_assert_same( 'unsupported_field', $res['skipped']['title'], 'aioseo skipped reason' );
					asc_assert_same( $before, asc_it_db_fingerprint(), 'aioseo write attempt mutated DB' );
				}
			);
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
			// phpcs:enable
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: dry-run causes zero mutation and reports the diff',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, 'rank_math_title', 'Old' );
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$before = asc_it_db_fingerprint();
					$res    = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => 'New', 'description' => 'D' ) );
					asc_assert_same( true, $res['dry_run'], 'dry_run default' );
					asc_assert_same( false, $res['applied'], 'applied' );
					asc_assert_same( 'dry_run', $res['reason'], 'reason' );
					asc_assert_same( 'Old', $res['would_write']['title']['old'], 'diff old' );
					asc_assert_same( 'New', $res['would_write']['title']['new'], 'diff new' );
					asc_assert_same( $before, asc_it_db_fingerprint(), 'dry-run mutated DB' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: real write blocked by default permission',
	function () {
		$post = asc_it_post();
		try {
			asc_it_as_seo_plugin(
				'yoast',
				function () use ( $post ) {
					$before = asc_it_db_fingerprint();
					$res    = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => 'x' ), false );
					asc_assert_same( false, $res['applied'], 'applied' );
					asc_assert_same( 'permission_denied', $res['reason'], 'reason' );
					asc_assert_same( $before, asc_it_db_fingerprint(), 'blocked write mutated DB' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: permitted write updates allowed fields and skips noindex, unknown and invalid URLs',
	function () {
		$post = asc_it_post();
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$res = asc_it_with_permissions(
						array( 'update_seo' => true ),
						function () use ( $post ) {
							return AI_Site_Connector_SEO::update_seo_meta(
								$post,
								array(
									'title'     => "Quote's <b>title</b>",
									'canonical' => 'javascript:alert(1)',
									'noindex'   => '1',
									'bogus'     => 'x',
								),
								false
							);
						}
					);
					asc_assert_same( true, $res['applied'], 'applied' );
					asc_assert_same( "Quote's title", get_post_meta( $post, 'rank_math_title', true ), 'stored title (sanitised, unslashed)' );
					asc_assert_same( 'invalid_url', $res['skipped']['canonical'], 'canonical skipped' );
					asc_assert_same( 'unsupported_field', $res['skipped']['noindex'], 'noindex skipped' );
					asc_assert_same( 'unknown_field', $res['skipped']['bogus'], 'unknown skipped' );
					asc_assert_same( '', (string) get_post_meta( $post, 'rank_math_robots', true ), 'robots untouched' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: author cannot dry-run or write another user\'s post',
	function () {
		$author = asc_it_user( 'author' );
		$post   = asc_it_post();
		try {
			asc_it_as_seo_plugin(
				'yoast',
				function () use ( $author, $post ) {
					$res = asc_it_as_user(
						$author,
						function () use ( $post ) {
							return AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => 'x' ) );
						}
					);
					asc_assert_same( 'forbidden_post', $res['reason'], 'reason' );
					asc_assert_same( array(), $res['would_write'], 'no diff disclosed' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
			asc_it_delete_user( $author );
		}
	}
);

asc_it(
	'seo: missing post is reported, not fatal',
	function () {
		$res = AI_Site_Connector_SEO::update_seo_meta( 999999999, array( 'title' => 'x' ) );
		asc_assert_same( 'post_not_found', $res['reason'], 'reason' );
		$meta = AI_Site_Connector_SEO::get_seo_meta( 999999999 );
		asc_assert_same( 'post_not_found', $meta['error'], 'read error' );
	}
);

asc_it(
	'seo: template variables and percent signs survive sanitisation',
	function () {
		$post = asc_it_post();
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$res = asc_it_with_permissions(
						array( 'update_seo' => true ),
						function () use ( $post ) {
							return AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => '%title% - %category% | 100%', 'description' => "Line one\nline <em>two</em>" ), false );
						}
					);
					asc_assert_same( true, $res['applied'], 'applied' );
					asc_assert_same( '%title% - %category% | 100%', get_post_meta( $post, 'rank_math_title', true ), 'template vars preserved' );
					asc_assert_same( 'Line one line two', get_post_meta( $post, 'rank_math_description', true ), 'tags and newlines stripped' );
					$again = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => '%title% - %category% | 100%' ) );
					asc_assert_same( 'no_op', $again['reason'], 'unchanged template value is a no-op' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: og_image change moves the paired attachment-id meta',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, 'rank_math_facebook_image', 'https://example.test/old.jpg' );
		update_post_meta( $post, 'rank_math_facebook_image_id', '55' );
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$dry = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'og_image' => 'https://example.test/new.jpg' ) );
					asc_assert_same( '55', $dry['would_write']['og_image_id']['old'], 'paired id in diff' );
					asc_assert_same( '', $dry['would_write']['og_image_id']['new'], 'non-attachment URL clears id' );
					asc_it_with_permissions(
						array( 'update_seo' => true ),
						function () use ( $post ) {
							return AI_Site_Connector_SEO::update_seo_meta( $post, array( 'og_image' => 'https://example.test/new.jpg' ), false );
						}
					);
					asc_assert_same( 'https://example.test/new.jpg', get_post_meta( $post, 'rank_math_facebook_image', true ), 'url written' );
					asc_assert_same( '', (string) get_post_meta( $post, 'rank_math_facebook_image_id', true ), 'stale id removed' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'seo: a failing key rolls back earlier writes and reports write_failed',
	function () {
		$post = asc_it_post();
		update_post_meta( $post, '_yoast_wpseo_title', 'Original title' );
		update_post_meta( $post, '_yoast_wpseo_metadesc', 'Original desc' );
		$block = static function ( $check, $object_id, $meta_key ) {
			return '_yoast_wpseo_metadesc' === $meta_key ? false : $check;
		};
		try {
			asc_it_as_seo_plugin(
				'yoast',
				function () use ( $post, $block ) {
					$res = asc_it_with_filter(
						'update_post_metadata',
						$block,
						function () use ( $post ) {
							return asc_it_with_permissions(
								array( 'update_seo' => true ),
								function () use ( $post ) {
									return AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => 'New title', 'description' => 'New desc' ), false );
								}
							);
						},
						10,
						3
					);
					asc_assert_same( false, $res['applied'], 'applied' );
					asc_assert_same( 'write_failed', $res['reason'], 'reason' );
					asc_assert_same( array( 'description' ), $res['failed'], 'failed fields' );
					asc_assert_same( array( 'title' ), $res['rolled_back'], 'rolled back' );
					asc_assert_same( 'Original title', get_post_meta( $post, '_yoast_wpseo_title', true ), 'title restored' );
					asc_assert_same( 'Original desc', get_post_meta( $post, '_yoast_wpseo_metadesc', true ), 'desc untouched' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
		}
	}
);
