/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/visual-compare.mjs, which writes the artifact of the visual check from what
// the comparison of the screenshot bot kept: the pictures before and after, keep's
// comparison and picture of the difference of each of them, of the frame with the most
// changes for an animation, a PNG also for a WebP and a GIF, the report for the comment
// and the pager, and the summary of the job.

import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { afterEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { change, compare, comparisonPath, diffPath, percent, readReport, summary } from '../e2e/visual-compare.mjs'

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

function file(root, path, data) {
	mkdirSync(dirname(join(root, path)), { recursive: true })
	writeFileSync(join(root, path), data)
}

// A picture of a difference as keep draws it: what counts here is that it is a PNG.
function drawn(name) {
	return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.from(name)])
}

test('diffPath puts the difference of every picture under diff/ as a PNG, as keep does', function() {
	assert.equal(diffPath('screenshots/01-unified-search.png'), 'diff/screenshots/01-unified-search.png')
	assert.equal(diffPath('screenshots/LOUD.PNG'), 'diff/screenshots/LOUD.PNG')
	assert.equal(diffPath('docs/images/dark-poster.webp'), 'diff/docs/images/dark-poster.webp.png')
	assert.equal(diffPath('docs/images/search-and-open.gif'), 'diff/docs/images/search-and-open.gif.png')
})

test('comparisonPath puts the comparison of every picture under comparison/ as a PNG, as keep does', function() {
	assert.equal(comparisonPath('screenshots/01-unified-search.png'), 'comparison/screenshots/01-unified-search.png')
	assert.equal(comparisonPath('screenshots/LOUD.PNG'), 'comparison/screenshots/LOUD.PNG')
	assert.equal(comparisonPath('docs/images/dark-poster.webp'), 'comparison/docs/images/dark-poster.webp.png')
	assert.equal(comparisonPath('docs/images/search-and-open.gif'), 'comparison/docs/images/search-and-open.gif.png')
})

test('change names the frame of the comparison of an animation, as the screenshot bot does', function() {
	assert.equal(change({ status: 'changed', frame: 3, frames: 4 }), 'changed, most in frame 3 of 4')
	assert.equal(change({ status: 'changed', frame: 1, frames: 1 }), 'changed')
	assert.equal(change({ status: 'new', frame: null, frames: null }), 'new')
})

test('percent shows the share of the changed pixels for people', function() {
	assert.equal(percent(null), '–')
	assert.equal(percent(1), '100 %')
	assert.equal(percent(0), '< 0.01 %')
	assert.equal(percent(0.00009), '< 0.01 %')
	assert.equal(percent(0.0123), '1.23 %')
})

