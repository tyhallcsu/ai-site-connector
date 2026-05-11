<?php
/**
 * Connection-pack format generators.
 *
 * Turns the generic connection pack array into ready-to-paste snippets for
 * specific AI tools, scripting languages, and no-code automation platforms.
 *
 * Each format returns an associative array:
 *   id       (string)  — slug, used for DOM ids and CSS classes
 *   label    (string)  — UI tab label
 *   language (string)  — informal hint for syntax highlighting (unused today,
 *                        reserved for a future Prism/Highlight.js integration)
 *   hint     (string)  — plain-text instructions shown above the code block
 *   code     (string)  — raw snippet, HTML-escape before rendering
 *
 * No HTML is emitted from here — the admin page is responsible for escaping
 * and layout. Keeping the formatter pure-data makes it reusable from WP-CLI
 * and future export endpoints.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Connection_Formats {

	/**
	 * Build the full ordered list of formats for a given pack.
	 *
	 * @param array $pack Connection pack from AI_Site_Connector_Admin_Page::build_connection_pack().
	 * @return array<int, array{id:string, label:string, language:string, hint:string, code:string}>
	 */
	public static function all( array $pack ) {
		$user        = isset( $pack['username'] ) ? (string) $pack['username'] : '';
		$pass        = isset( $pack['application_password'] ) ? (string) $pack['application_password'] : '';
		$site_url    = isset( $pack['site_url'] ) ? (string) $pack['site_url'] : '';
		$rest_base   = isset( $pack['rest_api_base'] ) ? (string) $pack['rest_api_base'] : '';
		$health_url  = isset( $pack['plugin_health_endpoint'] ) ? (string) $pack['plugin_health_endpoint'] : '';
		$site_host   = isset( $pack['site_host'] ) ? (string) $pack['site_host'] : 'wordpress';
		$server_name = self::sanitize_server_name( $site_host );

		return array(
			self::claude_desktop_mcp( $server_name, $rest_base, $user, $pass ),
			self::cursor_mcp( $server_name, $rest_base, $user, $pass ),
			self::n8n_instructions( $site_url, $user, $pass ),
			self::curl_snippet( $rest_base, $user, $pass ),
			self::python_snippet( $rest_base, $user, $pass ),
			self::node_snippet( $rest_base, $user, $pass ),
			self::generic_json( $pack ),
			self::claude_code_instructions( $rest_base, $user, $health_url ),
		);
	}

	/* ---------------------------------------------------------------------
	 * MCP — Claude Desktop & Cursor share the same JSON shape.
	 *
	 * The snippet drives `npx -y mcp-remote <url> --header Authorization:Basic <b64>`,
	 * which proxies the plugin's HTTP MCP endpoint over stdio. No local install,
	 * no path placeholder, no env vars — paste and restart. The bundled stdio
	 * bridge in examples/mcp-server/ is still available for air-gapped use; see
	 * examples/mcp-server/README.md.
	 * ------------------------------------------------------------------ */

	private static function claude_desktop_mcp( $server_name, $rest_base, $user, $pass ) {
		$config = self::mcp_config_shape( $server_name, $rest_base, $user, $pass );
		$code   = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return array(
			'id'       => 'claude_desktop_mcp',
			'label'    => __( 'Claude Desktop (MCP)', 'ai-site-connector' ),
			'language' => 'json',
			'hint'     => __(
				'Paste into Claude Desktop\'s MCP config and restart the app — no other setup. The snippet uses npm\'s mcp-remote package to proxy this site\'s HTTP MCP endpoint (/wp-json/ai-site-connector/v1/mcp), so there\'s no path to fill in and no npm install step. Requires Node 18+ on the machine running Claude Desktop. Config path: macOS = ~/Library/Application Support/Claude/claude_desktop_config.json, Windows = %APPDATA%\\Claude\\claude_desktop_config.json.',
				'ai-site-connector'
			),
			'code'     => $code,
		);
	}

	private static function cursor_mcp( $server_name, $rest_base, $user, $pass ) {
		$config = self::mcp_config_shape( $server_name, $rest_base, $user, $pass );
		$code   = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return array(
			'id'       => 'cursor_mcp',
			'label'    => __( 'Cursor / VS Code (MCP)', 'ai-site-connector' ),
			'language' => 'json',
			'hint'     => __(
				'Save to .cursor/mcp.json in your project root, or to ~/.cursor/mcp.json for a global Cursor config (VS Code with Continue or MCP extensions accepts the same shape). The snippet uses npm\'s mcp-remote package to proxy this site\'s HTTP MCP endpoint — no path placeholder, no npm install. Requires Node 18+.',
				'ai-site-connector'
			),
			'code'     => $code,
		);
	}

	private static function mcp_config_shape( $server_name, $rest_base, $user, $pass ) {
		$mcp_url    = rtrim( (string) $rest_base, '/' ) . '/mcp';
		$basic_auth = 'Basic ' . base64_encode( $user . ':' . $pass );

		return array(
			'mcpServers' => array(
				$server_name => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						'mcp-remote',
						$mcp_url,
						'--header',
						'Authorization:' . $basic_auth,
					),
				),
			),
		);
	}

	private static function sanitize_server_name( $host ) {
		$name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $host );
		$name = trim( (string) $name, '-' );
		if ( '' === $name ) {
			$name = 'wordpress';
		}
		return $name;
	}

	/* ---------------------------------------------------------------------
	 * No-code automation tools.
	 * ------------------------------------------------------------------ */

	private static function n8n_instructions( $site_url, $user, $pass ) {
		$lines = array(
			'# n8n — built-in WordPress credential',
			'  Credentials → New → "WordPress API"',
			'    Site URL: ' . $site_url,
			'    Username: ' . $user,
			'    Password: ' . $pass,
			'',
			'# Make.com (Integromat) — WordPress module',
			'  Add a WordPress connection → "Basic Auth"',
			'    URL:      ' . $site_url,
			'    Username: ' . $user,
			'    Password: ' . $pass,
			'',
			'# Zapier — WordPress integration',
			'  Connect Account → Basic Auth credentials',
			'    Website URL: ' . $site_url,
			'    Username:    ' . $user,
			'    Password:    ' . $pass,
			'',
			'Tip: the Application Password is shown with spaces for readability;',
			'WordPress accepts it with or without the spaces.',
		);
		return array(
			'id'       => 'n8n_makecom_zapier',
			'label'    => __( 'n8n / Make.com / Zapier', 'ai-site-connector' ),
			'language' => 'text',
			'hint'     => __(
				'Plain-text instructions for the three most common no-code automation platforms. All three accept WordPress Basic Auth with an Application Password.',
				'ai-site-connector'
			),
			'code'     => implode( "\n", $lines ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Scripting languages.
	 * ------------------------------------------------------------------ */

	private static function curl_snippet( $rest_base, $user, $pass ) {
		$code  = "curl -s -u '" . $user . ':' . $pass . "' \\\n";
		$code .= "  '" . $rest_base . "wp/v2/users/me'";
		return array(
			'id'       => 'curl',
			'label'    => __( 'curl', 'ai-site-connector' ),
			'language' => 'bash',
			'hint'     => __( 'Quickest way to verify the credential works. Should return your user record as JSON.', 'ai-site-connector' ),
			'code'     => $code,
		);
	}

	private static function python_snippet( $rest_base, $user, $pass ) {
		$code  = "import requests\n";
		$code .= "\n";
		$code .= "r = requests.get(\n";
		$code .= "    \"" . $rest_base . "wp/v2/users/me\",\n";
		$code .= "    auth=(\"" . $user . "\", \"" . $pass . "\"),\n";
		$code .= "    timeout=15,\n";
		$code .= ")\n";
		$code .= "r.raise_for_status()\n";
		$code .= "print(r.json())";
		return array(
			'id'       => 'python',
			'label'    => __( 'Python (requests)', 'ai-site-connector' ),
			'language' => 'python',
			'hint'     => __( 'Standard `requests` library with HTTP Basic Auth. Drop into a script or notebook.', 'ai-site-connector' ),
			'code'     => $code,
		);
	}

	private static function node_snippet( $rest_base, $user, $pass ) {
		$code  = "const auth = 'Basic ' + Buffer.from('" . $user . ":" . $pass . "').toString('base64');\n";
		$code .= "const r = await fetch('" . $rest_base . "wp/v2/users/me', {\n";
		$code .= "  headers: { Authorization: auth },\n";
		$code .= "});\n";
		$code .= "console.log(r.status, await r.json());";
		return array(
			'id'       => 'node',
			'label'    => __( 'Node.js (fetch)', 'ai-site-connector' ),
			'language' => 'javascript',
			'hint'     => __( 'Native fetch (Node 18+ or any modern browser). Use `btoa` in the browser instead of Buffer.', 'ai-site-connector' ),
			'code'     => $code,
		);
	}

	/* ---------------------------------------------------------------------
	 * Generic pack + plain-text agent instructions.
	 * ------------------------------------------------------------------ */

	private static function generic_json( array $pack ) {
		return array(
			'id'       => 'json_pack',
			'label'    => __( 'connection-pack.json', 'ai-site-connector' ),
			'language' => 'json',
			'hint'     => __( 'The full structured pack. Use this when the tool you\'re integrating doesn\'t fit any of the other tabs — it has every field you might need.', 'ai-site-connector' ),
			'code'     => (string) wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		);
	}

	private static function claude_code_instructions( $rest_base, $user, $health_url ) {
		$lines = array(
			'You can authenticate to this WordPress site over the REST API using HTTP Basic Auth.',
			'',
			'REST base:           ' . $rest_base,
			'Username:            ' . $user,
			'Password:            (use the Application Password from the connection pack)',
			'Auth header:         Authorization: Basic base64(username:application_password)',
			'Plugin health:       ' . $health_url,
			'',
			'Do not commit this password to git. Revoke it from Tools → AI Site Connector when access is no longer needed.',
		);
		return array(
			'id'       => 'agent_instructions',
			'label'    => __( 'Agent instructions (plain text)', 'ai-site-connector' ),
			'language' => 'text',
			'hint'     => __( 'Drop into a system prompt, task description, or onboarding doc so the AI agent knows how to authenticate.', 'ai-site-connector' ),
			'code'     => implode( "\n", $lines ),
		);
	}
}
