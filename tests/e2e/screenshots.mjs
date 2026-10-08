// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Takes the screenshots and the animation in screenshots/, which the README, the
// website and the App Store show. scripts/screenshots.sh starts it in the image of
// Playwright, inside the network of its Compose project, once Nextcloud and the mock
// of Paperless run. It writes the synthetic documents of the demo as PDF files into
// the folder Paperless of the archive account, shares the folder read-only with
// jamie, and goes through the app in Chromium as a person does: the search, the
// opened file and the settings, light and dark, on a phone, and as an animation. The
// images go to the folder of SCREENSHOTS_OUT.

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import gifenc from 'gifenc'
import pngjs from 'pngjs'
import { chromium } from 'playwright-core'

const { GIFEncoder, quantize } = gifenc
const { PNG } = pngjs

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
// The folder for the pictures, which scripts/screenshots.sh makes with mktemp.
const OUT = process.env.SCREENSHOTS_OUT
if (!OUT) {
	throw new Error('SCREENSHOTS_OUT names no folder for the pictures.')
}
const ADMIN = 'e2e-admin'
const ARCHIVE = 'paperless'
const READER = 'jamie'
const TERM = 'invoice'

// Nextcloud asks for system-ui, which is a Chinese font in the image of Playwright.
// Inter, which the social preview uses too, stands in for the font of the system.
const FONT = `
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=block');
* { --font-face: 'Inter', sans-serif !important; }
html, body { font-family: 'Inter', sans-serif !important; }
`

// The documents of the demo, in folders as Paperless Sync names them by default. The
// mock of Paperless finds the first three for "invoice", with the same IDs.
const PERSON = ['Jamie Example', '12 Example Street', '10115 Sampletown']
const DOCUMENTS = [
	{
		id: 412,
		folder: 'Northstar Energy/Bill/2026',
		name: '2026-08-12 - Electricity invoice - August 2026',
		company: 'Northstar Energy',
		accent: '#1f7a5c',
		number: ['Invoice no.', 'E-2026-0812'],
		facts: [['Billing period', 'July 01–31, 2026'], ['Due date', 'Sep 02, 2026']],
		lines: [['Electricity supply', '248 kWh', '67.48 EUR'], ['Grid and service charges', 'Monthly', '13.38 EUR'], ['Taxes', 'Included', '3.44 EUR']],
		total: ['Total due', '84.30 EUR'],
	},
	{
		id: 389,
		folder: 'Harbor Mutual/Insurance/2026',
		name: '2026-07-21 - Home insurance renewal',
		company: 'Harbor Mutual',
		accent: '#4f46e5',
		number: ['Policy no.', 'HM-55-2026'],
		intro: 'Thank you for staying with Harbor Mutual. Your annual insurance invoice covers August 2026 through July 2027. Your cover and your deductible stay the same.',
		facts: [['Cover period', 'Aug 2026 – Jul 2027'], ['Due date', 'Aug 01, 2026']],
		lines: [['Home contents', 'Up to 60,000 EUR', '214.00 EUR'], ['Personal liability', 'Up to 5 million EUR', '86.00 EUR'], ['Glass breakage', 'Optional', '12.00 EUR']],
		total: ['Annual premium', '312.00 EUR'],
	},
	{
		id: 371,
		folder: 'Fiberlink/Bill/2026',
		name: '2026-07-03 - Internet invoice - July 2026',
		company: 'Fiberlink',
		accent: '#0369a1',
		number: ['Invoice no.', 'FL-2026-07-2048'],
		intro: 'Monthly fiber internet invoice. Customer reference EX-2048.',
		facts: [['Billing period', 'July 2026'], ['Due date', 'Jul 17, 2026']],
		lines: [['Fiber 500', 'Monthly', '39.99 EUR'], ['Router rental', 'Monthly', '2.99 EUR']],
		total: ['Total due', '42.98 EUR'],
	},
	{
		id: 356,
		folder: 'City of Sampletown/Tax/2026',
		name: '2026-05-14 - Property tax notice 2026',
		company: 'City of Sampletown',
		accent: '#b45309',
		number: ['Notice no.', 'PT-2026-1187'],
		facts: [['Tax year', '2026'], ['Due date', 'Jun 15, 2026']],
		lines: [['Property tax', 'Annual', '486.00 EUR'], ['Waste collection', 'Annual', '212.40 EUR']],
		total: ['Amount due', '698.40 EUR'],
	},
	{
		id: 402,
		folder: 'Example Motors/Receipt/2026',
		name: '2026-09-02 - Car service',
		company: 'Example Motors',
		accent: '#be123c',
		number: ['Receipt no.', 'EM-40211'],
		facts: [['Service date', 'Sep 02, 2026'], ['Paid', 'By card']],
		lines: [['Annual inspection', 'Labor', '129.00 EUR'], ['Oil and filter', 'Parts', '64.50 EUR'], ['Wiper blades', 'Parts', '24.90 EUR']],
		total: ['Total paid', '218.40 EUR'],
	},
]

