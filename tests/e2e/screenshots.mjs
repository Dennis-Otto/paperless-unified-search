// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Takes the screenshots and the animation in screenshots/, which the README, the
// website and the App Store show. scripts/screenshots.sh starts it in the image of
// Playwright, inside the network of its Compose project, once Nextcloud and the mock
// of Paperless run. It writes the synthetic documents of the demo as PDF files into
// the folder Paperless of the archive account, shares the folder read-only with
// jamie, and goes through the app in Chromium as a person does: the search, the
// opened file, the search narrowed by the date filter of Nextcloud, the smart picker in
// a note of Text with the link that it inserts, the settings and the share of the
// archive, light and dark, Try a search of the settings, the search and the settings on
// a phone, and the search as an animation. The images go to the folder of
// SCREENSHOTS_OUT. A step fails when the app doesn't show what its picture is meant to
// show, such as a Paperless result that the date filter should have left out.
//
// With SCREENSHOTS_DEMO=1, which `bash scripts/screenshots.sh --demo` sets, it records
// the animation of the demo of the README instead, demo-search-and-open.gif, from the
// real Paperless-ngx and Nextcloud of demo/seed.sh, and takes nothing else
// (tests/e2e/demo.mjs).

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import gifenc from 'gifenc'
import pngjs from 'pngjs'
import { chromium } from 'playwright-core'
import { DEMO, OPENED, RESULTS, demoClock, lastChange, templateTimes, unexpectedResults } from './demo.mjs'
import {
	BASE_URL,
	PASSWORD,
	SETTINGS,
	SETTINGS_SCOPE,
	connectedServices,
	paperlessResult,
	resultGroup,
	search,
	searchButton,
	searchConnectedServices,
	searchField,
	signIn,
	waitForViewer,
} from './ui.mjs'

const { GIFEncoder, quantize } = gifenc
const { PNG } = pngjs

// The folder for the pictures, which scripts/screenshots.sh makes with mktemp.
const OUT = process.env.SCREENSHOTS_OUT
if (!OUT) {
	throw new Error('SCREENSHOTS_OUT names no folder for the pictures.')
}
const ADMIN = 'e2e-admin'
const ARCHIVE = 'paperless'
const READER = 'jamie'
const TERM = 'invoice'
// The moment of the pictures. The clock of every page starts there, in the time zone of
// the pages, and the files of the archive were filed two days before, so that what
// Nextcloud shows relative to now, such as "2 days ago" in Files or the greeting of the
// dashboard, is the same in every run: two runs render the same pixels, and the
// screenshot bot renews a picture only when the app changed what it shows.
const NOW = new Date('2026-10-09T09:30:00+02:00')
const TIME_ZONE = 'Europe/Berlin'
const FILED = Math.floor(Date.parse('2026-10-07T16:20:00+02:00') / 1000)

