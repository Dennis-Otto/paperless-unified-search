// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// Records the demo of the README as an animation, screenshots/demo.gif: jamie in the
// real Nextcloud of demo/seed.sh opens the archive that Paperless Sync wrote, searches
// for "invoice", and the search shows the real Paperless documents, the home insurance
// renewal among them, whose text says "invoice" while its title doesn't; it opens in
// Nextcloud's viewer. scripts/demo-gif.sh starts it in the image of Playwright, inside
// the network of the demo, once the demo runs, with E2E_BASE_URL, E2E_PASSWORD (the
// password of jamie from demo/.env) and RECORDING_OUT, the folder for the animation.
// It fails when the search doesn't show what the animation is meant to show.
//
// Unlike the pictures of scripts/screenshots.sh, from the mock of Paperless, this one
// keeps the clock of the machine: Paperless Sync wrote the files of the archive when
// the demo started, and Files shows how long ago that was.

import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import gifenc from 'gifenc'
import pngjs from 'pngjs'
import { chromium } from 'playwright-core'
import { CHROMIUM_ARGS, encodeGif, newPage, recorder, settle, steady } from './recording.mjs'
import { BASE_URL, paperlessResult, resultGroup, search, searchButton, signIn, waitForViewer } from './ui.mjs'

const { GIFEncoder, quantize } = gifenc
const { PNG } = pngjs

// The folder for the animation, which scripts/demo-gif.sh makes with mktemp.
const OUT = process.env.RECORDING_OUT
if (!OUT) {
	throw new Error('RECORDING_OUT names no folder for the animation.')
}
// The user of the demo, the search that demo/seed.sh ends with, and the documents
// that it shows to jamie: the two invoices, and the renewal, found by its text.
const READER = 'jamie'
const TERM = 'invoice'
const ARCHIVE = '/Archive'
const RESULTS = ['Electricity invoice - August 2026', 'Internet invoice - July 2026', 'Home insurance renewal']
const OPENED = 'Home insurance renewal'

// Fails unless the group Paperless documents shows the results of the titles and no
// other, with the list of what it shows.
async function expectResults(page) {
	const group = resultGroup(page, 'Paperless documents')
	for (const title of RESULTS) {
		await paperlessResult(page, title).waitFor({ timeout: 30_000 })
	}
	const shown = await group.getByRole('link').count()
	if (shown !== RESULTS.length) {
		throw new Error(`Paperless documents should show ${RESULTS.length} results for ${TERM}, but show ${shown}:\n${await group.ariaSnapshot()}`)
	}
}

const browser = await chromium.launch({ args: CHROMIUM_ARGS })
try {
	const session = await signIn(browser, READER)
	const page = await newPage(browser, session, { viewport: { width: 1280, height: 800 } }, { pointer: true })
	const start = [640, 460]
	const { frames, frame, click } = recorder(page, start)
	// The archive that the archive account paperless shares with jamie, with a folder for
	// each correspondent, as Paperless Sync names them.
	await page.goto(`${BASE_URL}/index.php/apps/files/files?dir=${encodeURIComponent(ARCHIVE)}`)
	await page.getByText('Northstar Energy', { exact: true }).first().waitFor({ timeout: 30_000 })
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
	// The demo searches Paperless always (occ paperless_unified_search:setup
	// --always-search), so its documents come with the files, without the switch for
	// connected services.
	await resultGroup(page, 'Paperless documents').waitFor({ timeout: 30_000 })
	await expectResults(page)
	await settle(page, 400)
	await frame(2800)
	await click(paperlessResult(page, OPENED))
	await waitForViewer(page, OPENED)
	await settle(page, 1500)
	await steady(page)
	await frame(3800)
	await page.context().close()
	writeFileSync(join(OUT, 'demo.gif'), encodeGif(frames, { PNG, GIFEncoder, quantize }))
	console.log(`Took demo.gif with ${frames.length} frames.`)
} finally {
	await browser.close()
}
