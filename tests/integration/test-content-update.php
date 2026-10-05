<?php
/**
 * Safe content update, snapshots and rollback (#70).
 *
 * @package AI_Site_Connector_Tests
 */

function asc_it_with_write( $fn, $seo = false ) {
	return asc_it_with_permissions( array( 'write_content' => true, 'update_seo' => (bool) $seo ), $fn );
}

function asc_it_cu( $post_id, $changes, $args = array() ) {
	return AI_Site_Connector_Content_Update::update( $post_id, $changes, $args );
}

asc_it(
	'content-update: dry-run (default) changes nothing and returns a diff',
	function () {
		$cat  = term_exists( 'asc-it-cu-cat', 'category' );
		$cat  = $cat ? $cat : wp_insert_term( 'ASC IT CU cat', 'category', array( 'slug' => 'asc-it-cu-cat' ) );
		$post = asc_it_post( array( 'post_title' => 'Before', 'post_status' => 'draft' ) );
		update_post_meta( $post, '_yoast_wpseo_title', 'Old SEO' );
		try {
			asc_it_with_filter(
				'ai_site_connector_seo_plugin',
				static function () {
					return 'yoast';
				},
				function () use ( $post, $cat ) {
					$before = asc_it_db_fingerprint();
					$res    = asc_it_with_write(
						function () use ( $post, $cat ) {
							return asc_it_cu(
								$post,
								array(
									'title'   => 'After',
									'excerpt' => 'New excerpt',
									'content' => 'New body',
									'slug'    => 'asc-it-cu-after',
									'status'  => 'pending',
									'terms'   => array( 'category' => array( (int) $cat['term_id'] ) ),
									'seo'     => array( 'title' => 'New SEO' ),
								)
							);
						},
						true
					);
					asc_assert( ! is_wp_error( $res ), 'dry-run error: ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
					asc_assert_same( true, $res['dry_run'], 'dry_run default' );
					asc_assert_same( false, $res['applied'], 'applied' );
					asc_assert_same( 'dry_run', $res['reason'], 'reason' );
					asc_assert_same( array( 'before' => 'Before', 'after' => 'After' ), $res['diff']['title'], 'title diff' );
					asc_assert_same( array( 'title' => 'Old SEO' ), $res['diff']['seo']['before'], 'seo diff before' );
					asc_assert( isset( $res['diff']['content']['after']['sha256'] ), 'content summarised' );
					asc_assert_same( $before, asc_it_db_fingerprint(), 'dry-run mutated the DB (content, SEO, terms or snapshots)' );
				}
			);
		} finally {
			wp_delete_post( $post, true );
			wp_delete_term( (int) $cat['term_id'], 'category' );
		}
	}
);

asc_it(
	'content-update: real write blocked by default; read-only mode and SEO gate enforced',
	function () {
		$post = asc_it_post( array( 'post_title' => 'Guarded' ) );
		try {
			$before = asc_it_db_fingerprint();
			$res    = asc_it_cu( $post, array( 'title' => 'Changed' ), array( 'dry_run' => false ) );
			asc_assert( is_wp_error( $res ) && 'rest_forbidden_tool' === $res->get_error_code(), 'write without write_content permission' );
			asc_assert_same( $before, asc_it_db_fingerprint(), 'blocked write mutated' );

			update_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION, 1 );
			try {
				$ro = asc_it_with_write(
					function () use ( $post ) {
						return asc_it_cu( $post, array( 'title' => 'Changed' ), array( 'dry_run' => false ) );
					}
				);
				asc_assert( is_wp_error( $ro ) && 'read_only_mode' === $ro->get_error_data()['reason'], 'read-only mode did not block the write' );
			} finally {
				delete_option( AI_Site_Connector_Permissions::READ_ONLY_OPTION );
			}

			$seo = asc_it_with_filter(
				'ai_site_connector_seo_plugin',
				static function () {
					return 'rankmath';
				},
				function () use ( $post ) {
					return asc_it_with_write(
						function () use ( $post ) {
							return asc_it_cu( $post, array( 'title' => 'Changed', 'seo' => array( 'title' => 'x' ) ), array( 'dry_run' => false ) );
						}
					);
				}
			);
			asc_assert( is_wp_error( $seo ) && 'rest_forbidden_tool' === $seo->get_error_code() && 'update_seo' === $seo->get_error_data()['tool'], 'SEO change without update_seo permission: ' . ( is_wp_error( $seo ) ? wp_json_encode( $seo->get_error_data() ) : 'allowed' ) );
			asc_assert_same( 'Guarded', get_post_field( 'post_title', $post ), 'nothing applied when one gate fails' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'content-update: permitted update applies, verifies and snapshots; rollback restores',
	function () {
		$img  = asc_it_attachment( 'cu-feature.jpg', 'img-bytes' );
		$post = asc_it_post( array( 'post_title' => 'Original', 'post_content' => 'Original body' ) );
		try {
			$res = asc_it_with_write(
				function () use ( $post, $img ) {
					return asc_it_cu( $post, array( 'title' => 'Updated', 'featured_image' => $img ), array( 'dry_run' => false ) );
				}
			);
			asc_assert( ! is_wp_error( $res ), 'update error: ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
			asc_assert_same( true, $res['applied'], 'applied' );
			asc_assert( '' !== $res['snapshot_id'], 'snapshot id' );
			asc_assert_same( 'Updated', get_post_field( 'post_title', $post ), 'title written' );
			asc_assert_same( $img, (int) get_post_thumbnail_id( $post ), 'featured image written' );

			$list = AI_Site_Connector_Content_Update::snapshots( $post );
			asc_assert_same( 'applied', $list['snapshots'][0]['state'], 'snapshot state' );
			asc_assert_same( array( 'title', 'featured_image' ), $list['snapshots'][0]['fields'], 'snapshot fields' );
			asc_assert( false === strpos( wp_json_encode( $list ), 'Original' ), 'snapshot list exposes stored values' );

			// An unrelated later edit to a field the update did not touch.
			wp_update_post( array( 'ID' => $post, 'post_content' => 'Edited later' ) );

			$dry = AI_Site_Connector_Content_Update::rollback( $post, $res['snapshot_id'] );
			asc_assert_same( 'dry_run', $dry['reason'], 'rollback dry-run' );
			asc_assert_same( 'Updated', get_post_field( 'post_title', $post ), 'rollback dry-run mutated' );

			$rb = asc_it_with_write(
				function () use ( $post, $res ) {
					return AI_Site_Connector_Content_Update::rollback( $post, $res['snapshot_id'], false );
				}
			);
			asc_assert_same( true, $rb['applied'], 'rollback applied' );
			asc_assert_same( 'Original', get_post_field( 'post_title', $post ), 'title restored' );
			asc_assert_same( 0, (int) get_post_thumbnail_id( $post ), 'featured image restored' );
			asc_assert_same( 'Edited later', get_post_field( 'post_content', $post ), 'rollback touched an unrelated field' );

			$again = asc_it_with_write(
				function () use ( $post, $res ) {
					return AI_Site_Connector_Content_Update::rollback( $post, $res['snapshot_id'], false );
				}
			);
			asc_assert( is_wp_error( $again ) && 409 === $again->get_error_data()['status'], 'second rollback allowed' );
		} finally {
			wp_delete_post( $post, true );
			asc_it_cleanup_attachments( array( $img ) );
		}
	}
);

asc_it(
	'content-update: rollback refuses when a touched field changed since',
	function () {
		$post = asc_it_post( array( 'post_title' => 'One' ) );
		try {
			$res = asc_it_with_write(
				function () use ( $post ) {
					return asc_it_cu( $post, array( 'title' => 'Two', 'excerpt' => 'Ex' ), array( 'dry_run' => false ) );
				}
			);
			wp_update_post( array( 'ID' => $post, 'post_title' => 'Three (human edit)' ) );
			$rb = asc_it_with_write(
				function () use ( $post, $res ) {
					return AI_Site_Connector_Content_Update::rollback( $post, $res['snapshot_id'], false );
				}
			);
			asc_assert_same( 'conflict', $rb['reason'], 'reason' );
			asc_assert_same( array( 'title' ), $rb['conflicts'], 'conflicts' );
			asc_assert_same( false, $rb['applied'], 'applied' );
			asc_assert_same( 'Three (human edit)', get_post_field( 'post_title', $post ), 'later edit overwritten' );
			asc_assert_same( 'Ex', get_post_field( 'post_excerpt', $post ), 'partial rollback applied' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'content-update: snapshot failure aborts; mid-operation and guard failures restore everything',
	function () {
		$img  = asc_it_attachment( 'cu-feature-2.jpg', 'img-bytes-2' );
		$cat  = term_exists( 'asc-it-cu-cat2', 'category' );
		$cat  = $cat ? $cat : wp_insert_term( 'ASC IT CU cat2', 'category', array( 'slug' => 'asc-it-cu-cat2' ) );
		$post = asc_it_post( array( 'post_title' => 'Stable', 'post_excerpt' => 'Stable excerpt' ) );
		$orig_terms = wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) );
		try {
			$no_index = static function ( $check, $object_id, $meta_key ) {
				return AI_Site_Connector_Content_Update::INDEX_META === $meta_key ? false : $check;
			};
			$res = asc_it_with_filter(
				'add_post_metadata',
				$no_index,
				function () use ( $post ) {
					return asc_it_with_write(
						function () use ( $post ) {
							return asc_it_cu( $post, array( 'title' => 'Never' ), array( 'dry_run' => false ) );
						}
					);
				},
				10,
				3
			);
			asc_assert( is_wp_error( $res ) && 'asc_snapshot_failed' === $res->get_error_code(), 'snapshot failure not reported' );
			asc_assert_same( 'Stable', get_post_field( 'post_title', $post ), 'wrote without a snapshot' );

			// Terms are written, then the featured image fails: terms restored, title never written.
			$no_thumb = static function ( $check, $object_id, $meta_key ) {
				return '_thumbnail_id' === $meta_key ? false : $check;
			};
			$mid = asc_it_with_filter(
				'update_post_metadata',
				$no_thumb,
				function () use ( $post, $img, $cat ) {
					return asc_it_with_write(
						function () use ( $post, $img, $cat ) {
							return asc_it_cu( $post, array( 'title' => 'Half', 'terms' => array( 'category' => array( (int) $cat['term_id'] ) ), 'featured_image' => $img ), array( 'dry_run' => false ) );
						}
					);
				},
				10,
				3
			);
			asc_assert_same( 'write_failed', $mid['reason'], 'reason' );
			asc_assert_same( 'featured_image', $mid['failed_field'], 'failed field' );
			asc_assert_same( array( 'terms', 'featured_image' ), $mid['restored'], 'restored fields (the failed side field is restored in full too)' );
			asc_assert_same( 'Stable', get_post_field( 'post_title', $post ), 'title written after an earlier failure' );
			asc_assert_same( $orig_terms, wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ), 'terms not restored' );
			$snaps = AI_Site_Connector_Content_Update::snapshots( $post );
			asc_assert_same( 'reverted', $snaps['snapshots'][0]['state'], 'snapshot marked reverted' );

			// Another plugin changes a column we did not touch while saving: abort and revert.
			$tamper = static function ( $data ) {
				$data['post_excerpt'] .= ' (tampered)';
				return $data;
			};
			$guard = asc_it_with_filter(
				'wp_insert_post_data',
				$tamper,
				function () use ( $post ) {
					return asc_it_with_write(
						function () use ( $post ) {
							return asc_it_cu( $post, array( 'title' => 'Guarded change' ), array( 'dry_run' => false ) );
						}
					);
				}
			);
			asc_assert_same( 'write_failed', $guard['reason'], 'guard reason' );
			asc_assert_same( 'Stable', get_post_field( 'post_title', $post ), 'title kept after guard trip' );
			asc_assert_same( 'Stable excerpt', get_post_field( 'post_excerpt', $post ), 'untouched column left altered' );
		} finally {
			wp_delete_post( $post, true );
			wp_delete_term( (int) $cat['term_id'], 'category' );
			asc_it_cleanup_attachments( array( $img ) );
		}
	}
);

asc_it(
	'content-update review fixes: rollback re-checks privileges; kses guard; numeric slugs; drafts; strict typing',
	function () {
		$contrib = asc_it_user( 'contributor' );
		$author  = asc_it_user( 'author' );
		$theirs  = asc_it_post( array( 'post_author' => $contrib, 'post_title' => 'Contributor post' ) );
		$tag     = term_exists( '2024', 'post_tag' );
		$tag     = $tag ? $tag : wp_insert_term( 'Year 2024', 'post_tag', array( 'slug' => '2024' ) );
		$iframe  = asc_it_post( array( 'post_author' => $author, 'post_title' => 'Embed post' ) );
		global $wpdb;
		// No quotes: the refusal must come from kses, not from slashing.
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<iframe src=x></iframe>' ), array( 'ID' => $iframe ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $iframe );
		$draft = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'Floating draft' ) );
		try {
			// Editor/admin unpublishes the contributor's post; the contributor
			// must not be able to republish it through rollback.
			$res = asc_it_with_write(
				function () use ( $theirs ) {
					return asc_it_cu( $theirs, array( 'status' => 'draft' ), array( 'dry_run' => false ) );
				}
			);
			$rb  = asc_it_as_user(
				$contrib,
				function () use ( $theirs, $res ) {
					return asc_it_with_write(
						function () use ( $theirs, $res ) {
							return AI_Site_Connector_Content_Update::rollback( $theirs, $res['snapshot_id'], false );
						}
					);
				}
			);
			asc_assert( is_wp_error( $rb ) && 'asc_forbidden_transition' === $rb->get_error_code(), 'contributor republished via rollback' );
			asc_assert_same( 'draft', get_post_field( 'post_status', $theirs ), 'status changed by escalated rollback' );

			// Author without unfiltered_html: changing the title would strip the iframe.
			$k = asc_it_as_user(
				$author,
				function () use ( $iframe ) {
					return asc_it_cu( $iframe, array( 'title' => 'New title' ) );
				}
			);
			asc_assert( is_wp_error( $k ) && 'asc_untouched_field_would_change' === $k->get_error_code(), 'kses would silently alter untouched content' );

			// Numeric strings are slugs, not IDs.
			$n = asc_it_cu( $theirs, array( 'terms' => array( 'post_tag' => array( '2024' ) ) ) );
			asc_assert( ! is_wp_error( $n ), 'numeric slug rejected: ' . ( is_wp_error( $n ) ? $n->get_error_message() : '' ) );
			asc_assert_same( array( (int) $tag['term_id'] ), $n['diff']['terms']['after']['post_tag'], 'numeric slug resolved to the slugged term' );

			// Draft title update keeps the floating date; publish then rollback restores draft state.
			$date_before = get_post_field( 'post_date_gmt', $draft );
			$u = asc_it_with_write(
				function () use ( $draft ) {
					return asc_it_cu( $draft, array( 'title' => 'Renamed draft' ), array( 'dry_run' => false ) );
				}
			);
			asc_assert_same( true, $u['applied'], 'draft title update applied: ' . wp_json_encode( $u ) );
			asc_assert_same( $date_before, get_post_field( 'post_date_gmt', $draft ), 'draft date changed' );
			$p = asc_it_with_write(
				function () use ( $draft ) {
					return asc_it_cu( $draft, array( 'status' => 'publish' ), array( 'dry_run' => false ) );
				}
			);
			asc_assert_same( true, $p['applied'], 'publish applied: ' . wp_json_encode( $p ) );
			asc_assert( '0000-00-00 00:00:00' !== get_post_field( 'post_date_gmt', $draft ), 'publish set a date' );
			$back = asc_it_with_write(
				function () use ( $draft, $p ) {
					return AI_Site_Connector_Content_Update::rollback( $draft, $p['snapshot_id'], false );
				}
			);
			asc_assert_same( true, $back['applied'], 'publish rollback applied: ' . wp_json_encode( $back ) );
			asc_assert_same( 'draft', get_post_field( 'post_status', $draft ), 'status restored' );
			asc_assert_same( $date_before, get_post_field( 'post_date_gmt', $draft ), 'publish date left behind' );

			foreach ( array( array( 'featured_image' => 'abc' ), array( 'featured_image' => array( 42 ) ), array( 'featured_image' => -5 ), array( 'slug' => array( 'x' ) ), array( 'title' => 42 ), array( 'terms' => array( 'post_tag' => 'notalist' ) ) ) as $bad ) {
				$r = asc_it_cu( $theirs, $bad );
				asc_assert( is_wp_error( $r ) && 400 === $r->get_error_data()['status'], 'accepted ' . wp_json_encode( $bad ) );
			}
			$block = wp_insert_post( array( 'post_type' => 'wp_block', 'post_title' => 'Reusable', 'post_status' => 'publish' ) );
			$r     = asc_it_cu( $block, array( 'title' => 'x' ) );
			asc_assert( is_wp_error( $r ) && 'asc_unsupported_post_type' === $r->get_error_code(), 'non-public type accepted' );
			wp_delete_post( $block, true );
		} finally {
			foreach ( array( $theirs, $iframe, $draft ) as $id ) {
				wp_delete_post( $id, true );
			}
			wp_delete_term( (int) $tag['term_id'], 'post_tag' );
			asc_it_delete_user( $contrib );
			asc_it_delete_user( $author );
		}
	}
);

asc_it(
	'content-update: validation — slug conflict, taxonomy, terms, featured image, status, concurrency',
	function () {
		$taken = asc_it_post( array( 'post_name' => 'asc-it-taken-slug' ) );
		$post  = asc_it_post();
		$pdf   = asc_it_attachment( 'cu-doc.pdf', '%PDF', array( 'mime' => 'application/pdf', 'alt' => '' ) );
		try {
			$cases = array(
				array( array( 'slug' => 'asc-it-taken-slug' ), 'asc_slug_conflict' ),
				array( array( 'terms' => array( 'nav_menu' => array( 1 ) ) ), 'asc_invalid_taxonomy' ),
				array( array( 'terms' => array( 'category' => array( 'asc-it-no-such-term' ) ) ), 'asc_invalid_term' ),
				array( array( 'featured_image' => $pdf ), 'asc_invalid_featured_image' ),
				array( array( 'featured_image' => 999999999 ), 'asc_invalid_featured_image' ),
				array( array( 'status' => 'trash' ), 'asc_invalid_status' ),
				array( array( 'status' => 'future' ), 'asc_invalid_status' ),
				array( array( 'bogus' => 'x' ), 'asc_unknown_field' ),
				array( array(), 'asc_no_changes' ),
			);
			foreach ( $cases as $case ) {
				$res = asc_it_cu( $post, $case[0] );
				asc_assert( is_wp_error( $res ) && $case[1] === $res->get_error_code(), 'expected ' . $case[1] . ' for ' . wp_json_encode( $case[0] ) . ', got ' . ( is_wp_error( $res ) ? $res->get_error_code() : 'success' ) );
			}
			asc_assert( false === get_term_by( 'slug', 'asc-it-no-such-term', 'category' ), 'a term was created' );
			$stale = asc_it_cu( $post, array( 'title' => 'x' ), array( 'expected_modified_gmt' => '2001-01-01 00:00:00' ) );
			asc_assert( is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'], 'concurrency check' );

			$contrib = asc_it_user( 'contributor' );
			$own     = asc_it_post( array( 'post_author' => $contrib, 'post_status' => 'draft' ) );
			try {
				$pub = asc_it_as_user( $contrib, function () use ( $own ) {
					return asc_it_cu( $own, array( 'status' => 'publish' ) );
				} );
				asc_assert( is_wp_error( $pub ) && 'asc_forbidden_transition' === $pub->get_error_code(), 'contributor may publish' );
				$other = asc_it_as_user( $contrib, function () use ( $post ) {
					return asc_it_cu( $post, array( 'title' => 'x' ) );
				} );
				asc_assert( is_wp_error( $other ) && 'asc_forbidden_post' === $other->get_error_code(), 'contributor edits others' );
			} finally {
				wp_delete_post( $own, true );
				asc_it_delete_user( $contrib );
			}
		} finally {
			wp_delete_post( $taken, true );
			wp_delete_post( $post, true );
			asc_it_cleanup_attachments( array( $pdf ) );
		}
	}
);

asc_it(
	'content-update: REST and MCP default to dry-run and enforce auth',
	function () {
		$post = asc_it_post( array( 'post_title' => 'Via REST' ) );
		try {
			$res = asc_it_rest( 'POST', '/content/update', array( 'post_id' => $post, 'changes' => array( 'title' => 'Changed' ) ) );
			asc_assert_same( 200, $res->get_status(), 'REST dry-run status' );
			asc_assert_same( 'dry_run', $res->get_data()['reason'], 'REST default dry_run' );
			asc_assert_same( 'Via REST', get_post_field( 'post_title', $post ), 'REST dry-run wrote' );
			$denied = asc_it_rest( 'POST', '/content/update', array( 'post_id' => $post, 'changes' => array( 'title' => 'Changed' ), 'dry_run' => false ) );
			asc_assert_same( 403, $denied->get_status(), 'REST write without permission' );
			$anon = asc_it_as_user( 0, function () use ( $post ) {
				return asc_it_rest( 'POST', '/content/update', array( 'post_id' => $post, 'changes' => array( 'title' => 'x' ) ) );
			} );
			asc_assert_same( 401, $anon->get_status(), 'anonymous' );

			$mcp = asc_it_mcp_call( 'wp_update_content', array( 'post_id' => $post, 'changes' => array( 'title' => 'By MCP' ) ) );
			asc_assert_same( false, $mcp['is_error'], 'MCP isError' );
			asc_assert_same( 'dry_run', $mcp['data']['reason'], 'MCP default dry_run' );
			$snaps = asc_it_rest( 'GET', '/content/snapshots/' . $post );
			asc_assert_same( 200, $snaps->get_status(), 'snapshots route' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'content-update review 2: slashing, author write, status-side conflict, future, orphans, prune, size cap, interrupted recovery',
	function () {
		$author = asc_it_user( 'author' );
		$quoted = asc_it_post( array( 'post_author' => $author, 'post_title' => 'Plain', 'post_content' => '<p class="x">ok</p>' ) );
		$draft  = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'Draft for side conflict' ) );
		$future = asc_it_post( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ) );
		$gone   = asc_it_post();
		try {
			// 2.1/2.2: a user without unfiltered_html writes a quote; content with quotes is untouched.
			$w = asc_it_as_user(
				$author,
				function () use ( $quoted ) {
					return asc_it_with_write(
						function () use ( $quoted ) {
							return asc_it_cu( $quoted, array( 'title' => "Don't \\ stop" ), array( 'dry_run' => false ) );
						}
					);
				}
			);
			asc_assert( ! is_wp_error( $w ) && true === $w['applied'], 'author write failed: ' . wp_json_encode( is_wp_error( $w ) ? $w->get_error_code() : $w ) );
			asc_assert_same( "Don't \\ stop", get_post_field( 'post_title', $quoted ), 'stored title differs from request' );
			asc_assert_same( "Don't \\ stop", $w['diff']['title']['after'], 'diff shows a different value than stored' );
			asc_assert_same( '<p class="x">ok</p>', get_post_field( 'post_content', $quoted ), 'untouched content altered' );

			// 2.3: publish, then a human edits the generated slug; rollback must refuse.
			$p = asc_it_with_write(
				function () use ( $draft ) {
					return asc_it_cu( $draft, array( 'status' => 'publish' ), array( 'dry_run' => false ) );
				}
			);
			wp_update_post( array( 'ID' => $draft, 'post_name' => 'human-chosen-slug' ) );
			$rb = asc_it_with_write(
				function () use ( $draft, $p ) {
					return AI_Site_Connector_Content_Update::rollback( $draft, $p['snapshot_id'], false );
				}
			);
			asc_assert_same( 'conflict', $rb['reason'], 'side-column edit not detected' );
			asc_assert_same( 'human-chosen-slug', get_post_field( 'post_name', $draft ), 'human slug overwritten' );

			// 2.5: scheduled posts cannot change status (rollback could not restore it).
			$f = asc_it_cu( $future, array( 'status' => 'draft' ) );
			asc_assert( is_wp_error( $f ) && 'asc_unsupported_transition' === $f->get_error_code(), 'future status change accepted' );

			// 1.6: size cap.
			$big = asc_it_cu( $gone, array( 'content' => str_repeat( 'a', AI_Site_Connector_Content_Update::MAX_CONTENT + 1 ) ) );
			asc_assert( is_wp_error( $big ) && 'asc_too_large' === $big->get_error_code(), 'oversized content accepted' );

			// 1.4: interrupted update (writes happened, snapshot never finalized) is recoverable.
			$u = asc_it_with_write(
				function () use ( $gone ) {
					return asc_it_cu( $gone, array( 'title' => 'Interrupted' ), array( 'dry_run' => false ) );
				}
			);
			$key  = AI_Site_Connector_Content_Update::OPTION_PREFIX . $u['snapshot_id'];
			$snap = get_option( $key );
			$snap['state']       = 'pending';
			$snap['created_gmt'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // Past the in-progress grace period.
			foreach ( $snap['fields'] as &$row ) {
				$row['after_raw'] = null;
			}
			unset( $row );
			update_option( $key, $snap, false );
			$rec = asc_it_with_write(
				function () use ( $gone, $u ) {
					return AI_Site_Connector_Content_Update::rollback( $gone, $u['snapshot_id'], false );
				}
			);
			asc_assert_same( true, $rec['applied'], 'interrupted update not recoverable: ' . wp_json_encode( $rec ) );
			asc_assert_same( 'ASC IT fixture', get_post_field( 'post_title', $gone ), 'interrupted title not restored' );

			// 2.8: prune keeps recovery snapshots.
			$keep = asc_it_with_write(
				function () use ( $gone ) {
					return asc_it_cu( $gone, array( 'title' => 'Keep me' ), array( 'dry_run' => false ) );
				}
			);
			$k2   = AI_Site_Connector_Content_Update::OPTION_PREFIX . $keep['snapshot_id'];
			$s2   = get_option( $k2 );
			$s2['state'] = 'revert_incomplete';
			update_option( $k2, $s2, false );
			for ( $i = 0; $i < AI_Site_Connector_Content_Update::MAX_SNAPSHOTS + 2; $i++ ) {
				asc_it_with_write(
					function () use ( $gone, $i ) {
						return asc_it_cu( $gone, array( 'title' => "Churn {$i}" ), array( 'dry_run' => false ) );
					}
				);
			}
			asc_assert( false !== get_option( $k2 ), 'prune deleted a recovery snapshot' );

			// 2.6: permanent deletion removes snapshot options.
			wp_delete_post( $gone, true );
			asc_assert_same( false, get_option( $k2 ), 'snapshot option orphaned after post deletion' );
		} finally {
			foreach ( array( $quoted, $draft, $future, $gone ) as $id ) {
				wp_delete_post( $id, true );
			}
			asc_it_delete_user( $author );
		}
	}
);

asc_it(
	'content-update review 2: rollback refused when save filters would alter content; guard never clobbers a concurrent save',
	function () {
		global $wpdb;
		$author = asc_it_user( 'author' );
		$post   = asc_it_post( array( 'post_author' => $author, 'post_title' => 'Embed owner' ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<iframe src=x></iframe>' ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( $post );
		$other = asc_it_post( array( 'post_title' => 'Concurrent', 'post_excerpt' => 'Base' ) );
		try {
			// 2.4: admin (unfiltered_html) unpublishes; the author's rollback would strip the iframe.
			$u  = asc_it_with_write(
				function () use ( $post ) {
					return asc_it_cu( $post, array( 'status' => 'draft' ), array( 'dry_run' => false ) );
				}
			);
			$rb = asc_it_as_user(
				$author,
				function () use ( $post, $u ) {
					return asc_it_with_write(
						function () use ( $post, $u ) {
							return AI_Site_Connector_Content_Update::rollback( $post, $u['snapshot_id'], false );
						}
					);
				}
			);
			asc_assert( is_wp_error( $rb ) && 'asc_untouched_field_would_change' === $rb->get_error_code(), 'rollback allowed although kses would strip content' );
			asc_assert_same( '<iframe src=x></iframe>', get_post_field( 'post_content', $post ), 'content altered' );
			asc_assert_same( 'draft', get_post_field( 'post_status', $post ), 'status changed by refused rollback' );

			// 2.7: guard trips (tampered excerpt) while another writer saves the row in between.
			$tamper     = static function ( $data ) {
				$data['post_excerpt'] .= ' (tampered)';
				return $data;
			};
			$concurrent = static function ( $id ) use ( $other ) {
				global $wpdb;
				if ( (int) $id === (int) $other ) {
					// Same second, same post_modified_gmt: only the title differs.
					$wpdb->update( $wpdb->posts, array( 'post_title' => 'Saved by someone else' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
				}
			};
			add_action( 'wp_insert_post', $concurrent );
			try {
				$g = asc_it_with_filter(
					'wp_insert_post_data',
					$tamper,
					function () use ( $other ) {
						return asc_it_with_write(
							function () use ( $other ) {
								return asc_it_cu( $other, array( 'title' => 'Mine' ), array( 'dry_run' => false ) );
							}
						);
					}
				);
			} finally {
				remove_action( 'wp_insert_post', $concurrent );
			}
			clean_post_cache( $other );
			asc_assert_same( 'write_failed', $g['reason'], 'guard reason' );
			asc_assert( in_array( 'post_columns', $g['restore_failed'], true ), 'restore failure not reported honestly: ' . wp_json_encode( $g ) );
			asc_assert_same( 'Saved by someone else', get_post_field( 'post_title', $other ), 'concurrent save overwritten by guard restore' );
		} finally {
			wp_delete_post( $post, true );
			wp_delete_post( $other, true );
			asc_it_delete_user( $author );
		}
	}
);

asc_it(
	'content-update review 3: partial side fields restored; interrupted side columns; slug reuse; in-progress pending; honest rollback failure',
	function () {
		global $wpdb;
		$cat  = term_exists( 'asc-it-r3-cat', 'category' );
		$cat  = $cat ? $cat : wp_insert_term( 'ASC IT R3 cat', 'category', array( 'slug' => 'asc-it-r3-cat' ) );
		$tag  = term_exists( 'asc-it-r3-tag', 'post_tag' );
		$tag  = $tag ? $tag : wp_insert_term( 'ASC IT R3 tag', 'post_tag', array( 'slug' => 'asc-it-r3-tag' ) );
		$post = asc_it_post( array( 'post_title' => 'R3', 'post_name' => 'asc-it-r3-old' ) );
		$taker = 0;
		$orig_cats = wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) );
		try {
			// 3.1: category is written, post_tag then fails -> category must be restored.
			$extra = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $post ) {
				if ( (int) $object_id === (int) $post && 'post_tag' === $taxonomy ) {
					remove_all_actions( 'set_object_terms' );
					wp_set_object_terms( $object_id, array( 'asc-it-r3-intruder' ), 'post_tag', true );
				}
			};
			add_action( 'set_object_terms', $extra, 10, 4 );
			try {
				$r = asc_it_with_write(
					function () use ( $post, $cat, $tag ) {
						return asc_it_cu( $post, array( 'terms' => array( 'category' => array( (int) $cat['term_id'] ), 'post_tag' => array( (int) $tag['term_id'] ) ) ), array( 'dry_run' => false ) );
					}
				);
			} finally {
				remove_action( 'set_object_terms', $extra, 10 );
			}
			asc_assert_same( 'write_failed', $r['reason'], 'multi-taxonomy failure reason' );
			asc_assert_same( $orig_cats, wp_get_object_terms( $post, 'category', array( 'fields' => 'ids' ) ), 'partially written taxonomy left behind' );
			$intruder = get_term_by( 'slug', 'asc-it-r3-intruder', 'post_tag' );
			if ( $intruder ) {
				wp_delete_term( $intruder->term_id, 'post_tag' );
			}

			// 3.5: slug changed, another post takes the old slug -> rollback refuses.
			$u = asc_it_with_write(
				function () use ( $post ) {
					return asc_it_cu( $post, array( 'slug' => 'asc-it-r3-new' ), array( 'dry_run' => false ) );
				}
			);
			$taker = asc_it_post( array( 'post_name' => 'asc-it-r3-old' ) );
			$rb    = AI_Site_Connector_Content_Update::rollback( $post, $u['snapshot_id'] );
			asc_assert( is_wp_error( $rb ) && 'asc_slug_conflict' === $rb->get_error_code(), 'rollback would duplicate a slug' );

			// 3.6: a pending snapshot younger than the grace period is not "interrupted".
			$v    = asc_it_with_write(
				function () use ( $post ) {
					return asc_it_cu( $post, array( 'title' => 'Pending check' ), array( 'dry_run' => false ) );
				}
			);
			$key  = AI_Site_Connector_Content_Update::OPTION_PREFIX . $v['snapshot_id'];
			$snap = get_option( $key );
			$snap['state'] = 'pending';
			update_option( $key, $snap, false );
			$pend = AI_Site_Connector_Content_Update::rollback( $post, $v['snapshot_id'] );
			asc_assert( is_wp_error( $pend ) && 'asc_snapshot_in_progress' === $pend->get_error_code(), 'fresh pending snapshot treated as interrupted' );
		} finally {
			foreach ( array( $post, $taker ) as $id ) {
				if ( $id ) {
					wp_delete_post( $id, true );
				}
			}
			wp_delete_term( (int) $cat['term_id'], 'category' );
			wp_delete_term( (int) $tag['term_id'], 'post_tag' );
		}
	}
);

asc_it(
	'content-update review 3: interrupted publish with an edited slug is a conflict; dirty rollback reported honestly',
	function () {
		global $wpdb;
		$draft = asc_it_post( array( 'post_status' => 'draft', 'post_title' => 'R3 draft' ) );
		$other = asc_it_post( array( 'post_title' => 'R3 other', 'post_excerpt' => 'Base' ) );
		try {
			// 3.2: publish, simulate an interruption (after_raw unknown), then a human edits the slug.
			$p    = asc_it_with_write(
				function () use ( $draft ) {
					return asc_it_cu( $draft, array( 'status' => 'publish' ), array( 'dry_run' => false ) );
				}
			);
			$key  = AI_Site_Connector_Content_Update::OPTION_PREFIX . $p['snapshot_id'];
			$snap = get_option( $key );
			$snap['state']       = 'pending';
			$snap['created_gmt'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
			foreach ( $snap['fields'] as &$row ) {
				$row['after_raw'] = null;
			}
			unset( $row );
			update_option( $key, $snap, false );
			wp_update_post( array( 'ID' => $draft, 'post_name' => 'r3-human-slug' ) );
			$rec = asc_it_with_write(
				function () use ( $draft, $p ) {
					return AI_Site_Connector_Content_Update::rollback( $draft, $p['snapshot_id'], false );
				}
			);
			asc_assert_same( 'conflict', $rec['reason'], 'interrupted publish recovery overwrote side columns' );
			asc_assert_same( 'r3-human-slug', get_post_field( 'post_name', $draft ), 'human slug lost' );

			// 3.4: rollback whose column step trips the guard while another writer saves.
			$u      = asc_it_with_write(
				function () use ( $other ) {
					return asc_it_cu( $other, array( 'title' => 'R3 mine' ), array( 'dry_run' => false ) );
				}
			);
			$tamper = static function ( $data ) {
				$data['post_excerpt'] .= ' (tampered)';
				return $data;
			};
			$conc   = static function ( $id ) use ( $other ) {
				global $wpdb;
				if ( (int) $id === (int) $other ) {
					$wpdb->update( $wpdb->posts, array( 'post_title' => 'R3 someone else' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
				}
			};
			add_action( 'wp_insert_post', $conc );
			try {
				$rb = asc_it_with_filter(
					'wp_insert_post_data',
					$tamper,
					function () use ( $other, $u ) {
						return asc_it_with_write(
							function () use ( $other, $u ) {
								return AI_Site_Connector_Content_Update::rollback( $other, $u['snapshot_id'], false );
							}
						);
					}
				);
			} finally {
				remove_action( 'wp_insert_post', $conc );
			}
			asc_assert_same( 'rollback_failed', $rb['reason'], 'reason' );
			asc_assert_same( array( 'post_columns' ), $rb['restore_failed'], 'dirty columns not reported' );
			asc_assert_same( false, $rb['reapplied'], 'reapplied claimed despite dirty columns' );
			$snap2 = get_option( AI_Site_Connector_Content_Update::OPTION_PREFIX . $u['snapshot_id'] );
			asc_assert_same( 'revert_incomplete', $snap2['state'], 'snapshot not left recoverable' );
			clean_post_cache( $other );
			asc_assert_same( 'R3 someone else', get_post_field( 'post_title', $other ), 'concurrent save overwritten' );
		} finally {
			wp_delete_post( $draft, true );
			wp_delete_post( $other, true );
		}
	}
);

asc_it(
	'content-update review 4: failing restore of a side field is reported and left recoverable',
	function () {
		$cat  = term_exists( 'asc-it-r4-cat', 'category' );
		$cat  = $cat ? $cat : wp_insert_term( 'ASC IT R4 cat', 'category', array( 'slug' => 'asc-it-r4-cat' ) );
		$tag  = term_exists( 'asc-it-r4-tag', 'post_tag' );
		$tag  = $tag ? $tag : wp_insert_term( 'ASC IT R4 tag', 'post_tag', array( 'slug' => 'asc-it-r4-tag' ) );
		$post = asc_it_post( array( 'post_title' => 'R4' ) );
		$calls = 0;
		// Every set after the first (post_tag forward, then the category restore) gets an intruder term.
		$sabotage = static function ( $object_id, $terms, $tt_ids, $taxonomy ) use ( $post, &$calls, &$sabotage ) {
			if ( (int) $object_id !== (int) $post ) {
				return;
			}
			if ( ++$calls >= 2 ) {
				remove_action( 'set_object_terms', $sabotage, 10 );
				wp_set_object_terms( $object_id, array( 'asc-it-r4-intruder' ), $taxonomy, true );
				add_action( 'set_object_terms', $sabotage, 10, 4 );
			}
		};
		add_action( 'set_object_terms', $sabotage, 10, 4 );
		try {
			$r = asc_it_with_write(
				function () use ( $post, $cat, $tag ) {
					return asc_it_cu( $post, array( 'terms' => array( 'category' => array( (int) $cat['term_id'] ), 'post_tag' => array( (int) $tag['term_id'] ) ) ), array( 'dry_run' => false ) );
				}
			);
		} finally {
			remove_action( 'set_object_terms', $sabotage, 10 );
		}
		try {
			asc_assert_same( 'write_failed', $r['reason'], 'reason' );
			asc_assert( in_array( 'terms', $r['restore_failed'], true ), 'failed restore reported as success: ' . wp_json_encode( $r ) );
			asc_assert( ! in_array( 'terms', $r['restored'], true ), 'terms listed as restored' );
			$snap = get_option( AI_Site_Connector_Content_Update::OPTION_PREFIX . $r['snapshot_id'] );
			asc_assert_same( 'revert_incomplete', $snap['state'], 'snapshot not left recoverable' );
		} finally {
			wp_delete_post( $post, true );
			foreach ( array( array( $cat, 'category' ), array( $tag, 'post_tag' ) ) as $t ) {
				wp_delete_term( (int) $t[0]['term_id'], $t[1] );
			}
			foreach ( array( 'category', 'post_tag' ) as $tax ) {
				$i = get_term_by( 'slug', 'asc-it-r4-intruder', $tax );
				if ( $i ) {
					wp_delete_term( $i->term_id, $tax );
				}
			}
		}
	}
);