// Inter, which the social preview uses too, from the files of one version of Google
// Fonts: the stylesheet that Google Fonts serves for a family may name another
// version or another cut from one request to the next, which moves the letters by a
// fraction of a pixel.
const INTER = `
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; font-display: block; src: url(https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa25L7SUc.woff2) format('woff2'); unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF; }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: 100 900; font-display: block; src: url(https://fonts.gstatic.com/s/inter/v20/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7.woff2) format('woff2'); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
`
// Nextcloud asks for system-ui, which is a Chinese font in the image of Playwright.
// Inter stands in for the font of the system.
const FONT = `${INTER}
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
<style>${INTER}</style>
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

async function request(user, method, path, body, headers = {}) {
	const response = await fetch(`${BASE_URL}${path}`, {
		method,
		body,
		headers: { Authorization: authorization(user), 'OCS-APIRequest': 'true', Accept: 'application/json', ...headers },
	})
	if (!response.ok && !(method === 'MKCOL' && response.status === 405)) {
		throw new Error(`${method} ${path} as ${user}: ${response.status} ${await response.text()}`)
	}
	return response
}

// The templates of Nextcloud that the search for "invoice" finds besides the archive,
// with times of their own: Nextcloud copies them for each account at the same moment,
// and the search of Files would list them in either order.
const TEMPLATES = [['Templates/Invoice.ods', FILED - 3600], ['Templates/Invoice.odt', FILED - 7200]]

async function fixTemplates(user, templates = TEMPLATES) {
	for (const [path, time] of templates) {
		const found = await fetch(`${BASE_URL}${davPath(user, path)}`, { method: 'HEAD', headers: { Authorization: authorization(user) } })
		if (found.ok) {
			await fileAt(user, path, time)
		} else {
			console.log(`${user} has no ${path}, which the search would find.`)
		}
	}
}

// Gives a file or folder the time of its last change, in seconds since 1970.
async function fileAt(user, path, time) {
	const response = await request(user, 'PROPPATCH', davPath(user, path),
		`<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:lastmodified>${time}</d:lastmodified></d:prop></d:set></d:propertyupdate>`)
	const answer = await response.text()
	if (!/<d:status>HTTP\/1\.1 200 OK<\/d:status>/.test(answer)) {
		throw new Error(`${path} of ${user} kept its time of change: ${answer}`)
	}
}

const davPath = (user, path) => `/remote.php/dav/files/${user}/${path.split('/').map(encodeURIComponent).join('/')}`

// The ID of a file, which the link /f/<ID> opens.
async function fileId(user, path) {
	const response = await request(user, 'PROPFIND', davPath(user, path),
		'<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>')
	const id = (await response.text()).match(/<oc:fileid>(\d+)<\/oc:fileid>/)?.[1]
	if (!id) {
		throw new Error(`${path} of ${user} has no ID.`)
	}
	return id
}

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
	// A minute apart, in their order, as the search of Files lists the newest first.
	for (const [index, paper] of DOCUMENTS.entries()) {
		await page.setContent(documentHtml(paper), { waitUntil: 'networkidle' })
		await page.evaluate(() => document.fonts.ready)
		const pdf = await page.pdf({ format: 'A4', printBackground: true })
		await request(ARCHIVE, 'PUT', davPath(ARCHIVE, `Paperless/${paper.folder}/${fileName(paper)}`), pdf,
			{ 'X-OC-MTime': String(FILED + index * 60) })
	}
	await page.close()
	// Each upload made its folders modified now; the deepest folders go first, as the
	// time of a folder moves on to the folder around it.
	for (const folder of [...folders].sort().reverse()) {
		await fileAt(ARCHIVE, folder, FILED)
	}
	const share = new URLSearchParams({ path: '/Paperless', shareType: '0', shareWith: READER, permissions: '1' })
	await request(ARCHIVE, 'POST', `/ocs/v2.php/apps/files_sharing/api/v1/shares?${share}`)
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

// A page of its own, with the session, the language, the time zone and the font of the
// pictures, and the clock of the pictures unless clock is false, or another moment
// that clock names; pointer adds the pointer of the animation.
async function newPage(browser, session, options, { pointer: withPointer = false, clock = NOW } = {}) {
	const context = await browser.newContext({
		storageState: session,
		locale: 'en-US',
		timezoneId: TIME_ZONE,
		bypassCSP: true,
		reducedMotion: 'reduce',
		...options,
	})
	if (clock) {
		await context.clock.install({ time: clock })
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
async function settle(page, milliseconds = 600) {
	await page.evaluate(async () => {
		await document.fonts.ready
		await Promise.all([...document.images].map((image) => image.decode().catch(() => {})))
	})
	await page.waitForTimeout(milliseconds)
}

// Waits until the page looks the same in three pictures in a row, 400 ms apart: the
// viewer of PDF files draws the text of a page once more, sharper, at a moment that
// varies from run to run.
async function steady(page) {
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

async function save(page, name, milliseconds) {
	await settle(page, milliseconds)
	await page.screenshot({ path: join(OUT, name), animations: 'disabled' })
	console.log(`Took ${name}.`)
}

async function openArchive(page) {
	await page.goto(`${BASE_URL}/index.php/apps/files/files?dir=/Paperless`)
	await page.getByText('Northstar Energy', { exact: true }).first().waitFor()
}

const electricity = { ...DOCUMENTS[0], title: 'Electricity invoice - August 2026' }
const insurance = { ...DOCUMENTS[1], title: 'Home insurance renewal' }
// The last of the results of Paperless for TERM, which shows that they are all there.
const LAST_RESULT = 'Internet invoice - July 2026'

// The search with its Paperless results, and the file that a result opens.
async function searchAndOpen(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 900 }, colorScheme: theme })
	await openArchive(page)
	await searchButton(page).click()
	await search(page, (input) => input.fill(TERM), 'Files')
	await searchConnectedServices(page, LAST_RESULT)
	await save(page, `01-unified-search${suffix}.png`)
	await paperlessResult(page, electricity.title).click()
	await waitForViewer(page, fileName(electricity))
	await save(page, `02-nextcloud-pdf-viewer${suffix}.png`, 1500)
	await page.context().close()
}

// A custom range of the date filter, July 2026, and the documents of TERM that Paperless
// dates within it: not the electricity invoice of August, and no file of the demo,
// whose date in Files is the day that createArchive() uploaded it.
const JULY = { start: '2026-07-01', end: '2026-07-31' }
const DATED_IN_JULY = [insurance.title, LAST_RESULT]

// Fails unless the group Paperless documents holds the results of the titles and no
// other, in the list that the date filter narrowed: Nextcloud moves the results of a
// provider that ignores the filter below the label Partial matches.
async function expectDatedResults(page, titles) {
	const group = resultGroup(page, 'Paperless documents')
	for (const title of titles) {
		await paperlessResult(page, title).waitFor()
	}
	// The titles show already in the unfiltered list, and Nextcloud swaps in the filtered
	// answer only once it has parsed the body: count again until the list has narrowed.
	const deadline = Date.now() + 10_000
	let shown = await group.getByRole('link').count()
	while (shown !== titles.length && Date.now() < deadline) {
		await page.waitForTimeout(200)
		shown = await group.getByRole('link').count()
	}
	const partial = await group.evaluate((list) => {
		const label = [...list.ownerDocument.querySelectorAll('body *')]
			.find((element) => element.childElementCount === 0 && element.textContent.trim() === 'Partial matches')
		return Boolean(label && label.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING)
	})
	if (shown !== titles.length || partial) {
		const where = partial ? ' below Partial matches, as if the app ignored the date filter' : ''
		throw new Error(`Paperless documents should hold only ${titles.map((title) => JSON.stringify(title)).join(' and ')} in the filtered list, but hold ${shown} result(s)${where}:\n${await page.getByRole('dialog').first().ariaSnapshot()}`)
	}
}

// The search narrowed to July 2026 with a custom range of the date filter. Paperless
// filters by the date of the document, so the results are the documents of July, in
// Nextcloud's filtered list, and Files finds none of the files of the demo.
async function dateFilter(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 900 }, colorScheme: theme })
	await openArchive(page)
	await searchButton(page).click()
	await search(page, (input) => input.fill(TERM), 'Files')
	await searchConnectedServices(page, LAST_RESULT)
	await page.getByRole('button', { name: 'Date', exact: true }).click()
	await page.getByRole('menuitem', { name: 'Custom date range' }).click()
	const range = page.getByRole('dialog', { name: 'Custom date range' })
	await range.getByLabel('Pick start date').fill(JULY.start)
	await range.getByLabel('Pick end date').fill(JULY.end)
	// The search of Paperless again, with the span of the filter as since and until.
	const narrowed = page.waitForResponse((response) => {
		const url = new URL(response.url())
		return url.pathname.endsWith('/search/providers/paperless_unified_search_documents/search')
			&& url.searchParams.has('since') && url.searchParams.has('until')
	})
	await range.getByRole('button', { name: 'Search in date range' }).click()
	const answer = await narrowed
	await answer.finished()
	if (!answer.ok()) {
		throw new Error(`The search of Paperless from ${JULY.start} to ${JULY.end} failed: HTTP ${answer.status()} ${await answer.text()}`)
	}
	await expectDatedResults(page, DATED_IN_JULY)
	// Files filters by the day that a file last changed, the day of createArchive(), so
	// the range leaves none of the files of the demo, as the picture says.
	await resultGroup(page, 'Files').waitFor({ state: 'detached', timeout: 10_000 }).catch(async () => {
		throw new Error(`Files should find no file of the demo from ${JULY.start} to ${JULY.end}:\n${await page.getByRole('dialog').first().ariaSnapshot()}`)
	})
	// The search selects its term again once the range closes; the picture shows it plain.
	await searchField(page).press('End')
	await page.mouse.move(0, 0)
	await save(page, `12-date-filter${suffix}.png`)
	console.log(`The date filter of July 2026 kept ${DATED_IN_JULY.join(' and ')} in the filtered list.`)
	await page.context().close()
}

// The dashboard of jamie with the newest Paperless documents of the demo, which the
// mock of Paperless lists by the moment that it added them, as the only widget, and
// without the statuses above it.
async function dashboard(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	for (const [setting, value] of [['layout', ['paperless_unified_search-recent']], ['statuses', []]]) {
		const response = await fetch(`${BASE_URL}/ocs/v2.php/apps/dashboard/api/v3/${setting}`, {
			method: 'POST',
			body: JSON.stringify({ [setting]: value }),
			headers: { Authorization: authorization(READER), 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
		})
		if (!response.ok) {
			throw new Error(`The dashboard didn't take the ${setting} of ${READER}: ${response.status} ${await response.text()}`)
		}
	}
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 720 }, colorScheme: theme })
	await page.goto(`${BASE_URL}/index.php/apps/dashboard/`)
	const widget = page.locator('.panel').filter({ hasText: 'New in Paperless' })
	await widget.getByText('Property tax notice 2026').waitFor()
	await save(page, `11-dashboard${suffix}.png`, 1200)
	await page.context().close()
}

