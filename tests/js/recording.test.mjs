/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/recording.mjs, which the animation of scripts/screenshots.sh and the
// recording of the demo of scripts/demo-gif.sh share: the way of the pointer, the frames
// that it takes on a page, and the encoder that turns the frames into a GIF. The
// pictures here are small grids of pixels, which a stand-in for pngjs reads from JSON,
// and stand-ins for gifenc keep what the encoder hands them.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { CHROMIUM_ARGS, FONT, INTER, ease, encodeGif, recorder } from '../e2e/recording.mjs'

const white = [255, 255, 255, 255]
const black = [0, 0, 0, 255]
const red = [250, 10, 10, 255]

// A frame of width × height pixels, each [r, g, b, a], shown for delay milliseconds.
function frame(width, height, pixels, delay) {
	return { png: Buffer.from(JSON.stringify({ width, height, data: pixels.flat() })), delay }
}

// The stand-ins: pngjs reads the JSON of frame(), quantize offers one red, whatever it
// gets, and the encoder keeps every frame that it is given.
function codecs() {
	const written = []
	const quantized = []
	return {
		written,
		quantized,
		PNG: {
			sync: {
				read: (buffer) => {
					const { width, height, data } = JSON.parse(buffer.toString('utf8'))
					return { width, height, data: Uint8Array.from(data) }
				},
			},
		},
		quantize: (rgba, colors) => {
			quantized.push({ length: rgba.length, colors })
			return [[255, 0, 0]]
		},
		GIFEncoder: () => {
			let finished = false
			return {
				writeFrame: (index, width, height, options) => written.push({ index: [...index], width, height, ...options }),
				finish: () => {
					finished = true
				},
				bytes: () => Uint8Array.of(finished ? 1 : 0, written.length),
			}
		},
	}
}

// The palette: the colors of quantize, 64 grays, white, and black for the transparent
// pixels, whose index is the one after white.
const RED = 0
const GRAY = (level) => 1 + level / 4
const WHITE = 65
const TRANSPARENT = 66

test('the pointer starts and ends slowly, and gets there', function() {
	assert.equal(ease(0), 0)
	assert.equal(ease(0.5), 0.5)
	assert.equal(ease(1), 1)
	assert.ok(ease(0.1) < 0.1, 'slow at the start')
	assert.ok(ease(0.9) > 0.9, 'slow at the end')
	for (let step = 1; step <= 20; step++) {
		assert.ok(ease(step / 20) > ease((step - 1) / 20), 'never back')
	}
})

test('the first frame holds every pixel in the colors of one palette', function() {
	const stand = codecs()
	const bytes = encodeGif([frame(3, 1, [white, black, red], 500)], stand)
	assert.deepEqual([...bytes], [1, 1], 'the encoder finished with one frame')
	assert.equal(stand.written.length, 1)
	const [first] = stand.written
	assert.deepEqual(first.index, [WHITE, GRAY(0), RED])
	assert.equal(first.width, 3)
	assert.equal(first.height, 1)
	assert.equal(first.delay, 500)
	assert.equal(first.dispose, 1)
	assert.equal(first.transparent, false)
	assert.equal(first.transparentIndex, TRANSPARENT)
	// With the 190 colors of a real quantize, the palette holds the 256 of a GIF.
	assert.equal(first.palette.length, 1 + 64 + 1 + 1)
	assert.deepEqual(first.palette.slice(1, 3), [[0, 0, 0], [4, 4, 4]])
	assert.deepEqual(first.palette[WHITE], [255, 255, 255])
	assert.deepEqual(first.palette[TRANSPARENT], [0, 0, 0])
	// quantize chooses the colors that the grays and white leave, from every seventh
	// pixel: the first of the three.
	assert.deepEqual(stand.quantized, [{ length: 4, colors: 190 }])
})

