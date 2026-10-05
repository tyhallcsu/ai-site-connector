<?php
/**
 * Safe content update with dry-run, snapshot and rollback (#70).
 *
 * Writable fields: title, excerpt, content, slug, status, featured_image,
 * terms (per taxonomy, replace), seo (via AI_Site_Connector_SEO).
 *
 * Safety model:
 *  - Dry-run by default: validates everything and returns the before/after
 *    diff (with the values WordPress would actually store) without writing.
 *  - Real writes need the `write_content` tool permission (default OFF);
 *    SEO fields also need `update_seo`. Both honour read-only mode and the
 *    site-wide disable switch.
 *  - Targets: public, REST-enabled post types in a core status. Per-object
 *    checks: edit_post; publish capability for publish/private; assign_terms;
 *    readable image attachment for the featured image. Rollback re-runs the
 *    same checks on the values it would restore.
 *  - Saving must not alter any column the caller did not change (e.g. kses
 *    stripping markup for users without unfiltered_html); such updates are
 *    refused at planning time.
 *  - A snapshot is stored before any write (one non-autoloaded option per
 *    snapshot + a per-post index row). Meta, terms and thumbnail are written
 *    first; all post columns are written last in a single wp_update_post()
 *    call (one revision, status side effects last). Every step is verified;
 *    on failure everything already written is restored.
 *  - Rollback restores only the fields the update touched, and only while
 *    each still holds the value the update wrote; otherwise it refuses and
 *    lists the conflicts. Interrupted updates can be recovered field by field.
 *
 * Never trashes or deletes. Status 'trash' and 'future' are not accepted.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Content_Update {

	const INDEX_META      = '_ai_site_connector_snapshot';
	const OPTION_PREFIX   = 'ai_site_connector_snapshot_';
	const MAX_SNAPSHOTS   = 10;
	const PENDING_GRACE   = 300; // Seconds before a pending snapshot counts as interrupted.
	const MAX_CONTENT     = 1048576; // 1 MB per text field.
	const FIELDS          = array( 'title', 'excerpt', 'content', 'slug', 'status', 'featured_image', 'terms', 'seo' );
	const ALLOWED_STATUS  = array( 'draft', 'pending', 'publish', 'private' );
	const CORE_STATUSES   = array( 'draft', 'pending', 'publish', 'private', 'future' );
	const POST_COLUMNS    = array(
		'title'   => 'post_title',
		'excerpt' => 'post_excerpt',
		'content' => 'post_content',
		'slug'    => 'post_name',
		'status'  => 'post_status',
	);
	/** Columns WordPress may set itself when the status changes (snapshotted as implicit). */
	const STATUS_SIDE_COLUMNS = array( 'post_name', 'post_date', 'post_date_gmt' );
	/** Columns compared to detect unintended changes. */
	const GUARDED_COLUMNS = array( 'post_title', 'post_excerpt', 'post_content', 'post_name', 'post_status', 'post_date', 'post_date_gmt', 'post_parent', 'menu_order', 'post_password', 'comment_status', 'ping_status', 'post_author', 'post_type', 'post_mime_type' );

	/**
	 * Post types this tool may write: public, REST-enabled, and in the
	 * content-inventory list.
	 *
	 * @return string[]
	 */
	public static function writable_post_types() {
		$out = array();
		foreach ( AI_Site_Connector_Content_Inventory::allowed_post_types() as $type ) {
			$obj = get_post_type_object( $type );
			if ( $obj && $obj->public && ! empty( $obj->show_in_rest ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * Validate and (optionally) apply a content update.
	 *
	 * @param int   $post_id Target post.
	 * @param array $changes Field => new value. terms: { taxonomy: [int id | string slug] }. seo: { field: value }.
	 * @param array $args { dry_run (default true), expected_modified_gmt }.
	 * @return array|WP_Error
	 */
	public static function update( $post_id, array $changes, $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'dry_run'               => true,
				'expected_modified_gmt' => '',
			)
		);
		$dry_run = rest_sanitize_boolean( $args['dry_run'] );
		$post    = self::target( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$unknown = array_diff( array_keys( $changes ), self::FIELDS );
		if ( ! empty( $unknown ) ) {
			return self::error( 'asc_unknown_field', sprintf( 'Unknown field(s): %s.', implode( ',', $unknown ) ), 400 );
		}
		if ( empty( $changes ) ) {
			return self::error( 'asc_no_changes', 'No changes given.', 400 );
		}
		if ( '' !== (string) $args['expected_modified_gmt'] && (string) $args['expected_modified_gmt'] !== (string) $post->post_modified_gmt ) {
			return self::error( 'asc_conflict', 'The post was modified since expected_modified_gmt.', 409, array( 'current_modified_gmt' => $post->post_modified_gmt ) );
		}

		$plan = self::plan( $post, $changes );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$response = array(
			'applied'     => false,
			'dry_run'     => $dry_run,
			'post_id'     => (int) $post->ID,
			'reason'      => '',
			'diff'        => self::public_diff( $plan ),
			'snapshot_id' => '',
		);
		if ( empty( $plan ) ) {
			$response['reason'] = 'no_op';
			return $response;
		}
		if ( $dry_run ) {
			$response['reason'] = 'dry_run';
			return $response;
		}

		$gate = self::write_gate( $post->ID, isset( $plan['seo'] ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Implicit columns WordPress may change along with the status.
		if ( isset( $plan['status'] ) ) {
			$plan['_status_side'] = array(
				'before_raw' => self::read_columns( $post->ID, self::STATUS_SIDE_COLUMNS ),
				'after_raw'  => null,
			);
		}

		$snapshot_id = self::store_snapshot( $post->ID, $plan );
		if ( is_wp_error( $snapshot_id ) ) {
			return $snapshot_id;
		}

		$result = self::apply_plan( $post->ID, $plan, 'after_raw' );
		if ( true !== $result ) {
			$restore = self::restore_written( $post->ID, $plan, $result['written'] );
			if ( ! empty( $result['columns_dirty'] ) ) {
				$restore[] = 'post_columns';
			}
			self::set_snapshot_state( $snapshot_id, empty( $restore ) ? 'reverted' : 'revert_incomplete' );
			self::audit( 'content_update_failed', $post->ID, sprintf( 'Content update failed at %s; %s.', $result['failed'], empty( $restore ) ? 'all written fields restored' : 'restore incomplete for ' . implode( ',', $restore ) ) );
			$response['reason']         = 'write_failed';
			$response['failed_field']   = $result['failed'];
			$response['restored']       = array_values( array_diff( $result['written'], $restore ) );
			$response['restore_failed'] = $restore;
			$response['snapshot_id']    = $snapshot_id;
			return $response;
		}

		// Record what was actually stored, so rollback detects later edits.
		foreach ( $plan as $field => &$row ) {
			$row['after_raw'] = '_status_side' === $field
				? self::read_columns( $post->ID, self::STATUS_SIDE_COLUMNS )
				: self::read_field( $post->ID, $field, $row['before_raw'] );
		}
		unset( $row );
		if ( ! self::finalize_snapshot( $snapshot_id, $plan ) ) {
			// Without a usable snapshot the update must not stand.
			$restore = self::restore_written( $post->ID, $plan, array_keys( $plan ) );
			self::set_snapshot_state( $snapshot_id, empty( $restore ) ? 'reverted' : 'revert_incomplete' );
			$response['reason']         = 'write_failed';
			$response['failed_field']   = 'snapshot';
			$response['restore_failed'] = $restore;
			$response['snapshot_id']    = $snapshot_id;
			return $response;
		}

		clean_post_cache( $post->ID );
		unset( $plan['_status_side'] );
		$response['applied']     = true;
		$response['reason']      = 'ok';
		$response['snapshot_id'] = $snapshot_id;
		$response['diff']        = self::public_diff( $plan );
		self::audit( 'content_updated', $post->ID, sprintf( 'Content updated (%s); snapshot %s.', implode( ',', array_keys( $plan ) ), $snapshot_id ) );
		return $response;
	}

	/**
	 * Roll a post back to a snapshot. Dry-run by default.
	 *
	 * Applied snapshots: every touched field must still hold the value the
	 * update wrote, else reason=conflict. Interrupted snapshots (pending,
	 * revert_incomplete): fields still at the written value are restored,
	 * fields already at the original value are skipped, anything else is a
	 * conflict.
	 *
	 * @return array|WP_Error
	 */
	public static function rollback( $post_id, $snapshot_id, $dry_run = true ) {
		$post = self::target( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$snap = self::load_snapshot( (string) $snapshot_id );
		if ( ! $snap || (int) $snap['post_id'] !== (int) $post->ID ) {
			return self::error( 'asc_snapshot_not_found', 'Snapshot not found for this post.', 404 );
		}
		if ( ! in_array( $snap['state'], array( 'applied', 'pending', 'revert_incomplete' ), true ) ) {
			return self::error( 'asc_snapshot_not_rollbackable', sprintf( 'Snapshot is %s and cannot be rolled back.', $snap['state'] ), 409 );
		}
		$interrupted = 'applied' !== $snap['state'];
		if ( 'pending' === $snap['state'] && time() - strtotime( $snap['created_gmt'] . ' UTC' ) < self::PENDING_GRACE ) {
			// It may still be running; recovering now would race it.
			return self::error( 'asc_snapshot_in_progress', 'This update may still be in progress; retry recovery in a few minutes.', 409 );
		}

		$restore   = array();
		$conflicts = array();
		foreach ( $snap['fields'] as $field => $row ) {
			if ( '_status_side' === $field ) {
				continue;
			}
			$current = self::read_field( $post->ID, $field, $row['before_raw'] );
			if ( null !== $row['after_raw'] && self::canon( $current ) === self::canon( $row['after_raw'] ) ) {
				$restore[ $field ] = $row;
			} elseif ( $interrupted && self::canon( $current ) === self::canon( $row['before_raw'] ) ) {
				continue; // Never written, or already restored.
			} elseif ( $interrupted && null === $row['after_raw'] && isset( $row['planned_raw'] ) && self::canon( $current ) === self::canon( $row['planned_raw'] ) ) {
				$restore[ $field ] = $row;
			} else {
				$conflicts[] = $field;
			}
		}
		if ( isset( $restore['status'] ) && isset( $snap['fields']['_status_side'] ) ) {
			$side = $snap['fields']['_status_side'];
			// The slug/date WordPress set on publish may have been edited since;
			// restoring them would overwrite that edit.
			$now_side = self::canon( self::read_columns( $post->ID, self::STATUS_SIDE_COLUMNS ) );
			// Interrupted updates never recorded the slug/date WordPress set,
			// so they cannot tell a later edit apart: refuse unless unchanged.
			$side_conflict = is_array( $side['after_raw'] )
				? $now_side !== self::canon( $side['after_raw'] )
				: $now_side !== self::canon( $side['before_raw'] );
			if ( $side_conflict ) {
				unset( $restore['status'] );
				$conflicts[] = 'status';
			} else {
				$restore['_status_side'] = $side;
			}
		}

		$result = array(
			'applied'     => false,
			'dry_run'     => (bool) $dry_run,
			'post_id'     => (int) $post->ID,
			'snapshot_id' => (string) $snapshot_id,
			'fields'      => array_values( array_diff( array_keys( $restore ), array( '_status_side' ) ) ),
			'conflicts'   => $conflicts,
			'reason'      => '',
		);
		if ( ! empty( $conflicts ) ) {
			$result['reason'] = 'conflict';
			return $result;
		}
		if ( empty( $restore ) ) {
			$result['reason'] = 'no_op';
			return $result;
		}

		// The restored values must pass the same checks as an update would.
		$check = self::validate_restore( $post, $restore );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( $dry_run ) {
			$result['reason'] = 'dry_run';
			return $result;
		}
		$gate = self::write_gate( $post->ID, isset( $restore['seo'] ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$applied = self::apply_plan( $post->ID, $restore, 'before_raw' );
		if ( true !== $applied ) {
			// Put back the update for fields already rolled back.
			$reapply = array();
			foreach ( $applied['written'] as $field ) {
				$reapply[ $field ] = $restore[ $field ];
			}
			$again = true === self::apply_plan( $post->ID, $reapply, 'after_raw' );
			if ( ! empty( $applied['columns_dirty'] ) ) {
				$again                    = false;
				$result['restore_failed'] = array( 'post_columns' );
			}
			if ( ! $again ) {
				// Mixed state: keep the snapshot recoverable field by field.
				self::set_snapshot_state( (string) $snapshot_id, 'revert_incomplete' );
			}
			$result['reason']       = 'rollback_failed';
			$result['failed_field'] = $applied['failed'];
			$result['reapplied']    = $again;
			self::audit( 'content_rollback_failed', $post->ID, sprintf( 'Rollback of %s failed at %s.', $snapshot_id, $applied['failed'] ) );
			return $result;
		}
		self::set_snapshot_state( (string) $snapshot_id, 'rolled_back' );
		clean_post_cache( $post->ID );
		$result['applied'] = true;
		$result['reason']  = 'ok';
		self::audit( 'content_rolled_back', $post->ID, sprintf( 'Rolled back snapshot %s (%s).', $snapshot_id, implode( ',', $result['fields'] ) ) );
		return $result;
	}

	/**
	 * Snapshot summaries for a post (newest first), without stored values.
	 *
	 * @return array|WP_Error
	 */
	public static function snapshots( $post_id ) {
		$post = self::target( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$out = array();
		foreach ( array_reverse( self::snapshot_ids( $post->ID ) ) as $id ) {
			$snap = self::load_snapshot( $id );
			if ( ! $snap ) {
				continue;
			}
			$out[] = array(
				'snapshot_id' => $id,
				'created_gmt' => $snap['created_gmt'],
				'user_id'     => (int) $snap['user_id'],
				'state'       => $snap['state'],
				'fields'      => array_values( array_diff( array_keys( $snap['fields'] ), array( '_status_side' ) ) ),
			);
		}
		return array(
			'post_id'   => (int) $post->ID,
			'snapshots' => $out,
		);
	}

	// --- validation -----------------------------------------------------------

	/**
	 * The value WordPress will store for a column when this user saves it.
	 * Save filters (kses) expect slashed input and return slashed output,
	 * exactly as wp_insert_post() runs them.
	 */
	private static function stored_value( $col, $value, $post_id ) {
		return (string) wp_unslash( sanitize_post_field( $col, wp_slash( (string) $value ), $post_id, 'db' ) );
	}

	/**
	 * Column of title/excerpt/content that saving would alter although the
	 * caller does not change it, or '' when none would.
	 *
	 * @param WP_Post  $post    Post.
	 * @param string[] $changed Columns being written.
	 */
	private static function untouched_change( WP_Post $post, array $changed ) {
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $col ) {
			if ( in_array( $col, $changed, true ) ) {
				continue;
			}
			$current = (string) $post->$col;
			if ( self::stored_value( $col, $current, $post->ID ) !== $current ) {
				return $col;
			}
		}
		return '';
	}

	/**
	 * @return WP_Post|WP_Error
	 */
	private static function target( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return self::error( 'asc_post_not_found', 'Post not found.', 404 );
		}
		if ( ! in_array( $post->post_type, self::writable_post_types(), true ) ) {
			return self::error( 'asc_unsupported_post_type', sprintf( 'Post type %s cannot be changed with this tool.', $post->post_type ), 400 );
		}
		if ( ! in_array( $post->post_status, self::CORE_STATUSES, true ) ) {
			return self::error( 'asc_unsupported_status', sprintf( 'Posts in status %s cannot be changed with this tool.', $post->post_status ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::error( 'asc_forbidden_post', 'You cannot edit this post.', 403 );
		}
		return $post;
	}

	private static function write_gate( $post_id, $with_seo ) {
		$gate = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_WRITE_CONTENT, array( 'post_id' => $post_id ) );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( $with_seo ) {
			$gate = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_UPDATE_SEO, array( 'post_id' => $post_id ) );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}
		return true;
	}

	/**
	 * Build field => { before_raw, after_raw } for every field that changes.
	 *
	 * @return array|WP_Error
	 */
	private static function plan( WP_Post $post, array $changes ) {
		$plan    = array();
		$columns = array();

		foreach ( array( 'title', 'excerpt', 'content' ) as $field ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			if ( ! is_string( $changes[ $field ] ) ) {
				return self::error( 'asc_invalid_value', sprintf( '%s must be a string.', $field ), 400 );
			}
			if ( strlen( $changes[ $field ] ) > self::MAX_CONTENT ) {
				return self::error( 'asc_too_large', sprintf( '%s exceeds %d bytes.', $field, self::MAX_CONTENT ), 400 );
			}
			$col = self::POST_COLUMNS[ $field ];
			// What WordPress will actually store (kses etc. for this user).
			$stored = self::stored_value( $col, $changes[ $field ], $post->ID );
			self::add( $plan, $field, (string) $post->$col, $stored );
			$columns[ $col ] = $stored;
		}

		if ( array_key_exists( 'slug', $changes ) ) {
			if ( ! is_string( $changes['slug'] ) ) {
				return self::error( 'asc_invalid_value', 'slug must be a string.', 400 );
			}
			$requested = sanitize_title( $changes['slug'] );
			if ( '' === $requested ) {
				return self::error( 'asc_invalid_slug', 'slug is empty after sanitisation.', 400 );
			}
			// Checked as if published: WordPress skips uniqueness for drafts
			// and would silently append -2 at publish time.
			$unique = wp_unique_post_slug( $requested, $post->ID, 'publish', $post->post_type, $post->post_parent );
			if ( $unique !== $requested ) {
				return self::error( 'asc_slug_conflict', sprintf( 'slug "%s" is already in use (WordPress would use "%s").', $requested, $unique ), 409, array( 'suggested' => $unique ) );
			}
			self::add( $plan, 'slug', (string) $post->post_name, $requested );
			$columns['post_name'] = $requested;
		}

		if ( array_key_exists( 'status', $changes ) ) {
			if ( ! is_string( $changes['status'] ) ) {
				return self::error( 'asc_invalid_status', 'status must be a string.', 400 );
			}
			if ( 'future' === $post->post_status && 'future' !== $changes['status'] ) {
				// Rollback could not restore 'future' (scheduling is out of scope).
				return self::error( 'asc_unsupported_transition', 'Changing the status of a scheduled post is not supported by this tool.', 400 );
			}
			$check = self::check_status( $post, $changes['status'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			self::add( $plan, 'status', (string) $post->post_status, $changes['status'] );
			$columns['post_status'] = $changes['status'];
		}

		if ( array_key_exists( 'featured_image', $changes ) ) {
			$v = $changes['featured_image'];
			if ( ! ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) ) {
				return self::error( 'asc_invalid_featured_image', 'featured_image must be an attachment ID (0 removes it).', 400 );
			}
			$check = self::check_featured_image( $post, (int) $v );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			self::add( $plan, 'featured_image', (int) get_post_thumbnail_id( $post->ID ), (int) $v );
		}

		if ( array_key_exists( 'terms', $changes ) ) {
			if ( ! is_array( $changes['terms'] ) || empty( $changes['terms'] ) ) {
				return self::error( 'asc_invalid_terms', 'terms must be an object of taxonomy => term list.', 400 );
			}
			$before = array();
			$after  = array();
			foreach ( $changes['terms'] as $tax => $list ) {
				$ids = self::resolve_terms( $post, (string) $tax, $list );
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				$before[ (string) $tax ] = self::current_terms( $post->ID, (string) $tax );
				$after[ (string) $tax ]  = $ids;
			}
			ksort( $before );
			ksort( $after );
			self::add( $plan, 'terms', $before, $after );
		}

		if ( array_key_exists( 'seo', $changes ) ) {
			if ( ! is_array( $changes['seo'] ) ) {
				return self::error( 'asc_invalid_seo', 'seo must be an object of field => value.', 400 );
			}
			$dry = AI_Site_Connector_SEO::update_seo_meta( $post->ID, $changes['seo'], true );
			if ( ! empty( $dry['skipped'] ) ) {
				return self::error( 'asc_invalid_seo', 'Some SEO fields cannot be written.', 400, array( 'skipped' => $dry['skipped'], 'plugin' => $dry['plugin'] ) );
			}
			if ( ! empty( $dry['blocked'] ) ) {
				return self::error( 'asc_invalid_seo', 'SEO update refused: ' . $dry['reason'], 403 );
			}
			$before = array();
			$after  = array();
			foreach ( $dry['would_write'] as $f => $row ) {
				$before[ $f ] = array( $row['meta_key'], $row['old'] );
				$after[ $f ]  = array( $row['meta_key'], $row['new'] );
			}
			ksort( $before );
			ksort( $after );
			self::add( $plan, 'seo', $before, $after );
		}

		// Saving runs every column through this user's filters (kses etc.).
		// Refuse if that would silently change a column the caller did not
		// touch — it could not be shown, snapshotted or rolled back.
		if ( array_intersect_key( $plan, self::POST_COLUMNS ) ) {
			$col = self::untouched_change( $post, array_keys( $columns ) );
			if ( '' !== $col ) {
				return self::error(
					'asc_untouched_field_would_change',
					sprintf( 'Saving would alter %s, which you did not change (your account cannot store its current markup). Ask an administrator to make this edit.', $col ),
					409,
					array( 'field' => $col )
				);
			}
		}

		return $plan;
	}

	private static function check_status( WP_Post $post, $status ) {
		if ( ! in_array( $status, self::ALLOWED_STATUS, true ) ) {
			return self::error( 'asc_invalid_status', sprintf( 'status must be one of: %s.', implode( ', ', self::ALLOWED_STATUS ) ), 400 );
		}
		$type = get_post_type_object( $post->post_type );
		if ( in_array( $status, array( 'publish', 'private' ), true ) && $status !== $post->post_status && ! current_user_can( $type->cap->publish_posts ) ) {
			return self::error( 'asc_forbidden_transition', sprintf( 'You cannot set status to %s.', $status ), 403 );
		}
		return true;
	}

	private static function check_featured_image( WP_Post $post, $att_id ) {
		if ( $att_id < 0 ) {
			return self::error( 'asc_invalid_featured_image', 'featured_image must be an attachment ID (0 removes it).', 400 );
		}
		if ( 0 === $att_id ) {
			return true;
		}
		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return self::error( 'asc_invalid_featured_image', 'This post type does not support featured images.', 400 );
		}
		$att = get_post( $att_id );
		if ( ! $att || 'attachment' !== $att->post_type || ! wp_attachment_is_image( $att_id ) ) {
			return self::error( 'asc_invalid_featured_image', 'featured_image must be an existing image attachment.', 400 );
		}
		if ( ! AI_Site_Connector_Export::can_read_attachment( $att ) ) {
			return self::error( 'asc_invalid_featured_image', 'featured_image is not accessible to you.', 403 );
		}
		return true;
	}

	/**
	 * Integers are term IDs; strings are slugs (so a tag slugged "2024" is
	 * never mistaken for term ID 2024). Terms are never created.
	 *
	 * @return int[]|WP_Error Sorted unique IDs.
	 */
	private static function resolve_terms( WP_Post $post, $tax, $list ) {
		if ( ! taxonomy_exists( $tax ) || ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
			return self::error( 'asc_invalid_taxonomy', sprintf( 'Taxonomy %s is not registered for %s.', $tax, $post->post_type ), 400 );
		}
		if ( ! current_user_can( get_taxonomy( $tax )->cap->assign_terms ) ) {
			return self::error( 'asc_forbidden_taxonomy', sprintf( 'You cannot assign %s terms.', $tax ), 403 );
		}
		if ( ! is_array( $list ) ) {
			return self::error( 'asc_invalid_terms', sprintf( 'Terms for %s must be a list.', $tax ), 400 );
		}
		$ids = array();
		foreach ( $list as $ref ) {
			if ( is_int( $ref ) ) {
				$term = get_term( $ref, $tax );
			} elseif ( is_string( $ref ) ) {
				$term = get_term_by( 'slug', $ref, $tax );
			} else {
				$term = null;
			}
			if ( ! $term || is_wp_error( $term ) ) {
				return self::error( 'asc_invalid_term', sprintf( 'Term %s does not exist in %s (terms are never created).', is_scalar( $ref ) ? (string) $ref : '?', $tax ), 400 );
			}
			$ids[] = (int) $term->term_id;
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Re-run update-time checks on values a rollback would restore.
	 */
	private static function validate_restore( WP_Post $post, array $restore ) {
		$cols = array();
		foreach ( self::POST_COLUMNS as $field => $col ) {
			if ( ! isset( $restore[ $field ] ) ) {
				continue;
			}
			$cols[] = $col;
			// The value being restored must survive this user's save filters,
			// or the guard would trip after hooks fired.
			if ( in_array( $col, array( 'post_title', 'post_excerpt', 'post_content' ), true )
				&& self::stored_value( $col, $restore[ $field ]['before_raw'], $post->ID ) !== (string) $restore[ $field ]['before_raw'] ) {
				return self::error( 'asc_restore_would_change', sprintf( 'Your account cannot store the original %s (it contains markup your role cannot save). Ask an administrator to roll back.', $col ), 409, array( 'field' => $col ) );
			}
		}
		if ( $cols ) {
			$col = self::untouched_change( $post, $cols );
			if ( '' !== $col ) {
				return self::error( 'asc_untouched_field_would_change', sprintf( 'Rolling back would alter %s, which this rollback does not restore. Ask an administrator to roll back.', $col ), 409, array( 'field' => $col ) );
			}
		}
		$slugs = array();
		if ( isset( $restore['slug'] ) ) {
			$slugs[] = (string) $restore['slug']['before_raw'];
		}
		if ( isset( $restore['_status_side'] ) && is_array( $restore['_status_side']['before_raw'] ) && isset( $restore['status'] )
			&& in_array( (string) $restore['status']['before_raw'], array( 'publish', 'private' ), true ) ) {
			$slugs[] = (string) $restore['_status_side']['before_raw']['post_name'];
		}
		foreach ( array_filter( $slugs ) as $slug ) {
			if ( wp_unique_post_slug( $slug, $post->ID, 'publish', $post->post_type, $post->post_parent ) !== $slug ) {
				return self::error( 'asc_slug_conflict', sprintf( 'slug "%s" is now used by another post; rolling back would duplicate it.', $slug ), 409 );
			}
		}
		foreach ( $restore as $field => $row ) {
			if ( '_status_side' === $field ) {
				continue;
			}
			$value = $row['before_raw'];
			if ( 'status' === $field ) {
				$check = self::check_status( $post, (string) $value );
			} elseif ( 'featured_image' === $field ) {
				$check = self::check_featured_image( $post, (int) $value );
			} elseif ( 'terms' === $field ) {
				$check = true;
				foreach ( (array) $value as $tax => $ids ) {
					$check = self::resolve_terms( $post, (string) $tax, array_map( 'intval', (array) $ids ) );
					if ( is_wp_error( $check ) ) {
						break;
					}
				}
			} else {
				$check = true;
			}
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		return true;
	}

	private static function add( array &$plan, $field, $before, $after ) {
		if ( self::canon( $before ) !== self::canon( $after ) ) {
			$plan[ $field ] = array(
				'before_raw'  => $before,
				'after_raw'   => $after,
				'planned_raw' => $after,
			);
		}
	}

	// --- applying ----------------------------------------------------------------

	/**
	 * Apply $key ('after_raw' or 'before_raw') of every field: meta, terms and
	 * thumbnail first, then all post columns in one wp_update_post().
	 *
	 * @return true|array{failed:string,written:string[]}
	 */
	private static function apply_plan( $post_id, array $plan, $key ) {
		$written = array();
		foreach ( array( 'seo', 'terms', 'featured_image' ) as $field ) {
			if ( ! isset( $plan[ $field ] ) ) {
				continue;
			}
			// Listed before applying: a side field spans several taxonomies or
			// meta keys, and a failure part-way must still be restored in full.
			$written[] = $field;
			if ( ! self::apply_side_field( $post_id, $field, $plan[ $field ][ $key ] ) ) {
				return array(
					'failed'  => $field,
					'written' => $written,
				);
			}
		}

		$columns = array();
		foreach ( self::POST_COLUMNS as $field => $col ) {
			if ( isset( $plan[ $field ] ) ) {
				$columns[ $col ] = $plan[ $field ][ $key ];
			}
		}
		if ( 'before_raw' === $key && isset( $plan['_status_side'] ) && is_array( $plan['_status_side']['before_raw'] ) ) {
			$columns = array_merge( $plan['_status_side']['before_raw'], $columns );
		}
		if ( $columns ) {
			$guard_before = self::read_columns( $post_id, self::GUARDED_COLUMNS );
			$args         = array_merge( array( 'ID' => $post_id ), $columns );
			if ( ! isset( $columns['post_status'] ) && ! isset( $columns['post_date'] ) ) {
				// WordPress re-dates never-published drafts on every save
				// unless the date is passed explicitly; keep it unchanged.
				$args['post_date'] = $guard_before['post_date'];
				$args['edit_date'] = true;
			}
			// Record the exact post_modified_gmt this call writes, so the guard
			// restore below can tell its own write from a later one.
			// Only this post's own write: wp_update_post() also inserts a
			// revision through the same filter. The filter sees slashed data.
			$written_row = null;
			$capture     = static function ( $data, $postarr ) use ( &$written_row, $post_id ) {
				if ( null === $written_row && isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post_id ) {
					$written_row = wp_unslash( $data );
				}
				return $data;
			};
			add_filter( 'wp_insert_post_data', $capture, PHP_INT_MAX, 2 );
			$res = wp_update_post( wp_slash( $args ), true );
			remove_filter( 'wp_insert_post_data', $capture, PHP_INT_MAX );
			clean_post_cache( $post_id );
			$after        = self::read_columns( $post_id, self::GUARDED_COLUMNS );
			$allowed      = array_keys( $columns );
			if ( isset( $columns['post_status'] ) ) {
				$allowed = array_merge( $allowed, self::STATUS_SIDE_COLUMNS );
			}
			$ok = ! is_wp_error( $res ) && $res;
			foreach ( $columns as $col => $value ) {
				// Explicit fields must match exactly; restored date side
				// columns may be normalised by WordPress.
				if ( ! in_array( $col, self::POST_COLUMNS, true ) ) {
					continue;
				}
				if ( (string) $after[ $col ] !== (string) $value ) {
					$ok = false;
				}
			}
			foreach ( self::GUARDED_COLUMNS as $col ) {
				if ( ! in_array( $col, $allowed, true ) && (string) $after[ $col ] !== (string) $guard_before[ $col ] ) {
					$ok = false; // Another column changed unexpectedly.
				}
			}
			if ( ! $ok ) {
				if ( ! is_wp_error( $res ) && $res ) {
					// Put the row back exactly as it was. A direct update, not
					// wp_update_post(): the save filters that caused the
					// mismatch would otherwise alter the restore too.
					// Conditional on the row still being the one this call
					// wrote, so a concurrent save is never overwritten.
					// The WHERE clause matches every guarded column (and the
					// modified time) as this call wrote them, so a concurrent
					// save within the same second is not overwritten either.
					global $wpdb;
					$rows = 0;
					if ( is_array( $written_row ) && isset( $written_row['post_modified_gmt'] ) ) {
						$where = array(
							'ID'                => $post_id,
							'post_modified_gmt' => (string) $written_row['post_modified_gmt'],
						);
						foreach ( self::GUARDED_COLUMNS as $col ) {
							if ( array_key_exists( $col, $written_row ) ) {
								$where[ $col ] = (string) $written_row[ $col ];
							}
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$rows = $wpdb->update( $wpdb->posts, $guard_before, $where );
					}
					clean_post_cache( $post_id );
					if ( 1 !== (int) $rows && self::canon( self::read_columns( $post_id, self::GUARDED_COLUMNS ) ) !== self::canon( $guard_before ) ) {
						return array(
							'failed'          => 'post_columns',
							'written'         => $written,
							'columns_dirty'   => true,
						);
					}
				}
				return array(
					'failed'  => implode( ',', array_intersect_key( array_flip( self::POST_COLUMNS ), $columns ) ),
					'written' => $written,
				);
			}
			foreach ( self::POST_COLUMNS as $field => $col ) {
				if ( isset( $plan[ $field ] ) ) {
					$written[] = $field;
				}
			}
		}
		return true;
	}

	private static function apply_side_field( $post_id, $field, $value ) {
		switch ( $field ) {
			case 'featured_image':
				if ( (int) $value > 0 ) {
					set_post_thumbnail( $post_id, (int) $value );
				} else {
					delete_post_thumbnail( $post_id );
				}
				wp_cache_delete( $post_id, 'post_meta' );
				return (int) get_post_thumbnail_id( $post_id ) === (int) $value;
			case 'terms':
				foreach ( (array) $value as $tax => $ids ) {
					$res = wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), $tax, false );
					if ( is_wp_error( $res ) ) {
						return false;
					}
					clean_object_term_cache( $post_id, get_post_type( $post_id ) );
					if ( self::current_terms( $post_id, $tax ) !== array_values( array_map( 'intval', (array) $ids ) ) ) {
						return false;
					}
				}
				return true;
			case 'seo':
				foreach ( (array) $value as $pair ) {
					list( $key, $val ) = $pair;
					if ( '' === (string) $val ) {
						delete_post_meta( $post_id, $key );
					} else {
						update_post_meta( $post_id, $key, wp_slash( (string) $val ) );
					}
					wp_cache_delete( $post_id, 'post_meta' );
					$now = get_post_meta( $post_id, $key, true );
					if ( (string) ( is_scalar( $now ) ? $now : '' ) !== (string) $val ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * Restore the given fields to before_raw after a failed update.
	 *
	 * @return string[] Fields that could not be restored.
	 */
	private static function restore_written( $post_id, array $plan, array $fields ) {
		$subset = array();
		foreach ( $fields as $field ) {
			if ( isset( $plan[ $field ] ) ) {
				$subset[ $field ] = $plan[ $field ];
			}
		}
		if ( isset( $subset['status'] ) && isset( $plan['_status_side'] ) ) {
			$subset['_status_side'] = $plan['_status_side'];
		}
		if ( empty( $subset ) ) {
			return array();
		}
		$res = self::apply_plan( $post_id, $subset, 'before_raw' );
		if ( true === $res ) {
			return array();
		}
		return array_values( array_diff( array_keys( $subset ), $res['written'], array( '_status_side' ) ) );
	}

	private static function current_terms( $post_id, $tax ) {
		$ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
		$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
		sort( $ids );
		return array_values( $ids );
	}

	private static function read_columns( $post_id, array $cols ) {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		$out  = array();
		foreach ( $cols as $col ) {
			$out[ $col ] = $post ? (string) $post->$col : '';
		}
		return $out;
	}

	/**
	 * Current stored value of a field in the same shape as a plan value.
	 */
	private static function read_field( $post_id, $field, $shape ) {
		if ( isset( self::POST_COLUMNS[ $field ] ) ) {
			$cols = self::read_columns( $post_id, array( self::POST_COLUMNS[ $field ] ) );
			return $cols[ self::POST_COLUMNS[ $field ] ];
		}
		switch ( $field ) {
			case 'featured_image':
				wp_cache_delete( $post_id, 'post_meta' );
				return (int) get_post_thumbnail_id( $post_id );
			case 'terms':
				$out = array();
				foreach ( array_keys( (array) $shape ) as $tax ) {
					$out[ $tax ] = self::current_terms( $post_id, $tax );
				}
				ksort( $out );
				return $out;
			case 'seo':
				$out = array();
				wp_cache_delete( $post_id, 'post_meta' );
				foreach ( (array) $shape as $f => $pair ) {
					$now       = get_post_meta( $post_id, $pair[0], true );
					$out[ $f ] = array( $pair[0], is_scalar( $now ) ? (string) $now : '' );
				}
				ksort( $out );
				return $out;
		}
		return null;
	}

	// --- snapshots (one non-autoloaded option each + per-post index rows) ---

	private static function snapshot_ids( $post_id ) {
		$ids = array_map( 'strval', (array) get_post_meta( $post_id, self::INDEX_META, false ) );
		sort( $ids );
		return $ids;
	}

	private static function load_snapshot( $id ) {
		if ( ! preg_match( '/^[0-9]{14}-[A-Za-z0-9]{6}$/', (string) $id ) ) {
			return null;
		}
		$snap = get_option( self::OPTION_PREFIX . $id, null );
		return is_array( $snap ) ? $snap : null;
	}

	/**
	 * @return string|WP_Error Snapshot ID.
	 */
	private static function store_snapshot( $post_id, array $plan ) {
		$id   = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
		$data = array(
			'post_id'     => (int) $post_id,
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'user_id'     => get_current_user_id(),
			'state'       => 'pending',
			'fields'      => self::snapshot_fields( $plan, true ),
		);
		// add_option is an atomic insert; autoload off keeps snapshots out
		// of every page load.
		if ( ! add_option( self::OPTION_PREFIX . $id, $data, '', 'no' ) || ! add_post_meta( $post_id, self::INDEX_META, $id ) ) {
			delete_option( self::OPTION_PREFIX . $id );
			return self::error( 'asc_snapshot_failed', 'Could not store a rollback snapshot; nothing was changed.', 500 );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		if ( ! in_array( $id, self::snapshot_ids( $post_id ), true ) || ! self::load_snapshot( $id ) ) {
			delete_option( self::OPTION_PREFIX . $id );
			delete_post_meta( $post_id, self::INDEX_META, $id );
			return self::error( 'asc_snapshot_failed', 'Could not store a rollback snapshot; nothing was changed.', 500 );
		}
		self::prune( $post_id );
		return $id;
	}

	/**
	 * Plan rows as stored: before/after values; while pending, after_raw is
	 * unknown (null) and planned_raw holds the intended value.
	 */
	private static function snapshot_fields( array $plan, $pending ) {
		$out = array();
		foreach ( $plan as $field => $row ) {
			$out[ $field ] = array(
				'before_raw'  => $row['before_raw'],
				'after_raw'   => $pending ? null : $row['after_raw'],
				'planned_raw' => isset( $row['planned_raw'] ) ? $row['planned_raw'] : $row['after_raw'],
			);
		}
		return $out;
	}

	private static function finalize_snapshot( $id, array $plan ) {
		$snap = self::load_snapshot( $id );
		if ( ! $snap ) {
			return false;
		}
		$snap['fields'] = self::snapshot_fields( $plan, false );
		$snap['state']  = 'applied';
		update_option( self::OPTION_PREFIX . $id, $snap, false );
		$check = self::load_snapshot( $id );
		return $check && 'applied' === $check['state'];
	}

	private static function set_snapshot_state( $id, $state ) {
		$snap = self::load_snapshot( $id );
		if ( $snap ) {
			$snap['state'] = $state;
			update_option( self::OPTION_PREFIX . $id, $snap, false );
		}
	}

	private static function prune( $post_id ) {
		$ids = self::snapshot_ids( $post_id );
		$n   = count( $ids );
		foreach ( $ids as $old ) {
			if ( $n <= self::MAX_SNAPSHOTS ) {
				break;
			}
			$snap = self::load_snapshot( $old );
			if ( $snap && in_array( $snap['state'], array( 'pending', 'revert_incomplete' ), true ) ) {
				continue; // Only record needed to recover an interrupted update.
			}
			delete_option( self::OPTION_PREFIX . $old );
			delete_post_meta( $post_id, self::INDEX_META, $old );
			--$n;
		}
	}

	/**
	 * Remove a post's snapshot options when the post is permanently deleted
	 * (they hold copies of its content).
	 */
	public static function delete_post_snapshots( $post_id ) {
		foreach ( self::snapshot_ids( (int) $post_id ) as $id ) {
			delete_option( self::OPTION_PREFIX . $id );
		}
	}

	public static function register_hooks() {
		add_action( 'before_delete_post', array( __CLASS__, 'delete_post_snapshots' ) );
	}

	// --- output helpers --------------------------------------------------------

	/**
	 * Diff for responses: content is summarised (length + sha256); SEO is
	 * shown as field => value.
	 */
	private static function public_diff( array $plan ) {
		$out = array();
		foreach ( $plan as $field => $row ) {
			if ( '_status_side' === $field ) {
				continue;
			}
			$before = $row['before_raw'];
			$after  = $row['after_raw'];
			if ( 'content' === $field ) {
				$before = self::summary( $before );
				$after  = self::summary( $after );
			} elseif ( 'seo' === $field ) {
				$before = wp_list_pluck( array_map( array( __CLASS__, 'pair' ), (array) $before ), 'value' );
				$after  = wp_list_pluck( array_map( array( __CLASS__, 'pair' ), (array) $after ), 'value' );
			}
			$out[ $field ] = array(
				'before' => $before,
				'after'  => $after,
			);
		}
		return $out;
	}

	private static function summary( $text ) {
		return array(
			'length' => strlen( (string) $text ),
			'sha256' => hash( 'sha256', (string) $text ),
		);
	}

	private static function pair( $pair ) {
		return array( 'value' => is_array( $pair ) && isset( $pair[1] ) ? $pair[1] : '' );
	}

	private static function canon( $v ) {
		return wp_json_encode( $v );
	}

	private static function audit( $action, $post_id, $message ) {
		if ( class_exists( 'AI_Site_Connector_Audit_Log' ) ) {
			AI_Site_Connector_Audit_Log::record(
				$action,
				array(
					'tool'    => AI_Site_Connector_Permissions::TOOL_WRITE_CONTENT,
					'message' => sprintf( 'Post %d: %s', (int) $post_id, $message ),
				)
			);
		}
	}

	private static function error( $code, $message, $status, $extra = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}
}