// The note of jamie in Text, in which the smart picker finds the electricity invoice.
const NOTE_LINE = 'The electricity bill of August, due on September 2:'

// Opens a new note in the folder and types the slash command for the smart picker on
// a line of its own below the line of the note. Text sets up the collaboration on the
// note after it shows the note and puts the cursor at its start, where a click on the
// line often failed to take it away from, which took the command into the heading.
// The keys put it at the end. Should the command land elsewhere all the same, the keys
// are undone and typed again; after three tries, or when the note can't be undone, the
// note is closed and null returned, for another try with another note.
async function typeCommand(browser, session, theme, folder) {
	for (const path of ['Notes', folder]) {
		await request(READER, 'MKCOL', davPath(READER, path))
	}
	await request(READER, 'PUT', davPath(READER, `${folder}/Household.md`), `# Household\n\n${NOTE_LINE}\n`)
	const id = await fileId(READER, `${folder}/Household.md`)
	// Without the clock of the pictures: the collaboration of Text tells the sessions of a
	// note apart by their time, and with a clock days behind the server's it shows the
	// session of the page as another one, with the name of the user, in the heading. The
	// note shows no time.
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 800 }, colorScheme: theme },
		{ clock: false })
	// The first answer of the collaboration, after which Text puts the cursor at the start.
	const synced = page.waitForResponse((response) => /\/apps\/text\/.*\b(sync|push)\b/.test(response.url()),
		{ timeout: 30000 }).catch(() => null)
	await page.goto(`${BASE_URL}/index.php/f/${id}`)
	const editor = page.locator('.ProseMirror[contenteditable="true"]')
	await editor.getByText(NOTE_LINE).waitFor()
	await synced
	await settle(page, 1500)
	// The lines of the note, without what Text draws into it and that can't be edited,
	// such as the anchor # of the heading.
	const read = () => editor.evaluate((element) => [...element.children].map((child) => {
		const copy = child.cloneNode(true)
		copy.querySelectorAll('[contenteditable="false"]').forEach((drawn) => drawn.remove())
		return copy.textContent.replace(/^#/, '')
	}))
	const empty = (lines) => lines.every((line) => line === '')
	const unchanged = (lines) => lines[0] === 'Household' && lines[1] === NOTE_LINE && empty(lines.slice(2))
	for (let attempt = 1; attempt <= 3; attempt++) {
		// The keys, not a click, put the cursor at the end of the note, below whatever
		// Text may draw over its lines: into the empty line that Text keeps at the end of
		// a note, or into a new one.
		await editor.focus()
		await page.keyboard.press('Control+End')
		if ((await read()).at(-1) !== '') {
			await page.keyboard.press('Enter')
		}
		await page.keyboard.type('/Paperless')
		// The note holds the heading, the line and the command, and nothing else but an
		// empty line at its end.
		const lines = await read()
		const [heading, line, command, ...rest] = lines
		if (heading === 'Household' && line === NOTE_LINE && command === '/Paperless' && empty(rest)) {
			return page
		}
		console.log(`Text moved the cursor while the command was typed, so ${folder}/Household.md reads ${JSON.stringify(lines)}; undoing it.`)
		// Undo what the keys did, a step at a time, until the note reads as before.
		for (let undo = 0; undo < 10 && !unchanged(await read()); undo++) {
			await page.keyboard.press('Control+z')
			await page.waitForTimeout(200)
		}
		if (!unchanged(await read())) {
			break
		}
		await settle(page, 1000)
	}
	await page.context().close()
	return null
}

