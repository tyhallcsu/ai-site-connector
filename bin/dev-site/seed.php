<?php
/**
 * Synthetic fixture content for the local dev site, run by
 * `bin/dev-site.sh seed` through `wp eval-file`. Idempotent: every item is
 * matched by its asc-fixture-* slug and created only once, and each carries
 * the _asc_dev_fixture meta key so it can be told apart from hand-made content.
 *
 * The set exercises the plugin's audits: one valid and one broken internal
 * link, an external link, two byte-identical images (duplicate detection) and
 * one image without alt text (media SEO audit).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$asc_fixture_find = static function ( $slug, $post_type ) {
	$found = get_posts(
		array(
			'name'        => $slug,
			'post_type'   => $post_type,
			'post_status' => array( 'publish', 'draft', 'inherit', 'private', 'pending', 'future' ),
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	return $found ? (int) $found[0] : 0;
};

$asc_fixture_post = static function ( $slug, array $args ) use ( $asc_fixture_find ) {
	$post_type = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$id        = $asc_fixture_find( $slug, $post_type );
	if ( $id ) {
		return array( $id, false );
	}
	$id = wp_insert_post(
		array_merge(
			array(
				'post_name'   => $slug,
				'post_status' => 'publish',
				'post_type'   => $post_type,
			),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id );
	}
	update_post_meta( $id, '_asc_dev_fixture', 1 );
	return array( (int) $id, true );
};

// A deterministic 64x64 PNG, so both copies are byte-identical duplicates.
$asc_fixture_png = static function () {
	$path = wp_tempnam( 'asc-fixture.png' );
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$image = imagecreatetruecolor( 64, 64 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 22, 160, 133 ) );
		imagepng( $image, $path );
		imagedestroy( $image );
	} else {
		copy( ABSPATH . 'wp-admin/images/wordpress-logo.png', $path );
	}
	return $path;
};

$asc_fixture_media = static function ( $slug, $title, $alt ) use ( $asc_fixture_find, $asc_fixture_png ) {
	$id = $asc_fixture_find( $slug, 'attachment' );
	if ( $id ) {
		return array( $id, false );
	}
	$id = media_handle_sideload(
		array(
			'name'     => $slug . '.png',
			'tmp_name' => $asc_fixture_png(),
		),
		0,
		$title,
		array( 'post_name' => $slug )
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id );
	}
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	update_post_meta( $id, '_asc_dev_fixture', 1 );
	return array( (int) $id, true );
};

$asc_created = 0;
$asc_tally   = static function ( array $result ) use ( &$asc_created ) {
	if ( $result[1] ) {
		++$asc_created;
	}
	return $result[0];
};

$asc_category = term_exists( 'Fixture', 'category' );
if ( ! $asc_category ) {
	$asc_category = wp_insert_term( 'Fixture', 'category', array( 'slug' => 'asc-fixture' ) );
}
$asc_category_id = (int) ( is_array( $asc_category ) ? $asc_category['term_id'] : $asc_category );

$asc_logo      = $asc_tally( $asc_fixture_media( 'asc-fixture-logo', 'Fixture logo', 'Teal fixture square' ) );
$asc_logo_copy = $asc_tally( $asc_fixture_media( 'asc-fixture-logo-copy', 'Fixture logo (duplicate, no alt)', '' ) );

$asc_about = $asc_tally(
	$asc_fixture_post(
		'asc-fixture-about',
		array(
			'post_type'    => 'page',
			'post_title'   => 'Fixture: About',
			'post_content' => '<!-- wp:paragraph --><p>Synthetic page created by bin/dev-site.sh seed.</p><!-- /wp:paragraph -->',
		)
	)
);

$asc_links_content = sprintf(
	'<!-- wp:paragraph --><p>Valid internal link: <a href="%1$s">About</a>. Broken internal link: <a href="%2$s">Missing page</a>. External link: <a href="https://example.com/">example.com</a>.</p><!-- /wp:paragraph -->'
	. '<!-- wp:image {"id":%3$d} --><figure class="wp-block-image"><img src="%4$s" alt="" class="wp-image-%3$d"/></figure><!-- /wp:image -->',
	esc_url( get_permalink( $asc_about ) ),
	esc_url( home_url( '/asc-fixture-missing-page/' ) ),
	$asc_logo_copy,
	esc_url( wp_get_attachment_url( $asc_logo_copy ) )
);

$asc_tally(
	$asc_fixture_post(
		'asc-fixture-links',
		array(
			'post_title'    => 'Fixture: Links and media',
			'post_content'  => $asc_links_content,
			'post_category' => array( $asc_category_id ),
		)
	)
);

$asc_tally(
	$asc_fixture_post(
		'asc-fixture-draft',
		array(
			'post_title'    => 'Fixture: Draft',
			'post_status'   => 'draft',
			'post_content'  => '<!-- wp:paragraph --><p>Unpublished synthetic draft.</p><!-- /wp:paragraph -->',
			'post_category' => array( $asc_category_id ),
		)
	)
);

WP_CLI::success( sprintf( 'Fixture content ready (%d item(s) created this run; logo #%d, duplicate #%d).', $asc_created, $asc_logo, $asc_logo_copy ) );
