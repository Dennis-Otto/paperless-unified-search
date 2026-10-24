// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// axe-core checks the pages of the app in Chromium against WCAG 2.1 at levels A and
// AA, in the light and the dark theme of Nextcloud, on a laptop and on a phone. It looks
// only into the element that holds the app's own markup, so what Nextcloud draws around
// it doesn't count. A serious or critical violation fails the check; the others are
// listed. On the way to each state of a page, it checks what the page does there and
// how it looks, such as the token field that asks for the token again for another
// server, the message of a failed save in the colour of an error, and the fields of one
// width, and an unexpected state fails the check as well. After the pages with the
// history of the problems of the search, it clears the history. run.sh starts it in the
// image of Playwright, inside the network of the Compose project, after npm ci of
// package-lock.json.

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { chromium } from 'playwright-core'
import {
	ALWAYS_SEARCH,
	ALWAYS_SEARCH_SAVED,
	BASE_URL,
	PERSONAL,
	PERSONAL_SCOPE,
	PERSONAL_STATUS,
	SETTINGS,
	SETTINGS_SCOPE,
	STATUS,
	chooseAlwaysSearch,
	expectStatus,
	expectWarning,
	signIn,
} from './ui.mjs'

const USER = process.env.E2E_USER ?? 'e2e-admin'
const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8')
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const FAILING = new Set(['serious', 'critical'])
const THEMES = ['light', 'dark']

const URL_FIELD = '#paperless-unified-search-url'
const LOCAL_NETWORK = '#paperless-unified-search-allow-local'
const TOKEN_FIELD = '#paperless-unified-search-token'
const ARCHIVE_OWNER_FIELD = '#paperless-unified-search-archive-owner'
const ARCHIVE_OWNER_MISSING = '#paperless-unified-search-archive-owner-missing'
const SAVE_BUTTON = '#paperless-unified-search-save'
const RESET_BUTTON = '#paperless-unified-search-reset'
const DIAGNOSTICS = '#paperless-unified-search-diagnostics'
const CLEAR_HISTORY_BUTTON = '#paperless-unified-search-clear-diagnostics'
const MOCK_URL = 'http://paperless-mock:8080'
// The mock of Paperless answers by this name too: another server for Nextcloud.
const OTHER_URL = 'http://other-host:8080'
const TOKEN = 'e2e-only-token'
const LAPTOP = { name: 'laptop', options: { viewport: { width: 1280, height: 900 } } }
const PHONE = { name: 'phone', options: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } }

function expectEqual(actual, expected, what) {
	if (actual !== expected) {
		throw new Error(`${what}: ${JSON.stringify(actual)} instead of ${JSON.stringify(expected)}.`)
	}
}

// The stored token goes only to the server it was saved for (#99): for another one,
// the token field is required and says why.
async function expectTokenField(page, url, required) {
	const field = await page.locator(TOKEN_FIELD).evaluate((input) => ({
		required: input.required,
		placeholder: input.placeholder,
	}))
	expectEqual(field.required, required, `Token field required for ${url}`)
	expectEqual(
		field.placeholder,
		required ? 'Required for a new server' : 'Configured — leave blank to keep it',
		`Placeholder of the token field for ${url}`,
	)
}

// Saves the form, waits until the server has answered, by default with a success, and
// checks the message, its colour and its icon.
async function saveSettings(page, expected = 'Connection successful. Settings saved.', kind = 'success') {
	await page.locator(STATUS).evaluate((status) => {
		status.textContent = ''
	})
	await page.locator(SAVE_BUTTON).click()
	await page.waitForFunction(
		({ save, status }) => !document.querySelector(save).disabled
			&& !['', 'Testing the Paperless connection…'].includes(document.querySelector(status).textContent),
		{ save: SAVE_BUTTON, status: STATUS },
	)
	await expectStatus(page, expected, kind)
}