// The smart picker in a note of Text, with the Paperless documents that it finds, and
// the link that it inserts, which Text shows as the preview of the file.
async function smartPicker(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	// A note of its own for each theme, and for each try: Text would keep the link of
	// another one.
	let page = null
	for (let attempt = 1; !page; attempt++) {
		if (attempt > 3) {
			throw new Error(`Text moved the cursor into the heading of ${attempt - 1} notes, each typed three times.`)
		}
		page = await typeCommand(browser, session, theme, attempt === 1 ? `Notes/${theme}` : `Notes/${theme}-${attempt}`)
	}
	await page.locator('.suggestion-list').getByText('Paperless documents', { exact: true }).click()
	const picker = page.locator('.modal-container').filter({ has: page.locator('.smart-picker-search') })
	await picker.locator('.smart-picker-search input').fill(TERM)
	await picker.getByRole('option', { name: /^Internet invoice - July 2026/ }).waitFor()
	await page.mouse.move(0, 0)
	await save(page, `07-smart-picker${suffix}.png`)
	await picker.getByRole('option', { name: /^Electricity invoice - August 2026/ }).click()
	await page.frameLocator('iframe.widget-file').locator('.page[data-loaded="true"]').first().waitFor({ timeout: 30000 })
	await page.mouse.move(0, 0)
	await save(page, `08-smart-picker-link${suffix}.png`, 1500)
	await page.context().close()
}

