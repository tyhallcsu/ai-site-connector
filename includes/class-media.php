<?php
/**
 * Safe media upload tool with SEO metadata.
 *
 * Accepts a remote URL, downloads it, validates the mime type, sanitises
 * the filename, inserts an attachment, sets title/alt/caption/description,
 * optionally attaches to a post and sets as featured image, and (with
 * separate permission) writes Rank Math / Yoast social-image meta on the
 * parent post.
 *
 * URL-sideload is the only upload path supported on purpose:
 *   - it avoids the forbidden `base64_decode` route
 *   - it sidesteps multipart parsing inside the REST framework
 *   - it gives wp_handle_sideload a real file on disk to mime-check
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Media {

	public static function register_hooks() {
		// REST routes added by class-rest-controller.php — nothing to hook
		// directly here; keeping the method present for boot symmetry.
	}

	/**
	 * Sideload a URL into the media library with metadata.
	 *
	 * @param array $args {
	 *   @type string $url                Remote URL to fetch. Required.
	 *   @type string $title              Attachment title.
	 *   @type string $alt_text           alt= text.
	 *   @type string $caption            Caption (post_excerpt).
	 *   @type string $description        Long description (post_content).
	 *   @type int    $post_id            Parent post to attach to (post_parent).
	 *   @type bool   $set_featured_image Set as featured image of the parent post.
	 *   @type bool   $seo_social_image   Set as Rank Math / Yoast social image of parent post.
	 *   @type string $filename_override  Optional explicit filename (still sanitised).
	 * }
	 * @return array|WP_Error  { attachment_id, url, mime_type, metadata, warnings[] } on success.
	 */
	public static function sideload( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'url'                => '',
				'title'              => '',
				'alt_text'           => '',
				'caption'            => '',
				'description'        => '',
				'post_id'            => 0,
				'set_featured_image' => false,
				'seo_social_image'   => false,
				'filename_override'  => '',
			)
		);

		$url = esc_url_raw( (string) $args['url'] );
		if ( '' === $url ) {
			return new WP_Error( 'rest_invalid_param', __( 'A "url" parameter is required.', 'ai-site-connector' ), array( 'status' => 400 ) );
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'URL scheme must be http or https.', 'ai-site-connector' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'rest_download_failed', $tmp->get_error_message(), array( 'status' => 502 ) );
		}

		// Pick the filename: explicit override → URL path tail → fallback.
		$filename = '';
		if ( '' !== (string) $args['filename_override'] ) {
			$filename = (string) $args['filename_override'];
		} else {
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$filename = basename( $path );
		}
		if ( '' === $filename || '.' === $filename || strpos( $filename, '.' ) === false ) {
			$filename = 'media-' . wp_generate_password( 8, false, false );
		}
		$filename = sanitize_file_name( $filename );

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$sideloaded = wp_handle_sideload(
			$file_array,
			array(
				'test_form' => false,
			)
		);

		if ( isset( $sideloaded['error'] ) ) {
			@unlink( $tmp );
			return new WP_Error( 'rest_upload_rejected', (string) $sideloaded['error'], array( 'status' => 415 ) );
		}

		$attachment = array(
			'post_mime_type' => isset( $sideloaded['type'] ) ? (string) $sideloaded['type'] : 'application/octet-stream',
			'post_title'     => '' !== (string) $args['title'] ? sanitize_text_field( (string) $args['title'] ) : pathinfo( $filename, PATHINFO_FILENAME ),
			'post_content'   => wp_kses_post( (string) $args['description'] ),
			'post_excerpt'   => sanitize_text_field( (string) $args['caption'] ),
			'post_status'    => 'inherit',
			'post_parent'    => (int) $args['post_id'],
		);

		$attachment_id = wp_insert_attachment( $attachment, (string) $sideloaded['file'], (int) $args['post_id'], true );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( (string) $sideloaded['file'] );
			return $attachment_id;
		}

		$meta = wp_generate_attachment_metadata( $attachment_id, (string) $sideloaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $meta );

		if ( '' !== (string) $args['alt_text'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt_text'] ) );
		}

		$warnings = array();

		if ( (int) $args['post_id'] > 0 && ! empty( $args['set_featured_image'] ) ) {
			$set_ok = set_post_thumbnail( (int) $args['post_id'], (int) $attachment_id );
			if ( ! $set_ok ) {
				$warnings[] = sprintf( 'Failed to set attachment %d as featured image of post %d.', (int) $attachment_id, (int) $args['post_id'] );
			}
		}

		if ( (int) $args['post_id'] > 0 && ! empty( $args['seo_social_image'] ) ) {
			$seo_check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_UPDATE_SEO );
			if ( is_wp_error( $seo_check ) ) {
				$warnings[] = 'SEO social image not set: ' . $seo_check->get_error_message();
			} else {
				$seo_warn = self::apply_seo_social_image( (int) $args['post_id'], (int) $attachment_id );
				$warnings = array_merge( $warnings, $seo_warn );
			}
		}

		$result = array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'mime_type'     => (string) $attachment['post_mime_type'],
			'filename'      => $filename,
			'metadata'      => is_array( $meta ) ? $meta : array(),
			'parent_post'   => (int) $args['post_id'],
			'warnings'      => $warnings,
		);

		AI_Site_Connector_Audit_Log::record(
			'media_uploaded',
			array(
				'tool'        => AI_Site_Connector_Permissions::TOOL_UPLOAD_MEDIA,
				'status'      => AI_Site_Connector_Audit_Log::STATUS_SUCCESS,
				'target_type' => 'attachment',
				'target_id'   => (int) $attachment_id,
				'summary'     => sprintf(
					'Uploaded %s (%s) → attachment %d',
					$filename,
					(string) $attachment['post_mime_type'],
					(int) $attachment_id
				),
				'meta'        => array(
					'parent_post'        => (int) $args['post_id'],
					'set_featured_image' => (bool) $args['set_featured_image'],
					'seo_social_image'   => (bool) $args['seo_social_image'],
					'mime_type'          => (string) $attachment['post_mime_type'],
					'warning_count'      => count( $warnings ),
					'source_host'        => (string) wp_parse_url( $url, PHP_URL_HOST ),
				),
			)
		);

		return $result;
	}

	/**
	 * Apply social-image / featured-image meta on a parent post for whichever
	 * SEO plugins are active. Yoast and Rank Math both expose stable meta
	 * keys for this; AIOSEO uses its own table and is skipped.
	 *
	 * @return string[] Warnings (empty array on full success).
	 */
	private static function apply_seo_social_image( $post_id, $attachment_id ) {
		$warnings = array();
		$attached = wp_get_attachment_image_src( $attachment_id, 'full' );
		$url      = is_array( $attached ) && isset( $attached[0] ) ? (string) $attached[0] : '';
		$wrote    = 0;

		// Yoast SEO — both Free and Premium read this pair.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $url );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', (int) $attachment_id );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $url );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', (int) $attachment_id );
			$wrote++;
		}

		// Rank Math.
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $url );
			update_post_meta( $post_id, 'rank_math_facebook_image_id', (int) $attachment_id );
			update_post_meta( $post_id, 'rank_math_twitter_image', $url );
			update_post_meta( $post_id, 'rank_math_twitter_image_id', (int) $attachment_id );
			$wrote++;
		}

		// AIOSEO stores in its own table (aioseo_posts). We do not write
		// directly there — it'd be fragile across versions. Surface as warning.
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( '\AIOSEO\Plugin\AIOSEO' ) ) {
			$warnings[] = 'AIOSEO detected but social-image fields not auto-applied (AIOSEO uses a custom table; set the image manually in its post sidebar).';
		}

		if ( 0 === $wrote && empty( $warnings ) ) {
			$warnings[] = 'No supported SEO plugin detected — social image meta was not written.';
		}

		return $warnings;
	}
}
