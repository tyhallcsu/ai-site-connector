// Bridge contract (#112): tools/list comes from the site's MCP endpoint, so
// a tool added on the server is discoverable through the bridge without a
// bridge change; tools/call is forwarded with Basic Auth; a pack's
// mcp_endpoint is honoured. Runs the real bridge over stdio against a mock
// HTTP MCP endpoint. Not shipped (tests/ is excluded from the plugin ZIP).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const bridge = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'index.mjs');
const SERVER_TOOLS = [
	{ name: 'wp_health', description: 'Plugin health check.', inputSchema: { type: 'object', properties: {} } },
	{ name: 'wp_update_content', description: 'Safe content update.', inputSchema: { type: 'object', properties: { post_id: { type: 'integer' } }, required: ['post_id'] } },
	{ name: 'wp_tool_added_later', description: 'A tool the bridge has never heard of.', inputSchema: { type: 'object', properties: {} } },
];

async function mockSite(t) {
	const seen = [];
	const srv = http.createServer((req, res) => {
		let body = '';
		req.on('data', (c) => { body += c; });
		req.on('end', () => {
			const msg = JSON.parse(body);
			seen.push({ path: req.url, auth: req.headers.authorization, method: msg.method, params: msg.params });
			const result = msg.method === 'tools/list'
				? { tools: SERVER_TOOLS }
				: { content: [{ type: 'text', text: JSON.stringify({ called: msg.params.name }) }], isError: false };
			res.setHeader('content-type', 'application/json');
			res.end(JSON.stringify({ jsonrpc: '2.0', id: msg.id, result }));
		});
	});
	await new Promise((resolve) => srv.listen(0, '127.0.0.1', resolve));
	t.after(() => srv.close());
	return { seen, origin: `http://127.0.0.1:${srv.address().port}` };
}

function startBridge(t, env) {
	const child = spawn(process.execPath, [bridge], { env: { ...process.env, ...env }, stdio: ['pipe', 'pipe', 'inherit'] });
	t.after(() => child.kill());
	let buffer = '';
	const messages = [];
	child.stdout.on('data', (chunk) => {
		buffer += chunk.toString();
		let nl;
		while ((nl = buffer.indexOf('\n')) >= 0) {
			const line = buffer.slice(0, nl).trim();
			buffer = buffer.slice(nl + 1);
			if (line) messages.push(JSON.parse(line));
		}
	});
	const send = (msg) => child.stdin.write(JSON.stringify(msg) + '\n');
	const reply = async (id) => {
		for (let i = 0; i < 200; i++) {
			const hit = messages.find((m) => m.id === id);
			if (hit) return hit;
			await new Promise((r) => setTimeout(r, 25));
		}
		throw new Error(`no reply for request ${id}`);
	};
	return { send, reply };
}

async function handshake(io) {
	io.send({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'bridge-test', version: '1' } } });
	await io.reply(1);
	io.send({ jsonrpc: '2.0', method: 'notifications/initialized' });
}

test('tools/list is the site catalog, including tools the bridge never declared', async (t) => {
	const site = await mockSite(t);
	const io = startBridge(t, { WORDPRESS_SITE_URL: site.origin, WORDPRESS_USERNAME: 'agent', WORDPRESS_APPLICATION_PASSWORD: 'secret' });
	await handshake(io);
	io.send({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
	const list = await io.reply(2);
	assert.deepEqual(list.result.tools.map((tool) => tool.name), SERVER_TOOLS.map((tool) => tool.name));
	assert.deepEqual(list.result.tools[1].inputSchema, SERVER_TOOLS[1].inputSchema);

	io.send({ jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'wp_tool_added_later', arguments: {} } });
	const call = await io.reply(3);
	assert.equal(call.result.isError, false);
	assert.deepEqual(JSON.parse(call.result.content[0].text), { called: 'wp_tool_added_later' });

	const expectedAuth = 'Basic ' + Buffer.from('agent:secret').toString('base64');
	assert.ok(site.seen.length >= 2, 'bridge never called the site');
	for (const req of site.seen) {
		assert.equal(req.path, '/wp-json/ai-site-connector/v1/mcp');
		assert.equal(req.auth, expectedAuth);
	}
});

test('a connection pack mcp_endpoint is used as-is (plain permalinks, subdirectories)', async (t) => {
	const site = await mockSite(t);
	const dir = await mkdtemp(path.join(tmpdir(), 'asc-bridge-'));
	t.after(() => rm(dir, { recursive: true, force: true }));
	const pack = path.join(dir, 'pack.json');
	await writeFile(pack, JSON.stringify({
		site_url: site.origin,
		mcp_endpoint: `${site.origin}/blog/?rest_route=/ai-site-connector/v1/mcp`,
		username: 'agent',
		application_password: 'secret',
	}));
	const io = startBridge(t, { AI_SITE_CONNECTOR_PACK: pack });
	await handshake(io);
	io.send({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
	await io.reply(2);
	assert.equal(site.seen.at(-1).path, '/blog/?rest_route=/ai-site-connector/v1/mcp');
});
