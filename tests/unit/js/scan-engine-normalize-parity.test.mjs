/**
 * The client half of the URL-normalization parity contract.
 *
 * scan-engine.js normalizeUrl() must produce byte-identical output to
 * Scanner_Controller::normalize_url(). The PHP half of this table lives in
 * tests/unit/test-url-normalization-parity-php.php and reads the SAME fixture,
 * so neither runner can be made green by editing the other implementation.
 *
 * Why this exists: the PHP side stopped force-appending a trailing slash to
 * file-like paths and the client was not updated with it. deduplicateUrls()
 * applies normalizeUrl() to server-supplied result.urls / result.priority_urls,
 * so on a /%postname%.html permalink structure discover returned '/x.html', the
 * client crawled '/x.html/', reported the slashed form, and the replay re-queued
 * it — the same page captured under two keys, with the server-side fix undone
 * before anything was fetched. Nothing in the suite caught it.
 *
 * The function is extracted from the shipped source rather than reimplemented:
 * a copy would pass while the real file diverged, which is the whole failure
 * mode this test exists to prevent.
 *
 * Run: node --test tests/unit/js/scan-engine-normalize-parity.test.mjs
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');

const fixture = JSON.parse(
	readFileSync(resolve(here, '../fixtures/url-normalization-parity.json'), 'utf8')
);

/**
 * Pull the real normalizeUrl() out of scan-engine.js.
 *
 * The module is an IIFE that touches browser globals at load time, so importing
 * it is not an option. Slicing the one function out keeps the assertion pointed
 * at shipped bytes: if someone edits normalizeUrl, this test sees the edit.
 */
async function loadShippedNormalizeUrl() {
	const src = readFileSync(
		resolve(repoRoot, 'admin/assets/js/modules/scan-engine.js'),
		'utf8'
	);

	const start = src.indexOf('function normalizeUrl(');
	assert.notEqual(
		start,
		-1,
		'normalizeUrl() was not found in scan-engine.js — it was renamed or removed. ' +
			'If it moved, point this loader at the new name; do not delete the parity check.'
	);

	// Brace-match from the function's opening brace. Counting braces beats a
	// regex here: the body contains a regex literal with no braces but the
	// naive "up to the next }" slice would stop at the first inner block.
	const open = src.indexOf('{', start);
	let depth = 0;
	let end = -1;
	for (let i = open; i < src.length; i += 1) {
		if (src[i] === '{') depth += 1;
		else if (src[i] === '}') {
			depth -= 1;
			if (depth === 0) {
				end = i + 1;
				break;
			}
		}
	}
	assert.notEqual(end, -1, 'could not brace-match normalizeUrl() — the source is malformed');

	const body = src.slice(start, end);

	// Evaluate the extracted function as a module via a data: URL rather than
	// new Function(). Both execute repo source — anyone who can edit
	// scan-engine.js already has code execution in the plugin — but new
	// Function() with an interpolated body is a pattern the project's own
	// security guidance flags on sight, and a test that trips it on every
	// touch is a standing tax for no safety gain here.
	//
	// normalizeUrl reads window.location.origin for relative inputs. Every
	// fixture case is absolute, so the stub only has to exist.
	const module = `const window = { location: { origin: ${JSON.stringify(fixture.origin)} } };\n${body}\nexport default normalizeUrl;\n`;
	const imported = await import(
		`data:text/javascript;base64,${Buffer.from(module, 'utf8').toString('base64')}`
	);
	return imported.default;
}

const normalizeUrl = await loadShippedNormalizeUrl();

test('client normalizeUrl matches the shared parity table', async (t) => {
	for (const testCase of fixture.cases) {
		await t.test(`${testCase.input} → ${testCase.expected}`, () => {
			assert.equal(
				normalizeUrl(testCase.input),
				testCase.expected,
				testCase.why
			);
		});
	}
});

test('normalization is idempotent — normalizing twice changes nothing', () => {
	// The client and the server both re-normalize URLs that have already been
	// through the other one (deduplicateUrls on server-supplied lists, then
	// sanitize_scanned_urls on client-reported ones). A non-idempotent rule
	// would drift a little further on every hop.
	for (const testCase of fixture.cases) {
		const once = normalizeUrl(testCase.input);
		assert.equal(normalizeUrl(once), once, `not idempotent for ${testCase.input}`);
	}
});

test('the leading-dot exclusion is present in the shipped source', () => {
	// A behavioural assertion alone would not catch someone reproducing the
	// table with a different rule that happens to agree on these inputs. The
	// exclusion is the subtle half of the contract, so pin it explicitly.
	const src = readFileSync(
		resolve(repoRoot, 'admin/assets/js/modules/scan-engine.js'),
		'utf8'
	);
	assert.match(
		src,
		/lastSegment\.charAt\(0\) !== '\.'/,
		'the leading-dot exclusion is gone from normalizeUrl — /.well-known/ would stop ' +
			'being treated as a directory, diverging from the PHP rule'
	);
});
