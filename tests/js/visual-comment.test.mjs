/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The Python of .github/workflows/visual-comment.yml, which writes the comment of the
// visual check from the report of its artifact alone: the pictures that look different,
// whether the artifact holds the picture of the difference of each, and the link to it,
// which promises red pixels only when one of them has some. The test takes the script
// out of the workflow and runs it as the job does, with Python 3.

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, test } from 'node:test'

const workflow = readFileSync(new URL('../../.github/workflows/visual-comment.yml', import.meta.url), 'utf8')
const python = process.env.PYTHON ?? (process.platform === 'win32' ? 'python' : 'python3')
const folders = []

afterEach(function() {
	for (const folder of folders.splice(0)) {
		rmSync(folder, { recursive: true, force: true })
	}
})

// The script between <<'PYTHON' and PYTHON, without the indentation of the YAML.
function script() {
	const lines = workflow.split('\n')
	const start = lines.findIndex((line) => line.endsWith("<<'PYTHON'")) + 1
	const end = lines.findIndex((line, index) => index >= start && line.trim() === 'PYTHON')
	assert.ok(start > 0 && end > start, 'visual-comment.yml holds the script of the comment.')
	const body = lines.slice(start, end)
	const indent = Math.min(...body.filter((line) => line.trim()).map((line) => line.match(/^ */)[0].length))
	return body.map((line) => line.slice(indent)).join('\n')
}

// Runs the script on a folder as the job downloads it: the list of the artifacts of the
// run, and the artifact visual with its report, when there is one.
function comment({ report, artifacts = 'visual 4242\n', conclusion = 'success' }) {
	const folder = mkdtempSync(join(tmpdir(), 'visual-comment-'))
	folders.push(folder)
	writeFileSync(join(folder, 'artifacts.txt'), artifacts)
	if (report !== undefined) {
		mkdirSync(join(folder, 'visual'))
		writeFileSync(join(folder, 'visual', 'report.json'), typeof report === 'string' ? report : JSON.stringify(report))
	}
	const run = spawnSync(python, ['-', folder], {
		input: script(),
		encoding: 'utf8',
		env: {
			...process.env,
			PYTHONUTF8: '1',
			HEAD_SHA: '0123456789abcdef',
			RUN_URL: 'https://github.com/o/r/actions/runs/7',
			RUN_ID: '7',
			GITHUB_SERVER_URL: 'https://github.com',
			GH_REPO: 'o/r',
			CONCLUSION: conclusion,
		},
	})
	assert.equal(run.status, 0, run.stderr)
	// Python on Windows writes the lines of a text file with CRLF, the job on Linux with LF.
	const text = readFileSync(join(folder, 'comment.md'), 'utf8').replaceAll('\r\n', '\n')
	return { post: run.stdout.trim(), text }
}

test('the comment names the pictures with their changed pixels in red, and only where keep draws them', function() {
	const { post, text } = comment({
		report: {
			pictures: [
				{ path: 'docs/images/dark-poster.webp', status: 'changed', share: 0.0816, diff: 'diff/docs/images/dark-poster.webp.png' },
				{ path: 'docs/images/search-and-open-dark.gif', status: 'changed', share: 0.0011, diff: 'diff/docs/images/search-and-open-dark.gif.png' },
				{ path: 'screenshots/01-unified-search.png', status: 'changed', share: 0.5, diff: 'diff/screenshots/01-unified-search.png' },
				{ path: 'docs/images/search-and-open-dark.mp4', status: 'changed', share: null, diff: null },
				// A difference at another place, or of a new picture, is none.
				{ path: 'screenshots/02-forged.png', status: 'changed', share: 0.1, diff: '../../outside.png' },
				{ path: 'screenshots/13-new.png', status: 'new', share: null, diff: 'diff/screenshots/13-new.png' },
				// What a report may not hold is left out.
				{ path: '../outside.png', status: 'changed', share: 0.1, diff: 'diff/../outside.png' },
				'no picture',
			],
		},
	})
	assert.equal(post, 'always')
	assert.match(text, /^<!-- visual-check -->\n### Visual check\n\nRendered by `scripts\/screenshots\.sh` for 0123456: 6 pictures look different/)
	assert.match(text, /\| Picture \| Change \| Pixels changed \| In red \|\n\| --- \| --- \| ---: \| --- \|\n/)
	assert.match(text, /\| `docs\/images\/dark-poster\.webp` \| changed \| 8\.16 % \| yes \|\n/)
	assert.match(text, /\| `docs\/images\/search-and-open-dark\.gif` \| changed \| 0\.11 % \| yes \|\n/)
	assert.match(text, /\| `screenshots\/01-unified-search\.png` \| changed \| 50\.00 % \| yes \|\n/)
	assert.match(text, /\| `docs\/images\/search-and-open-dark\.mp4` \| changed \| – \| – \|\n/)
	assert.match(text, /\| `screenshots\/02-forged\.png` \| changed \| 10\.00 % \| – \|\n/)
	assert.match(text, /\| `screenshots\/13-new\.png` \| new \| – \| – \|\n/)
	assert.doesNotMatch(text, /outside/)
	assert.match(text, /\[The pictures before and after\]\(https:\/\/github\.com\/o\/r\/actions\/runs\/7\/artifacts\/4242\), and in `diff\/` each changed pixel in red /)
	assert.match(text, /This check only reports\./)
})

test('the comment promises no red pixels when no picture has its difference', function() {
	const { text } = comment({
		report: { pictures: [{ path: 'docs/images/search-and-open.mp4', status: 'changed', share: null, diff: null }] },
	})
	assert.match(text, /1 picture looks different/)
	assert.match(text, /\[The pictures before and after\]\([^)]+\)\. None of them has its changed pixels in red: /)
	assert.doesNotMatch(text, /in `diff\/`/)
})

test('the comment links no artifact that the run did not keep', function() {
	const { text } = comment({
		report: { pictures: [{ path: 'a.gif', status: 'changed', share: 0.5, diff: 'diff/a.gif.png' }] },
		artifacts: 'pr-report-visual 1\n',
	})
	assert.match(text, /\| `a\.gif` \| changed \| 50\.00 % \| yes \|/)
	assert.doesNotMatch(text, /The pictures before and after/)
	assert.match(text, /This check only reports\./)
})

test('the comment only updates an existing one when every picture looks as committed', function() {
	const { post, text } = comment({ report: { pictures: [] } })
	assert.equal(post, 'existing')
	assert.match(text, /for 0123456: every picture looks as committed\.\n$/)
})

test('the comment says why there is no report', function() {
	assert.match(comment({ conclusion: 'failure' }).text, /could not be rendered for 0123456; the \[run\]/)
	assert.match(comment({ report: 'no JSON' }).text, /The \[run\]\(https:\/\/github\.com\/o\/r\/actions\/runs\/7\) left no report/)
})
