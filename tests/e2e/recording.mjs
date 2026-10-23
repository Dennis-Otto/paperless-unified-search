// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// What the pictures of the app share: the screenshots and the animation of
// scripts/screenshots.sh (screenshots.mjs), from the mock of Paperless, and the
// recording of the demo of scripts/demo-gif.sh (demo-recording.mjs), from a real
// Paperless-ngx and a real Nextcloud. A page of the pictures with a fixed font, the waits
// for a page at rest, the pointer that an animation shows, the frames of an animation
// with the pointer gliding and clicking, and the encoder that turns the frames into a GIF.
//
// It imports no package: the scripts hand it gifenc and pngjs, which the image of
// Playwright installs, so that the tests of tests/js run it with Node.js alone.

// Inter, which the social preview uses too, from the files of one version of Google
// Fonts: the stylesheet that Google Fonts serves for a family may name another
// version or another cut from one request to the next, which moves the letters by a
// fraction of a pixel.
export const INTER = `
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; font-display: block; src: url(https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa25L7SUc.woff2) format('woff2'); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF; }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; font-display: block; src: url(https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2) format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
`
// Nextcloud asks for system-ui, which is a Chinese font in the image of Playwright.
// Inter stands in for the font of the system.
export const FONT = `${INTER}
* { --font-face: 'Inter', sans-serif !important; }
html, body { font-family: 'Inter', sans-serif !important; }
`

// Chromium draws the same pixels in every run and on every processor: Skia without
// the code of a particular processor, every tile and every image drawn in full before
// the picture, and the colours in sRGB.
export const CHROMIUM_ARGS = [
	'--disable-skia-runtime-opts',
	'--disable-partial-raster',
	'--disable-checker-imaging',
	'--force-color-profile=srgb',
]

// A pointer for the animation: Chromium draws none into its screenshots. It follows
// the mouse of Playwright and keeps its place across a page load. It runs in the page,
// as an init script, so it uses nothing from around it.
export function pointer() {
	if (window.top !== window) {
		return
	}
	const place = JSON.parse(sessionStorage.getItem('demo-pointer') ?? '[-40,-40]')
	const show = () => {
		const element = document.createElement('div')
		element.id = 'demo-pointer'
		element.innerHTML = '<svg width="28" height="28" viewBox="0 0 28 28"><path d="M5 3l15 13h-8l-4 8z" fill="#111" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>'
		Object.assign(element.style, {
			position: 'fixed', left: '0', top: '0', zIndex: '2147483647', pointerEvents: 'none',
			transform: `translate(${place[0] - 5}px, ${place[1] - 3}px)`,
		})
		const ring = document.createElement('div')
		Object.assign(ring.style, {
			position: 'absolute', left: '-11px', top: '-13px', width: '32px', height: '32px', borderRadius: '50%',
			background: 'rgba(0, 130, 201, 0.35)', display: 'none',
		})
		element.prepend(ring)
		document.documentElement.append(element)
		document.addEventListener('mousemove', (event) => {
			sessionStorage.setItem('demo-pointer', JSON.stringify([event.clientX, event.clientY]))
			element.style.transform = `translate(${event.clientX - 5}px, ${event.clientY - 3}px)`
		}, true)
		document.addEventListener('mousedown', () => {
			ring.style.display = 'block'
		}, true)
		document.addEventListener('mouseup', () => setTimeout(() => {
			ring.style.display = 'none'
		}, 350), true)
	}
	if (document.documentElement) {
		show()
	} else {
		document.addEventListener('DOMContentLoaded', show)
	}
}

// A page of its own, with the session, the language and the font of the pictures and
// the options of its context, such as the viewport and the time zone; now, a Date,
// starts the clock of the page there, and pointer adds the pointer of the animation.
export async function newPage(browser, session, options, { pointer: withPointer = false, now = null } = {}) {
	const context = await browser.newContext({
		storageState: session,
		locale: 'en-US',
		bypassCSP: true,
		reducedMotion: 'reduce',
		...options,
	})
	if (now) {
		await context.clock.install({ time: now })
	}
	await context.addInitScript((css) => {
		const add = () => {
			const style = document.createElement('style')
			style.textContent = css
			document.documentElement.append(style)
		}
		if (document.documentElement) {
			add()
		} else {
			document.addEventListener('DOMContentLoaded', add)
		}
	}, FONT)
	if (withPointer) {
		await context.addInitScript(pointer)
	}
	return context.newPage()
}

// Waits for the fonts and the pictures of the page to be ready, so that a picture
// draws each of them at its final quality, then for the page to come to rest.
export async function settle(page, milliseconds = 600) {
	await page.evaluate(async () => {
		await document.fonts.ready
		await Promise.all([...document.images].map((image) => image.decode().catch(() => {})))
	})
	await page.waitForTimeout(milliseconds)
}

