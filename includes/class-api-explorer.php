<?php
/**
 * In-admin REST endpoint explorer.
 *
 * Renders a "Try it" view of the REST routes most relevant to AI tools.
 * Each Try-it click goes through admin-ajax → rest_do_request() so we
 * dispatch the request in-process (no HTTP loopback, no WAF/SSL surprises).
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_API_Explorer {

	const AJAX_ACTION = 'ai_site_connector_try_rest';
	const NONCE       = 'ai_site_connector_try_rest_nonce';

	/**
	 * Curated allowlist of core WP routes — the ones AI tools hit most often.
	 *
	 * @var string[]
	 */
	private static $core_allowlist = array(
		'/wp/v2/users/me',
		'/wp/v2/posts',
		'/wp/v2/pages',
		'/wp/v2/media',
		'/wp/v2/categories',
		'/wp/v2/tags',
		'/wp/v2/settings',
	);

	public static function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Build the structured list of routes to show.
	 *
	 * @return array<int, array{namespace:string, route:string, methods:array<int,string>, description:string}>
	 */
	public static function discoverable_routes() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}
		$server = rest_get_server();
		$routes = $server->get_routes();
		$out    = array();
		foreach ( $routes as $route => $handlers ) {
			$keep = false;
			$ns   = 'core';
			if ( 0 === strpos( $route, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE ) ) {
				$keep = true;
				$ns   = 'ai-site-connector';
			} elseif ( in_array( $route, self::$core_allowlist, true ) ) {
				$keep = true;
				$ns   = 'wordpress';
			}
			if ( ! $keep ) {
				continue;
			}
			// Collapse repeated handlers into the set of accepted methods.
			$methods = array();
			$desc    = '';
			foreach ( $handlers as $h ) {
				if ( ! is_array( $h ) ) {
					continue;
				}
				if ( isset( $h['methods'] ) ) {
					foreach ( (array) $h['methods'] as $m => $on ) {
						if ( $on ) {
							$methods[ $m ] = true;
						}
					}
				}
				if ( '' === $desc && ! empty( $h['args'] ) ) {
					// Best-effort: take the description from the first arg with a label.
					foreach ( $h['args'] as $arg ) {
						if ( ! empty( $arg['description'] ) ) {
							$desc = (string) $arg['description'];
							break;
						}
					}
				}
			}
			$out[] = array(
				'namespace'   => $ns,
				'route'       => $route,
				'methods'     => array_keys( $methods ),
				'description' => $desc,
			);
		}
		// Sort: plugin namespace first, then wordpress allowlist.
		usort( $out, static function ( $a, $b ) {
			if ( $a['namespace'] === $b['namespace'] ) {
				return strcmp( $a['route'], $b['route'] );
			}
			return $a['namespace'] === 'ai-site-connector' ? -1 : 1;
		} );
		return $out;
	}

	public static function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ai-site-connector' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$method = isset( $_POST['method'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['method'] ) ) ) : 'GET';
		$route  = isset( $_POST['route'] ) ? sanitize_text_field( wp_unslash( $_POST['route'] ) ) : '';
		$body   = isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '';

		if ( '' === $route || '/' !== substr( $route, 0, 1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid route.', 'ai-site-connector' ) ), 400 );
		}
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid HTTP method.', 'ai-site-connector' ) ), 400 );
		}

		// Confine to known namespaces/allowlist to avoid being a generic REST proxy.
		$allowed = false;
		if ( 0 === strpos( $route, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE ) ) {
			$allowed = true;
		}
		foreach ( self::$core_allowlist as $allow_prefix ) {
			if ( 0 === strpos( $route, $allow_prefix ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Route is not in the explorer allowlist.', 'ai-site-connector' ) ), 400 );
		}

		$request = new WP_REST_Request( $method, $route );
		if ( '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$request->set_body_params( $decoded );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( $decoded ) );
			}
		}

		$response = rest_do_request( $request );
		$status   = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		$data     = method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		AI_Site_Connector_Audit_Log::record(
			'api_explorer_used',
			array( 'message' => sprintf(
				/* translators: 1: method, 2: route, 3: status code. */
				__( 'API explorer dispatched %1$s %2$s — HTTP %3$d.', 'ai-site-connector' ),
				$method,
				$route,
				$status
			) )
		);

		wp_send_json_success(
			array(
				'status' => $status,
				'data'   => $data,
			)
		);
	}
}
