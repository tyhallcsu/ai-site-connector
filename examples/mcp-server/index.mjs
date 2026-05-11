#!/usr/bin/env node
/**
 * AI Site Connector — stdio MCP server.
 *
 * Speaks MCP over stdio (the transport Claude Desktop and Cursor use
 * locally) and forwards each tools/call to the plugin's HTTP MCP
 * endpoint at /wp-json/ai-site-connector/v1/mcp using HTTP Basic Auth.
 *
 * Tool descriptors MIRROR the PHP-side declarations in
 * includes/class-mcp-server.php — keep both in sync when adding tools.
 *
 * Configuration:
 *   WORDPRESS_SITE_URL              (required) e.g. https://example.com
 *   WORDPRESS_USERNAME              (required)
 *   WORDPRESS_APPLICATION_PASSWORD  (required)
 *   AI_SITE_CONNECTOR_PACK          (optional) path to a connection-pack JSON;
 *                                              read first, overrides the env trio.
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

const TOOLS = [
	{ name: 'wp_health',        description: 'Plugin health check.', inputSchema: { type: 'object', properties: {}, additionalProperties: false } },
	{ name: 'wp_site_info',     description: 'Site name, URL, WP/PHP versions, active theme.', inputSchema: { type: 'object', properties: {}, additionalProperties: false } },
	{ name: 'wp_list_posts',    description: 'List posts (status, per_page, search, post_type).',
		inputSchema: { type: 'object', properties: { status: { type: 'string' }, per_page: { type: 'integer' }, search: { type: 'string' }, post_type: { type: 'string' } } } },
	{ name: 'wp_get_post',      description: 'Fetch a single post by id (post_type optional).',
		inputSchema: { type: 'object', properties: { id: { type: 'integer' }, post_type: { type: 'string' } }, required: ['id'] } },
	{ name: 'wp_create_post',   description: 'Create a post. Required: title, content. Optional: status, post_type.',
		inputSchema: { type: 'object', properties: { title: { type: 'string' }, content: { type: 'string' }, status: { type: 'string' }, post_type: { type: 'string' } }, required: ['title', 'content'] } },
	{ name: 'wp_update_post',   description: 'Update an existing post.',
		inputSchema: { type: 'object', properties: { id: { type: 'integer' }, title: { type: 'string' }, content: { type: 'string' }, status: { type: 'string' }, post_type: { type: 'string' } }, required: ['id'] } },
	{ name: 'wp_list_pages',    description: 'Alias for wp_list_posts with post_type=page.',
		inputSchema: { type: 'object', properties: { status: { type: 'string' }, per_page: { type: 'integer' }, search: { type: 'string' } } } },
	{ name: 'wp_list_plugins',  description: 'List installed plugins.',  inputSchema: { type: 'object', properties: {}, additionalProperties: false } },
	{ name: 'wp_list_themes',   description: 'List installed themes.',   inputSchema: { type: 'object', properties: {}, additionalProperties: false } },
];

async function loadCredentials() {
	const packPath = process.env.AI_SITE_CONNECTOR_PACK;
	if (packPath) {
		try {
			const raw  = await readFile(packPath, 'utf8');
			const pack = JSON.parse(raw);
			return {
				siteUrl:  pack.site_url,
				username: pack.username,
				password: pack.application_password,
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
	const url  = `${creds.siteUrl.replace(/\/$/, '')}/wp-json/ai-site-connector/v1/mcp`;
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
		{ name: 'ai-site-connector', version: '0.8.0' },
		{ capabilities: { tools: { listChanged: false } } }
	);

	server.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOLS }));

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
