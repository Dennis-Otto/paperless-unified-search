// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The steps through the pages of Nextcloud that the checks in the browser (flow.mjs,
// accessibility.mjs) and the screenshots of the README (screenshots.mjs) share: signing
// in, the unified search with the results of Paperless, and the Viewer that a result
// opens. Each step waits for what a person would see before going on, and finds it by
// its role and name, as a screen reader does, in every supported version of Nextcloud:
// Nextcloud 33 and 34 search in a dialog that a button of the header opens, and
// Nextcloud 35 in a field of the header, with the results below it.

export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://nextcloud'
export const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-only-password'

// Signs in through the login form, as a person does, and returns the session for the
// contexts of the pages that follow.
export async function signIn(browser, user) {
	const context = await browser.newContext({ locale: 'en-US' })
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
