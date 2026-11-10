/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/gif-frames.mjs, with which screenshots.mjs writes each frame of an animation
// no larger than what changed in it and with a palette of its own colours: the rectangle
// around the changed pixels, its palette, and its place in the bytes that the encoder wrote.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { UNCHANGED, animationFrames, changedRectangle, colorsOf, framePalette, placeFrame } from '../e2e/gif-frames.mjs'

const TRANSPARENT = 255

// An image of four pixels per row, from its rows.
const image = (...rows) => Uint8Array.from(rows.flat())
const blank = () => image([1, 1, 1, 1], [1, 1, 1, 1], [1, 1, 1, 1])

test('an image that stayed the same has no rectangle', function() {
	assert.equal(changedRectangle(blank(), blank(), 4, TRANSPARENT), null)
})

test('one changed pixel is a rectangle of one pixel in its place', function() {
	const changed = image([1, 1, 1, 1], [1, 1, 7, 1], [1, 1, 1, 1])
	assert.deepEqual(changedRectangle(changed, blank(), 4, TRANSPARENT), {
		left: 2,
		top: 1,
		width: 1,
		height: 1,
		pixels: Uint8Array.of(7),
	})
})

test('the rectangle reaches around every changed pixel, and those that stayed are transparent', function() {
	const changed = image([1, 7, 1, 1], [1, 1, 1, 1], [1, 1, 1, 8])
	assert.deepEqual(changedRectangle(changed, blank(), 4, TRANSPARENT), {
		left: 1,
		top: 0,
		width: 3,
		height: 3,
		pixels: Uint8Array.of(
			7, TRANSPARENT, TRANSPARENT,
			TRANSPARENT, TRANSPARENT, TRANSPARENT,
			TRANSPARENT, TRANSPARENT, 8,
		),
	})
})

test('the changed pixels at the corners make a rectangle of the whole image', function() {
	const changed = image([7, 1, 1, 1], [1, 1, 1, 1], [1, 1, 1, 8])
	const { left, top, width, height } = changedRectangle(changed, blank(), 4, TRANSPARENT)
	assert.deepEqual({ left, top, width, height }, { left: 0, top: 0, width: 4, height: 3 })
})

test('the colours of RGBA bytes are a number per pixel, red in its lowest byte, without the alpha', function() {
	assert.deepEqual(colorsOf(Uint8Array.of(1, 2, 3, 255, 0xff, 0x80, 0x10, 0)), Uint32Array.of(0x030201, 0x1080ff))
})

test('a rectangle of colours keeps them, and marks the pixels that stayed', function() {
	const before = Uint32Array.of(0xffffff, 0xffffff, 0xffffff, 0xffffff)
	const changed = Uint32Array.of(0x123456, 0xffffff, 0xffffff, 0xabcdef)
	assert.deepEqual(changedRectangle(changed, before, 2, UNCHANGED), {
		left: 0,
		top: 0,
		width: 2,
		height: 2,
		pixels: Uint32Array.of(0x123456, UNCHANGED, UNCHANGED, 0xabcdef),
	})
})

// A quantizer for the frames whose colours all fit a palette, which must not run.
const noQuantizer = () => assert.fail('the quantizer ran')

test('a frame of up to 255 colours keeps each of them exactly, in the order of their numbers', function() {
	const red = 0x0000ff
	const green = 0x00ff00
	const white = 0xffffff
	assert.deepEqual(framePalette(Uint32Array.of(white, red, green, red), noQuantizer), {
		palette: [[255, 0, 0], [0, 255, 0], [255, 255, 255]],
		pixels: Uint8Array.of(2, 0, 1, 0),
		transparent: false,
		transparentIndex: 3,
	})
})

test('the pixels that stayed take the index after the colours, which the palette keeps for them', function() {
	assert.deepEqual(framePalette(Uint32Array.of(UNCHANGED, 0xffffff, UNCHANGED, 0x000000), noQuantizer), {
		palette: [[0, 0, 0], [255, 255, 255], [0, 0, 0]],
		pixels: Uint8Array.of(2, 1, 2, 0),
		transparent: true,
		transparentIndex: 2,
	})
})

// Colours of red alone, from 0 to count - 1.
const reds = (count) => Uint32Array.from({ length: count }, (_, i) => i)

test('255 colours still keep their values', function() {
	const { palette, pixels, transparentIndex } = framePalette(reds(255), noQuantizer)
	assert.deepEqual(palette, Array.from({ length: 255 }, (_, i) => [i, 0, 0]))
	assert.deepEqual(pixels, Uint8Array.from({ length: 255 }, (_, i) => i))
	assert.equal(transparentIndex, 255)
})

test('more colours go to the quantizer, beside white and 64 neutral grays', function() {
	const calls = []
	const quantize = (rgba, colors) => {
		calls.push({ rgba: [...rgba], colors })
		return [[200, 0, 0]]
	}
	const colors = Uint32Array.of(...reds(256), UNCHANGED)
	const { palette, pixels, transparent, transparentIndex } = framePalette(colors, quantize)
	// The pixels that stayed are no colour of the frame.
	assert.deepEqual(calls, [{ rgba: [...reds(256)].flatMap((red) => [red, 0, 0, 255]), colors: 190 }])
	assert.deepEqual(palette, [
		[200, 0, 0],
		...Array.from({ length: 64 }, (_, i) => [i * 4, i * 4, i * 4]),
		[255, 255, 255],
		[0, 0, 0],
	])
	assert.equal(transparent, true)
	assert.equal(transparentIndex, 66)
	// Each pixel takes the nearest colour: black the first gray, a full red the red.
	assert.deepEqual([pixels[0], pixels[200], pixels[255], pixels[256]], [1, 0, 0, 66])
})

