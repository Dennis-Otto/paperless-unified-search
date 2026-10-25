// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The search dialog of Nextcloud as a person uses it, on a laptop and on a phone: the
// user opens the unified search, types the term, turns on the search of connected
// services and sees the group Paperless documents with the title and the excerpt of
// the document, and only the one that a file of the archive account stands for. A click
// on the result opens that file in Files, in the Viewer, which names it in its
// header. A user who chose in the personal settings to include Paperless in every
// search of their own finds its results after a reload without the switch (#220).
// run.sh starts it in the image of Playwright after accessibility.mjs, inside the
// network of the Compose project.

import { chromium } from 'playwright-core'
import { SIZES, flowSizes } from './sizes.mjs'
import {
	ALWAYS_SEARCH,
	BASE_URL,
	PERSONAL,
	PERSONAL_SCOPE,
	chooseAlwaysSearch,
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

// The sizes of this run, from E2E_FLOW_SIZES, and every size without it.
const RUN = flowSizes(process.env.E2E_FLOW_SIZES)
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

// The user ticks Always search my Paperless documents in the personal settings, opens
// another page, as a reload does, and finds the result among the Paperless documents
// without the switch Search connected services, which Nextcloud turned off again. The
// user then takes the choice back, for the next runs.
async function searchAlways(browser, session, size) {
	const context = await browser.newContext({ storageState: session, locale: 'en-US', ...size.options })
	context.setDefaultTimeout(TIMEOUT)
	const page = await context.newPage()
	try {
		await page.goto(`${BASE_URL}${PERSONAL}`, { waitUntil: 'domcontentloaded' })
		await page.locator(PERSONAL_SCOPE).waitFor()
		await chooseAlwaysSearch(page, true)
		// The dialog searches only the providers that it has loaded: a term typed before
		// they arrive starts no search, and nothing else here would start one later.
		const providers = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/search/providers'))
		await page.goto(`${BASE_URL}${START}`, { waitUntil: 'domcontentloaded' })
		await searchButton(page).click()
		await providers
		await search(page, (input) => input.fill(TERM))
		await resultGroup(page, 'Paperless documents').getByRole('link', { name: RESULT, exact: true }).waitFor()
		console.log(`Search dialog on a ${size.name} after choosing to always search Paperless: ${TITLE} without the switch.`)
	} catch (error) {
		throw new Error(`On a ${size.name}, with the choice to always search Paperless, at ${page.url()}: ${error.message}`, { cause: error })
	} finally {
		try {
			await page.goto(`${BASE_URL}${PERSONAL}`, { waitUntil: 'domcontentloaded' })
			// Unticks only a stored choice, so that a section that didn't show or a save
			// that failed doesn't turn the cleanup into a timeout that hides the error.
			const box = page.locator(ALWAYS_SEARCH)
			if (await box.isVisible() && await box.isChecked()) {
				await chooseAlwaysSearch(page, false)
			}
		} finally {
			await context.close()
		}
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
	// Once, on the first size of the run: the choice takes no other path on a phone.
	await searchAlways(browser, session, RUN[0])
} finally {
	await browser.close()
}
