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
 *   wp_content_inventory — paginated posts/pages/CPT inventory
 *   wp_media_audit       — media SEO/hygiene audit
 *   wp_media_duplicates  — duplicate media by filename and hash
 *   wp_broken_links      — offline broken internal link scan
 *   wp_export_bundle     — deterministic manifest bundle
 *   wp_update_content    — safe content update (dry-run by default)
 *   wp_rollback_content  — conflict-aware rollback of an update
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
		// Defence in depth behind the REST gate: several MCP tools dispatch
		// straight to core /wp/v2 routes.
		if ( AI_Site_Connector_Permissions::is_disabled() ) {
			return new WP_Error( 'ai_site_connector_disabled', __( 'AI Site Connector is disabled on this site by an administrator.', 'ai-site-connector' ), array( 'status' => 503 ) );
		}
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
				'description' => 'List posts. Optional: status (publish/draft/any), per_page (default 10, max 100), search (string), post_type (default post; any REST-enabled type, e.g. page or attachment; unknown types are rejected).',
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
				'description' => 'Fetch a single post by ID. Required: id. Optional: post_type (default post; any REST-enabled type, e.g. page or attachment; unknown types are rejected).',
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
				'description' => 'Create a post. Required: title, content. Optional: status (default draft), post_type (default post; any REST-enabled type; unknown types are rejected). Requires the write_content permission (off by default); blocked in read-only mode.',
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
				'description' => 'Update a post by ID. Required: id. Optional: title, content, status, post_type (REST-enabled types only). Requires the write_content permission (off by default); blocked in read-only mode. Prefer wp_update_content (dry-run, snapshot, rollback).',
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
				'name'        => 'wp_content_inventory',
				'description' => 'Paginated inventory of posts, pages and custom post types with taxonomy terms and SEO fields. Optional: post_type and status (comma-separated), modified_after/modified_before (UTC), limit (1-500, default 100), offset, include_terms, include_seo, format (json|csv). Returns total and next_offset. Read-only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'       => array( 'type' => 'string' ),
						'status'          => array( 'type' => 'string' ),
						'modified_after'  => array( 'type' => 'string' ),
						'modified_before' => array( 'type' => 'string' ),
						'limit'           => array( 'type' => 'integer' ),
						'offset'          => array( 'type' => 'integer' ),
						'include_terms'   => array( 'type' => 'boolean' ),
						'include_seo'     => array( 'type' => 'boolean' ),
						'format'          => array(
							'type' => 'string',
							'enum' => array( 'json', 'csv' ),
						),
					),
				),
			),
			array(
				'name'        => 'wp_media_audit',
				'description' => 'Audit media for missing alt/title/caption/description, unattached, oversized dimensions or bytes, suspicious filenames, missing files. Optional: limit (1-500), offset, mime (image|all), only_issues (default true), max_dimension, max_bytes. Read-only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'limit'         => array( 'type' => 'integer' ),
						'offset'        => array( 'type' => 'integer' ),
						'mime'          => array(
							'type' => 'string',
							'enum' => array( 'image', 'all' ),
						),
						'only_issues'   => array( 'type' => 'boolean' ),
						'max_dimension' => array( 'type' => 'integer' ),
						'max_bytes'     => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'wp_media_duplicates',
				'description' => 'Find duplicate media across the whole library by filename and SHA-256 content hash. Resumable and bounded per call: repeat with the returned scan_id until complete is true; groups are reported only when complete. Never deletes. Optional: max_scan (attachments per call, 1-20000, default 5000), scan_id, max_file_bytes. Read-only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'max_scan'       => array( 'type' => 'integer' ),
						'scan_id'        => array( 'type' => 'string' ),
						'max_file_bytes' => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'wp_broken_links',
				'description' => 'Find broken internal links in post/page/CPT content, resolved offline (no HTTP requests; external links ignored). Optional: post_type, status (default publish), limit (posts, 1-200, default 50), offset, max_links (1-5000), only_broken (default true). Returns next_offset. Read-only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'   => array( 'type' => 'string' ),
						'status'      => array( 'type' => 'string' ),
						'limit'       => array( 'type' => 'integer' ),
						'offset'      => array( 'type' => 'integer' ),
						'max_links'   => array( 'type' => 'integer' ),
						'only_broken' => array( 'type' => 'boolean' ),
					),
				),
			),
			array(
				'name'        => 'wp_export_bundle',
				'description' => 'Deterministic, GitHub-ready manifest bundle (site-inventory, media-seo-audit, duplicate-media, broken-links, redirects, plugin-builder-detection, rest-routes, mcp-self-test) plus manifest_index with sha256 per file. Optional: max_items (per section, 1-5000, default 1000), sections (comma-separated file names). Commit the files yourself; WordPress never pushes anywhere. Read-only; admin only.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'max_items' => array( 'type' => 'integer' ),
						'sections'  => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'wp_update_content',
				'description' => 'Safely update one post: title, excerpt, content, slug, status (draft/pending/publish/private), featured_image (attachment id, 0 removes), terms ({taxonomy: [id|slug]}, existing terms only), seo ({title, description, canonical, og_*}). DRY-RUN BY DEFAULT — returns the before/after diff. Set dry_run=false to write (requires the write_content permission; SEO also update_seo); a rollback snapshot_id is returned. Optional expected_modified_gmt for concurrency. Never trashes or deletes.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'changes' ),
					'properties' => array(
						'post_id'               => array( 'type' => 'integer' ),
						'changes'               => array( 'type' => 'object' ),
						'dry_run'               => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'expected_modified_gmt' => array( 'type' => 'string' ),
					),
				),
			),
			array(
				'name'        => 'wp_rollback_content',
				'description' => 'Roll back an update made with wp_update_content using its snapshot_id. Dry-run by default; refuses with reason=conflict if any of those fields changed since, so later edits are never overwritten.',
				'inputSchema' => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'snapshot_id' ),
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'snapshot_id' => array( 'type' => 'string' ),
						'dry_run'     => array(
							'type'    => 'boolean',
							'default' => true,
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

		if ( AI_Site_Connector_Permissions::is_disabled() ) {
			return new WP_REST_Response( self::jsonrpc_error( $id, self::JSONRPC_INTERNAL, 'AI Site Connector is disabled on this site.' ), 503 );
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
				return self::dispatch( 'GET', self::post_type_route( $pt ) . '/' . $id );
			case 'wp_create_post':
				self::require_write_content();
				$pt = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
				return self::dispatch(
					'POST',
					self::post_type_route( $pt ),
					array(
						'title'   => isset( $args['title'] ) ? (string) $args['title'] : '',
						'content' => isset( $args['content'] ) ? (string) $args['content'] : '',
						'status'  => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft',
					)
				);
			case 'wp_update_post':
				self::require_write_content();
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
				return self::dispatch( 'POST', self::post_type_route( $pt ) . '/' . $id, $body );
			case 'wp_self_test':
				return self::dispatch_checked( 'GET', '/diagnostics/self-test' );
			case 'wp_rest_routes':
				return self::dispatch_checked( 'GET', '/diagnostics/rest-routes', self::pick( $args, array( 'namespace' ) ) );
			case 'wp_page_builder':
				return self::dispatch_checked( 'GET', '/diagnostics/page-builder', self::pick( $args, array( 'post_ids' ) ) );
			case 'wp_content_inventory':
				return self::dispatch_checked( 'GET', '/export/content-inventory', self::pick( $args, array( 'post_type', 'status', 'modified_after', 'modified_before', 'limit', 'offset', 'include_terms', 'include_seo', 'format' ) ) );
			case 'wp_media_audit':
				return self::dispatch_checked( 'GET', '/media/audit', self::pick( $args, array( 'limit', 'offset', 'mime', 'only_issues', 'max_dimension', 'max_bytes' ) ) );
			case 'wp_media_duplicates':
				return self::dispatch_checked( 'GET', '/media/duplicates', self::pick( $args, array( 'max_scan', 'scan_id', 'max_file_bytes' ) ) );
			case 'wp_broken_links':
				return self::dispatch_checked( 'GET', '/content/broken-links', self::pick( $args, array( 'post_type', 'status', 'limit', 'offset', 'max_links', 'only_broken' ) ) );
			case 'wp_export_bundle':
				return self::dispatch_checked( 'GET', '/export/bundle', self::pick( $args, array( 'max_items', 'sections' ) ) );
			case 'wp_update_content':
				return self::dispatch_checked( 'POST', '/content/update', self::pick( $args, array( 'post_id', 'changes', 'dry_run', 'expected_modified_gmt' ) ) );
			case 'wp_rollback_content':
				return self::dispatch_checked( 'POST', '/content/rollback', self::pick( $args, array( 'post_id', 'snapshot_id', 'dry_run' ) ) );
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
		return self::checked_data( rest_do_request( $req ) );
	}

	/**
	 * Response data, or an MCP tool error when the request failed (#111).
	 *
	 * @param WP_REST_Response|WP_Error $resp Result of rest_do_request().
	 * @return mixed
	 * @throws AI_Site_Connector_MCP_Tool_Error When the route returns >= 400.
	 */
	private static function checked_data( $resp ) {
		if ( is_wp_error( $resp ) ) {
			// WordPress < 5.7 returns rest_pre_dispatch errors unconverted.
			$err_data = $resp->get_error_data();
			$resp     = new WP_REST_Response(
				array(
					'code'    => $resp->get_error_code(),
					'message' => $resp->get_error_message(),
				),
				is_array( $err_data ) && isset( $err_data['status'] ) ? (int) $err_data['status'] : 500
			);
		}
		$data = $resp->get_data();
		if ( $resp->get_status() >= 400 ) {
			// The payload is JSON-encoded into the MCP result, never echoed as HTML.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new AI_Site_Connector_MCP_Tool_Error(
				array(
					'status'  => (int) $resp->get_status(),
					'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'error',
					'message' => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'Request failed.',
				)
			);
			// phpcs:enable
		}
		return $data;
	}

	/**
	 * Writes through MCP honour the write_content tool permission and
	 * read-only mode, like every other plugin write path.
	 *
	 * @throws AI_Site_Connector_MCP_Tool_Error When writes are not allowed.
	 */
	private static function require_write_content() {
		$gate = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_WRITE_CONTENT );
		if ( is_wp_error( $gate ) ) {
			$data = $gate->get_error_data();
			// JSON-encoded into the MCP result, never echoed as HTML.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new AI_Site_Connector_MCP_Tool_Error(
				array(
					'status'  => 403,
					'code'    => $gate->get_error_code(),
					'message' => $gate->get_error_message(),
					'reason'  => is_array( $data ) && isset( $data['reason'] ) ? (string) $data['reason'] : '',
				)
			);
			// phpcs:enable
		}
	}

	private static function list_posts( array $args, $default_pt ) {
		$pt        = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : $default_pt;
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 10;
		$status    = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish';
		$query     = array( 'per_page' => $per_page, 'status' => $status );
		if ( isset( $args['search'] ) && '' !== (string) $args['search'] ) {
			$query['search'] = (string) $args['search'];
		}
		$path = self::post_type_route( $pt );
		$req  = new WP_REST_Request( 'GET', $path );
		foreach ( $query as $k => $v ) {
			$req->set_query_params( array_merge( $req->get_query_params(), array( $k => $v ) ) );
		}
		return self::checked_data( rest_do_request( $req ) );
	}

	private static function dispatch( $method, $path, $body = null ) {
		$req = new WP_REST_Request( $method, $path );
		if ( null !== $body ) {
			$req->set_body_params( $body );
		}
		return self::checked_data( rest_do_request( $req ) );
	}

	/**
	 * REST collection route for a post type: only types registered with
	 * show_in_rest, using their own namespace and base. Anything else is
	 * refused instead of silently becoming a blog post (#118).
	 *
	 * @param string $pt Post type slug; "media" is accepted for attachment.
	 * @return string Route such as /wp/v2/posts.
	 * @throws AI_Site_Connector_MCP_Tool_Error When the type is unknown or not REST-enabled.
	 */
	private static function post_type_route( $pt ) {
		$pt  = 'media' === $pt ? 'attachment' : (string) $pt;
		$obj = get_post_type_object( $pt );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			// JSON-encoded into the MCP result, never echoed as HTML.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new AI_Site_Connector_MCP_Tool_Error(
				array(
					'status'  => 400,
					'code'    => 'asc_unsupported_post_type',
					'message' => sprintf( 'Unsupported post_type "%s": use a post type registered with show_in_rest, e.g. post, page or attachment.', $pt ),
				)
			);
			// phpcs:enable
		}
		$base      = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;
		$namespace = ! empty( $obj->rest_namespace ) ? $obj->rest_namespace : 'wp/v2';
		return '/' . trim( $namespace, '/' ) . '/' . trim( $base, '/' );
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
