// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The pictures of a pull request before and after, for the visual check (visual.yml):
//   node tests/e2e/visual-compare.mjs KEPT OUT
// scripts/screenshots.sh renders every picture in place, and
// `python3 .github/blueprint.py screenshots keep KEPT`, the comparison of the screenshot
// bot, gives each picture whose pixels stayed the same its committed file back and
// leaves the others in KEPT: their files under KEPT/files/, and for each changed
// picture that kept its size and frames, its comparison under KEPT/comparison/, the
// place of the change before and after in one PNG, in the picture's own colors with a
// red frame around each change, and the picture of its difference under KEPT/diff/, a
// PNG of its changed pixels in red over a pale copy of the new picture. Both show the
// same frame: for an animation the one with the most changed pixels. Their list is in
// KEPT/report.json, with the share of the pixels that changed, the difference as
// `diff`, the comparison as `comparison` and its frame as `frame` of `frames`; KEPT
// stays away when no picture changed. This script writes the artifact of the check into
// OUT: each picture as the commit has it in before/, as rendered in after/, keep's
// comparison in comparison/ and its picture of the difference in diff/, of a WebP or a
// GIF as well as of a PNG; then report.json, which visual-comment.yml turns into the
// comment on the pull request and the pager reads, and summary.md for the summary of
// the job.

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

// Where keep puts the comparison of the picture at path, and where the artifact holds
// it: under comparison/ at its path, as a PNG, whose name ends in .png.
export function comparisonPath(path) {
	return `comparison/${diffPath(path).slice('diff/'.length)}`
}

// A count of keep's report, such as the number of a frame: a whole number from 1.
function counted(value) {
	return Number.isSafeInteger(value) && value >= 1
}

// The pictures of keep's report, each checked: its path, its status, the share of its
// pixels that changed, null when its size or number of frames changed or when it is
// new or deleted, the picture of its difference and its comparison, each null without
// one, and the frame of the comparison, from 1, of the frames of the picture, both null
// without a comparison. keep names a difference and a comparison only for a changed
// picture, at diffPath and comparisonPath of its path, and the frame and the number of
// frames together with the comparison, the frame one of them; any other is refused. A
// report of a keep before the comparisons names none.
export function readReport(text) {
	const report = JSON.parse(text)
	if (!report || !Array.isArray(report.pictures)) {
		throw new Error('The report of keep has no list of pictures.')
	}
	return report.pictures.map((picture) => {
		const { path, status, share, diff = null, comparison = null, frame = null, frames = null } = picture ?? {}
		if (typeof path !== 'string' || !PATH.test(path) || !STATUSES.has(status)) {
			throw new Error(`The report of keep names a picture that it can't: ${JSON.stringify(picture)}.`)
		}
		if (diff !== null && (status !== 'changed' || diff !== diffPath(path))) {
			throw new Error(`The report of keep names a difference that it doesn't draw: ${JSON.stringify(picture)}.`)
		}
		if (comparison !== null && (status !== 'changed' || comparison !== comparisonPath(path))) {
			throw new Error(`The report of keep names a comparison that it doesn't draw: ${JSON.stringify(picture)}.`)
		}
		const framed = comparison === null
			? frame === null && frames === null
			: counted(frame) && counted(frames) && frame <= frames
		if (!framed) {
			throw new Error(`The report of keep names a frame that its comparison doesn't show: ${JSON.stringify(picture)}.`)
		}
		const known = typeof share === 'number' && share >= 0 && share <= 1
		return { path, status, share: known ? share : null, diff, comparison, frame, frames }
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

// Why a picture that looks different may have no comparison and no picture of its
// difference.
const WITHOUT = 'a new or a deleted picture, a video and a picture whose size or number of frames changed have neither'

// How a picture changed, and for an animation which of its frames the comparison
// shows, as the screenshot bot names it.
export function change({ status, frame, frames }) {
	return frames !== null && frames > 1 ? `${status}, most in frame ${frame} of ${frames}` : status
}

// The summary of the job: the pictures that look different, which of them have a
// comparison and a picture of their difference, of which frame, or that none does.
export function summary(pictures) {
	const lines = ['### Visual check', '']
	if (pictures.length === 0) {
		lines.push('Every picture of `scripts/screenshots.sh` looks as committed.')
		return `${lines.join('\n')}\n`
	}
	const many = pictures.length === 1 ? '1 picture looks' : `${pictures.length} pictures look`
	const drawn = pictures.filter(({ diff, comparison }) => diff !== null || comparison !== null).length
	const named = (file) => (file === null ? '–' : `\`${file}\``)
	lines.push(
		`${many} different from the committed ones:`,
		'',
		'| Picture | Change | Pixels changed | Before and after | Changed pixels in red |',
		'| --- | --- | ---: | --- | --- |',
		...pictures.map((picture) => `| \`${picture.path}\` | ${change(picture)} | ${percent(picture.share)} | `
			+ `${named(picture.comparison)} | ${named(picture.diff)} |`),
		'',
		drawn === 0
			? 'The artifact `visual` of this run holds each picture before and after. None of them has a comparison '
				+ `or a picture of its changed pixels: ${WITHOUT}.`
			: 'The artifact `visual` of this run holds each picture before and after, in `comparison/` the place of '
				+ 'the change before and after in one picture, in its own colors and cut to the changes, with a red frame '
				+ 'around each change on both sides, and in `diff/` the whole picture with every changed pixel in red, '
				+ 'behind a thin veil, as the last two columns name them. Both show the same frame: for an animation the '
				+ `one with the most changes, which the column *Change* names${drawn < pictures.length ? `; ${WITHOUT}` : ''}.`,
		'The check only reports: when the change is intended, the screenshot bot renews the pictures after the merge.',
	)
	return `${lines.join('\n')}\n`
}

function write(path, data) {
	mkdirSync(dirname(path), { recursive: true })
	writeFileSync(path, data)
}

// Writes the artifact into out from the folder kept of keep; committed returns a file
// as the commit has it. keep drew the comparison and the difference of each picture in
// the same job, so one that is missing or no PNG fails the check rather than leave its
// picture without.
export function compare(kept, out, { committed }) {
	const report = join(kept, 'report.json')
	const pictures = existsSync(report) ? readReport(readFileSync(report, 'utf8')) : []
	for (const { path, status, diff, comparison } of pictures) {
		if (status !== 'new') {
			write(join(out, 'before', path), committed(path))
		}
		if (status !== 'deleted') {
			write(join(out, 'after', path), readFileSync(join(kept, 'files', path)))
		}
		for (const [file, what] of [[comparison, 'comparison'], [diff, 'difference']]) {
			if (file === null) {
				continue
			}
			const drawn = readFileSync(join(kept, file))
			if (!drawn.subarray(0, PNG.length).equals(PNG)) {
				throw new Error(`keep drew ${file}, the ${what} of ${path}, which is no PNG.`)
			}
			write(join(out, file), drawn)
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
	const drawn = pictures.filter(({ comparison }) => comparison !== null).length
	console.log(`${pictures.length} picture(s) look different from the committed ones, ${drawn} with a comparison `
		+ `before and after; the artifact is in ${out}.`)
}