// The fields of text, those of Try a search among them, are as wide as each other, and
// each checkbox, at least 20 pixels big, sits on the line of its label, in front of it.
async function expectFormLayout(page, size) {
	const widths = await page.locator(SETTINGS_SCOPE).evaluate((root) => [
		...root.querySelectorAll('input[type="url"], input[type="password"], input[type="text"], input[type="search"]'),
	].map((input) => Math.round(input.getBoundingClientRect().width)))
	expectEqual(widths.length, 5, `Fields of text on a ${size}`)
	expectEqual(
		widths.every((width) => width === widths[0]),
		true,
		`Fields of text on a ${size} as wide as each other (${widths.join(', ')} pixels)`,
	)
	await expectCheckboxes(page, SETTINGS_SCOPE, size)
}

// Each checkbox of the page within the scope is big enough to hit and in front of its
// label, on the line of it.
async function expectCheckboxes(page, scope, size) {
	const checkboxes = await page.locator(scope).evaluate((root) => [...root.querySelectorAll('input[type="checkbox"]')].map((input) => {
		const checkbox = input.getBoundingClientRect()
		const label = root.querySelector(`label[for="${input.id}"]`).getBoundingClientRect()
		const middle = checkbox.top + (checkbox.height / 2)
		return {
			id: input.id,
			size: Math.min(checkbox.width, checkbox.height),
			beside: checkbox.right <= label.left && middle >= label.top && middle <= label.bottom,
		}
	}))
	expectEqual(checkboxes.length > 0, true, `Checkboxes in ${scope} on a ${size}`)
	for (const checkbox of checkboxes) {
		expectEqual(checkbox.size >= 20, true, `Checkbox ${checkbox.id} on a ${size} at least 20 pixels big`)
		expectEqual(checkbox.beside, true, `Checkbox ${checkbox.id} on a ${size} in front of its label`)
	}
}

// On a phone, each problem of the history is a card, each cell holds its text without
// running into the next one, and the settings fit the width of the screen.
async function expectHistoryCards(page) {
	const history = await page.locator(SETTINGS_SCOPE).evaluate((root) => ({
		overflow: root.scrollWidth - root.clientWidth,
		rows: [...root.querySelectorAll('.paperless-unified-search-events tbody tr')].map((row) => getComputedStyle(row).display),
		crowded: [...root.querySelectorAll('.paperless-unified-search-events td, .paperless-unified-search-summary td')]
			.filter((cell) => cell.scrollWidth > cell.clientWidth)
			.map((cell) => cell.textContent.trim()),
	}))
	expectEqual(history.overflow <= 0, true, `Settings within the width of a phone, ${history.overflow} pixels wider than it`)
	expectEqual(history.rows.join(', '), 'block, block', 'Problems of the history as cards on a phone')
	expectEqual(history.crowded.join(', '), '', 'Cells of the history whose text runs out of them on a phone')
}

// The history of the problems that run.sh recorded, newest first, and the warning about
// the archive account that it stored.
async function expectRecordedProblems(page) {
	const events = await page.locator(`${DIAGNOSTICS} .paperless-unified-search-events tbody tr`).evaluateAll(
		(rows) => rows.map((row) => row.cells[1].textContent.trim()),
	)
	expectEqual(events.join(', '), 'Second try answered, Search failed', 'Recorded problems, newest first')
	await page.locator(CLEAR_HISTORY_BUTTON).waitFor()
	await expectWarning(page, ARCHIVE_OWNER_MISSING, 'The archive account e2e-gone does not exist.')
	await expectTokenField(page, 'the stored URL', false)
}

