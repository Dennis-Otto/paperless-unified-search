/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/search-picture.mjs, with which screenshots.mjs cuts the search alone out of
// the page for the social preview, and .github/social-preview.html, which shows that
// picture whole. The social preview cut its picture out of the whole screenshot at a
// place typed in once, and showed the dimmed page below the panel once Nextcloud moved
// its search into the top bar (#497).

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { test } from 'node:test'
import { SEARCH_ALONE, clipAround, strayPixel } from '../e2e/search-picture.mjs'

const ROOT = new URL('../../', import.meta.url)
const VIEWPORT = { width: 1600, height: 900 }
// The field and the panel of the search of Nextcloud 35 in the top bar.
const FIELD = { x: 520, y: 5, width: 560, height: 34 }
const PANEL = { x: 500, y: 45, width: 600, height: 583 }

test('the picture spans the field and the panel of the search, and nothing more', function() {
	assert.deepEqual(clipAround([FIELD, PANEL], VIEWPORT), { x: 500, y: 5, width: 600, height: 623 })
})

test('a box between pixels widens the picture to the whole pixels around it', function() {
	assert.deepEqual(clipAround([{ x: 10.5, y: 20.25, width: 5, height: 4.5 }], VIEWPORT), { x: 10, y: 20, width: 6, height: 5 })
})

test('the picture needs the boxes of what it shows', function() {
	assert.throws(() => clipAround([], VIEWPORT), /needs the boxes/)
})

test('a part without a size fails, as when Nextcloud hides the panel', function() {
	assert.throws(() => clipAround([FIELD, { ...PANEL, height: 0 }], VIEWPORT), /has no size/)
	assert.throws(() => clipAround([{ ...FIELD, width: Number.NaN }], VIEWPORT), /has no size/)
})

test('a part that reaches out of the page fails, as the picture would cut it off', function() {
	for (const part of [
		{ ...PANEL, x: -1 },
		{ ...PANEL, y: -1 },
		{ ...PANEL, x: 1001 },
		{ ...PANEL, height: 856 },
	]) {
		assert.throws(() => clipAround([FIELD, part], VIEWPORT), /reaches out of the page of 1600×900/)
	}
	assert.deepEqual(clipAround([{ x: 0, y: 0, ...VIEWPORT }], VIEWPORT), { x: 0, y: 0, ...VIEWPORT })
})

// A picture of RGBA bytes, width pixels per row, from the alpha of its pixels, row by row.
const picture = (...rows) => Uint8Array.from(rows.flat().flatMap((alpha) => [255, 255, 255, alpha]))

test('a picture of the two parts alone has no stray pixel', function() {
	const rgba = picture(
		[255, 255, 0, 0],
		[0, 0, 0, 0],
		[255, 255, 255, 255],
	)
	assert.equal(strayPixel(rgba, 4, [{ x: 0, y: 0, width: 2, height: 1 }, { x: 0, y: 2, width: 4, height: 1 }]), null)
})

test('a pixel of the page between the parts is stray, even a faint one', function() {
	const rgba = picture(
		[255, 255, 0, 0],
		[0, 0, 1, 0],
		[255, 255, 255, 255],
	)
	assert.deepEqual(strayPixel(rgba, 4, [{ x: 0, y: 0, width: 2, height: 1 }, { x: 0, y: 2, width: 4, height: 1 }]), { x: 2, y: 1 })
})

test('the edge of a part between pixels belongs to the part', function() {
	const rgba = picture(
		[0, 128, 255, 128],
		[0, 0, 0, 0],
	)
	assert.equal(strayPixel(rgba, 4, [{ x: 1.5, y: 0.25, width: 2, height: 0.5 }]), null)
	assert.deepEqual(strayPixel(rgba, 4, [{ x: 2, y: 0, width: 2, height: 1 }]), { x: 1, y: 0 })
})

const PAGE = readFileSync(new URL('.github/social-preview.html', ROOT), 'utf8').replace(/<!--[\s\S]*?-->/g, '')
const css = (selector) => PAGE.match(new RegExp(`(?:^|[\\s}])${selector.replace('.', '\\.')}\\s*\\{([^}]*)\\}`))?.[1]

test('the social preview shows the picture of the search alone, which screenshots.mjs takes', function() {
	const images = [...PAGE.matchAll(/<img\b[^>]*\bclass="search"[^>]*>/g)].map(([tag]) => tag)
	assert.equal(images.length, 1, 'one picture of the search, with the class search')
	assert.match(images[0], new RegExp(`\\bsrc="\\.\\./screenshots/${SEARCH_ALONE.replaceAll('.', '\\.')}"`))
	assert.ok(existsSync(new URL(`screenshots/${SEARCH_ALONE}`, ROOT)), `screenshots/${SEARCH_ALONE} is in the repository`)
})

test('the social preview cuts no screenshot out at a place of its own', function() {
	assert.doesNotMatch(PAGE, /url\([^)]*screenshots\//, 'no screenshot as a background, which would show a part of it at a typed place')
})

test('the social preview shows the picture of the search whole, in its own shape', function() {
	const rule = css('.search')
	assert.ok(rule, 'a rule for the picture of the search')
	assert.match(rule, /max-width:\s*100%/)
	assert.match(rule, /max-height:\s*100%/)
	for (const property of ['width', 'height', 'object-fit', 'object-position', 'clip-path', 'transform', 'background', 'border', 'padding', 'overflow']) {
		assert.doesNotMatch(rule, new RegExp(`(?:^|[;\\s])${property}\\s*:`), `the picture sets no ${property}, which would cut, stretch or frame it`)
	}
})