// The tab Sharing of the folder Paperless of the archive account, which shares it
// read-only with jamie: what the configuration asks an administrator to do.
async function share(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 900 }, colorScheme: theme })
	await openArchive(page)
	// The menu of the folder in the path above the list, as an administrator shares it.
	await page.getByRole('navigation', { name: 'Current directory path' }).getByRole('button', { name: 'Paperless' }).click()
	await page.getByRole('menuitem', { name: 'Share' }).click()
	const sidebar = page.locator('#app-sidebar-vue')
	await sidebar.getByText('Jamie Example').first().waitFor()
	await sidebar.getByText('View only').first().waitFor()
	// Away from the list of files, whose rows light up under the pointer.
	await page.mouse.move(0, 0)
	await save(page, `10-share-read-only${suffix}.png`)
	await page.context().close()
}

// The settings after a save, down to the message of success below the buttons.
async function settings(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 1000 }, colorScheme: theme })
	await page.goto(`${BASE_URL}${SETTINGS}`)
	await page.locator(SETTINGS_SCOPE).waitFor()
	await page.locator('#paperless-unified-search-save').click()
	await page.getByText('Connection successful. Settings saved.').waitFor()
	// The demo talks to the mock in the network of Docker, with the switch for the local
	// network; the picture shows the name of a service of Docker that an administrator
	// enters, as the stored one. Typed, it would name another server, and the token
	// field would ask for the token again.
	await page.locator('#paperless-unified-search-url').evaluate((input) => {
		input.defaultValue = 'http://paperless:8000'
		input.value = input.defaultValue
	})
	await page.locator('#app-navigation a[href$="/settings/admin/paperless_unified_search"]').scrollIntoViewIfNeeded()
	await save(page, `03-admin-settings${suffix}.png`)
	// The history of the problems of the search, which scripts/screenshots.sh sets up.
	const problems = page.locator('.paperless-unified-search-diagnostics')
	await problems.scrollIntoViewIfNeeded()
	// Away from the table, whose rows light up under the pointer.
	await page.mouse.move(0, 0)
	await settle(page)
	await problems.screenshot({ path: join(OUT, `06-search-problems${suffix}.png`), animations: 'disabled' })
	console.log(`Took 06-search-problems${suffix}.png.`)
	await page.context().close()
}

