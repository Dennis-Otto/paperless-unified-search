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

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import gifenc from 'gifenc'
import pngjs from 'pngjs'
import { chromium } from 'playwright-core'
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
import { CHROMIUM_ARGS, INTER, encodeGif, newPage as recordingPage, recorder, settle, steady } from './recording.mjs'

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

// A page of its own, in the time zone of the pictures and, unless clock is false, with
// their clock (recording.mjs); pointer adds the pointer of the animation.
const newPage = (browser, session, options, { pointer = false, clock = true } = {}) =>
	recordingPage(browser, session, { timezoneId: TIME_ZONE, ...options }, { pointer, now: clock ? NOW : null })

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

async function fixTemplates(user) {
	for (const [path, time] of TEMPLATES) {
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

// The animation: the archive, the search for "invoice", the switch for connected
// services and the insurance renewal, which only Paperless finds by its text.
async function animation(browser, session) {
	const start = [640, 460]
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 800 } }, { pointer: true })
	const { frames, frame, click } = recorder(page, start)

	await openArchive(page)
	await page.mouse.move(...start)
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
	}, 'Files')
	await settle(page, 400)
	await frame(1400)
	await click(connectedServices(page))
	await resultGroup(page, 'Paperless documents').waitFor()
	await paperlessResult(page, LAST_RESULT).waitFor()
	await settle(page, 300)
	await frame(2600)
	await click(paperlessResult(page, insurance.title))
	await waitForViewer(page, fileName(insurance))
	await settle(page, 1500)
	await steady(page)
	await frame(3800)
	await page.context().close()
	writeFileSync(join(OUT, 'search-and-open.gif'), encodeGif(frames, { PNG, GIFEncoder, quantize }))
	console.log(`Took search-and-open.gif with ${frames.length} frames.`)
}

const browser = await chromium.launch({ args: CHROMIUM_ARGS })
try {
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
} finally {
	await browser.close()
}
