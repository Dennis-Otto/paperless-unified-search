/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The screenshots that the pages of the website, docs/*.md, load from main. The hooks of
// the blueprint give a picture of the website its width and height, so that the page
// doesn't shift while it loads, but can't read those of a picture of main: the home
// page names them for each of its screenshots, and each width and height that a page
// names has the shape of its file, also at a smaller size, such as the phones of the
// gallery at the width of 120.

import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { test } from 'node:test'

const ROOT = new URL('../../', import.meta.url)
const HOME = ['docs/index.md', 'docs/index.de.md']

// A picture of Markdown from main, with its attributes, such as
// ![Text](https://github.com/Dennis-Otto/paperless-unified-search/raw/main/screenshots/a.png#only-dark){ width="1600" height="900" }
const PICTURE = /!\[(?:[^[\]]|\[[^\]]*\])*\]\(https:\/\/github\.com\/Dennis-Otto\/paperless-unified-search\/raw\/main\/(?<path>screenshots\/[^)#?\s]+\.png)[^)\s]*\)(?<attributes>\{[^}]*\})?/g

// The screenshots that the Markdown shows from main, with the width and the height
// that it names, or null for one that it doesn't name.
function screenshots(markdown) {
	return [...markdown.matchAll(PICTURE)].map(({ groups }) => {
		const named = (name) => {
			const found = groups.attributes?.match(new RegExp(`\\b${name}="(\\d+)"`))
			return found ? Number(found[1]) : null
		}
		return { path: groups.path, width: named('width'), height: named('height') }
	})
}

// The width and the height of a PNG, from its header.
function size(path) {
	const bytes = readFileSync(new URL(path, ROOT))
	assert.equal(bytes.toString('latin1', 12, 16), 'IHDR', `${path} is no PNG`)
	return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) }
}

const pages = readdirSync(new URL('docs/', ROOT)).filter((name) => name.endsWith('.md')).map((name) => `docs/${name}`)

test('the pictures of main that a page shows, with the size that it names', function() {
	const raw = 'https://github.com/Dennis-Otto/paperless-unified-search/raw/main/'
	const markdown = [
		`![A [linked] text](${raw}screenshots/a.png#only-dark){ width="1600" height="900" }`,
		`![B](${raw}screenshots/b.png){ width="120" }, ![C](${raw}screenshots/c.png?raw=1)`,
		`![D](${raw}img/app.svg) ![E](screenshots/e.png) ![F](${raw}screenshots/f.gif)`,
	].join('\n')
	assert.deepEqual(screenshots(markdown), [
		{ path: 'screenshots/a.png', width: 1600, height: 900 },
		{ path: 'screenshots/b.png', width: 120, height: null },
		{ path: 'screenshots/c.png', width: null, height: null },
	])
})

test('the home page names the width and the height of each of its screenshots', function() {
	for (const page of HOME) {
		const shown = screenshots(readFileSync(new URL(page, ROOT), 'utf8'))
		assert.ok(shown.length > 0, `${page} shows no screenshot of main`)
		for (const { path, width, height } of shown) {
			assert.ok(width !== null && height !== null, `${page} shows ${path} without { width="…" height="…" }, so the page shifts while it loads`)
		}
	}
})

test('each width and height that a page names has the shape of its screenshot', function() {
	let checked = 0
	for (const page of pages) {
		for (const { path, width, height } of screenshots(readFileSync(new URL(page, ROOT), 'utf8'))) {
			if (width === null || height === null) {
				continue
			}
			const file = size(path)
			// At a smaller size, the height may round to the next pixel.
			assert.ok(
				Math.abs(height - (width * file.height) / file.width) <= 1,
				`${page} shows ${path} at ${width} × ${height}, but the file is ${file.width} × ${file.height}: name its width and height, or a smaller width and the height of the same shape`,
			)
			checked++
		}
	}
	assert.ok(checked > 0)
})
