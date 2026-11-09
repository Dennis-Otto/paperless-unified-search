/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// openInViewer of tests/e2e/ui.mjs, the step of the screenshots that opens a file in the
// Viewer with the clock of the page standing still, so that the viewer of PDF files
// draws the page in one slice and the picture comes out the same in every run.
// scripts/screenshots.sh shows that it does so in a real Nextcloud; this shows the
// order of its steps without a browser.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { openInViewer } from '../e2e/ui.mjs'

// The moment of the clock of the page, which the clock of the pictures set: never the
// time of the machine that runs the test.
const PAGE_TIME = Date.parse('2026-10-09T09:30:07+02:00')
const NAME = '2026-08-12 - Electricity invoice - August 2026 [P412].pdf'

// A stand-in for a page of Playwright, as far as openInViewer reaches: it notes what
// the step does in `steps`.
function standIn() {
	const steps = []
	const waiting = (step) => ({ waitFor: async () => steps.push(step) })
	const page = {
		evaluate: async (read) => {
			steps.push(`read the clock: ${typeof read}`)
			return PAGE_TIME
		},
		clock: {
			setFixedTime: async (time) => steps.push(`hold the clock at ${time}`),
		},
		waitForURL: async (matches) => {
			assert.ok(matches(new URL('http://nextcloud/index.php/apps/files/files/412?openfile=true')))
			assert.ok(!matches(new URL('http://nextcloud/index.php/apps/dashboard/')))
			steps.push('the Viewer of Files')
		},
		frameLocator: (selector) => ({
			locator: (inside) => ({ first: () => waiting(`drawn: ${selector} ${inside}`) }),
		}),
		locator: (selector) => ({ getByText: (text) => waiting(`${selector}: ${text}`) }),
	}
	return { page, steps }
}

test('holds the clock of the page still before it opens the file, then waits for the drawn page', async function() {
	const { page, steps } = standIn()
	await openInViewer(page, async () => steps.push('open'), NAME)
	assert.deepEqual(steps, [
		'read the clock: function',
		`hold the clock at ${PAGE_TIME}`,
		'open',
		'the Viewer of Files',
		'drawn: iframe .page[data-loaded="true"]',
		`.modal-header: ${NAME}`,
	])
})

test('a click that fails names its own error and waits for no viewer', async function() {
	const { page, steps } = standIn()
	const failure = new Error('The result is covered.')
	await assert.rejects(openInViewer(page, async () => {
		throw failure
	}, NAME), failure)
	assert.deepEqual(steps, ['read the clock: function', `hold the clock at ${PAGE_TIME}`])
})