const fileName = (document) => `${document.name} [P${document.id}].pdf`

function escapeHtml(text) {
	return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
}

function documentHtml(document) {
	const cells = (row) => row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')
	return `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=block">
<style>
@page { size: A4; margin: 0; }
* { box-sizing: border-box; margin: 0; }
body { font-family: Inter, sans-serif; color: #1f2937; padding: 72px 80px; font-size: 15px; }
header { display: flex; justify-content: space-between; align-items: flex-start; }
.brand { display: flex; gap: 22px; align-items: center; }
.logo { width: 84px; height: 84px; background: ${document.accent}; color: #fff; font: 800 40px Inter, sans-serif; display: grid; place-items: center; border-radius: 4px; }
.company { color: ${document.accent}; font-size: 28px; font-weight: 800; letter-spacing: 0.02em; text-transform: uppercase; }
.demo { color: #475569; font-size: 12px; font-weight: 700; letter-spacing: 0.08em; margin-top: 8px; }
.number { text-align: right; }
.label { color: #475569; font-size: 12px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
.number .value { font-size: 17px; font-weight: 700; margin-top: 18px; }
.facts { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 24px; margin-top: 56px; }
.facts .value { font-size: 16px; font-weight: 600; margin-top: 10px; }
.address { color: #475569; font-size: 12.5px; line-height: 1.55; margin-top: 10px; }
.intro { margin-top: 44px; line-height: 1.6; font-size: 15.5px; max-width: 560px; }
table { width: 100%; border-collapse: collapse; margin-top: 52px; }
th { background: color-mix(in srgb, ${document.accent} 10%, white); color: ${document.accent}; font-size: 12.5px; font-weight: 700; letter-spacing: 0.06em; text-align: left; padding: 20px 24px; text-transform: uppercase; }
td { padding: 22px 24px; border-bottom: 1px solid #e5e7eb; }
td:nth-child(2) { color: #475569; }
th:last-child, td:last-child { text-align: right; }
.total { display: flex; justify-content: space-between; align-items: center; margin: 40px 0 0 auto; width: 420px; background: #f4f6fa; padding: 26px 24px; }
.total .value { color: ${document.accent}; font-size: 34px; font-weight: 800; }
footer { position: fixed; bottom: 56px; left: 80px; right: 80px; color: #64748b; font-size: 11.5px; border-top: 1px solid #e5e7eb; padding-top: 14px; }
</style></head><body>
<header>
	<div class="brand"><div class="logo">${escapeHtml(document.company[0])}</div>
		<div><div class="company">${escapeHtml(document.company)}</div><div class="demo">DEMO DOCUMENT</div></div></div>
	<div class="number"><div class="label">${escapeHtml(document.number[0])}</div><div class="value">${escapeHtml(document.number[1])}</div></div>
</header>
<section class="facts">
	<div><div class="label">Bill to</div><div class="value">${escapeHtml(PERSON[0])}</div><div class="address">${PERSON.slice(1).map(escapeHtml).join('<br>')}</div></div>
	${document.facts.map(([label, value]) => `<div><div class="label">${escapeHtml(label)}</div><div class="value">${escapeHtml(value)}</div></div>`).join('')}
</section>
${document.intro ? `<p class="intro">${escapeHtml(document.intro)}</p>` : ''}
<table><thead><tr><th>Description</th><th>Details</th><th>Amount</th></tr></thead>
<tbody>${document.lines.map((row) => `<tr>${cells(row)}</tr>`).join('')}</tbody></table>
<div class="total"><span>${escapeHtml(document.total[0])}</span><span class="value">${escapeHtml(document.total[1])}</span></div>
<footer>A synthetic document of the demo of Paperless Unified Search. Every name, address and number in it is made up.</footer>
</body></html>`
}

function authorization(user) {
	return `Basic ${Buffer.from(`${user}:${PASSWORD}`).toString('base64')}`
}