// The pages of the app, each with the element that holds its markup, the size of the
// screen, and what to do before the check, such as opening a section or showing a
// result. The results of the global search are left out: Nextcloud draws them, and the
// app only gives the title, the line below it and the icon. A page that changes the
// settings leaves them as it found them, as it runs once for each theme.
const PAGES = [
	{
		// run.sh made a search fail and another one need a second try, and stored an
		// archive account that doesn't exist.
		name: 'administration settings with recorded problems and a missing archive account',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		size: LAPTOP,
		async prepare(page) {
			await expectRecordedProblems(page)
			await expectFormLayout(page, LAPTOP.name)
		},
	},
	{
		// The same on a phone, where each problem is a card. Once both themes are
		// checked, Clear history forgets the problems, and the other pages show the empty
		// history.
		name: 'administration settings with recorded problems on a phone',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		size: PHONE,
		async prepare(page) {
			await expectRecordedProblems(page)
			await expectHistoryCards(page)
			await expectFormLayout(page, PHONE.name)
		},
		async after(page, theme) {
			if (theme === THEMES.at(-1)) {
				return await clearHistory(page)
			}
		},
	},
	{
		// A token that Paperless refuses: the message says so in the colour of an error,
		// and the settings stay as they are, with Disconnect.
		name: 'administration settings after a failed save',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		size: LAPTOP,
		async prepare(page) {
			await page.locator(ARCHIVE_OWNER_FIELD).fill('e2e-user')
			await page.locator(TOKEN_FIELD).fill('e2e-wrong-token')
			await saveSettings(page, 'Paperless rejected the API token (HTTP 401). Check the token, and that its account may view documents.', 'error')
			expectEqual(await page.locator(RESET_BUTTON).isDisabled(), false, 'Disconnect disabled after a failed save of stored settings')
			expectEqual(await page.locator(URL_FIELD).evaluate((input) => input.defaultValue), MOCK_URL, 'Stored URL after a failed save')
		},
	},
	{
		// Another path of the same server keeps the stored token; another scheme, host
		// or port needs it again. The check sees the last of them.
		name: 'administration settings for another server',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		size: LAPTOP,
		async prepare(page) {
			const urls = [
				[`${MOCK_URL}/paperless`, false],
				['HTTP://Paperless-Mock:8080', false],
				['https://paperless-mock:8080', true],
				['http://paperless-mock', true],
				[OTHER_URL, true],
			]
			for (const [url, required] of urls) {
				await page.locator(URL_FIELD).fill(url)
				await expectTokenField(page, url, required)
			}
		},
	},
	{
		// Saving another server with its token makes it the stored one, and an archive
		// account that exists ends the warning. Nextcloud reaches the mock under either
		// name only with the switch for the local network, which the settings show as
		// stored (#117). Then the mock's own name is another server, which gets the token
		// once more.
		name: 'administration settings after saving',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		size: LAPTOP,
		async prepare(page) {
			expectEqual(await page.locator(LOCAL_NETWORK).isChecked(), true, 'Switch for the local network as stored')
			await page.locator(LOCAL_NETWORK).uncheck()
			await page.locator(URL_FIELD).fill(OTHER_URL)
			await page.locator(TOKEN_FIELD).fill(TOKEN)
			await page.locator(ARCHIVE_OWNER_FIELD).fill('e2e-user')
			await saveSettings(page, 'Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.', 'error')
			await page.locator(LOCAL_NETWORK).check()
			await saveSettings(page)
			expectEqual(await page.locator(LOCAL_NETWORK).isChecked(), true, 'Switch for the local network after saving')
			expectEqual(await page.locator(URL_FIELD).evaluate((input) => input.defaultValue), OTHER_URL, 'Stored URL after saving')
			expectEqual(await page.locator(TOKEN_FIELD).inputValue(), '', 'Token field after saving')
			expectEqual(await page.locator(ARCHIVE_OWNER_MISSING).textContent(), '', 'Warning about the archive account after saving')
			await page.locator(URL_FIELD).fill(MOCK_URL)
			await expectTokenField(page, `${MOCK_URL} after saving ${OTHER_URL}`, true)
			await page.locator(TOKEN_FIELD).fill(TOKEN)
			await saveSettings(page)
			await expectTokenField(page, `${MOCK_URL} after saving it`, false)
		},
	},
	{
		// The section Paperless of the personal settings, once the settings above name an
		// archive account that exists, after the choice to include Paperless in every
		// search: its message in the colour of success. The choice is taken back after
		// the check (#220).
		name: 'personal settings after choosing to always search Paperless',
		path: PERSONAL,
		scope: PERSONAL_SCOPE,
		size: LAPTOP,
		async prepare(page) {
			expectEqual(await page.locator(ALWAYS_SEARCH).isChecked(), false, 'Always search my Paperless documents as stored')
			await chooseAlwaysSearch(page, true)
			await expectStatus(page, ALWAYS_SEARCH_SAVED.true, 'success', PERSONAL_STATUS)
		},
		async after(page) {
			await chooseAlwaysSearch(page, false)
		},
	},
	{
		// On a phone, the checkbox stays in front of its label and the section within
		// the width of the screen.
		name: 'personal settings on a phone',
		path: PERSONAL,
		scope: PERSONAL_SCOPE,
		size: PHONE,
		async prepare(page) {
			await expectCheckboxes(page, PERSONAL_SCOPE, PHONE.name)
			const overflow = await page.locator(PERSONAL_SCOPE).evaluate((root) => root.scrollWidth - root.clientWidth)
			expectEqual(overflow <= 0, true, `Personal settings within the width of a phone, ${overflow} pixels wider than it`)
		},
	},
]

