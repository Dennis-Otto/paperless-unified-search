// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The search dialog of Nextcloud as a person uses it, on a laptop and on a phone: the
// user opens the unified search, types the term, turns on the search of connected
// services and sees the group Paperless documents with the title and the excerpt of
// the document, and only the one that a file of the archive account stands for. A click
// on the result opens that file in Files, in the Viewer, which names it in its
// header. run.sh starts it in the image of Playwright after accessibility.mjs, inside
// the network of the Compose project.

import { chromium } from 'playwright-core'
import {
	BASE_URL,
	connectedServices,
	resultGroup,
	search,
	searchButton,
	signIn,
	waitForViewer,
} from './ui.mjs'

const USER = process.env.E2E_SEARCH_USER ?? 'e2e-user'
const TERM = 'mobiletest'
const TITLE = 'Mobile viewer test'
// The date of the document in the language of the user, its correspondent and its type,
// and its excerpt, which Paperless sends marked up and escaped as HTML, as text without
// a tag.
const SUBLINE = 'Aug 26, 2026 · Example Mobile · Letter — Synthetic OCR mobiletest result for O\'Brien & "Sons" < GmbH>'
// The name of the result, as a screen reader reads it.
const RESULT = `${TITLE} ${SUBLINE}`
const FILE = 'Mobile viewer test [P123].pdf'
const START = '/index.php/settings/user'

// The phone draws one pixel per CSS pixel: its layout is the same, and Chromium draws
// a quarter of the pixels of a screen with twice the density.
const SIZES = [
	{ name: 'laptop', options: { viewport: { width: 1280, height: 800 } } },
	{ name: 'phone', options: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
]
// The sizes of this run, such as "laptop" or "laptop phone", and every size without
// E2E_FLOW_SIZES: run-all.sh tests the phone in a pull request only against the newest
// Nextcloud (E2E_PHONE=newest), where each size costs a version 16 to 19 seconds.
const NAMES = (process.env.E2E_FLOW_SIZES ?? '').split(/[\s,]+/).filter(Boolean)
const unknown = NAMES.filter((name) => !SIZES.some((size) => size.name === name))
if (unknown.length > 0) {
	throw new Error(`E2E_FLOW_SIZES names ${unknown.join(', ')}, but the sizes are ${SIZES.map((size) => size.name).join(', ')}.`)
}
const RUN = NAMES.length === 0 ? SIZES : SIZES.filter((size) => NAMES.includes(size.name))
// A step waits this long, in milliseconds: on a runner of the CI, the three versions of
// Nextcloud and their browsers share four CPUs, and a page of Files takes seconds there.
const TIMEOUT = 60000

async function searchAndOpen(browser, session, size) {
	const started = Date.now()
	const context = await browser.newContext({ storageState: session, locale: 'en-US', ...size.options })
	context.setDefaultTimeout(TIMEOUT)
	const page = await context.newPage()
	// What went wrong in the page, for the error of a step that doesn't come.
	const problems = []
	page.on('pageerror', (error) => problems.push(`Error in the page: ${error.message}`))
	page.on('requestfailed', (request) => {
		// A page that the browser leaves aborts its requests, as it should.
		if (request.failure()?.errorText !== 'net::ERR_ABORTED') {
			problems.push(`Failed request: ${request.url()} (${request.failure()?.errorText})`)
		}
	})
	page.on('response', (response) => {
		if (response.status() >= 500) {
			problems.push(`HTTP ${response.status()}: ${response.url()}`)
		}
	})
	try {
		// The search is in the header of every page. It starts from the personal settings,
		// which load in half the time of Files: the result opens Files anyway, and on a
		// runner of the CI every page of Files costs seconds.
		await page.goto(`${BASE_URL}${START}`, { waitUntil: 'domcontentloaded' })
		await searchButton(page).click()
		await search(page, (input) => input.fill(TERM))
		await connectedServices(page).click()

		// Only the document that a file of the archive account stands for, of the two that
		// Paperless ranks, read out as its title, then its date and its excerpt as text, as
		// a screen reader reads them. Otherwise the error shows what the group holds.
		const group = resultGroup(page, 'Paperless documents')
		const results = group.getByRole('link')
		await results.first().waitFor()
		const result = results.and(page.getByRole('link', { name: RESULT, exact: true }))
		if (await results.count() !== 1 || await result.count() !== 1) {
			throw new Error(`Paperless documents should hold only the result ${JSON.stringify(RESULT)}, but hold:\n${await group.ariaSnapshot()}`)
		}

		await result.click()
		// The header names the file of the result; drawing the PDF is Nextcloud's own job.
		await waitForViewer(page, FILE, { drawn: false })
		const seconds = ((Date.now() - started) / 1000).toFixed(1)
		console.log(`Search dialog on a ${size.name}: ${TITLE} with its excerpt among the Paperless documents, opened in the Viewer, in ${seconds} s.`)
	} catch (error) {
		// Where it stopped: the address, what a screen reader finds in the header, and
		// what went wrong in the page.
		const header = await page.locator('#header').ariaSnapshot({ timeout: 5000 }).catch(() => '(none)')
		throw new Error([
			`On a ${size.name}, at ${page.url()}: ${error.message}`,
			`The header holds:\n${header}`,
			...problems,
		].join('\n'), { cause: error })
	} finally {
		await context.close()
	}
}

const browser = await chromium.launch()
try {
	const session = await signIn(browser, USER)
	// One size after the other, each in a context of its own, as two devices of the
	// user. Both at once would take no less time on the CPUs of the CI, and the first
	// visits of the personal settings at once race in Nextcloud to store the profile of
	// the user: one of them fails with HTTP 500 (UNIQUE constraint of oc_profile_config).
	for (const size of SIZES) {
		if (RUN.includes(size)) {
			await searchAndOpen(browser, session, size)
		} else {
			console.log(`Search dialog on a ${size.name}: left out, as E2E_FLOW_SIZES is ${JSON.stringify(process.env.E2E_FLOW_SIZES)}.`)
		}
	}
} finally {
	await browser.close()
}
