// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The picture of the search alone, which the social preview shows (.github/social-preview.html):
// the field with the term and the panel with the results, cut out of the page at the place
// where the page draws them, without the page behind them. screenshots.mjs takes it, and
// tests/js/search-picture.test.mjs tests these steps without a browser. A place typed in
// once, as the social preview took before, showed the dimmed page below the panel once
// Nextcloud moved its search into the top bar (#497).

// The name of the picture in screenshots/.
export const SEARCH_ALONE = '01-unified-search-alone.png'

// The rectangle of whole pixels around the boxes of the page, such as the field and the
// panel, each with x, y, width and height as the page measures them. It fails when a box is
// empty or reaches out of the viewport, where the picture would cut it off.
export function clipAround(boxes, viewport) {
	if (boxes.length === 0) {
		throw new Error('The picture of the search needs the boxes of what it shows.')
	}
	for (const box of boxes) {
		if (!(box.width > 0 && box.height > 0)) {
			throw new Error(`A part of the search has no size: ${JSON.stringify(box)}.`)
		}
		if (box.x < 0 || box.y < 0 || box.x + box.width > viewport.width || box.y + box.height > viewport.height) {
			throw new Error(`A part of the search reaches out of the page of ${viewport.width}×${viewport.height}, which would cut it off: ${JSON.stringify(box)}.`)
		}
	}
	const left = Math.floor(Math.min(...boxes.map((box) => box.x)))
	const top = Math.floor(Math.min(...boxes.map((box) => box.y)))
	const right = Math.ceil(Math.max(...boxes.map((box) => box.x + box.width)))
	const bottom = Math.ceil(Math.max(...boxes.map((box) => box.y + box.height)))
	return { x: left, y: top, width: right - left, height: bottom - top }
}

// Whether the box, in whole pixels outward, holds the pixel at x and y.
function holds(box, x, y) {
	return x >= Math.floor(box.x) && x < Math.ceil(box.x + box.width)
		&& y >= Math.floor(box.y) && y < Math.ceil(box.y + box.height)
}

// The first pixel of a picture of RGBA bytes, width pixels per row, that isn't fully
// transparent and lies outside every box, which are in the coordinates of the picture:
// something of the page beside the search. Null when the picture shows nothing else.
export function strayPixel(rgba, width, boxes) {
	for (let i = 3; i < rgba.length; i += 4) {
		const pixel = (i - 3) / 4
		const x = pixel % width
		const y = Math.floor(pixel / width)
		if (rgba[i] !== 0 && !boxes.some((box) => holds(box, x, y))) {
			return { x, y }
		}
	}
	return null
}
