<?php
/**
 * Credentials scope presets do what their labels say (#113).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'scope presets: create/update presets allow item updates but not deletes or other collections (#113)',
	function () {
		$presets = new ReflectionMethod( 'AI_Site_Connector_Admin_Page', 'scope_presets' );
		if ( PHP_VERSION_ID < 80100 ) {
			$presets->setAccessible( true ); // Needed before PHP 8.1; deprecated no-op later.
		}
		$scope_for = static function ( $label ) use ( $presets ) {
			foreach ( $presets->invoke( null ) as $preset ) {
				if ( $label === $preset['label'] ) {
					return array( array( 'method' => $preset['method'], 'route' => $preset['route'] ) );
				}
			}
			asc_assert( false, "preset {$label} missing" );
			return array();
		};
		$cases = array(
			'Create/update posts' => array( 'posts', 'pages' ),
			'Create/update pages' => array( 'pages', 'posts' ),
		);
		foreach ( $cases as $label => $collections ) {
			list( $own, $other ) = $collections;
			$scopes = $scope_for( $label );
			$match  = static function ( $method, $route ) use ( $scopes ) {
				return AI_Site_Connector_App_Password_Meta::route_matches_scopes( $method, $route, $scopes );
			};
			asc_assert( $match( 'POST', "/wp/v2/{$own}" ), "{$label}: create denied" );
			asc_assert( $match( 'POST', "/wp/v2/{$own}/123" ), "{$label}: update denied" );
			asc_assert( ! $match( 'DELETE', "/wp/v2/{$own}/123" ), "{$label}: delete allowed" );
			asc_assert( ! $match( 'GET', "/wp/v2/{$own}/123" ), "{$label}: read allowed by a write preset" );
			asc_assert( ! $match( 'POST', "/wp/v2/{$other}/123" ), "{$label}: other collection allowed" );
			asc_assert( ! $match( 'POST', '/wp/v2/users/1' ), "{$label}: unrelated route allowed" );
		}
		// Credentials issued before the fix keep their exact scope (no silent widening).
		$legacy = array( array( 'method' => 'POST', 'route' => '/wp/v2/posts' ) );
		asc_assert( ! AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'POST', '/wp/v2/posts/123', $legacy ), 'stored exact scope was widened' );
	}
);