// An image of colours from its rows, shown for delay milliseconds.
const picture = (delay, ...rows) => ({ colors: Uint32Array.from(rows.flat()), width: rows[0].length, height: rows.length, delay })

test('the first frame is the whole first image, and each after it the rectangle of what changed', function() {
	const frames = [...animationFrames([picture(100, [1, 1], [1, 1]), picture(200, [1, 1], [1, 2])], noQuantizer)]
	assert.deepEqual(frames, [
		{ width: 2, height: 2, left: 0, top: 0, delay: 100, palette: [[1, 0, 0]], pixels: Uint8Array.of(0, 0, 0, 0), transparent: false, transparentIndex: 1 },
		{ width: 1, height: 1, left: 1, top: 1, delay: 200, palette: [[2, 0, 0]], pixels: Uint8Array.of(0), transparent: false, transparentIndex: 1 },
	])
})

test('an image that changed nothing adds its delay to the frame before', function() {
	const images = [picture(100, [1]), picture(50, [1]), picture(20, [2]), picture(30, [2])]
	assert.deepEqual([...animationFrames(images, noQuantizer)].map(({ delay }) => delay), [150, 50])
})

test('no images make no frames', function() {
	assert.deepEqual([...animationFrames([], noQuantizer)], [])
})

// A quantizer of one colour, the mean of the pixels that it gets, so that a palette shows
// from which pixels it came.
const mean = (rgba) => {
	const sum = [0, 0, 0]
	for (let i = 0; i < rgba.length; i += 4) {
		sum[0] += rgba[i]
		sum[1] += rgba[i + 1]
		sum[2] += rgba[i + 2]
	}
	return [sum.map((part) => Math.round(part / (rgba.length / 4)))]
}
// A row of more colours than a palette holds, so that each frame goes to the quantizer.
const row = (shift) => Array.from({ length: 300 }, (_, i) => (i * 0x010203 + shift) & 0xffffff)

test('a change in the last image changes the colours of its frame alone', function() {
	const first = picture(100, row(0), row(0))
	const second = picture(100, row(0), row(7))
	const third = (shift) => picture(100, row(5), row(shift))
	const before = [...animationFrames([first, second, third(9)], mean)]
	const after = [...animationFrames([first, second, third(90)], mean)]
	assert.notDeepEqual(after[2], before[2])
	assert.deepEqual(after.slice(0, 2), before.slice(0, 2))
})

test('a change in a middle image that the next one undoes changes its frame and the next, which draws it back', function() {
	const images = (pixel) => [0, 1, 2, 3, 4].map((step) => {
		const rows = [row(step), row(step + 40)]
		if (step === 2) {
			rows[1].fill(pixel, 100, 200)
		}
		return picture(100, ...rows)
	})
	const before = [...animationFrames(images(0x000000), mean)]
	const after = [...animationFrames(images(0xffffff), mean)]
	assert.equal(after.length, 5)
	assert.notDeepEqual(after[2], before[2])
	// The frames before it, and every frame from two after it on, stay as they were; the
	// frame right after it may differ, as it draws those pixels back.
	assert.deepEqual(after.slice(0, 2), before.slice(0, 2))
	assert.deepEqual(after.slice(4), before.slice(4))
})

// The bytes that the encoder writes for a frame at offset: its control, then its image at
// the top left.
function frame(offset = 0) {
	const bytes = new Uint8Array(offset + 20)
	bytes.set([0x21, 0xf9, 4, 0, 0, 0, 0, 0, 0x2c, 0, 0, 0, 0, 3, 0, 3, 0, 0], offset)
	return bytes
}

test('a frame gets its place after the first byte of its image, low byte first', function() {
	const bytes = frame(5)
	placeFrame(bytes, 5, 0x0123, 0x0204)
	assert.deepEqual([...bytes.subarray(5 + 8, 5 + 13)], [0x2c, 0x23, 0x01, 0x04, 0x02])
	// Its size stays.
	assert.deepEqual([...bytes.subarray(5 + 13, 5 + 17)], [3, 0, 3, 0])
})

test('a frame in a view of a larger buffer gets its place in that view', function() {
	const buffer = new Uint8Array(64)
	const bytes = buffer.subarray(10, 40)
	bytes.set(frame().subarray(0, 18), 2)
	placeFrame(bytes, 2, 1, 2)
	assert.deepEqual([...buffer.subarray(10 + 2 + 8, 10 + 2 + 13)], [0x2c, 1, 0, 2, 0])
})

test('another layout of the encoder fails, instead of writing into the frame', function() {
	const bytes = frame()
	bytes[8] = 0
	const before = [...bytes]
	assert.throws(() => placeFrame(bytes, 0, 1, 2), /did the encoder change how it writes a frame/)
	assert.deepEqual([...bytes], before)
})