test('a later frame holds only the pixels that changed, and the rest shows through', function() {
	const stand = codecs()
	encodeGif([
		frame(3, 1, [white, white, white], 100),
		frame(3, 1, [white, black, white], 200),
	], stand)
	assert.equal(stand.written.length, 2)
	assert.deepEqual(stand.written[1].index, [TRANSPARENT, GRAY(0), TRANSPARENT])
	assert.equal(stand.written[1].transparent, true)
	assert.equal(stand.written[1].delay, 200)
})

test('a frame that changes no pixel adds its time to the frame before', function() {
	const stand = codecs()
	encodeGif([
		frame(2, 1, [white, white], 100),
		frame(2, 1, [white, white], 30),
		frame(2, 1, [black, white], 400),
		// A shade that the palette shows as the same black changes nothing either.
		frame(2, 1, [[1, 1, 1, 255], white], 50),
	], stand)
	assert.deepEqual(stand.written.map(({ delay }) => delay), [130, 450])
	assert.deepEqual(stand.written[1].index, [GRAY(0), TRANSPARENT])
})

test('each color takes the nearest of the palette', function() {
	const stand = codecs()
	encodeGif([frame(4, 1, [[130, 128, 126, 255], [254, 254, 254, 255], [200, 30, 20, 255], [9, 9, 9, 128]], 100)], stand)
	assert.deepEqual(stand.written[0].index, [GRAY(128), WHITE, RED, GRAY(8)])
})

test('an animation without frames is refused', function() {
	assert.throws(() => encodeGif([], codecs()), /at least one frame/)
})

// A page whose screenshots are numbered, and whose mouse keeps where it went.
function fakePage() {
	const moves = []
	let shots = 0
	return {
		moves,
		screenshot: async (options) => {
			assert.deepEqual(options, { animations: 'disabled' })
			shots++
			return Buffer.from(String(shots))
		},
		mouse: {
			move: async (x, y) => moves.push(['move', x, y]),
			down: async () => moves.push(['down']),
			up: async () => moves.push(['up']),
		},
	}
}

const element = (x, y, width, height) => ({ boundingBox: async () => ({ x, y, width, height }) })

test('a frame shows what the page shows, for its time', async function() {
	const page = fakePage()
	const { frames, frame: take } = recorder(page, [0, 0])
	await take(1600)
	await take(30)
	assert.deepEqual(frames.map(({ png, delay }) => [png.toString(), delay]), [['1', 1600], ['2', 30]])
})

test('the pointer glides to an element in steps, a frame each, and goes on from there', async function() {
	const page = fakePage()
	const { frames, glide } = recorder(page, [0, 0])
	// A small element: its middle.
	await glide(element(100, 200, 20, 10), 2)
	assert.deepEqual(page.moves, [['move', 55, 102.5], ['move', 110, 205]])
	assert.deepEqual(frames.map(({ delay }) => delay), [30, 30])
	// A wide one: 40 pixels into it, where its text starts, from where the pointer is.
	page.moves.length = 0
	await glide(element(300, 400, 600, 20), 1)
	assert.deepEqual(page.moves, [['move', 340, 410]])
	// Fourteen steps unless told otherwise.
	await glide(element(0, 0, 10, 10))
	assert.equal(frames.length, 2 + 1 + 14)
	assert.deepEqual(page.moves.at(-1), ['move', 5, 5])
})

test('a click glides there and shows the ring of the pointer in a frame of its own', async function() {
	const page = fakePage()
	const { frames, click } = recorder(page, [10, 10])
	await click(element(10, 10, 0, 0))
	assert.deepEqual(page.moves.slice(-2), [['down'], ['up']])
	assert.equal(page.moves.filter(([kind]) => kind === 'move').length, 14)
	assert.deepEqual(frames.at(-1).delay, 120)
	assert.equal(frames.length, 15)
})

test('the pictures draw with Inter in Chromium that draws the same pixels each time', function() {
	assert.ok(FONT.startsWith(INTER))
	assert.match(FONT, /--font-face: 'Inter'/)
	assert.equal((INTER.match(/@font-face/g) ?? []).length, 2)
	assert.ok(CHROMIUM_ARGS.includes('--force-color-profile=srgb'))
})
