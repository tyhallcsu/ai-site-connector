<?php
/**
 * SEO value types (#117): a malformed value must never become a deletion.
 * Uses asc_it_as_seo_plugin() from test-seo.php (all files load before any
 * case runs).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'seo: wrong-typed values are refused for the whole request and never plan a deletion (#117)',
	function () {
		$post = asc_it_post( array( 'post_title' => 'SEO value types' ) );
		update_post_meta( $post, 'rank_math_title', 'Keep me' );
		try {
			asc_it_as_seo_plugin(
				'rankmath',
				function () use ( $post ) {
					$bad_values = array(
						'object' => (object) array( 'unexpected' => 'object' ),
						'array'  => array( 'x' ),
						'null'   => null,
						'false'  => false,
					);
					foreach ( $bad_values as $label => $bad ) {
						$res = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => $bad, 'description' => 'valid' ), true );
						asc_assert_same( 'invalid_value', $res['reason'], "{$label}: reason" );
						asc_assert_same( array( 'title' => 'invalid_type' ), $res['skipped'], "{$label}: skipped" );
						asc_assert_same( array(), $res['would_write'], "{$label}: a plan was built" );
					}
					$res = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'canonical' => array( 'https://example.com/' ) ), true );
					asc_assert_same( array( 'canonical' => 'invalid_type' ), $res['skipped'], 'URL field given an array' );
					$res = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'noindex' => true ), true );
					asc_assert_same( array( 'noindex' => 'unsupported_field' ), $res['skipped'], 'a boolean noindex is not a type error' );

					$res = AI_Site_Connector_SEO::update_seo_meta( $post, array( 'title' => '' ), true );
					asc_assert_same( array( 'Keep me', '' ), array( $res['would_write']['title']['old'], $res['would_write']['title']['new'] ), 'explicit empty string still clears' );

					$http = asc_it_rest( 'POST', '/content/update', array( 'post_id' => $post, 'changes' => array( 'seo' => array( 'title' => array( 'unexpected' => 'object' ) ) ) ) );
					asc_assert_same( 400, $http->get_status(), 'content update status' );
					$data = (array) $http->get_data();
					asc_assert_same( array( 'title' => 'invalid_type' ), $data['data']['skipped'], 'content update error names the field' );
				}
			);
			asc_assert_same( 'Keep me', get_post_meta( $post, 'rank_math_title', true ), 'title kept' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);
