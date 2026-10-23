/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/visual-compare.mjs, which writes the artifact of the visual check from what
// the comparison of the screenshot bot kept: the pictures before and after with their
// differences, the report for the comment and the summary of the job. The pictures here
// are small grids of pixels, which a stand-in for pngjs writes as JSON.

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { afterEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { compare, difference, percent, readReport, summary } from '../e2e/visual-compare.mjs'

const script = fileURLToPath(new URL('../e2e/visual-compare.mjs', import.meta.url))
const folders = []

afterEach(function() {
	for (const folder of folders.splice(0)) {
		rmSync(folder, { recursive: true, force: true })
	}
})

function folder() {
	const path = mkdtempSync(join(tmpdir(), 'visual-compare-'))
	folders.push(path)
	return path
}

// A picture of width × height pixels, each [r, g, b, a].
function picture(width, height, pixels) {
	return { width, height, data: Uint8Array.from(pixels.flat()) }
}

const json = {
	read: (buffer) => {
		const { width, height, data } = JSON.parse(buffer.toString('utf8'))
		return { width, height, data: Uint8Array.from(data) }
	},
	write: ({ width, height, data }) => Buffer.from(JSON.stringify({ width, height, data: [...data] })),
}

function file(root, path, data) {
	mkdirSync(dirname(join(root, path)), { recursive: true })
	writeFileSync(join(root, path), data)
}

const white = [255, 255, 255, 255]
const black = [0, 0, 0, 255]
const red = [220, 38, 38, 255]

test('difference paints each changed pixel red and the others pale', function() {
	const diff = difference(picture(2, 1, [white, black]), picture(2, 1, [black, black]))
	assert.equal(diff.changed, 1)
	assert.deepEqual([...diff.data.slice(0, 4)], red)
	// Black, which stayed, turns into a pale gray, a quarter of the way to black.
	assert.deepEqual([...diff.data.slice(4)], [191, 191, 191, 255])
})

test('difference counts a pixel transparent in both pictures as the same, whatever its color', function() {
	const diff = difference(picture(1, 1, [[255, 0, 0, 0]]), picture(1, 1, [[0, 0, 255, 0]]))
	assert.equal(diff.changed, 0)
	assert.deepEqual([...diff.data], white)
})

test('difference notices a change of transparency alone', function() {
	const diff = difference(picture(1, 1, [[10, 20, 30, 255]]), picture(1, 1, [[10, 20, 30, 128]]))
	assert.equal(diff.changed, 1)
})

test('difference draws nothing for pictures of different sizes', function() {
	assert.equal(difference(picture(1, 1, [white]), picture(2, 1, [white, white])), null)
	assert.equal(difference(picture(1, 2, [white, white]), picture(1, 1, [white])), null)
})

test('percent shows the share of the changed pixels for people', function() {
	assert.equal(percent(null), '–')
	assert.equal(percent(1), '100 %')
	assert.equal(percent(0), '< 0.01 %')
	assert.equal(percent(0.00009), '< 0.01 %')
	assert.equal(percent(0.0123), '1.23 %')
})

test('readReport takes the pictures of the report of keep', function() {
	const text = JSON.stringify({
		pictures: [
			{ path: 'screenshots/01-unified-search.png', status: 'changed', share: 0.5 },
			{ path: '.github/social-preview.png', status: 'changed', share: null },
			{ path: 'screenshots/13-new.png', status: 'new', share: null },
			{ path: 'screenshots/old.gif', status: 'deleted' },
			{ path: 'screenshots/odd.png', status: 'changed', share: 2 },
		],
		ignored: ['tests/e2e/node_modules'],
	})
	assert.deepEqual(readReport(text), [
		{ path: 'screenshots/01-unified-search.png', status: 'changed', share: 0.5 },
		{ path: '.github/social-preview.png', status: 'changed', share: null },
		{ path: 'screenshots/13-new.png', status: 'new', share: null },
		{ path: 'screenshots/old.gif', status: 'deleted', share: null },
		{ path: 'screenshots/odd.png', status: 'changed', share: null },
	])
})

test('readReport refuses a report without pictures, and pictures it may not name', function() {
	assert.throws(() => readReport('null'), /no list of pictures/)
	assert.throws(() => readReport('{"pictures": {}}'), /no list of pictures/)
	for (const picture of [
		null,
		{ path: 'screenshots/a.png', status: 'renamed' },
		{ path: '../outside.png', status: 'new' },
		{ path: 'screenshots/../../outside.png', status: 'new' },
		{ path: '/etc/passwd', status: 'new' },
		{ path: 'screenshots/a b.png', status: 'new' },
		{ path: 'screenshots/`a`.png', status: 'new' },
		{ status: 'new' },
	]) {
		assert.throws(() => readReport(JSON.stringify({ pictures: [picture] })), /names a picture that it can't/)
	}
})

test('summary lists the pictures that look different', function() {
	const text = summary([
		{ path: 'screenshots/03-admin-settings.png', status: 'changed', share: 0.0123 },
		{ path: 'screenshots/13-new.png', status: 'new', share: null },
	])
	assert.match(text, /^### Visual check\n\n2 pictures look different from the committed ones:\n/)
	assert.match(text, /\| `screenshots\/03-admin-settings\.png` \| changed \| 1\.23 % \|\n/)
	assert.match(text, /\| `screenshots\/13-new\.png` \| new \| – \|\n/)
	assert.match(text, /artifact `visual`/)
	assert.match(summary([{ path: 'a.png', status: 'deleted', share: null }]), /\n1 picture looks different/)
})

test('summary says when every picture looks as committed', function() {
	assert.equal(summary([]), '### Visual check\n\nEvery picture of `scripts/screenshots.sh` looks as committed.\n')
})

test('compare writes each picture before and after, and the difference of a PNG of the same size', function() {
	const kept = folder()
	const out = folder()
	const committed = {
		'screenshots/a.png': json.write(picture(2, 1, [white, white])),
		'screenshots/b.png': json.write(picture(1, 1, [white])),
		'screenshots/c.gif': Buffer.from('GIF89a before'),
		'screenshots/gone.png': json.write(picture(1, 1, [black])),
	}
	file(kept, 'files/screenshots/a.png', json.write(picture(2, 1, [white, black])))
	file(kept, 'files/screenshots/b.png', json.write(picture(2, 1, [white, white])))
	file(kept, 'files/screenshots/c.gif', 'GIF89a after')
	file(kept, 'files/screenshots/new.png', json.write(picture(1, 1, [black])))
	file(kept, 'report.json', JSON.stringify({
		pictures: [
			{ path: 'screenshots/a.png', status: 'changed', share: 0.5 },
			{ path: 'screenshots/b.png', status: 'changed', share: null },
			{ path: 'screenshots/c.gif', status: 'changed', share: 0.25 },
			{ path: 'screenshots/gone.png', status: 'deleted', share: null },
			{ path: 'screenshots/new.png', status: 'new', share: null },
		],
	}))
	const asked = []
	const pictures = compare(kept, out, {
		png: json,
		committed: (path) => {
			asked.push(path)
			return committed[path]
		},
	})

	assert.equal(pictures.length, 5)
	assert.deepEqual(asked, ['screenshots/a.png', 'screenshots/b.png', 'screenshots/c.gif', 'screenshots/gone.png'])
	assert.deepEqual(json.read(readFileSync(join(out, 'diff/screenshots/a.png'))).data.slice(4), Uint8Array.from(red))
	// A picture whose size changed, a GIF, a deleted and a new one have no difference.
	for (const path of ['b.png', 'c.gif', 'gone.png', 'new.png']) {
		assert.equal(existsSync(join(out, 'diff/screenshots', path)), false, path)
	}
	assert.equal(readFileSync(join(out, 'before/screenshots/c.gif'), 'utf8'), 'GIF89a before')
	assert.equal(readFileSync(join(out, 'after/screenshots/c.gif'), 'utf8'), 'GIF89a after')
	assert.equal(existsSync(join(out, 'after/screenshots/gone.png')), false)
	assert.equal(existsSync(join(out, 'before/screenshots/new.png')), false)
	assert.deepEqual(JSON.parse(readFileSync(join(out, 'report.json'), 'utf8')).pictures, pictures)
	assert.match(readFileSync(join(out, 'summary.md'), 'utf8'), /5 pictures look different/)
})

test('compare reports no change when keep left no folder', function() {
	const out = folder()
	const pictures = compare(join(out, 'missing'), out, {
		png: json,
		committed: () => assert.fail('No picture changed, so none is read.'),
	})
	assert.deepEqual(pictures, [])
	assert.deepEqual(JSON.parse(readFileSync(join(out, 'report.json'), 'utf8')), { pictures: [] })
	assert.match(readFileSync(join(out, 'summary.md'), 'utf8'), /looks as committed/)
})

test('the script names its arguments when one is missing', function() {
	const run = spawnSync(process.execPath, [script], { encoding: 'utf8' })
	assert.equal(run.status, 2)
	assert.match(run.stderr, /Usage: node tests\/e2e\/visual-compare\.mjs KEPT OUT/)
})
