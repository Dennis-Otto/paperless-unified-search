// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The frames of an animation of screenshots.mjs, each no larger than what changed in it
// and with colours of its own, without a dependency, so that tests/js/gif-frames.test.mjs
// tests them. A whole page around a few pixels, such as a letter that was typed, costs far
// more than those pixels. A palette that all frames share would come from the pixels of
// every frame, so a change of a few pixels in one frame could move it, and with it the
// colours of every frame and of the poster, the first frame.

// The value of a pixel that stayed the same among the colours of a frame, which no colour
// of 24 bits takes.
export const UNCHANGED = 0x1000000

// The colours of an image of RGBA bytes, one number of 24 bits per pixel with red in its
// lowest byte, row by row, without the alpha, as a screenshot is opaque.
export function colorsOf(rgba) {
	const colors = new Uint32Array(rgba.length / 4)
	for (let i = 0; i < colors.length; i++) {
		colors[i] = rgba[i * 4] | (rgba[i * 4 + 1] << 8) | (rgba[i * 4 + 2] << 16)
	}
	return colors
}

// The rectangle around the pixels of an image that differ from the image before it, both
// as values row by row, such as colours or palette indexes, in width pixels per row: its
// place, its size and its pixels, of which those that stayed the same hold the value
// transparent, so that the image before shows through. Null when no pixel differs.
export function changedRectangle(image, before, width, transparent) {
	let [left, top, right, bottom] = [width, Infinity, -1, -1]
	for (let i = 0; i < image.length; i++) {
		if (image[i] !== before[i]) {
			const [x, y] = [i % width, Math.floor(i / width)]
			left = Math.min(left, x)
			right = Math.max(right, x)
			top = Math.min(top, y)
			bottom = Math.max(bottom, y)
		}
	}
	if (right < 0) {
		return null
	}
	const [boxWidth, boxHeight] = [right - left + 1, bottom - top + 1]
	const pixels = new image.constructor(boxWidth * boxHeight).fill(transparent)
	for (let y = 0; y < boxHeight; y++) {
		for (let x = 0; x < boxWidth; x++) {
			const i = (top + y) * width + left + x
			if (image[i] !== before[i]) {
				pixels[y * boxWidth + x] = image[i]
			}
		}
	}
	return { left, top, width: boxWidth, height: boxHeight, pixels }
}

// The most colours of a palette of a GIF, and so of a frame, which keeps one index of its
// 256 for the pixels that stayed the same. Nextcloud is mostly white and gray; a quantizer
// would merge white with the light blue of the background and tint the grays of shadows,
// so a palette that has to merge colours has white and 64 neutral grays of its own.
const MOST_COLORS = 255
const GRAYS = Array.from({ length: 64 }, (_, i) => [i * 4, i * 4, i * 4])
const WHITE = [255, 255, 255]

const rgb = (color) => [color & 0xff, (color >> 8) & 0xff, (color >> 16) & 0xff]

// The index of the colour of the palette nearest to a colour, the first of equals. The
// applyPalette of gifenc decides for 32 shades at once, by the first that it meets.
function nearest(palette, color) {
	const [r, g, b] = rgb(color)
	let best = 0
	let distance = Infinity
	palette.forEach(([pr, pg, pb], i) => {
		const d = (r - pr) ** 2 + (g - pg) ** 2 + (b - pb) ** 2
		if (d < distance) {
			best = i
			distance = d
		}
	})
	return best
}

// The palette of a frame from its own colours alone, as UNCHANGED leaves them out, and its
// pixels as indexes of that palette, row by row. Up to 255 colours keep their exact values,
// in the order of their numbers; more go to quantize, such as that of gifenc, which takes
// RGBA bytes and the most colours that it may choose. The pixels that stayed the same take
// the index after the colours, which transparentIndex names and transparent asks for, so
// that the GIF shows the frame before through them.
export function framePalette(colors, quantize) {
	const distinct = [...new Set(colors)].filter((color) => color !== UNCHANGED).sort((a, b) => a - b)
	let palette = distinct.map(rgb)
	if (distinct.length > MOST_COLORS) {
		const shown = colors.filter((color) => color !== UNCHANGED)
		const rgba = new Uint8Array(shown.length * 4).fill(255)
		shown.forEach((color, i) => rgba.set(rgb(color), i * 4))
		palette = [...quantize(rgba, MOST_COLORS - GRAYS.length - 1), ...GRAYS, WHITE]
	}
	const indexes = new Map(distinct.map((color) => [color, nearest(palette, color)]))
	const transparentIndex = palette.length
	const transparent = colors.includes(UNCHANGED)
	return {
		palette: transparent ? [...palette, [0, 0, 0]] : palette,
		pixels: Uint8Array.from(colors, (color) => (color === UNCHANGED ? transparentIndex : indexes.get(color))),
		transparent,
		transparentIndex,
	}
}

// The frames of a GIF of images, each with its colours, width, height and delay in
// milliseconds, one after the other, so that only two of them take memory at a time. Each
// frame has a palette of its own colours (framePalette) and its place, left and top. The
// first frame is the whole first image. Each one after it holds the rectangle around the
// pixels whose colour changed (changedRectangle), and an image that changed nothing adds
// its delay to the frame before.
export function* animationFrames(images, quantize) {
	const frame = ({ colors, ...place }) => ({ ...place, ...framePalette(colors, quantize) })
	let previous = null
	let pending = null
	for (const { colors, width, height, delay } of images) {
		if (previous === null) {
			pending = { colors, width, height, left: 0, top: 0, delay }
		} else {
			const changed = changedRectangle(colors, previous, width, UNCHANGED)
			if (changed === null) {
				pending.delay += delay
				continue
			}
			yield frame(pending)
			pending = { ...changed, colors: changed.pixels, delay }
		}
		previous = colors
	}
	if (pending !== null) {
		yield frame(pending)
	}
}

// The bytes of the control of a frame in a GIF, which come before the image of the frame,
// and the first byte of that image, whose place follows, each number low byte first.
const CONTROL = 8
const IMAGE = 0x2c

// Puts a frame in its place in the bytes of a GIF: an encoder such as gifenc writes each
// frame at the top left, so its control starts at offset. It fails on any other layout,
// which an update of the encoder could bring, as a frame in the wrong place would only
// show in the picture.
export function placeFrame(bytes, offset, left, top) {
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
	if (view.getUint8(offset + CONTROL) !== IMAGE) {
		throw new Error(`No image of a frame ${CONTROL} bytes after offset ${offset} of the GIF: did the encoder change how it writes a frame?`)
	}
	view.setUint16(offset + CONTROL + 1, left, true)
	view.setUint16(offset + CONTROL + 3, top, true)
}
