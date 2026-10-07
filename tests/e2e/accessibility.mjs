// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// axe-core checks the pages of the app in Chromium against WCAG 2.1 at levels A and
// AA, in the light and the dark theme of Nextcloud. It looks only into the element
// that holds the app's own markup, so what Nextcloud draws around it doesn't count.
// A serious or critical violation fails the check; the others are listed. run.sh
// starts it in the image of Playwright, inside the network of the Compose project,
// after npm ci of package-lock.json.

import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { chromium } from 'playwright-core'

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
const USER = process.env.E2E_USER ?? 'e2e-admin'
const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'
const AXE = readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8')
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const FAILING = new Set(['serious', 'critical'])
const THEMES = ['light', 'dark']

// The pages of the app, each with the element that holds its markup and what to do
// before the check, such as opening a section or showing a result. The results of the
// global search are left out: Nextcloud draws them, and the app only gives the title,
// the line below it and the icon.
const PAGES = [
	{
		name: 'administration settings',
		path: '/index.php/settings/admin/additional',
		scope: '#paperless-unified-search-settings',
		async prepare() {},
	},
	{
		name: 'administration settings after saving',
		path: '/index.php/settings/admin/additional',
		scope: '#paperless-unified-search-settings',
		async prepare(page) {
			await page.locator('#paperless-unified-search-save').click()
			await page.getByText('Connection successful. Settings saved.').waitFor()
			await page.locator('#paperless-unified-search-save:enabled').waitFor()
		},
	},
]

// Signs in through the login form, as a person does, and keeps the session for every
// page and theme.
async function signIn(browser) {
	const context = await browser.newContext()
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(USER)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		await page.waitForURL((url) => !url.pathname.endsWith('/login'))
		return await context.storageState()
	} finally {
		await context.close()
	}
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
		return await page.evaluate(
			({ scope, tags }) => window.axe.run(scope, {
				runOnly: { type: 'tag', values: tags },
				resultTypes: ['violations'],
			}),
			{ scope: target.scope, tags: WCAG_TAGS },
		)
	} finally {
		await context.close()
	}
}

const browser = await chromium.launch()
let failures = 0
try {
	const session = await signIn(browser)
	for (const target of PAGES) {
		for (const theme of THEMES) {
			const { violations, passes } = await check(browser, session, target, theme)
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