// Clear history forgets the problems of the search: the page says so at once, and
// run.sh checks that Nextcloud forgot them too. Returns the line for the log.
async function clearHistory(page) {
	await page.locator(CLEAR_HISTORY_BUTTON).click()
	await page.locator(DIAGNOSTICS).getByText('No problem recorded.', { exact: true }).waitFor()
	await expectStatus(page, 'History cleared.', 'success')
	return 'Clear history: the page and its message say that no problem is recorded.'
}

async function check(browser, session, target, theme) {
	const context = await browser.newContext({
		storageState: session,
		colorScheme: theme,
		...target.size.options,
	})
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}${target.path}`)
		await page.locator(target.scope).waitFor()
		await target.prepare(page)
		await page.waitForLoadState('networkidle')
		await page.evaluate(AXE)
		const results = await page.evaluate(
			({ scope, tags }) => window.axe.run(scope, {
				runOnly: { type: 'tag', values: tags },
				resultTypes: ['violations'],
			}),
			{ scope: target.scope, tags: WCAG_TAGS },
		)
		// What the page does after the check, such as a line for the log.
		return { ...results, note: await target.after?.(page, theme) }
	} finally {
		await context.close()
	}
}

const browser = await chromium.launch()
let failures = 0
try {
	const session = await signIn(browser, USER)
	for (const target of PAGES) {
		for (const theme of THEMES) {
			const { violations, passes, note } = await check(browser, session, target, theme)
			const failing = violations.filter((violation) => FAILING.has(violation.impact))
			failures += failing.length
			console.log(`axe-core, ${target.name}, ${theme} theme: ${passes.length} rule(s) passed, ${violations.length} violated, ${failing.length} of them serious or critical`)
			for (const violation of violations) {
				console.log(`  ${violation.impact} ${violation.id}: ${violation.help} (${violation.helpUrl})`)
				for (const node of violation.nodes) {
					console.log(`    ${node.target.join(' ')}`)
					console.log(`      ${node.html}`)
					console.log(`      ${node.failureSummary.replaceAll('\n', '\n      ')}`)
				}
			}
			if (note) {
				console.log(note)
			}
		}
	}
} finally {
	await browser.close()
}

if (failures > 0) {
	console.error(`axe-core found ${failures} serious or critical violation(s) of WCAG 2.1 AA in the app.`)
	process.exit(1)
}
console.log('axe-core found no serious or critical violation of WCAG 2.1 AA in the app.')