// Try a search of the settings, as the administrator searches for "invoice" as
// themselves: the archive account has shared only the file of the electricity invoice
// with them, so the other two documents are not shared with them.
async function shareElectricityWithAdmin() {
	const share = new URLSearchParams({
		path: `/Paperless/${electricity.folder}/${fileName(electricity)}`,
		shareType: '0',
		shareWith: ADMIN,
		permissions: '1',
	})
	await request(ARCHIVE, 'POST', `/ocs/v2.php/apps/files_sharing/api/v1/shares?${share}`)
}

async function trySearch(browser, session, theme) {
	const suffix = theme === 'dark' ? '-dark' : ''
	const page = await newPage(browser, session, { viewport: { width: 1600, height: 1000 }, colorScheme: theme })
	await page.goto(`${BASE_URL}${SETTINGS}`)
	await page.locator(SETTINGS_SCOPE).waitFor()
	await page.locator('#paperless-unified-search-try-term').fill(TERM)
	await page.locator('#paperless-unified-search-try-user').fill(ADMIN)
	await page.locator('#paperless-unified-search-try').click()
	await page.locator('.paperless-unified-search-documents').waitFor()
	const block = page.locator('.paperless-unified-search-try')
	await block.scrollIntoViewIfNeeded()
	// Away from the table, whose rows light up under the pointer.
	await page.mouse.move(0, 0)
	await settle(page)
	await block.screenshot({ path: join(OUT, `10-try-a-search${suffix}.png`), animations: 'disabled' })
	console.log(`Took 10-try-a-search${suffix}.png.`)
	await page.context().close()
}

// The settings on a phone at the problems of the search, each of them a card whose
// lines name their column.
async function phoneSettings(browser, session) {
	const page = await newPage(browser, session, {
		viewport: { width: 390, height: 844 },
		deviceScaleFactor: 2,
		isMobile: true,
		hasTouch: true,
	})
	await page.goto(`${BASE_URL}${SETTINGS}`)
	await page.locator(SETTINGS_SCOPE).waitFor()
	await page.locator('.paperless-unified-search-diagnostics h3').evaluate((heading) => {
		heading.scrollIntoView({ block: 'start' })
		// Back up to the end of the text of the box above, so that the button of the
		// navigation, which stays in the top left corner of a phone, covers nothing.
		const note = document.querySelector('.paperless-unified-search-note')
		const gap = heading.getBoundingClientRect().top - note.getBoundingClientRect().bottom
			+ parseFloat(getComputedStyle(note).paddingBottom)
		let box = heading.parentElement
		while (box !== document.scrollingElement && !['auto', 'scroll'].includes(getComputedStyle(box).overflowY)) {
			box = box.parentElement
		}
		box.scrollBy(0, -gap)
	})
	await save(page, '09-admin-settings-mobile.png')
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
	await search(page, (input) => input.fill(TERM), 'Files')
	await searchConnectedServices(page, LAST_RESULT)
	await save(page, '04-mobile-search.png')
	await paperlessResult(page, electricity.title).click()
	await waitForViewer(page, fileName(electricity))
	await save(page, '05-mobile-viewer.png', 1500)
	await page.context().close()
}

