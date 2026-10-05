<?php
/**
 * MCP HTTP transport — speaks JSON-RPC 2.0 over a single REST endpoint.
 *
 * Lets AI tools that support HTTP MCP (Claude Desktop / Cursor with the
 * appropriate config, plus any custom client) talk to this WordPress
 * install without needing a separate Node/Python MCP bridge.
 *
 * Endpoint: POST /wp-json/ai-site-connector/v1/mcp
 * Auth:     HTTP Basic Auth (Application Password), same as the rest
 *           of the plugin.
 *
 * Supported MCP methods: `initialize`, `tools/list`, `tools/call`, `ping`.
 *
 * Tools exposed (call by name via tools/call):
 *   wp_health        — plugin health check
 *   wp_site_info     — basic site metadata
 *   wp_list_posts    — list posts by status
 *   wp_get_post      — fetch a single post
 *   wp_create_post   — create a draft / published post
 *   wp_update_post   — update an existing post
 *   wp_list_pages    — list pages by status
 *   wp_list_plugins  — list installed plugins
 *   wp_list_themes   — list installed themes
 *   wp_self_test     — MCP-surface pass/warn/fail self-test
 *   wp_rest_routes   — REST route inventory
 *   wp_page_builder  — page builder detection (site + per-post)
 *   wp_redirects     — redirect plugin detection + export
 *
 * Constants:
 *   AI_SITE_CONNECTOR_MCP_DISABLE — when true, the route is not registered.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_MCP_Server {

	const SERVER_NAME          = 'ai-site-connector';
	const PROTOCOL_VERSION     = '2025-06-18';
	const JSONRPC_PARSE_ERROR  = -32700;
	const JSONRPC_INVALID_REQ  = -32600;
	const JSONRPC_METHOD_NF    = -32601;
	const JSONRPC_INVALID_PARM = -32602;
	const JSONRPC_INTERNAL     = -32603;

	public static function register_hooks() {
		if ( defined( 'AI_SITE_CONNECTOR_MCP_DISABLE' ) && AI_SITE_CONNECTOR_MCP_DISABLE ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			AI_SITE_CONNECTOR_REST_NAMESPACE,
			'/mcp',
			array(
				array(
					'methods'             => array( 'POST' ),
					'callback'            => array( __CLASS__, 'handle' ),
					// Auth is handled by core Basic Auth + Application Password.
					// We just require an authenticated user with REST access.
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
	}

	public static function permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'MCP requires authentication (Basic Auth with an Application Password).', 'ai-site-connector' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle( WP_REST_Request $request ) {
		$raw = $request->get_body();
		$msg = json_decode( $raw, true );
		if ( ! is_array( $msg ) ) {
			return new WP_REST_Response( self::jsonrpc_error( null, self::JSONRPC_PARSE_ERROR, 'Parse error' ), 200 );
		}

		// Optional: support batch arrays in a future iteration. For now, single objects.
		$id = isset( $msg['id'] ) ? $msg['id'] : null;
		if ( empty( $msg['jsonrpc'] ) || '2.0' !== $msg['jsonrpc'] || empty( $msg['method'] ) ) {
			return new WP_REST_Response( self::jsonrpc_error( $id, self::JSONRPC_INVALID_REQ, 'Invalid Request' ), 200 );
		}

		$method = (string) $msg['method'];
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		switch ( $method ) {
			case 'initialize':
				return new WP_REST_Response( self::jsonrpc_result( $id, self::initialize( $params ) ), 200 );
			case 'ping':
				return new WP_REST_Response( self::jsonrpc_result( $id, (object) array() ), 200 );
			case 'tools/list':
				return new WP_REST_Response( self::jsonrpc_result( $id, array( 'tools' => self::tools_descriptors() ) ), 200 );
			case 'tools/call':
				return self::handle_tool_call( $id, $params );
			default:
				return new WP_REST_Response( self::jsonrpc_error( $id, self::JSONRPC_METHOD_NF, 'Method not found: ' . $method ), 200 );
		}
	}

	private static function initialize( $params ) {
		unset( $params );
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'serverInfo'      => array(
				'name'    => self::SERVER_NAME,
				'version' => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? AI_SITE_CONNECTOR_VERSION : '0',
			),
			'capabilities'    => array(
				'tools' => array( 'listChanged' => false ),
			),
			'instructions'    => 'WordPress site exposed via AI Site Connector. Use tools/list to discover available operations.',
		);
	}

	private static function tools_descriptors() {
		return array(
			array(
				'name'        => 'wp_health',
				'description' => 'Plugin health check: returns role, HTTPS, REST, and App Passwords status.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			),
			array(
				'name'        => 'wp_site_info',
				'description' => 'Site name, URL, WordPress version, PHP version, active theme.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			),
			array(
				'name'        => 'wp_list_posts',
				'description' => 'List posts. Optional: status (publish/draft/any), per_page (default 10, max 100), search (string), post_type (default post).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array( 'type' => 'string' ),
						'per_page'  => array( 'type' => 'integer' ),
						'search'    => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'wp_get_post',
				'description' => 'Fetch a single post by ID. Required: id. Optional: post_type (default post).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
					),
					'required' => array( 'id' ),
				),
			),
			array(
				'name'        => 'wp_create_post',
				'description' => 'Create a post. Required: title, content. Optional: status (default draft), post_type (default post).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
					),
					'required' => array( 'title', 'content' ),
				),
			),
			array(
				'name'        => 'wp_update_post',
				'description' => 'Update a post by ID. Required: id. Optional: title, content, status, post_type.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string' ),
					),
					'required' => array( 'id' ),
				),
			),
			array(
				'name'        => 'wp_list_pages',
				'description' => 'Alias for wp_list_posts with post_type=page.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer' ),
						'search'   => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'wp_list_plugins',
				'description' => 'List installed plugins (via the plugin REST endpoint).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			),
			array(
				'name'        => 'wp_list_themes',
				'description' => 'List installed themes (via the plugin REST endpoint).',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			),
			array(
				'name'        => 'wp_self_test',
				'description' => 'Structured pass/warn/fail self-test of this MCP surface (REST, MCP route, caller capabilities, writable dirs, SEO/page-builder detection, audit log, SEO dry-run invariant). Read-only; admin only.',
				'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			),
			array(
				'name'        => 'wp_rest_routes',
				'description' => 'Inventory of registered REST routes with methods, argument metadata and permission-callback presence. Optional: namespace (exact, e.g. wp/v2). Read-only; admin only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'namespace' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'wp_page_builder',
				'description' => 'Detect page builders site-wide, plus per-post evidence for up to 100 post_ids. Read-only; admin only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 100,
						),
					),
				),
			),
			array(
				'name'        => 'wp_redirects',
				'description' => 'Detect redirect plugins (Rank Math, Redirection, AIOSEO, Yoast Premium) and export their redirects. Optional: limit (1-1000, default 500), offset. Read-only; admin only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'  => array( 'type' => 'integer' ),
						'offset' => array( 'type' => 'integer' ),
					),
				),
			),
		);
	}

	private static function handle_tool_call( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		if ( '' === $name ) {
			return new WP_REST_Response( self::jsonrpc_error( $id, self::JSONRPC_INVALID_PARM, 'Missing tool name' ), 200 );
		}

		try {
			$content = self::dispatch_tool( $name, $args );
		} catch ( AI_Site_Connector_MCP_Tool_Error $e ) {
			// Tool-level failure (denied, invalid input): per the MCP spec this
			// is a successful JSON-RPC result carrying isError=true.
			return new WP_REST_Response(
				self::jsonrpc_result(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => (string) wp_json_encode( $e->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
							),
						),
						'isError' => true,
					)
				),
				200
			);
		} catch ( Exception $e ) {
			AI_Site_Connector_Audit_Log::record(
				'mcp_tool_failed',
				array( 'message' => sprintf(
					/* translators: 1: tool name, 2: error. */
					__( 'MCP tool %1$s failed: %2$s', 'ai-site-connector' ),
					$name,
					$e->getMessage()
				) )
			);
			return new WP_REST_Response( self::jsonrpc_error( $id, self::JSONRPC_INTERNAL, 'Tool error: ' . $e->getMessage() ), 200 );
		}

		AI_Site_Connector_Audit_Log::record(
			'mcp_tool_called',
			array( 'message' => sprintf(
				/* translators: 1: tool name, 2: arg count. */
				__( 'MCP tool %1$s called with %2$d args.', 'ai-site-connector' ),
				$name,
				count( $args )
			) )
		);

		// MCP tools/call response shape: { content: [{ type: 'text', text: '...' }], isError: false }
		return new WP_REST_Response(
			self::jsonrpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => is_string( $content ) ? $content : (string) wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
						),
					),
					'isError' => false,
				)
			),
			200
		);
	}

	/**
	 * Route a tool name to its underlying implementation.
	 *
	 * Reuses the REST controllers by issuing internal rest_do_request()
	 * calls so we get capability checks and existing schema validation
	 * for free.
	 *
	 * @param string $name
	 * @param array  $args
	 * @return mixed Tool result (typically array or string).
	 */
	private static function dispatch_tool( $name, array $args ) {
		switch ( $name ) {
			case 'wp_health':
				return self::dispatch( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/health' );
			case 'wp_site_info':
				return self::dispatch( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/site-info' );
			case 'wp_list_plugins':
				return self::dispatch( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/plugins' );
			case 'wp_list_themes':
				return self::dispatch( 'GET', '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . '/themes' );
			case 'wp_list_posts':
				return self::list_posts( $args, 'post' );
			case 'wp_list_pages':
				$args['post_type'] = 'page';
				return self::list_posts( $args, 'page' );
			case 'wp_get_post':
				$pt = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
				$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
				if ( $id <= 0 ) {
					throw new InvalidArgumentException( 'id required' );
				}
				return self::dispatch( 'GET', '/wp/v2/' . self::pt_rest_base( $pt ) . '/' . $id );
			case 'wp_create_post':
				$pt = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
				return self::dispatch(
					'POST',
					'/wp/v2/' . self::pt_rest_base( $pt ),
					array(
						'title'   => isset( $args['title'] ) ? (string) $args['title'] : '',
						'content' => isset( $args['content'] ) ? (string) $args['content'] : '',
						'status'  => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft',
					)
				);
			case 'wp_update_post':
				$pt = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
				$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
				if ( $id <= 0 ) {
					throw new InvalidArgumentException( 'id required' );
				}
				$body = array();
				foreach ( array( 'title', 'content', 'status' ) as $k ) {
					if ( isset( $args[ $k ] ) ) {
						$body[ $k ] = (string) $args[ $k ];
					}
				}
				return self::dispatch( 'POST', '/wp/v2/' . self::pt_rest_base( $pt ) . '/' . $id, $body );
			case 'wp_self_test':
				return self::dispatch_checked( 'GET', '/diagnostics/self-test' );
			case 'wp_rest_routes':
				return self::dispatch_checked( 'GET', '/diagnostics/rest-routes', self::pick( $args, array( 'namespace' ) ) );
			case 'wp_page_builder':
				return self::dispatch_checked( 'GET', '/diagnostics/page-builder', self::pick( $args, array( 'post_ids' ) ) );
			case 'wp_redirects':
				return self::dispatch_checked( 'GET', '/diagnostics/redirects', self::pick( $args, array( 'limit', 'offset' ) ) );
			default:
				throw new InvalidArgumentException( 'Unknown tool: ' . esc_html( $name ) );
		}
	}

	private static function pick( array $args, array $keys ) {
		return array_intersect_key( $args, array_flip( $keys ) );
	}

	/**
	 * Dispatch to one of this plugin's routes and surface HTTP errors as an
	 * MCP tool error instead of a "successful" error payload.
	 *
	 * @throws AI_Site_Connector_MCP_Tool_Error When the route returns >= 400.
	 */
	private static function dispatch_checked( $method, $route, array $params = array() ) {
		$req = new WP_REST_Request( $method, '/' . AI_SITE_CONNECTOR_REST_NAMESPACE . $route );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_body_params( $params );
		}
		$resp = rest_do_request( $req );
		$data = $resp->get_data();
		if ( $resp->get_status() >= 400 ) {
			throw new AI_Site_Connector_MCP_Tool_Error(
				array(
					'status'  => (int) $resp->get_status(),
					'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'error',
					'message' => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Request failed.',
				)
			);
		}
		return $data;
	}

	private static function list_posts( array $args, $default_pt ) {
		$pt        = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : $default_pt;
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 10;
		$status    = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish';
		$query     = array( 'per_page' => $per_page, 'status' => $status );
		if ( isset( $args['search'] ) && '' !== (string) $args['search'] ) {
			$query['search'] = (string) $args['search'];
		}
		$path = '/wp/v2/' . self::pt_rest_base( $pt );
		$req  = new WP_REST_Request( 'GET', $path );
		foreach ( $query as $k => $v ) {
			$req->set_query_params( array_merge( $req->get_query_params(), array( $k => $v ) ) );
		}
		$resp = rest_do_request( $req );
		return method_exists( $resp, 'get_data' ) ? $resp->get_data() : null;
	}

	private static function dispatch( $method, $path, $body = null ) {
		$req = new WP_REST_Request( $method, $path );
		if ( null !== $body ) {
			$req->set_body_params( $body );
		}
		$resp = rest_do_request( $req );
		return method_exists( $resp, 'get_data' ) ? $resp->get_data() : null;
	}

	private static function pt_rest_base( $pt ) {
		// Map common post types to REST base names. Custom post types
		// would need the plugin's rest_base — not exposed here.
		switch ( $pt ) {
			case 'page':
				return 'pages';
			case 'attachment':
			case 'media':
				return 'media';
			case 'post':
			default:
				return 'posts';
		}
	}

	private static function jsonrpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function jsonrpc_error( $id, $code, $message, $data = null ) {
		$err = array( 'code' => (int) $code, 'message' => (string) $message );
		if ( null !== $data ) {
			$err['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $err,
		);
	}
}

/**
 * Tool-level failure carried back to the client as `isError: true`.
 */
class AI_Site_Connector_MCP_Tool_Error extends Exception {

	/** @var array JSON-safe error payload: { status, code, message }. */
	public $payload;

	public function __construct( array $payload ) {
		parent::__construct( isset( $payload['message'] ) ? (string) $payload['message'] : 'Tool error' );
		$this->payload = $payload;
	}
}
