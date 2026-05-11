<?php
/**
 * Export / GitHub-sync helper.
 *
 * Generates structured JSON manifests of the things AI agents need to
 * keep a sibling repo in sync with a live WP install: media library,
 * recent content changes, individual page bodies, and a site overview.
 *
 * Does not call the GitHub API. Outputs are intended for a downstream
 * agent / script to commit. Storage path is under wp-content/uploads/
 * ai-site-connector/exports/ (gitignored on most setups, ad-hoc on others).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Export {

	const EXPORT_DIR_BASE = 'ai-site-connector/exports';

	public static function register_hooks() {
		// REST routes wired in class-rest-controller.php.
	}

	/**
	 * Export every (or page-limited) attachment as a media manifest array.
	 *
	 * @param array $args {
	 *   @type int $limit    Max attachments (default 1000, hard cap 5000).
	 *   @type int $offset   Skip first N.
	 *   @type bool $include_sha256 Compute SHA-256 of local file (default true; skipped for remote-hosted media).
	 * }
	 * @return array
	 */
	public static function media_manifest( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'          => 1000,
				'offset'         => 0,
				'include_sha256' => true,
			)
		);
		$limit  = max( 1, min( 5000, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$items = array();
		foreach ( $attachments as $att ) {
			$items[] = self::build_media_item( $att, (bool) $args['include_sha256'] );
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'site_url'     => home_url(),
			'count'        => count( $items ),
			'limit'        => $limit,
			'offset'       => $offset,
			'items'        => $items,
		);
	}

	private static function build_media_item( $att, $include_sha256 ) {
		$id       = (int) $att->ID;
		$url      = (string) wp_get_attachment_url( $id );
		$path     = (string) get_attached_file( $id );
		$alt      = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$mime     = (string) get_post_mime_type( $id );
		$size     = 0;
		$sha256   = '';
		if ( $path && file_exists( $path ) ) {
			$size = (int) filesize( $path );
			if ( $include_sha256 && $size > 0 && $size <= 200 * 1024 * 1024 ) {
				// Skip very large files to avoid 30+ second hashing — the
				// caller can request individual hashes via a follow-up if needed.
				$h = @hash_file( 'sha256', $path );
				if ( is_string( $h ) ) {
					$sha256 = $h;
				}
			}
		}

		return array(
			'attachment_id' => $id,
			'url'           => $url,
			'filename'      => $path ? basename( $path ) : basename( $url ),
			'title'         => (string) $att->post_title,
			'alt'           => $alt,
			'caption'       => (string) $att->post_excerpt,
			'description'   => (string) $att->post_content,
			'attached_to'   => (int) $att->post_parent,
			'mime_type'     => $mime,
			'size_bytes'    => $size,
			'sha256'        => $sha256,
			'modified_gmt'  => (string) $att->post_modified_gmt,
		);
	}

	/**
	 * Recent content changes across posts + pages. Useful to surface "what
	 * changed since the last sync" without scanning everything.
	 *
	 * @param array $args {
	 *   @type int $limit  default 50, cap 500
	 *   @type string $since  ISO-8601 datetime (UTC). When set, only newer rows.
	 *   @type string[] $post_types  default ['post','page']
	 * }
	 * @return array
	 */
	public static function recent_changes( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'      => 50,
				'since'      => '',
				'post_types' => array( 'post', 'page' ),
			)
		);
		$query_args = array(
			'post_type'      => (array) $args['post_types'],
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => max( 1, min( 500, (int) $args['limit'] ) ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== (string) $args['since'] ) {
			$query_args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => (string) $args['since'],
				),
			);
		}
		$posts = get_posts( $query_args );
		$items = array();
		foreach ( $posts as $p ) {
			$items[] = array(
				'id'             => (int) $p->ID,
				'type'           => $p->post_type,
				'status'         => $p->post_status,
				'title'          => $p->post_title,
				'slug'           => $p->post_name,
				'permalink'      => get_permalink( $p->ID ),
				'modified_gmt'   => $p->post_modified_gmt,
				'author_id'      => (int) $p->post_author,
				'content_length' => strlen( (string) $p->post_content ),
				'content_hash'   => hash( 'sha256', (string) $p->post_content ),
			);
		}

		return array(
			'generated_at' => gmdate( 'c' ),
			'site_url'     => home_url(),
			'since'        => (string) $args['since'],
			'count'        => count( $items ),
			'items'        => $items,
		);
	}

	/**
	 * Export a single page/post's content for repo storage.
	 *
	 * @param int $post_id
	 * @return array|WP_Error
	 */
	public static function page_content( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Post not found.', 'ai-site-connector' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to read this post.', 'ai-site-connector' ), array( 'status' => 403 ) );
		}

		return array(
			'id'              => (int) $post->ID,
			'type'            => $post->post_type,
			'status'          => $post->post_status,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'author_id'       => (int) $post->post_author,
			'permalink'       => get_permalink( $post->ID ),
			'modified_gmt'    => $post->post_modified_gmt,
			'created_gmt'     => $post->post_date_gmt,
			'content'         => (string) $post->post_content,
			'excerpt'         => (string) $post->post_excerpt,
			'featured_image'  => array(
				'attachment_id' => (int) get_post_thumbnail_id( $post->ID ),
				'url'           => (string) get_the_post_thumbnail_url( $post->ID, 'full' ),
			),
			'meta_keys'       => array_values( array_filter( array_keys( (array) get_post_meta( $post->ID ) ), array( __CLASS__, 'is_public_meta_key' ) ) ),
		);
	}

	/**
	 * Whole-site overview manifest — combines counts, recent changes, and
	 * detected capabilities into one snapshot.
	 *
	 * @return array
	 */
	public static function site_manifest() {
		$counts = array(
			'posts'       => (int) wp_count_posts( 'post' )->publish,
			'pages'       => (int) wp_count_posts( 'page' )->publish,
			'attachments' => (int) wp_count_posts( 'attachment' )->inherit,
		);
		return array(
			'generated_at'   => gmdate( 'c' ),
			'site_url'       => home_url(),
			'plugin_version' => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? AI_SITE_CONNECTOR_VERSION : '',
			'counts'         => $counts,
			'recent_changes' => self::recent_changes( array( 'limit' => 20 ) ),
			'detected'       => class_exists( 'AI_Site_Connector_Diagnostics' )
				? AI_Site_Connector_Diagnostics::generate()['detected']
				: array(),
		);
	}

	/**
	 * Write a JSON export to wp-content/uploads/ai-site-connector/exports/
	 * and return the public URL. Used by the admin "Export" tab buttons.
	 *
	 * @param string $kind  manifest|recent|site
	 * @param array  $data
	 * @return array|WP_Error  { path, url, bytes }
	 */
	public static function write_to_disk( $kind, $data ) {
		$kind = sanitize_key( $kind );
		if ( ! in_array( $kind, array( 'media-manifest', 'recent-changes', 'site-manifest' ), true ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Unknown export kind.', 'ai-site-connector' ), array( 'status' => 400 ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'rest_export_failed', (string) $uploads['error'], array( 'status' => 500 ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . self::EXPORT_DIR_BASE;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'rest_export_failed', __( 'Could not create export directory.', 'ai-site-connector' ), array( 'status' => 500 ) );
		}

		// Drop a no-index marker the first time — exports may legally be public
		// (uploads/ is browsable) but we don't want them in search results.
		$htaccess_path = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			@file_put_contents( $htaccess_path, "Options -Indexes\nHeader set X-Robots-Tag \"noindex, nofollow\"\n" );
		}
		$index_path = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, '' );
		}

		$filename = sprintf( '%s-%s.json', $kind, gmdate( 'Ymd-His' ) );
		$path     = trailingslashit( $dir ) . $filename;
		$json     = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new WP_Error( 'rest_export_failed', __( 'Could not JSON-encode export.', 'ai-site-connector' ), array( 'status' => 500 ) );
		}
		$bytes = @file_put_contents( $path, $json );
		if ( false === $bytes ) {
			return new WP_Error( 'rest_export_failed', __( 'Could not write export file.', 'ai-site-connector' ), array( 'status' => 500 ) );
		}

		$url = trailingslashit( $uploads['baseurl'] ) . self::EXPORT_DIR_BASE . '/' . rawurlencode( $filename );

		AI_Site_Connector_Audit_Log::record(
			'export_written',
			array(
				'tool'    => AI_Site_Connector_Permissions::TOOL_EXPORT_MANIFEST,
				'status'  => AI_Site_Connector_Audit_Log::STATUS_SUCCESS,
				'summary' => sprintf( 'Wrote %s export (%d bytes) to %s', $kind, (int) $bytes, $filename ),
				'meta'    => array(
					'kind'     => $kind,
					'filename' => $filename,
					'bytes'    => (int) $bytes,
				),
			)
		);

		return array(
			'kind'  => $kind,
			'path'  => $path,
			'url'   => $url,
			'bytes' => (int) $bytes,
		);
	}

	/** Public-meta filter callback — drop private `_meta` keys from the list. */
	public static function is_public_meta_key( $key ) {
		return is_string( $key ) && '' !== $key && '_' !== substr( $key, 0, 1 );
	}
}