// The animation of the README, from the archive of the mock: its name, the moment of its
// clock, the page that it starts on, whether the search waits for the whole term,
// whether it switches on the connected services, as the search of Paperless needs
// unless the administrator trusts it, the Paperless results that it waits for, a check
// of them, and the result that it opens, by its title and the name of its file.
const SEARCH_AND_OPEN = {
	name: 'search-and-open.gif',
	clock: NOW,
	start: openArchive,
	holdSearch: false,
	connected: true,
	results: [LAST_RESULT],
	check: async () => {},
	opened: { title: insurance.title, file: fileName(insurance) },
}

// An animation of a film such as SEARCH_AND_OPEN: the archive, the search for "invoice",
// the switch for connected services where it needs it, and the insurance renewal, which
// only Paperless finds by its text.
async function animation(browser, session, film = SEARCH_AND_OPEN) {
	const frames = []
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 800 } }, { pointer: true, clock: film.clock })
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

	await film.start(page)
	await page.mouse.move(...position)
	await settle(page)
	await frame(1600)
	await click(searchButton(page))
	await search(page, async (input) => {
		await settle(page, 300)
		// The filters below the field, such as Places, come a moment after the dialog,
		// later in one run than in another.
		await steady(page)
		await frame(500)
		if (film.holdSearch) {
			// The clock stands still while the term grows, so that Nextcloud, which
			// searches once the typing pauses, searches for the whole term alone.
			await page.clock.pauseAt(await page.evaluate(() => Date.now() + 1000))
		}
		for (const letter of TERM) {
			await input.press(letter)
			await frame(110)
		}
		if (film.holdSearch) {
			await page.clock.resume()
		}
	}, 'Files')
	if (film.connected) {
		await settle(page, 400)
		await frame(1400)
		await click(connectedServices(page))
	}
	await resultGroup(page, 'Paperless documents').waitFor()
	for (const title of film.results) {
		await paperlessResult(page, title).waitFor()
	}
	await settle(page, 300)
	await film.check(page)
	await frame(2600)
	await click(paperlessResult(page, film.opened.title))
	await waitForViewer(page, film.opened.file)
	await settle(page, 1500)
	await steady(page)
	await frame(3800)
	await page.context().close()
	writeGif(frames, join(OUT, film.name))
	console.log(`Took ${film.name} with ${frames.length} frames.`)
}

