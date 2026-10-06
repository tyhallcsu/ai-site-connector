<?php
/**
 * OpenAPI path parameters (#120): every {template} variable has exactly one
 * required `in: path` parameter; other arguments stay in the query.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'openapi: template variables are required path parameters; others stay in the query (#120)',
	function () {
		$doc = AI_Site_Connector_OpenAPI::generate();
		asc_assert( isset( $doc['paths']['/content/snapshots/{id}'] ), 'snapshot route missing from the document' );

		$templated = 0;
		foreach ( $doc['paths'] as $path => $operations ) {
			preg_match_all( '/\{([^}]+)\}/', $path, $vars );
			foreach ( $operations as $method => $op ) {
				$params      = isset( $op['parameters'] ) ? $op['parameters'] : array();
				$path_params = array();
				foreach ( $params as $p ) {
					if ( 'path' === $p['in'] ) {
						asc_assert( true === $p['required'], "{$method} {$path}: path parameter {$p['name']} not required" );
						$path_params[] = $p['name'];
					}
				}
				sort( $path_params );
				$expected = $vars[1];
				sort( $expected );
				asc_assert_same( $expected, $path_params, "{$method} {$path}: path parameters" );
				if ( $expected ) {
					++$templated;
				}
			}
		}
		asc_assert( $templated >= 1, 'no templated operations were checked' );

		$inventory = $doc['paths']['/export/content-inventory']['get']['parameters'];
		$limit     = wp_list_filter( $inventory, array( 'name' => 'limit' ) );
		asc_assert_same( 'query', reset( $limit )['in'], 'query arguments stay in the query' );
	}
);
