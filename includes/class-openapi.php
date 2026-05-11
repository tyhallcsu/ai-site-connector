<?php
/**
 * OpenAPI 3 spec generator for /wp-json/ai-site-connector/v1/*.
 *
 * Walks the live REST route registry instead of being hand-maintained,
 * so the spec stays accurate as new routes are added. Each generated
 * operation pulls method, parameters, and an optional `summary` /
 * `description` from the existing args declarations in
 * class-rest-controller.php.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_OpenAPI {

	const CACHE_TRANSIENT = 'ai_site_connector_openapi_cache';
	const CACHE_TTL       = HOUR_IN_SECONDS;

	public static function register_hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			AI_SITE_CONNECTOR_REST_NAMESPACE,
			'/openapi.json',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_spec' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function serve_spec() {
		$cached = get_site_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) && $cached['version'] === AI_SITE_CONNECTOR_VERSION ) {
			$response = new WP_REST_Response( $cached['spec'], 200 );
		} else {
			$spec = self::generate();
			set_site_transient(
				self::CACHE_TRANSIENT,
				array( 'version' => AI_SITE_CONNECTOR_VERSION, 'spec' => $spec ),
				self::CACHE_TTL
			);
			$response = new WP_REST_Response( $spec, 200 );
		}
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	/**
	 * Build the OpenAPI 3.0 document from the live route registry.
	 *
	 * @return array
	 */
	public static function generate() {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$paths     = array();

		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			foreach ( $routes as $route => $handlers ) {
				if ( 0 !== strpos( $route, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/' ) ) {
					continue;
				}
				$operations = self::collect_operations( $handlers );
				if ( empty( $operations ) ) {
					continue;
				}
				$path                  = self::route_to_openapi_path( substr( $route, strlen( '/' . AI_SITE_CONNECTOR_REST_NAMESPACE ) ) );
				$paths[ $path ]        = $operations;
			}
		}
		ksort( $paths );

		return array(
			'openapi' => '3.0.3',
			'info'    => array(
				'title'       => 'AI Site Connector REST API',
				'version'     => AI_SITE_CONNECTOR_VERSION,
				'description' => 'REST endpoints exposed by the AI Site Connector WordPress plugin. All endpoints authenticate via HTTP Basic Auth using a WordPress Application Password.',
				'contact'     => array( 'url' => 'https://github.com/tyhallcsu/ai-site-connector' ),
				'license'     => array( 'name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT' ),
			),
			'servers' => array(
				array(
					'url'         => trailingslashit( rest_url() ) . AI_SITE_CONNECTOR_REST_NAMESPACE,
					'description' => $site_host ? $site_host : home_url(),
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'basicAuth' => array(
						'type'        => 'http',
						'scheme'      => 'basic',
						'description' => 'WordPress Application Password (Basic Auth). Per-password scopes / IP allowlist / expiry may further restrict access.',
					),
				),
			),
			'security' => array( array( 'basicAuth' => array() ) ),
			'paths'    => $paths,
		);
	}

	private static function collect_operations( $handlers ) {
		$ops = array();
		foreach ( $handlers as $h ) {
			if ( ! is_array( $h ) ) {
				continue;
			}
			$methods = array();
			if ( isset( $h['methods'] ) ) {
				foreach ( (array) $h['methods'] as $m => $on ) {
					if ( $on ) {
						$methods[ strtolower( $m ) ] = true;
					}
				}
			}
			$summary     = isset( $h['summary'] ) ? (string) $h['summary'] : '';
			$description = isset( $h['description'] ) ? (string) $h['description'] : '';
			$parameters  = self::args_to_parameters( isset( $h['args'] ) && is_array( $h['args'] ) ? $h['args'] : array(), $methods );
			$op_body     = array(
				'summary'     => $summary,
				'description' => $description,
				'responses'   => array(
					'200' => array( 'description' => 'OK' ),
					'401' => array( 'description' => 'Authentication required' ),
					'403' => array( 'description' => 'Forbidden (scope / IP / capability)' ),
				),
			);
			if ( ! empty( $parameters ) ) {
				$op_body['parameters'] = $parameters;
			}
			foreach ( array_keys( $methods ) as $method ) {
				$ops[ $method ] = $op_body;
			}
		}
		return $ops;
	}

	/**
	 * Convert args declarations into OpenAPI parameter objects. GET-only
	 * args go in `query`; POST/PUT/PATCH bodies fall back to query for now
	 * (existing routes use inline args, no request bodies needed for the
	 * AI Site Connector namespace).
	 */
	private static function args_to_parameters( array $args, array $methods ) {
		$out  = array();
		$in   = isset( $methods['get'] ) || isset( $methods['delete'] ) ? 'query' : 'query';
		foreach ( $args as $name => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$param = array(
				'name'     => (string) $name,
				'in'       => $in,
				'required' => ! empty( $spec['required'] ),
			);
			$schema = array();
			if ( isset( $spec['type'] ) ) {
				$schema['type'] = is_array( $spec['type'] ) ? reset( $spec['type'] ) : (string) $spec['type'];
			}
			if ( isset( $spec['default'] ) ) {
				$schema['default'] = $spec['default'];
			}
			if ( isset( $spec['enum'] ) ) {
				$schema['enum'] = $spec['enum'];
			}
			if ( isset( $spec['items'] ) ) {
				$schema['items'] = $spec['items'];
			}
			if ( ! empty( $schema ) ) {
				$param['schema'] = $schema;
			}
			if ( ! empty( $spec['description'] ) ) {
				$param['description'] = (string) $spec['description'];
			}
			$out[] = $param;
		}
		return $out;
	}

	/**
	 * Convert a WP route pattern like '/posts/(?P<id>\d+)' into the
	 * OpenAPI shape '/posts/{id}'.
	 */
	private static function route_to_openapi_path( $route ) {
		return preg_replace( '#\(\?P<([^>]+)>[^)]+\)#', '{$1}', $route );
	}
}
