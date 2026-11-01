/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/gif-frames.mjs, with which screenshots.mjs writes each frame of an animation
// no larger than what changed in it: the rectangle around the changed pixels, and its
// place in the bytes that the encoder wrote.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { changedRectangle, placeFrame } from '../e2e/gif-frames.mjs'

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