test('readReport takes the pictures of the report of keep, with the comparison, its frame and the difference of each', function() {
	const text = JSON.stringify({
		pictures: [
			{
				path: 'docs/images/search-and-open-dark.gif',
				status: 'changed',
				share: 0.0042,
				before: null,
				diff: 'diff/docs/images/search-and-open-dark.gif.png',
				comparison: 'comparison/docs/images/search-and-open-dark.gif.png',
				frame: 3,
				frames: 4,
			},
			{
				path: 'screenshots/01-unified-search.png',
				status: 'changed',
				share: 0.5,
				before: null,
				diff: 'diff/screenshots/01-unified-search.png',
				comparison: 'comparison/screenshots/01-unified-search.png',
				frame: 1,
				frames: 1,
			},
			{ path: 'screenshots/02-settings.png', status: 'changed', share: 0.25, diff: 'diff/screenshots/02-settings.png' },
			{ path: 'docs/images/dark-poster.webp', status: 'changed', share: 0.0816, diff: 'diff/docs/images/dark-poster.webp.png' },
			{ path: 'docs/images/search-and-open.gif', status: 'changed', share: 0.0011, diff: 'diff/docs/images/search-and-open.gif.png' },
			{ path: 'docs/images/search-and-open.mp4', status: 'changed', share: null, diff: null },
			{ path: '.github/social-preview.png', status: 'changed', share: null, before: [1280, 640] },
			{ path: 'screenshots/13-new.png', status: 'new', share: null },
			{ path: 'screenshots/old.gif', status: 'deleted' },
			{ path: 'screenshots/odd.png', status: 'changed', share: 2 },
		],
		ignored: ['tests/e2e/node_modules'],
	})
	const none = { comparison: null, frame: null, frames: null }
	assert.deepEqual(readReport(text), [
		{
			path: 'docs/images/search-and-open-dark.gif',
			status: 'changed',
			share: 0.0042,
			diff: 'diff/docs/images/search-and-open-dark.gif.png',
			comparison: 'comparison/docs/images/search-and-open-dark.gif.png',
			frame: 3,
			frames: 4,
		},
		{
			path: 'screenshots/01-unified-search.png',
			status: 'changed',
			share: 0.5,
			diff: 'diff/screenshots/01-unified-search.png',
			comparison: 'comparison/screenshots/01-unified-search.png',
			frame: 1,
			frames: 1,
		},
		// A report of a keep before the comparisons names none.
		{ path: 'screenshots/02-settings.png', status: 'changed', share: 0.25, diff: 'diff/screenshots/02-settings.png', ...none },
		{ path: 'docs/images/dark-poster.webp', status: 'changed', share: 0.0816, diff: 'diff/docs/images/dark-poster.webp.png', ...none },
		{ path: 'docs/images/search-and-open.gif', status: 'changed', share: 0.0011, diff: 'diff/docs/images/search-and-open.gif.png', ...none },
		{ path: 'docs/images/search-and-open.mp4', status: 'changed', share: null, diff: null, ...none },
		// A report of a keep before the differences names none.
		{ path: '.github/social-preview.png', status: 'changed', share: null, diff: null, ...none },
		{ path: 'screenshots/13-new.png', status: 'new', share: null, diff: null, ...none },
		{ path: 'screenshots/old.gif', status: 'deleted', share: null, diff: null, ...none },
		{ path: 'screenshots/odd.png', status: 'changed', share: null, diff: null, ...none },
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

test('readReport refuses a difference other than the one keep draws of a changed picture', function() {
	for (const picture of [
		{ path: 'docs/images/a.webp', status: 'changed', diff: '../outside.png' },
		{ path: 'docs/images/a.webp', status: 'changed', diff: 'diff/docs/images/a.webp' },
		{ path: 'docs/images/a.webp', status: 'changed', diff: 'diff/docs/images/b.webp.png' },
		{ path: 'docs/images/a.webp', status: 'changed', diff: 1 },
		{ path: 'docs/images/a.webp', status: 'new', diff: 'diff/docs/images/a.webp.png' },
		{ path: 'docs/images/a.webp', status: 'deleted', diff: 'diff/docs/images/a.webp.png' },
	]) {
		assert.throws(() => readReport(JSON.stringify({ pictures: [picture] })), /names a difference that it doesn't draw/)
	}
})

test('readReport refuses a comparison other than the one keep draws of a changed picture', function() {
	const framed = { frame: 1, frames: 1 }
	for (const picture of [
		{ path: 'docs/images/a.gif', status: 'changed', comparison: '../outside.png', ...framed },
		{ path: 'docs/images/a.gif', status: 'changed', comparison: 'comparison/docs/images/a.gif', ...framed },
		{ path: 'docs/images/a.gif', status: 'changed', comparison: 'diff/docs/images/a.gif.png', ...framed },
		{ path: 'docs/images/a.gif', status: 'changed', comparison: 'comparison/docs/images/b.gif.png', ...framed },
		{ path: 'docs/images/a.gif', status: 'changed', comparison: 1, ...framed },
		{ path: 'docs/images/a.gif', status: 'new', comparison: 'comparison/docs/images/a.gif.png', ...framed },
		{ path: 'docs/images/a.gif', status: 'deleted', comparison: 'comparison/docs/images/a.gif.png', ...framed },
	]) {
		assert.throws(() => readReport(JSON.stringify({ pictures: [picture] })), /names a comparison that it doesn't draw/)
	}
})

test('readReport refuses a frame that the comparison does not show, and one without a comparison', function() {
	const comparison = 'comparison/docs/images/a.gif.png'
	for (const picture of [
		{ path: 'docs/images/a.gif', status: 'changed', comparison },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: 1 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: 0, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: 5, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: 1.5, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: '2', frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', comparison, frame: true, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', frame: 2, frames: 4 },
		{ path: 'docs/images/a.gif', status: 'changed', frames: 4 },
	]) {
		assert.throws(() => readReport(JSON.stringify({ pictures: [picture] })), /names a frame that its comparison doesn't show/)
	}
})

const NONE = { comparison: null, frame: null, frames: null }

test('summary lists the pictures that look different, the comparison of each with its frame, and its difference', function() {
	const text = summary([
		{
			path: 'docs/images/search-and-open-dark.gif',
			status: 'changed',
			share: 0.0042,
			diff: 'diff/docs/images/search-and-open-dark.gif.png',
			comparison: 'comparison/docs/images/search-and-open-dark.gif.png',
			frame: 3,
			frames: 4,
		},
		{
			path: 'docs/images/dark-poster.webp',
			status: 'changed',
			share: 0.0816,
			diff: 'diff/docs/images/dark-poster.webp.png',
			comparison: 'comparison/docs/images/dark-poster.webp.png',
			frame: 1,
			frames: 1,
		},
		{ path: 'docs/images/search-and-open.mp4', status: 'changed', share: null, diff: null, ...NONE },
		{ path: 'screenshots/13-new.png', status: 'new', share: null, diff: null, ...NONE },
	])
	assert.match(text, /^### Visual check\n\n4 pictures look different from the committed ones:\n/)
	assert.match(text, /\| Picture \| Change \| Pixels changed \| Before and after \| Changed pixels in red \|\n/)
	assert.match(text, new RegExp('\\| `docs/images/search-and-open-dark\\.gif` \\| changed, most in frame 3 of 4 \\| 0\\.42 % '
		+ '\\| `comparison/docs/images/search-and-open-dark\\.gif\\.png` \\| `diff/docs/images/search-and-open-dark\\.gif\\.png` \\|\n'))
	// A still picture has one frame, which the summary doesn't name.
	assert.match(text, /\| `docs\/images\/dark-poster\.webp` \| changed \| 8\.16 % \| `comparison\/docs\/images\/dark-poster\.webp\.png` \| `diff\/docs\/images\/dark-poster\.webp\.png` \|\n/)
	assert.match(text, /\| `docs\/images\/search-and-open\.mp4` \| changed \| – \| – \| – \|\n/)
	assert.match(text, /\| `screenshots\/13-new\.png` \| new \| – \| – \| – \|\n/)
	assert.match(text, /in `comparison\/` the place of the change before and after in one picture, in its own colors/)
	assert.match(text, /Both show the same frame: for an animation the one with the most changes, which the column \*Change\* names;/)
	// The others have neither, and the summary says why.
	assert.match(text, /names; a new or a deleted picture, a video and a picture whose size or number of frames changed have neither\./)
	assert.match(text, /The check only reports/)
})

test('summary says nothing of the others when every picture has its comparison', function() {
	const text = summary([
		{ path: 'a.gif', status: 'changed', share: 0.5, diff: 'diff/a.gif.png', comparison: 'comparison/a.gif.png', frame: 2, frames: 2 },
	])
	assert.match(text, /\n1 picture looks different/)
	assert.match(text, /which the column \*Change\* names\.\n/)
	assert.doesNotMatch(text, /have neither/)
})

test('summary promises no comparison when no picture has one', function() {
	const text = summary([{ path: 'a.png', status: 'deleted', share: null, diff: null, ...NONE }])
	assert.match(text, /holds each picture before and after\. None of them has a comparison or a picture of its changed pixels: /)
	assert.doesNotMatch(text, /in `diff\/`|in `comparison\/`/)
})

test('summary says when every picture looks as committed', function() {
	assert.equal(summary([]), '### Visual check\n\nEvery picture of `scripts/screenshots.sh` looks as committed.\n')
})

test('compare writes each picture before and after, and the comparison and the difference of a PNG, a WebP and a GIF', function() {
	const kept = folder()
	const out = folder()
	const committed = {
		'screenshots/a.png': Buffer.from('PNG before'),
		'docs/images/b.webp': Buffer.from('WebP before'),
		'docs/images/c.gif': Buffer.from('GIF89a before'),
		'docs/images/c.mp4': Buffer.from('ftyp before'),
		'screenshots/gone.png': Buffer.from('PNG gone'),
	}
	file(kept, 'files/screenshots/a.png', 'PNG after')
	file(kept, 'files/docs/images/b.webp', 'WebP after')
	file(kept, 'files/docs/images/c.gif', 'GIF89a after')
	file(kept, 'files/docs/images/c.mp4', 'ftyp after')
	file(kept, 'files/screenshots/new.png', 'PNG new')
	file(kept, 'diff/screenshots/a.png', drawn('a'))
	file(kept, 'diff/docs/images/b.webp.png', drawn('b'))
	file(kept, 'diff/docs/images/c.gif.png', drawn('c'))
	file(kept, 'comparison/screenshots/a.png', drawn('a before and after'))
	file(kept, 'comparison/docs/images/b.webp.png', drawn('b before and after'))
	file(kept, 'comparison/docs/images/c.gif.png', drawn('c before and after'))
	file(kept, 'report.json', JSON.stringify({
		pictures: [
			{
				path: 'docs/images/b.webp',
				status: 'changed',
				share: 0.0816,
				diff: 'diff/docs/images/b.webp.png',
				comparison: 'comparison/docs/images/b.webp.png',
				frame: 1,
				frames: 1,
			},
			// An animation whose change is in a later frame.
			{
				path: 'docs/images/c.gif',
				status: 'changed',
				share: 0.0011,
				diff: 'diff/docs/images/c.gif.png',
				comparison: 'comparison/docs/images/c.gif.png',
				frame: 3,
				frames: 4,
			},
			{ path: 'docs/images/c.mp4', status: 'changed', share: null, diff: null },
			// A report of a keep before the comparisons names none.
			{ path: 'screenshots/a.png', status: 'changed', share: 0.5, diff: 'diff/screenshots/a.png' },
			{ path: 'screenshots/gone.png', status: 'deleted', share: null, diff: null },
			{ path: 'screenshots/new.png', status: 'new', share: null, diff: null },
		],
		ignored: [],
	}))
	const asked = []
	const pictures = compare(kept, out, {
		committed: (path) => {
			asked.push(path)
			return committed[path]
		},
	})

	assert.equal(pictures.length, 6)
	assert.deepEqual(asked, ['docs/images/b.webp', 'docs/images/c.gif', 'docs/images/c.mp4', 'screenshots/a.png', 'screenshots/gone.png'])
	assert.deepEqual(readFileSync(join(out, 'diff/screenshots/a.png')), drawn('a'))
	assert.deepEqual(readFileSync(join(out, 'diff/docs/images/b.webp.png')), drawn('b'))
	assert.deepEqual(readFileSync(join(out, 'diff/docs/images/c.gif.png')), drawn('c'))
	assert.deepEqual(readFileSync(join(out, 'comparison/docs/images/b.webp.png')), drawn('b before and after'))
	assert.deepEqual(readFileSync(join(out, 'comparison/docs/images/c.gif.png')), drawn('c before and after'))
	// A video, a deleted and a new picture have no difference and no comparison, and a
	// picture that the report names none for has none in the artifact.
	for (const path of ['docs/images/c.mp4.png', 'screenshots/gone.png', 'screenshots/new.png']) {
		assert.equal(existsSync(join(out, 'diff', path)), false, path)
		assert.equal(existsSync(join(out, 'comparison', path)), false, path)
	}
	assert.equal(existsSync(join(out, 'comparison/screenshots/a.png')), false)
	assert.equal(readFileSync(join(out, 'before/docs/images/c.gif'), 'utf8'), 'GIF89a before')
	assert.equal(readFileSync(join(out, 'after/docs/images/c.gif'), 'utf8'), 'GIF89a after')
	assert.equal(readFileSync(join(out, 'before/screenshots/gone.png'), 'utf8'), 'PNG gone')
	assert.equal(existsSync(join(out, 'after/screenshots/gone.png')), false)
	assert.equal(existsSync(join(out, 'before/screenshots/new.png')), false)
	assert.equal(readFileSync(join(out, 'after/screenshots/new.png'), 'utf8'), 'PNG new')
	// The report of the artifact names each difference where the artifact holds it.
	const report = JSON.parse(readFileSync(join(out, 'report.json'), 'utf8'))
	assert.deepEqual(report.pictures, pictures)
	assert.equal(report.pictures[1].diff, 'diff/docs/images/c.gif.png')
	// The report names the comparison and its frame where the artifact holds it, as the
	// pager reads them.
	assert.deepEqual(
		[report.pictures[1].comparison, report.pictures[1].frame, report.pictures[1].frames],
		['comparison/docs/images/c.gif.png', 3, 4],
	)
	assert.deepEqual([report.pictures[3].comparison, report.pictures[3].frame, report.pictures[3].frames], [null, null, null])
	const text = readFileSync(join(out, 'summary.md'), 'utf8')
	assert.match(text, /6 pictures look different/)
	assert.match(text, /\| `docs\/images\/c\.gif` \| changed, most in frame 3 of 4 \| 0\.11 % \| `comparison\/docs\/images\/c\.gif\.png` \|/)
})

test('compare fails when the difference that keep names is missing or no PNG', function() {
	const kept = folder()
	const report = { pictures: [{ path: 'docs/images/b.webp', status: 'changed', share: 0.5, diff: 'diff/docs/images/b.webp.png' }] }
	file(kept, 'files/docs/images/b.webp', 'WebP after')
	file(kept, 'report.json', JSON.stringify(report))
	const committed = () => Buffer.from('WebP before')
	assert.throws(() => compare(kept, folder(), { committed }), /ENOENT/)
	file(kept, 'diff/docs/images/b.webp.png', 'RIFF no PNG')
	assert.throws(() => compare(kept, folder(), { committed }),
		/keep drew diff\/docs\/images\/b\.webp\.png, the difference of docs\/images\/b\.webp, which is no PNG/)
})

test('compare fails when the comparison that keep names is missing or no PNG', function() {
	const kept = folder()
	const report = {
		pictures: [{
			path: 'docs/images/b.gif',
			status: 'changed',
			share: 0.5,
			diff: 'diff/docs/images/b.gif.png',
			comparison: 'comparison/docs/images/b.gif.png',
			frame: 2,
			frames: 3,
		}],
	}
	file(kept, 'files/docs/images/b.gif', 'GIF89a after')
	file(kept, 'diff/docs/images/b.gif.png', drawn('b'))
	file(kept, 'report.json', JSON.stringify(report))
	const committed = () => Buffer.from('GIF89a before')
	assert.throws(() => compare(kept, folder(), { committed }), /ENOENT/)
	file(kept, 'comparison/docs/images/b.gif.png', 'GIF89a no PNG')
	assert.throws(() => compare(kept, folder(), { committed }),
		/keep drew comparison\/docs\/images\/b\.gif\.png, the comparison of docs\/images\/b\.gif, which is no PNG/)
})

test('compare reports no change when keep left no folder', function() {
	const out = folder()
	const pictures = compare(join(out, 'missing'), out, {
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

test('the script reads each picture before from the commit of the repository it runs in', function() {
	const repository = folder()
	const kept = folder()
	const out = folder()
	// A repository of its own, which no variable of git that points at another reaches,
	// such as GIT_DIR in the hook before a push, and no setting of the machine.
	const config = join(folder(), 'gitconfig')
	writeFileSync(config, '')
	const env = {
		...Object.fromEntries(Object.entries(process.env).filter(([name]) => !name.startsWith('GIT_'))),
		GIT_CONFIG_GLOBAL: config,
		GIT_CONFIG_NOSYSTEM: '1',
	}
	const git = (...args) => execFileSync('git', ['-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', ...args],
		{ cwd: repository, env })
	git('init', '--quiet')
	file(repository, 'docs/images/a.gif', 'GIF89a before')
	git('add', '.')
	git('commit', '--quiet', '--no-gpg-sign', '-m', 'A picture')
	file(kept, 'files/docs/images/a.gif', 'GIF89a after')
	file(kept, 'diff/docs/images/a.gif.png', drawn('a'))
	file(kept, 'comparison/docs/images/a.gif.png', drawn('a before and after'))
	file(kept, 'report.json', JSON.stringify({
		pictures: [{
			path: 'docs/images/a.gif',
			status: 'changed',
			share: 0.25,
			diff: 'diff/docs/images/a.gif.png',
			comparison: 'comparison/docs/images/a.gif.png',
			frame: 2,
			frames: 5,
		}],
	}))

	const run = spawnSync(process.execPath, [script, kept, out], { cwd: repository, env, encoding: 'utf8' })
	assert.equal(run.status, 0, run.stderr)
	assert.match(run.stdout, /^1 picture\(s\) look different from the committed ones, 1 with a comparison before and after; /)
	assert.equal(readFileSync(join(out, 'before/docs/images/a.gif'), 'utf8'), 'GIF89a before')
	assert.equal(readFileSync(join(out, 'after/docs/images/a.gif'), 'utf8'), 'GIF89a after')
	assert.deepEqual(readFileSync(join(out, 'diff/docs/images/a.gif.png')), drawn('a'))
	assert.deepEqual(readFileSync(join(out, 'comparison/docs/images/a.gif.png')), drawn('a before and after'))
})