async function request(user, method, path, body) {
	const response = await fetch(`${BASE_URL}${path}`, {
		method,
		body,
		headers: { Authorization: authorization(user), 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	if (!response.ok && !(method === 'MKCOL' && response.status === 405)) {
		throw new Error(`${method} ${path} as ${user}: ${response.status} ${await response.text()}`)
	}
}

const davPath = (user, path) => `/remote.php/dav/files/${user}/${path.split('/').map(encodeURIComponent).join('/')}`

// Writes the documents into the archive account and shares its folder with the reader.
async function createArchive(browser) {
	const page = await browser.newPage()
	const folders = new Set(['Paperless'])
	for (const paper of DOCUMENTS) {
		const parts = paper.folder.split('/')
		parts.forEach((_, index) => folders.add(`Paperless/${parts.slice(0, index + 1).join('/')}`))
	}
	for (const folder of [...folders].sort()) {
		await request(ARCHIVE, 'MKCOL', davPath(ARCHIVE, folder))
	}
	for (const paper of DOCUMENTS) {
		await page.setContent(documentHtml(paper), { waitUntil: 'networkidle' })
		await page.evaluate(() => document.fonts.ready)
		const pdf = await page.pdf({ format: 'A4', printBackground: true })
		await request(ARCHIVE, 'PUT', davPath(ARCHIVE, `Paperless/${paper.folder}/${fileName(paper)}`), pdf)
	}
	await page.close()
	const share = new URLSearchParams({ path: '/Paperless', shareType: '0', shareWith: READER, permissions: '1' })
	await request(ARCHIVE, 'POST', `/ocs/v2.php/apps/files_sharing/api/v1/shares?${share}`)
}

// Signs in through the login form, as a person does, and keeps the session.
async function signIn(browser, user) {
	const context = await browser.newContext({ locale: 'en-US' })
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(user)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		await page.waitForURL((url) => !url.pathname.endsWith('/login'))
		return await context.storageState()
	} finally {
		await context.close()
	}
}

// A pointer for the animation: Chromium draws none into its screenshots. It follows
// the mouse of Playwright and keeps its place across a page load.
function pointer() {
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

async function newPage(browser, session, options, withPointer = false) {
	const context = await browser.newContext({
		storageState: session,
		locale: 'en-US',
		timezoneId: 'Europe/Berlin',
		bypassCSP: true,
		reducedMotion: 'reduce',
		...options,
	})
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

async function settle(page, milliseconds = 600) {
	await page.evaluate(() => document.fonts.ready)
	await page.waitForTimeout(milliseconds)
}

async function save(page, name, milliseconds) {
	await settle(page, milliseconds)
	await page.screenshot({ path: join(OUT, name), animations: 'disabled' })
	console.log(`Took ${name}.`)
}

const searchDialog = (page) => page.locator('.modal-container').filter({ hasText: 'Unified search' })
// A result of Paperless starts with the title of its document, one of the files with
// the date that Paperless Sync puts in front of its name.
const paperlessResult = (page, document) => searchDialog(page).getByRole('link', {
	name: new RegExp(`^${document.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} `),
})

async function openArchive(page) {
	await page.goto(`${BASE_URL}/index.php/apps/files/files?dir=/Paperless`)
	await page.getByText('Northstar Energy', { exact: true }).first().waitFor()
}

const searchButton = (page) => page.locator('header').getByRole('button', { name: 'Unified search' })

// Types into the open search with typing() and waits for the files that it finds.
async function search(page, typing) {
	const input = searchDialog(page).getByRole('textbox')
	await input.waitFor()
	await typing(input)
	await searchDialog(page).getByText('Files', { exact: true }).waitFor()
}

async function searchConnectedServices(page) {
	await searchDialog(page).getByText('Search connected services').click()
	await searchDialog(page).getByText('Paperless documents').waitFor()
	await paperlessResult(page, { title: 'Internet invoice - July 2026' }).waitFor()
}

async function waitForViewer(page, document) {
	await page.waitForURL((url) => url.pathname.includes('/apps/files/'))
	const viewer = page.frameLocator('iframe')
	await viewer.locator('.page[data-loaded="true"]').first().waitFor({ timeout: 30000 })
	await page.locator('.modal-header').getByText(fileName(document)).waitFor()
}

const electricity = { ...DOCUMENTS[0], title: 'Electricity invoice - August 2026' }
const insurance = { ...DOCUMENTS[1], title: 'Home insurance renewal' }

// The search with its Paperless results, and the file that a result opens.
async function searchAndOpen(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 900 }, colorScheme: theme })
	await openArchive(page)
	await searchButton(page).click()
	await search(page, (input) => input.fill(TERM))
	await searchConnectedServices(page)
	await save(page, `01-unified-search${suffix}.png`)
	await paperlessResult(page, electricity).click()
	await waitForViewer(page, electricity)
	await save(page, `02-nextcloud-pdf-viewer${suffix}.png`, 1500)
	await page.context().close()
}

async function settings(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 900 }, colorScheme: theme })
	await page.goto(`${BASE_URL}/index.php/settings/admin/paperless_unified_search`)
	await page.locator('#paperless-unified-search-settings').waitFor()
	await page.locator('#paperless-unified-search-save').click()
	await page.getByText('Connection successful. Settings saved.').waitFor()
	// The demo talks to the mock in the network of Docker; the picture shows the
	// address that an administrator enters.
	await page.locator('#paperless-unified-search-url').fill('https://paperless.example.com')
	await page.locator('#paperless-unified-search-url').blur()
	await page.locator('#app-navigation a[href$="/settings/admin/paperless_unified_search"]').scrollIntoViewIfNeeded()
	await save(page, `03-admin-settings${suffix}.png`)
	await page.context().close()
}

