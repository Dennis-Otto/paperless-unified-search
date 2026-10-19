// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The steps through the pages of Nextcloud that the checks in the browser (flow.mjs,
// accessibility.mjs, settings.mjs) and the screenshots of the README (screenshots.mjs)
// share: signing in, the unified search with the results of Paperless, the Viewer that a
// result opens, and the messages of the settings page of the app. Each step waits for
// what a person would see before going on, and finds it by its role and name, as a
// screen reader does, in every supported version of Nextcloud: Nextcloud 33 and 34
// search in a dialog that a button of the header opens, and Nextcloud 35 in a field of
// the header, with the results below it.
//
// No workflow runs screenshots.mjs, and some steps here serve only it: search() with a
// group, searchConnectedServices(), paperlessResult() and the drawn PDF of
// waitForViewer(). After a change here, run `bash scripts/screenshots.sh` once and see
// that it still takes every picture; `git checkout screenshots/` drops the pictures
// again if they shouldn't change.

export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
export const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'

// Signs in through the login form, as a person does, and returns the session for the
// contexts of the pages that follow. Nextcloud keeps the time zone of the browser that
// signs in as the user's, in which the server writes times, such as those of the
// problems of the search; options of the context, such as timezoneId, choose it.
export async function signIn(browser, user, options = {}) {
	const context = await browser.newContext({ locale: 'en-US', ...options })
	try {
		const page = await context.newPage()
		await page.goto(`${BASE_URL}/index.php/login`)
		await page.locator('input[name="user"]').fill(user)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('input[name="password"]').press('Enter')
		// The answer to the login carries the cookies of the session; the page after it
		// needn't load.
		await page.waitForURL((url) => !url.pathname.endsWith('/login'), { waitUntil: 'commit' })
		return await context.storageState()
	} finally {
		await context.close()
	}
}

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// The names of the search in the header: the button of Nextcloud 33, the button of
// Nextcloud 34, and the field of Nextcloud 35, a button on a phone.
const SEARCH = /^(Unified search|Search apps, files, tags, messages|Apps, files, messages, and more)/

// What opens the search in the header of Nextcloud.
export const searchButton = (page) => {
	const header = page.locator('#header')
	return header.getByRole('button', { name: SEARCH }).or(header.getByRole('combobox', { name: SEARCH }))
}

// The field of the open search, into which the term goes.
export const searchField = (page) => page.getByRole('textbox', { name: SEARCH })
	.or(page.getByRole('combobox', { name: SEARCH }))
	.or(page.getByRole('searchbox', { name: SEARCH }))

// What adds the connected services, Paperless among them, to the search: the switch of
// Nextcloud 33 and 34, and the button of Nextcloud 35.
export const connectedServices = (page) => page.getByText('Search connected services', { exact: true })
	.or(page.getByRole('button', { name: 'More from connected services' }))

// The results of one provider of the search, such as Files or Paperless documents.
export const resultGroup = (page, name) => page.getByRole('list', { name, exact: true })
	.or(page.getByRole('listbox', { name, exact: true }))

// A result of Paperless starts with the title of its document; one of the files starts
// with the name of the file, to which Paperless Sync adds the date in front.
export const paperlessResult = (page, title) => resultGroup(page, 'Paperless documents').getByRole('link', {
	name: new RegExp(`^${escapeRegExp(title)} `),
})

// Types into the open search with typing() and, when given, waits for the results of
// the group, such as Files.
export async function search(page, typing, group) {
	const field = searchField(page)
	await field.waitFor()
	await typing(field)
	if (group) {
		await resultGroup(page, group).waitFor()
	}
}

// Adds the connected services to the search, among them Paperless unless the
// administrator trusts it, and waits for the result of the document with the title.
export async function searchConnectedServices(page, title) {
	await connectedServices(page).click()
	await paperlessResult(page, title).waitFor()
}

// Waits for the Viewer of Nextcloud, which a click on a result opens, with the file of
// the name in its header and, unless drawn is false, the first page of the PDF drawn,
// which a picture needs and a check doesn't: drawing it takes Chromium seconds.
export async function waitForViewer(page, name, { drawn = true } = {}) {
	await page.waitForURL((url) => url.pathname.includes('/apps/files/'))
	if (drawn) {
		await page.frameLocator('iframe').locator('.page[data-loaded="true"]').first().waitFor({ timeout: 30000 })
	}
	await page.locator('.modal-header').getByText(name).waitFor()
}

// The administration settings of the app, and the element that holds its markup.
export const SETTINGS = '/index.php/settings/admin/paperless_unified_search'
export const SETTINGS_SCOPE = '#paperless-unified-search-settings'
export const STATUS = '#paperless-unified-search-status'

// What keeps the kind of a message of the settings page from resting on its colour
// alone (WCAG 1.4.1): the colour that the theme of Nextcloud has for that kind, which
// Nextcloud's grey for the paragraphs of a section must not win over, and an icon in
// front. The problems found, empty when there are none.
async function lookProblems(locator, kind) {
	const look = await locator.evaluate((element, kind) => {
		const probe = document.createElement('span')
		probe.style.color = `var(--color-${kind}-text)`
		element.parentElement.append(probe)
		const expectedColour = getComputedStyle(probe).color
		probe.remove()
		return {
			colour: getComputedStyle(element).color,
			expectedColour,
			icon: getComputedStyle(element, '::before').maskImage,
		}
	}, kind)
	return [
		look.colour === look.expectedColour ? '' : `the colour ${look.colour} instead of ${look.expectedColour}, the one of ${kind} in the theme`,
		look.icon.startsWith('url(') ? '' : `no icon but ${JSON.stringify(look.icon)}`,
	].filter(Boolean)
}

// Checks the message of the settings page after an action: its text and its kind,
// success or error, in the colour of that kind and with its icon.
export async function expectStatus(page, text, kind) {
	const locator = page.locator(STATUS)
	const status = await locator.evaluate((element) => ({
		text: element.textContent,
		kinds: [...element.classList].filter((name) => name.startsWith('paperless-unified-search-status--')),
	}))
	const problems = [
		status.text === text ? '' : `the text ${JSON.stringify(status.text)} instead of ${JSON.stringify(text)}`,
		status.kinds.join(' ') === `paperless-unified-search-status--${kind}` ? '' : `the classes ${JSON.stringify(status.kinds)} instead of the one of ${kind}`,
		...await lookProblems(locator, kind),
	].filter(Boolean)
	if (problems.length > 0) {
		throw new Error(`The message of the settings page has ${problems.join(', ')}.`)
	}
}

// Checks a warning below a field of the settings page, such as the one about an archive
// account that doesn't exist: its text, in the colour of an error and with its icon.
export async function expectWarning(page, selector, text) {
	const locator = page.locator(selector)
	const actual = await locator.textContent()
	const problems = [
		actual === text ? '' : `the text ${JSON.stringify(actual)} instead of ${JSON.stringify(text)}`,
		...await lookProblems(locator, 'error'),
	].filter(Boolean)
	if (problems.length > 0) {
		throw new Error(`The warning ${selector} of the settings page has ${problems.join(', ')}.`)
	}
}
