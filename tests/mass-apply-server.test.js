'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

const repo = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'rss-leads-mass-apply-'));
const fakeCodex = path.join(temp, 'codex');
const port = 18000 + Math.floor(Math.random() * 1000);

fs.writeFileSync(fakeCodex, `#!/usr/bin/env node
const args = process.argv.slice(2);
if (args[0] === 'login' && args[1] === 'status') {
  console.log('Logged in using ChatGPT');
  process.exit(0);
}
if (args[0] === 'login' && args[1] === '--device-auth') {
  console.log('Open https://example.test/device and enter TEST-CODE');
  process.exit(0);
}
if (args[0] === 'exec') {
  let input = '';
  process.stdin.setEncoding('utf8');
  process.stdin.on('data', chunk => { input += chunk; });
  process.stdin.on('end', () => {
    if (!input.includes('LEAD_DATA:')) process.exit(2);
    console.log(JSON.stringify({ draft: 'Hello! This is the prepared test DM.' }));
  });
  return;
}
process.exit(3);
`);
fs.chmodSync(fakeCodex, 0o755);

const child = spawn(process.execPath, ['mass-apply/server.js'], {
	cwd: repo,
	env: {
		...process.env,
		PATH: temp + path.delimiter + process.env.PATH,
		MASS_APPLY_PORT: String(port),
		MASS_APPLY_ALLOWED_ORIGINS: 'http://freshrss.test',
		MASS_APPLY_TOKEN: 'test-helper-token',
		MASS_APPLY_WORKSPACE: temp,
		MASS_APPLY_SCHEMA_PATH: path.join(repo, 'mass-apply/draft-schema.json'),
		MASS_APPLY_CODEX_BINARY: fakeCodex,
		MASS_APPLY_APP_DIR: repo
	},
	stdio: ['ignore', 'pipe', 'pipe']
});

let childOutput = '';
child.stdout.on('data', (chunk) => { childOutput += chunk; });
child.stderr.on('data', (chunk) => { childOutput += chunk; });

async function request(route, options = {}) {
	return fetch(`http://127.0.0.1:${port}${route}`, {
		...options,
		headers: { Origin: 'http://freshrss.test', Authorization: 'Bearer test-helper-token', ...(options.headers || {}) }
	});
}

async function waitForServer() {
	for (let attempt = 0; attempt < 40; attempt += 1) {
		try {
			const response = await request('/healthz');
			if (response.ok) return;
		} catch (error) {
			// Server is still starting.
		}
		await new Promise((resolve) => setTimeout(resolve, 50));
	}
	throw new Error('server did not start: ' + childOutput);
}

async function main() {
	await waitForServer();
	let response = await request('/api/status');
	let data = await response.json();
	assert.equal(response.status, 200);
	assert.equal(data.codex.authenticated, true, JSON.stringify(data));

	response = await fetch(`http://127.0.0.1:${port}/api/status`, { headers: { Origin: 'https://evil.test' } });
	assert.equal(response.status, 403);
	response = await fetch(`http://127.0.0.1:${port}/api/status`, { headers: { Origin: 'http://freshrss.test' } });
	assert.equal(response.status, 401);

	response = await request('/api/jobs', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			profile: 'Video editor with a relevant portfolio.',
			instructions: 'Write a concise DM.',
			leads: [{ id: 'entry:42', redditUsername: 'Client_Name', title: 'Need an editor', content: 'Paid editing work.' }]
		})
	});
	data = await response.json();
	assert.equal(response.status, 202);
	const jobId = data.job.id;

	for (let attempt = 0; attempt < 40; attempt += 1) {
		response = await request('/api/jobs/' + jobId);
		data = await response.json();
		if (String(data.job.status).startsWith('completed')) break;
		await new Promise((resolve) => setTimeout(resolve, 50));
	}
	assert.equal(data.job.status, 'completed');
	assert.equal(data.job.results[0].id, 'entry:42');
	assert.equal(data.job.results[0].draft, 'Hello! This is the prepared test DM.');
	console.log('mass-apply server tests passed');
}

main().finally(() => {
	child.kill('SIGTERM');
	fs.rmSync(temp, { recursive: true, force: true });
}).catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