async function phone(browser, session) {
	const page = await newPage(browser, session, {
		viewport: { width: 390, height: 844 },
		deviceScaleFactor: 2,
		isMobile: true,
		hasTouch: true,
	})
	await openArchive(page)
	await searchButton(page).click()
	await search(page, (input) => input.fill(TERM))
	await searchConnectedServices(page)
	await save(page, '04-mobile-search.png')
	await paperlessResult(page, electricity).click()
	await waitForViewer(page, electricity)
	await save(page, '05-mobile-viewer.png', 1500)
	await page.context().close()
}

// The animation: the archive, the search for "invoice", the switch for connected
// services and the insurance renewal, which only Paperless finds by its text.
async function animation(browser, session) {
	const frames = []
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 800 } }, true)
	const frame = async (delay) => {
		frames.push({ png: await page.screenshot({ animations: 'disabled' }), delay })
	}
	let position = [640, 460]
	const glide = async (locator, steps = 14) => {
		const box = await locator.boundingBox()
		const target = [box.x + Math.min(box.width / 2, 40), box.y + box.height / 2]
		for (let step = 1; step <= steps; step++) {
			const t = step / steps
			const ease = t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2
			await page.mouse.move(position[0] + (target[0] - position[0]) * ease, position[1] + (target[1] - position[1]) * ease)
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

	await openArchive(page)
	await page.mouse.move(...position)
	await settle(page)
	await frame(1600)
	await click(searchButton(page))
	await search(page, async (input) => {
		await settle(page, 300)
		await frame(500)
		for (const letter of TERM) {
			await input.press(letter)
			await frame(110)
		}
	})
	await settle(page, 400)
	await frame(1400)
	await click(searchDialog(page).getByText('Search connected services'))
	await searchDialog(page).getByText('Paperless documents').waitFor()
	await paperlessResult(page, { title: 'Internet invoice - July 2026' }).waitFor()
	await settle(page, 300)
	await frame(2600)
	await click(paperlessResult(page, insurance))
	await waitForViewer(page, insurance)
	await settle(page, 1200)
	await frame(3800)
	await page.context().close()
	writeGif(frames, join(OUT, 'search-and-open.gif'))
	console.log(`Took search-and-open.gif with ${frames.length} frames.`)
}

// Encodes the frames as GIF, with one palette for all of them. Every frame after the
// first holds only the pixels whose color in that palette changed; the rest stays
// transparent, so that the previous frame shows through. A palette of each frame of
// its own would let the colors of the unchanged pixels drift from their neighbors.
function writeGif(frames, path) {
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
	writeFileSync(path, gif.bytes())
}

const browser = await chromium.launch()
try {
	await createArchive(browser)
	const reader = await signIn(browser, READER)
	const admin = await signIn(browser, ADMIN)
	for (const theme of ['light', 'dark']) {
		await searchAndOpen(browser, reader, theme)
		await settings(browser, admin, theme)
	}
	await phone(browser, reader)
	await animation(browser, reader)
} finally {
	await browser.close()
}
