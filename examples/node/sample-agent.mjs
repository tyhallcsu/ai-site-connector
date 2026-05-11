#!/usr/bin/env node
/**
 * AI Site Connector — Node.js sample agent.
 *
 * Reference client demonstrating the typical AI-agent flow against a
 * WordPress site secured by this plugin.
 *
 * Requires Node 18+ (uses native fetch and Buffer).
 *
 * Configuration:
 *   WORDPRESS_SITE_URL              e.g. https://example.com
 *   WORDPRESS_USERNAME              the AI user's login
 *   WORDPRESS_APPLICATION_PASSWORD  the Application Password
 *
 * Usage:
 *   node sample-agent.mjs
 *   node sample-agent.mjs --dry-run
 *   node sample-agent.mjs --json
 */

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const jsonOut = args.includes('--json');

function envOrDie(name) {
	const v = (process.env[name] || '').trim();
	if (!v) {
		process.stderr.write(`Missing env var: ${name}\n`);
		process.exit(2);
	}
	return v;
}

const site = envOrDie('WORDPRESS_SITE_URL').replace(/\/+$/, '');
const user = envOrDie('WORDPRESS_USERNAME');
const pass = envOrDie('WORDPRESS_APPLICATION_PASSWORD');
const restBase = `${site}/wp-json`;
const authHeader = 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');

const results = {};

function log(label, data) {
	results[label] = data;
	if (!jsonOut) {
		process.stdout.write(`\n=== ${label} ===\n`);
		process.stdout.write(typeof data === 'string' ? data : JSON.stringify(data, null, 2));
		process.stdout.write('\n');
	}
}

async function call(method, path, options = {}) {
	const url = `${restBase}${path}`;
	if (dryRun) {
		return { _dry_run: true, method, url, options: Object.keys(options) };
	}
	const headers = { Authorization: authHeader, ...(options.headers || {}) };
	if (options.json !== undefined) {
		headers['Content-Type'] = 'application/json';
		options.body = JSON.stringify(options.json);
		delete options.json;
	}
	const resp = await fetch(url, { method, headers, body: options.body, signal: AbortSignal.timeout(15_000) });
	const ct = resp.headers.get('content-type') || '';
	const body = ct.includes('application/json') ? await resp.json() : await resp.text();
	return { status: resp.status, body };
}

async function main() {
	// 1. Health
	log('health', await call('GET', '/ai-site-connector/v1/health'));

	// 2. List posts
	log('posts.list', await call('GET', '/wp/v2/posts?per_page=5&status=publish'));

	// 3. Create a draft
	log(
		'posts.create',
		await call('POST', '/wp/v2/posts', {
			json: {
				title: 'AI Site Connector sample-agent draft (Node)',
				content: 'Hello from the Node sample agent.',
				status: 'draft',
			},
		})
	);

	// 4. Upload a 1x1 PNG
	const pngBytes = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
		'base64'
	);
	if (dryRun) {
		log('media.upload', { _dry_run: true, method: 'POST', url: `${restBase}/wp/v2/media`, bytes: pngBytes.length });
	} else {
		const mediaResp = await fetch(`${restBase}/wp/v2/media`, {
			method: 'POST',
			headers: {
				Authorization: authHeader,
				'Content-Disposition': 'attachment; filename="sample-agent-pixel.png"',
				'Content-Type': 'image/png',
			},
			body: pngBytes,
			signal: AbortSignal.timeout(15_000),
		});
		const ct = mediaResp.headers.get('content-type') || '';
		const body = ct.includes('application/json') ? await mediaResp.json() : await mediaResp.text();
		log('media.upload', { status: mediaResp.status, body });
	}

	if (jsonOut) {
		process.stdout.write(JSON.stringify(results, null, 2) + '\n');
	}
}

main().catch((err) => {
	process.stderr.write(`Error: ${err.message}\n`);
	process.exit(1);
});
