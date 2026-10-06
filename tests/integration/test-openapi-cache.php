<?php
/**
 * The cached OpenAPI document always names the current site's endpoint
 * (#129): the cache is per site and refreshed when the server URL changes.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'openapi cache: per-site and refreshed when the server URL changes (#129)',
	function () {
		$key = AI_Site_Connector_OpenAPI::CACHE_TRANSIENT;
		delete_transient( $key );
		delete_site_transient( $key );
		$server = static function () {
			$spec = AI_Site_Connector_OpenAPI::serve_spec()->get_data();
			return $spec['servers'][0]['url'];
		};
		try {
			$first = $server();
			asc_assert_same( trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE, $first, 'server URL' );
			asc_assert( is_array( get_transient( $key ) ), 'per-site cache not written' );
			if ( ! is_multisite() ) {
				asc_assert_same( false, get_site_transient( $key ), 'network-wide cache written' );
			}

			$other = asc_it_with_filter(
				'rest_url',
				static function ( $url ) {
					return str_replace( wp_parse_url( $url, PHP_URL_HOST ), 'other-site.example', $url );
				},
				$server
			);
			asc_assert( false !== strpos( $other, 'other-site.example' ), 'a cached document for another URL was served: ' . $other );
			asc_assert_same( $first, $server(), 'original URL restored after the filter' );
		} finally {
			delete_transient( $key );
		}
	}
);