// Waits until the page looks the same in three pictures in a row, 400 ms apart: the
// viewer of PDF files draws the text of a page once more, sharper, at a moment that
// varies from run to run.
export async function steady(page) {
	const pictures = []
	for (let tries = 0; tries < 40; tries++) {
		pictures.push(await page.screenshot({ animations: 'disabled' }))
		if (pictures.length >= 3 && pictures.slice(-3).every((picture) => picture.equals(pictures.at(-1)))) {
			return
		}
		await page.waitForTimeout(400)
	}
	throw new Error('The page still changed after 16 seconds.')
}

// How far the pointer has come at the share t of its way, from 0 to 1: slow at the
// start and at the end, as a hand moves it.
export function ease(t) {
	return t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2
}

// The frames of an animation of the page, whose pointer starts at the place start:
// frame(delay) takes a picture that shows for delay milliseconds, glide(locator) moves
// the pointer to the element in steps, a frame each, and click(locator) glides there
// and clicks it, with the ring of the pointer in a frame of its own.
export function recorder(page, start) {
	const frames = []
	let position = start
	const frame = async (delay) => {
		frames.push({ png: await page.screenshot({ animations: 'disabled' }), delay })
	}
	const glide = async (locator, steps = 14) => {
		const box = await locator.boundingBox()
		const target = [box.x + Math.min(box.width / 2, 40), box.y + box.height / 2]
		for (let step = 1; step <= steps; step++) {
			const share = ease(step / steps)
			await page.mouse.move(position[0] + (target[0] - position[0]) * share, position[1] + (target[1] - position[1]) * share)
			await frame(30)
		}
		position = target
	}
	const click = async (locator) => {
		await glide(locator)
		await page.mouse.down()
		await frame(120)
		await page.mouse.up()
	}
	return { frames, frame, glide, click }
}

// Encodes the frames, each a PNG with the milliseconds that it shows, as the bytes of a
// GIF, with one palette for all of them; PNG is pngjs, GIFEncoder and quantize are those
// of gifenc. Every frame after the first holds only the pixels whose color in that
// palette changed; the rest stays transparent, so that the previous frame shows
// through, and a frame that changes no pixel adds its time to the one before. A palette
// of each frame of its own would let the colors of the unchanged pixels drift from their
// neighbors.
export function encodeGif(frames, { PNG, GIFEncoder, quantize }) {
	if (frames.length === 0) {
		throw new Error('An animation needs at least one frame.')
	}
	const decode = (png) => {
		const { width, height, data } = PNG.sync.read(png)
		return { width, height, rgba: new Uint8Array(data) }
	}
	// Every seventh pixel of every frame is enough to choose the colors.
	const samples = frames.map(({ png }) => {
		const pixels = new Uint32Array(decode(png).rgba.buffer)
		return pixels.filter((_, i) => i % 7 === 0)
	})
	const sample = new Uint32Array(samples.reduce((sum, part) => sum + part.length, 0))
	samples.reduce((offset, part) => {
		sample.set(part, offset)
		return offset + part.length
	}, 0)
	// Nextcloud is mostly white and gray. The quantizer would merge white with the
	// light blue of the background and tint the grays of shadows, so the palette has
	// white and 64 neutral grays of its own.
	const grays = Array.from({ length: 64 }, (_, i) => [i * 4, i * 4, i * 4])
	const palette = [...quantize(new Uint8Array(sample.buffer), 255 - grays.length - 1), ...grays, [255, 255, 255]]
	const transparentIndex = palette.length
	// The nearest color of the palette for every color of a pixel, looked up once.
	// applyPalette of gifenc decides for 32 shades at once, by the first it meets.
	const lookup = new Int16Array(1 << 24).fill(-1)
	const nearest = (color) => {
		const [r, g, b] = [color & 0xff, (color >> 8) & 0xff, (color >> 16) & 0xff]
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
	const indexOf = (rgba) => {
		const pixels = new Uint32Array(rgba.buffer)
		const index = new Uint8Array(pixels.length)
		for (let i = 0; i < pixels.length; i++) {
			const color = pixels[i] & 0xffffff
			if (lookup[color] < 0) {
				lookup[color] = nearest(color)
			}
			index[i] = lookup[color]
		}
		return index
	}

	const gif = GIFEncoder()
	let previous = null
	let pending = null
	const write = () => {
		gif.writeFrame(pending.index, pending.width, pending.height, {
			palette: [...palette, [0, 0, 0]],
			delay: pending.delay,
			dispose: 1,
			transparent: pending.transparent,
			transparentIndex,
		})
	}
	for (const { png, delay } of frames) {
		const { width, height, rgba } = decode(png)
		const index = indexOf(rgba)
		if (previous === null) {
			pending = { index, width, height, delay, transparent: false }
			previous = index
			continue
		}
		const changes = new Uint8Array(index.length).fill(transparentIndex)
		let changed = false
		for (let i = 0; i < index.length; i++) {
			if (index[i] !== previous[i]) {
				changes[i] = index[i]
				changed = true
			}
		}
		if (!changed) {
			pending.delay += delay
			continue
		}
		write()
		pending = { index: changes, width, height, delay, transparent: true }
		previous = index
	}
	write()
	gif.finish()
	return gif.bytes()
}
