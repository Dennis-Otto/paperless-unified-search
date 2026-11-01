// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The frames of an animation of screenshots.mjs, each no larger than what changed in it,
// without a dependency, so that tests/js/gif-frames.test.mjs tests them. A whole page around
// a few pixels, such as a letter that was typed, costs far more than those pixels.

// The rectangle around the pixels of an image that differ from the image before it, both
// as palette indexes row by row, in width pixels per row: its place, its size and its
// pixels, of which those that stayed the same hold the transparent index, so that the
// image before shows through. Null when no pixel differs.
export function changedRectangle(image, before, width, transparentIndex) {
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
	const pixels = new Uint8Array(boxWidth * boxHeight).fill(transparentIndex)
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