// The animation of the demo of the README, from the real Paperless-ngx and Nextcloud of
// demo/seed.sh, as jamie: the clock starts two days after the last change of the
// archive, and the templates that the search finds get times of their own
// (tests/e2e/demo.mjs). It fails when the search shows other Paperless documents than
// the three of the demo.
async function demoAnimation(browser) {
	const archive = await fetch(`${BASE_URL}${davPath(DEMO.user, DEMO.folder)}`, {
		method: 'PROPFIND',
		body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getlastmodified/></d:prop></d:propfind>',
		headers: { Authorization: authorization(DEMO.user), Depth: '1' },
	})
	if (archive.status === 401) {
		throw new Error(`${DEMO.user} can't sign in with DEMO_PASSWORD of demo/.env: is it the demo of this demo/.env? Remove the demo and demo/.env, then run bash demo/seed.sh.`)
	}
	if (!archive.ok) {
		throw new Error(`${DEMO.user} has no folder ${DEMO.folder} (HTTP ${archive.status}), which the archive account shares: run bash demo/seed.sh to its end.`)
	}
	const changed = lastChange(await archive.text())
	// What a person does at their first sign-in: they close the wizard of Nextcloud, which
	// would cover the page, and Nextcloud shows them online, as the dot on their avatar
	// says. Chosen here, the dot is there from the first frame on, not from the moment
	// that the browser tells Nextcloud, which varies from run to run. And they read their
	// notifications: a demo that has run for a while has some, a fresh one none, and the
	// red dot of the bell would change every frame of the animation. Without the app of
	// one of them, Nextcloud answers 404, and there is nothing to do.
	for (const [method, path] of [
		['DELETE', '/index.php/apps/firstrunwizard/wizard'],
		['DELETE', '/ocs/v2.php/apps/notifications/api/v2/notifications'],
		['PUT', `/ocs/v2.php/apps/user_status/api/v1/user_status/status?${new URLSearchParams({ statusType: 'online' })}`],
	]) {
		const response = await fetch(`${BASE_URL}${path}`, {
			method,
			headers: { Authorization: authorization(DEMO.user), 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		if (!response.ok && response.status !== 404) {
			throw new Error(`${method} ${path} as ${DEMO.user}: ${response.status} ${await response.text()}`)
		}
	}
	const session = await signIn(browser, DEMO.user, { timezoneId: TIME_ZONE })
	// Nextcloud copies the templates at the first sign-in.
	await fixTemplates(DEMO.user, templateTimes(changed))
	await animation(browser, session, {
		name: DEMO.gif,
		clock: demoClock(changed),
		start: async (page) => {
			await page.goto(`${BASE_URL}/index.php/apps/files/files?dir=/${encodeURIComponent(DEMO.folder)}`)
			await page.getByText(DEMO.shows, { exact: true }).first().waitFor()
		},
		// seed.sh sets the app up with --always-search: Paperless answers every search.
		connected: false,
		// A real Paperless answers a part of the term, such as "inv", sooner in one run
		// than in another.
		holdSearch: true,
		results: RESULTS,
		check: async (page) => {
			const names = await resultGroup(page, 'Paperless documents').getByRole('link')
				.evaluateAll((links) => links.map((link) => link.textContent))
			const problem = unexpectedResults(names)
			if (problem) {
				throw new Error(problem)
			}
		},
		opened: OPENED,
	})
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

// Every picture of the README, the website and the App Store, from the mock.
async function everyPicture(browser) {
	await createArchive(browser)
	// In the time zone of the pages, which Nextcloud keeps as the user's: the times that
	// the server writes, as in Search problems, are then in the zone of the browser's.
	const reader = await signIn(browser, READER, { timezoneId: TIME_ZONE })
	const admin = await signIn(browser, ADMIN, { timezoneId: TIME_ZONE })
	const archive = await signIn(browser, ARCHIVE, { timezoneId: TIME_ZONE })
	// Once signed in, each account has its templates.
	for (const user of [READER, ADMIN]) {
		await fixTemplates(user)
	}
	await shareElectricityWithAdmin()
	for (const theme of ['light', 'dark']) {
		await searchAndOpen(browser, reader, theme)
		await dateFilter(browser, reader, theme)
		await smartPicker(browser, reader, theme)
		await settings(browser, admin, theme)
		await share(browser, archive, theme)
		await trySearch(browser, admin, theme)
		await dashboard(browser, reader, theme)
	}
	await phone(browser, reader)
	await phoneSettings(browser, admin)
	await animation(browser, reader)
}

// Chromium draws the same pixels in every run and on every processor: Skia without
// the code of a particular processor, every tile and every image drawn in full before
// the picture, and the colours in sRGB.
const browser = await chromium.launch({
	args: [
		'--disable-skia-runtime-opts',
		'--disable-partial-raster',
		'--disable-checker-imaging',
		'--force-color-profile=srgb',
	],
})
try {
	if (process.env.SCREENSHOTS_DEMO === '1') {
		await demoAnimation(browser)
	} else {
		await everyPicture(browser)
	}
} finally {
	await browser.close()
}
