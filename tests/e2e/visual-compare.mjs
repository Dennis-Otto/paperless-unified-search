// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The pictures of a pull request before and after, for the visual check (visual.yml):
//   node tests/e2e/visual-compare.mjs KEPT OUT
// scripts/screenshots.sh renders every picture in place, and
// `python3 .github/blueprint.py screenshots keep KEPT`, the comparison of the screenshot
// bot, gives each picture whose pixels stayed the same its committed file back and
// leaves the others in KEPT: their files under KEPT/files/ and their list in
// KEPT/report.json, with the share of the pixels that changed; KEPT stays away when no
// picture changed. This script writes the artifact of the check into OUT: each picture
// as the commit has it in before/, as rendered in after/ and, for a PNG of the same size,
// the pixels that changed in red over a pale copy of the new picture in diff/; then
// report.json, which visual-comment.yml turns into the comment on the pull request, and
// summary.md for the summary of the job.

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

// What keep may report of a picture; a picture is new, changed or deleted.
const STATUSES = new Set(['new', 'changed', 'deleted'])
// A path of plain names inside the repository, as keep lets through.
const PATH = /^(?!.*(^|\/)\.\.?(\/|$))[A-Za-z0-9_.][A-Za-z0-9._/-]*$/

// The pictures of keep's report, each checked: its path, its status and the share of
// its pixels that changed, null when its size or number of frames changed or when it
// is new or deleted.
export function readReport(text) {
	const report = JSON.parse(text)
	if (!report || !Array.isArray(report.pictures)) {
		throw new Error('The report of keep has no list of pictures.')
	}
	return report.pictures.map((picture) => {
		const { path, status, share } = picture ?? {}
		if (typeof path !== 'string' || !PATH.test(path) || !STATUSES.has(status)) {
			throw new Error(`The report of keep names a picture that it can't: ${JSON.stringify(picture)}.`)
		}
		const known = typeof share === 'number' && share >= 0 && share <= 1
		return { path, status, share: known ? share : null }
	})
}

// The pixels that changed between two pictures of the same size, as RGBA: each changed
// pixel red, every other one a pale gray of the new picture, so that the changes stand
// out where they are. A pixel that is fully transparent in both counts as the same,
// whatever its color, as keep counts it. Null when the sizes differ.
export function difference(before, after) {
	if (before.width !== after.width || before.height !== after.height) {
		return null
	}
	const data = new Uint8Array(after.width * after.height * 4)
	let changed = 0
	for (let i = 0; i < data.length; i += 4) {
		const transparent = before.data[i + 3] === 0 && after.data[i + 3] === 0
		const same = transparent || (before.data[i] === after.data[i] && before.data[i + 1] === after.data[i + 1]
			&& before.data[i + 2] === after.data[i + 2] && before.data[i + 3] === after.data[i + 3])
		if (same) {
			const light = 0.299 * after.data[i] + 0.587 * after.data[i + 1] + 0.114 * after.data[i + 2]
			const alpha = after.data[i + 3] / 255
			const pale = Math.round(255 - ((255 - light) * alpha) / 4)
			data.set([pale, pale, pale, 255], i)
		} else {
			changed++
			data.set([220, 38, 38, 255], i)
		}
	}
	return { width: after.width, height: after.height, data, changed }
}

// The share of the pixels that changed, for people.
export function percent(share) {
	if (share === null) {
		return '–'
	}
	if (share >= 1) {
		return '100 %'
	}
	return share < 0.0001 ? '< 0.01 %' : `${(share * 100).toFixed(2)} %`
}

// The summary of the job: the pictures that look different, or that none does.
export function summary(pictures) {
	const lines = ['### Visual check', '']
	if (pictures.length === 0) {
		lines.push('Every picture of `scripts/screenshots.sh` looks as committed.')
		return `${lines.join('\n')}\n`
	}
	const many = pictures.length === 1 ? '1 picture looks' : `${pictures.length} pictures look`
	lines.push(
		`${many} different from the committed ones:`,
		'',
		'| Picture | Change | Pixels changed |',
		'| --- | --- | ---: |',
		...pictures.map(({ path, status, share }) => `| \`${path}\` | ${status} | ${percent(share)} |`),
		'',
		'The artifact `visual` of this run holds each picture before and after, and the pixels that changed in red. '
			+ 'The check only reports: when the change is intended, the screenshot bot renews the pictures after the merge.',
	)
	return `${lines.join('\n')}\n`
}

function write(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

// Writes the artifact into out from the folder kept of keep. png reads and writes PNG
// files as { width, height, data }, and committed returns a file as the commit has it.
export function compare(kept, out, { png, committed }) {
	const report = join(kept, 'report.json')
	const pictures = existsSync(report) ? readReport(readFileSync(report, 'utf8')) : []
	for (const { path, status } of pictures) {
		const before = status === 'new' ? null : committed(path)
		const after = status === 'deleted' ? null : readFileSync(join(kept, 'files', path))
		if (before) {
			write(join(out, 'before', path), before)
		}
		if (after) {
			write(join(out, 'after', path), after)
		}
		if (before && after && path.toLowerCase().endsWith('.png')) {
			const diff = difference(png.read(before), png.read(after))
			if (diff) {
				write(join(out, 'diff', path), png.write(diff))
			}
		}
	}
	write(join(out, 'report.json'), `${JSON.stringify({ pictures }, null, 1)}\n`)
	write(join(out, 'summary.md'), summary(pictures))
	return pictures
}

// Run as a script, not imported by its tests.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
	const [kept, out] = process.argv.slice(2)
	if (!kept || !out) {
		console.error('Usage: node tests/e2e/visual-compare.mjs KEPT OUT')
		process.exit(2)
	}
	// pngjs comes with the packages of tests/e2e, which the job installs.
	const { PNG } = (await import('pngjs')).default
	const png = {
		read: (buffer) => PNG.sync.read(buffer),
		write: ({ width, height, data }) => PNG.sync.write(Object.assign(new PNG({ width, height }), { data: Buffer.from(data) })),
	}
	const committed = (path) => execFileSync('git', ['show', `HEAD:${path}`], { maxBuffer: 256 * 1024 * 1024 })
	const pictures = compare(kept, out, { png, committed })
	console.log(`${pictures.length} picture(s) look different from the committed ones; the artifact is in ${out}.`)
}
