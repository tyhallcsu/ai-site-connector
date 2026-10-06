#!/usr/bin/env node
/**
 * AI Site Connector — stdio MCP server.
 *
 * Speaks MCP over stdio (the transport Claude Desktop and Cursor use
 * locally) and forwards tools/list and tools/call to the plugin's HTTP MCP
 * endpoint (/wp-json/ai-site-connector/v1/mcp) using HTTP Basic Auth. The
 * site's own catalog is authoritative, so every tool the site offers is
 * discoverable with its real description and input schema (#112).
 *
 * Configuration:
 *   WORDPRESS_SITE_URL              (required) e.g. https://example.com
 *   WORDPRESS_USERNAME              (required)
 *   WORDPRESS_APPLICATION_PASSWORD  (required)
 *   AI_SITE_CONNECTOR_PACK          (optional) path to a connection-pack JSON;
 *                                              read first, overrides the env trio.
 *                                              Its mcp_endpoint is used when present.
 *
 * Run: node index.mjs   (or `npx ai-site-connector-mcp` if published)
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
	CallToolRequestSchema,
	ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const { version } = createRequire(import.meta.url)('./package.json');

async function loadCredentials() {
	const packPath = process.env.AI_SITE_CONNECTOR_PACK;
	if (packPath) {
		try {
			const raw  = await readFile(packPath, 'utf8');
			const pack = JSON.parse(raw);
			return {
				siteUrl:     pack.site_url,
				mcpEndpoint: pack.mcp_endpoint,
				username:    pack.username,
				password:    pack.application_password,
			};
		} catch (err) {
			throw new Error(`Failed to read AI_SITE_CONNECTOR_PACK at ${packPath}: ${err.message}`);
		}
	}
	const siteUrl  = process.env.WORDPRESS_SITE_URL;
	const username = process.env.WORDPRESS_USERNAME;
	const password = process.env.WORDPRESS_APPLICATION_PASSWORD;
	if (!siteUrl || !username || !password) {
		throw new Error('Missing config. Set WORDPRESS_SITE_URL, WORDPRESS_USERNAME, WORDPRESS_APPLICATION_PASSWORD env vars, OR AI_SITE_CONNECTOR_PACK pointing at a pack JSON.');
	}
	return { siteUrl, username, password };
}

async function callRemoteMcp(creds, jsonRpcMessage) {
	// Packs carry the exact endpoint (plain permalinks, subdirectory installs);
	// otherwise assume pretty permalinks at the site root.
	const url  = creds.mcpEndpoint || `${creds.siteUrl.replace(/\/$/, '')}/wp-json/ai-site-connector/v1/mcp`;
	const auth = 'Basic ' + Buffer.from(`${creds.username}:${creds.password}`).toString('base64');
	const res = await fetch(url, {
		method:  'POST',
		headers: { 'Content-Type': 'application/json', Authorization: auth },
		body:    JSON.stringify(jsonRpcMessage),
		signal:  AbortSignal.timeout(30_000),
	});
	const text = await res.text();
	let body;
	try { body = JSON.parse(text); } catch (e) { body = { raw: text }; }
	if (!res.ok) {
		throw new Error(`HTTP ${res.status}: ${typeof body === 'object' ? JSON.stringify(body) : body}`);
	}
	if (body && body.error) {
		throw new Error(`MCP error: ${body.error.message || JSON.stringify(body.error)}`);
	}
	return body && body.result;
}

async function main() {
	const creds = await loadCredentials();

	const server = new Server(
		{ name: 'ai-site-connector', version },
		{ capabilities: { tools: { listChanged: false } } }
	);

	server.setRequestHandler(ListToolsRequestSchema, async () => {
		const result = await callRemoteMcp(creds, { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} });
		return { tools: result && Array.isArray(result.tools) ? result.tools : [] };
	});

	server.setRequestHandler(CallToolRequestSchema, async (request) => {
		const { name, arguments: args } = request.params;
		const result = await callRemoteMcp(creds, {
			jsonrpc: '2.0',
			id:      1,
			method:  'tools/call',
			params:  { name, arguments: args || {} },
		});
		// The HTTP MCP endpoint returns { content: [...], isError: false } already.
		return result;
	});

	const transport = new StdioServerTransport();
	await server.connect(transport);
}

main().catch((err) => {
	process.stderr.write(`ai-site-connector-mcp fatal: ${err.message}\n`);
	process.exit(1);
});
