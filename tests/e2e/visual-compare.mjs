// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The pictures of a pull request before and after, for the visual check (visual.yml):
//   node tests/e2e/visual-compare.mjs KEPT OUT
// scripts/screenshots.sh renders every picture in place, and
// `python3 .github/blueprint.py screenshots keep KEPT`, the comparison of the screenshot
// bot, gives each picture whose pixels stayed the same its committed file back and
// leaves the others in KEPT: their files under KEPT/files/, the picture of the
// difference of each changed picture that kept its size and frames under KEPT/diff/,
// a PNG of its changed pixels in red over a pale copy of the new picture, and their
// list in KEPT/report.json, with the share of the pixels that changed and the
// difference as `diff`; KEPT stays away when no picture changed. This script writes the
// artifact of the check into OUT: each picture as the commit has it in before/, as
// rendered in after/ and keep's picture of its difference in diff/, of a WebP or a GIF
// as well as of a PNG; then report.json, which visual-comment.yml turns into the
// comment on the pull request, and summary.md for the summary of the job.

import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

// What keep may report of a picture; a picture is new, changed or deleted.
const STATUSES = new Set(['new', 'changed', 'deleted'])
// A path of plain names inside the repository, as keep lets through.
const PATH = /^(?!.*(^|\/)\.\.?(\/|$))[A-Za-z0-9_.][A-Za-z0-9._/-]*$/
// The first bytes of every PNG, which keep draws each difference as.
const PNG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

// Where keep puts the picture of the difference of the picture at path, and where the
// artifact holds it: under diff/ at its path, as a PNG, whose name ends in .png.
export function diffPath(path) {
	return `diff/${path}${path.toLowerCase().endsWith('.png') ? '' : '.png'}`
}

// The pictures of keep's report, each checked: its path, its status, the share of its
// pixels that changed, null when its size or number of frames changed or when it is
// new or deleted, and the picture of its difference, null without one. keep names a
// difference only for a changed picture, at diffPath of its path; any other is refused.
export function readReport(text) {
	const report = JSON.parse(text)
	if (!report || !Array.isArray(report.pictures)) {
		throw new Error('The report of keep has no list of pictures.')
	}
	return report.pictures.map((picture) => {
		const { path, status, share, diff = null } = picture ?? {}
		if (typeof path !== 'string' || !PATH.test(path) || !STATUSES.has(status)) {
			throw new Error(`The report of keep names a picture that it can't: ${JSON.stringify(picture)}.`)
		}
		if (diff !== null && (status !== 'changed' || diff !== diffPath(path))) {
			throw new Error(`The report of keep names a difference that it doesn't draw: ${JSON.stringify(picture)}.`)
		}
		const known = typeof share === 'number' && share >= 0 && share <= 1
		return { path, status, share: known ? share : null, diff }
	})
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

// Why a picture that looks different may have no picture of its difference.
const WITHOUT = 'a new or a deleted picture, a video and a picture whose size or number of frames changed have none'

// The summary of the job: the pictures that look different, which of them have a
// picture of their difference, or that none does.
export function summary(pictures) {
	const lines = ['### Visual check', '']
	if (pictures.length === 0) {
		lines.push('Every picture of `scripts/screenshots.sh` looks as committed.')
		return `${lines.join('\n')}\n`
	}
	const many = pictures.length === 1 ? '1 picture looks' : `${pictures.length} pictures look`
	const drawn = pictures.filter(({ diff }) => diff !== null).length
	lines.push(
		`${many} different from the committed ones:`,
		'',
		'| Picture | Change | Pixels changed | Changed pixels in red |',
		'| --- | --- | ---: | --- |',
		...pictures.map(({ path, status, share, diff }) =>
			`| \`${path}\` | ${status} | ${percent(share)} | ${diff === null ? '–' : `\`${diff}\``} |`),
		'',
		drawn === 0
			? 'The artifact `visual` of this run holds each picture before and after. None of them has a picture of '
				+ `its changed pixels: ${WITHOUT}.`
			: 'The artifact `visual` of this run holds each picture before and after, and in `diff/` the picture of the '
				+ 'difference that the last column names: each changed pixel in red, with a red frame around each place '
				+ `that changed${drawn < pictures.length ? `; ${WITHOUT}` : ''}.`,
		'The check only reports: when the change is intended, the screenshot bot renews the pictures after the merge.',
	)
	return `${lines.join('\n')}\n`
}

function write(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

// Writes the artifact into out from the folder kept of keep; committed returns a file
// as the commit has it. keep drew the difference of each picture in the same job, so
// one that is missing or no PNG fails the check rather than leave its picture without.
export function compare(kept, out, { committed }) {
	const report = join(kept, 'report.json')
	const pictures = existsSync(report) ? readReport(readFileSync(report, 'utf8')) : []
	for (const { path, status, diff } of pictures) {
		if (status !== 'new') {
			write(join(out, 'before', path), committed(path))
		}
		if (status !== 'deleted') {
			write(join(out, 'after', path), readFileSync(join(kept, 'files', path)))
		}
		if (diff !== null) {
			const drawn = readFileSync(join(kept, diff))
			if (!drawn.subarray(0, PNG.length).equals(PNG)) {
				throw new Error(`keep drew ${diff}, the difference of ${path}, which is no PNG.`)
			}
			write(join(out, diff), drawn)
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
	const committed = (path) => execFileSync('git', ['show', `HEAD:${path}`], { maxBuffer: 256 * 1024 * 1024 })
	const pictures = compare(kept, out, { committed })
	const drawn = pictures.filter(({ diff }) => diff !== null).length
	console.log(`${pictures.length} picture(s) look different from the committed ones, ${drawn} with a picture of `
		+ `their changed pixels; the artifact is in ${out}.`)
}
