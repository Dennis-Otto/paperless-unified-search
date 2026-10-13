// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The search dialog of Nextcloud as a person uses it, on a laptop and on a phone: in
// Files, the user opens the unified search, types the term, turns on the search of
// connected services and sees the group Paperless documents with the title and the
// excerpt of the document, and only the one that a file of the archive account stands
// for. A click on the result opens that file in the Viewer, which names it in its
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
// The date of the document and its excerpt, which Paperless sends marked up and escaped
// as HTML, as text.
const SUBLINE = '2026-08-26 · Synthetic OCR mobiletest result for O\'Brien & "Sons" <GmbH>'
// The name of the result, as a screen reader reads it.
const RESULT = `${TITLE} ${SUBLINE}`
const FILE = 'Mobile viewer test [P123].pdf'

// The phone draws one pixel per CSS pixel: its layout is the same, and Chromium draws
// a quarter of the pixels of a screen with twice the density.
const SIZES = [
	{ name: 'laptop', options: { viewport: { width: 1280, height: 800 } } },
	{ name: 'phone', options: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
]
// A step waits this long, in milliseconds: on a runner of the CI, the three versions of
// Nextcloud and their browsers share four CPUs, and a page of Files takes seconds there.
const TIMEOUT = 60000

async function searchAndOpen(browser, session, size) {
	const started = Date.now()
	const context = await browser.newContext({ storageState: session, locale: 'en-US', ...size.options })
	context.setDefaultTimeout(TIMEOUT)
	try {
		const page = await context.newPage()
		// The search waits for its button, not for every script of Files.
		await page.goto(`${BASE_URL}/index.php/apps/files/`, { waitUntil: 'domcontentloaded' })
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
			throw new Error(`On a ${size.name}, Paperless documents should hold only the result ${JSON.stringify(RESULT)}, but hold:\n${await group.ariaSnapshot()}`)
		}

		await result.click()
		// The header names the file of the result; drawing the PDF is Nextcloud's own job.
		await waitForViewer(page, FILE, { drawn: false })
		const seconds = ((Date.now() - started) / 1000).toFixed(1)
		console.log(`Search dialog on a ${size.name}: ${TITLE} with its excerpt among the Paperless documents, opened in the Viewer, in ${seconds} s.`)
	} finally {
		await context.close()
	}
}

const browser = await chromium.launch()
try {
	const session = await signIn(browser, USER)
	// Both sizes at once, each in a context of its own, as two devices of the user.
	await Promise.all(SIZES.map((size) => searchAndOpen(browser, session, size)))
} finally {
	await browser.close()
}
