// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// axe-core checks the pages of the app in Chromium against WCAG 2.1 at levels A and
// AA, in the light and the dark theme of Nextcloud. It looks only into the element
// that holds the app's own markup, so what Nextcloud draws around it doesn't count.
// A serious or critical violation fails the check; the others are listed. On the way
// to each state of a page, it checks what the page does there, such as the token
// field that asks for the token again for another server, and an unexpected state
// fails the check as well. After the first page, it clears the history of the problems
// of the search. run.sh starts it in the image of Playwright, inside the network of the
// Compose project, after npm ci of package-lock.json.

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { chromium } from 'playwright-core'
import { BASE_URL, signIn } from './ui.mjs'

const USER = process.env.E2E_USER ?? 'e2e-admin'
const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8')
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const FAILING = new Set(['serious', 'critical'])
const THEMES = ['light', 'dark']

const SETTINGS = '/index.php/settings/admin/paperless_unified_search'
const SETTINGS_SCOPE = '#paperless-unified-search-settings'
const URL_FIELD = '#paperless-unified-search-url'
const LOCAL_NETWORK = '#paperless-unified-search-allow-local'
const TOKEN_FIELD = '#paperless-unified-search-token'
const ARCHIVE_OWNER_FIELD = '#paperless-unified-search-archive-owner'
const ARCHIVE_OWNER_MISSING = '#paperless-unified-search-archive-owner-missing'
const SAVE_BUTTON = '#paperless-unified-search-save'
const STATUS = '#paperless-unified-search-status'
const DIAGNOSTICS = '#paperless-unified-search-diagnostics'
const CLEAR_HISTORY_BUTTON = '#paperless-unified-search-clear-diagnostics'
const MOCK_URL = 'http://paperless-mock:8080'
// The mock of Paperless answers by this name too: another server for Nextcloud.
const OTHER_URL = 'http://other-host:8080'
const TOKEN = 'e2e-only-token'

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

// Saves the form and waits until the server has answered, by default with a success.
async function saveSettings(page, expected = 'Connection successful. Settings saved.') {
	await page.locator(STATUS).evaluate((status) => {
		status.textContent = ''
	})
	await page.locator(SAVE_BUTTON).click()
	await page.waitForFunction(
		({ save, status }) => !document.querySelector(save).disabled
			&& !['', 'Testing the Paperless connection…'].includes(document.querySelector(status).textContent),
		{ save: SAVE_BUTTON, status: STATUS },
	)
	expectEqual(await page.locator(STATUS).textContent(), expected, 'Status after saving')
}

// The pages of the app, each with the element that holds its markup and what to do
// before the check, such as opening a section or showing a result. The results of the
// global search are left out: Nextcloud draws them, and the app only gives the title,
// the line below it and the icon. A page that changes the settings leaves them as it
// found them, as it runs once for each theme.
const PAGES = [
	{
		// run.sh made a search fail and another one need a second try, and stored an
		// archive account that doesn't exist. Once both themes are checked, Clear history
		// forgets the problems, and the other pages show the empty history.
		name: 'administration settings with recorded problems and a missing archive account',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
		async prepare(page) {
			const events = await page.locator(`${DIAGNOSTICS} .paperless-unified-search-events tbody tr`).evaluateAll(
				(rows) => rows.map((row) => row.cells[1].textContent.trim()),
			)
			expectEqual(events.join(', '), 'Second try answered, Search failed', 'Recorded problems, newest first')
			await page.locator(CLEAR_HISTORY_BUTTON).waitFor()
			expectEqual(
				await page.locator(ARCHIVE_OWNER_MISSING).textContent(),
				'The archive account e2e-gone does not exist.',
				'Warning about the archive account',
			)
			await expectTokenField(page, 'the stored URL', false)
		},
		async after(page, theme) {
			if (theme === THEMES.at(-1)) {
				return await clearHistory(page)
			}
		},
	},
	{
		// Another path of the same server keeps the stored token; another scheme, host
		// or port needs it again. The check sees the last of them.
		name: 'administration settings for another server',
		path: SETTINGS,
		scope: SETTINGS_SCOPE,
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
		async prepare(page) {
			expectEqual(await page.locator(LOCAL_NETWORK).isChecked(), true, 'Switch for the local network as stored')
			await page.locator(LOCAL_NETWORK).uncheck()
			await page.locator(URL_FIELD).fill(OTHER_URL)
			await page.locator(TOKEN_FIELD).fill(TOKEN)
			await page.locator(ARCHIVE_OWNER_FIELD).fill('e2e-user')
			await saveSettings(page, 'Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.')
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
]

// Clear history forgets the problems of the search: the page says so at once, and
// run.sh checks that Nextcloud forgot them too. Returns the line for the log.
async function clearHistory(page) {
	await page.locator(CLEAR_HISTORY_BUTTON).click()
	await page.locator(DIAGNOSTICS).getByText('No problem recorded.', { exact: true }).waitFor()
	expectEqual(await page.locator(STATUS).textContent(), 'History cleared.', 'Status after clearing the history')
	return 'Clear history: the page and its message say that no problem is recorded.'
}

async function check(browser, session, target, theme) {
	const context = await browser.newContext({
		storageState: session,
		colorScheme: theme,
		viewport: { width: 1280, height: 900 },
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
