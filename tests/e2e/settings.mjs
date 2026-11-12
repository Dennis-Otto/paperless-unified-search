// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// What the settings page says and does in a real Nextcloud when an answer isn't the
// one it asked for, and when the administrator disconnects: one short sentence for the
// HTML page of maintenance mode, for the page of a proxy, for an expired session and for
// a network failure, in the colour of an error; a save while Paperless doesn't answer,
// which stores the options and keeps the connection; Disconnect declined and confirmed; a
// failed first save that keeps Disconnect off, as nothing is stored to disconnect; the
// search account, created in Paperless with the token of a superuser, which saves the
// settings with its token, and refused the second time, and once more while only its
// user is deleted, as Paperless names each workflow once; a save after which the URL field
// shows the URL as Nextcloud stored it; and Try a search,
// which names for each document whether it shows to a user and why not. The answers that
// Nextcloud itself would give only in maintenance mode or after the session ended come
// from Playwright, the rest from Nextcloud and the mock of Paperless. It runs last of the
// checks in the browser, as it disconnects Paperless, and saves the settings again as
// run.sh expects them afterwards. run.sh starts it in the image of Playwright, inside the
// network of the Compose project.

import { chromium } from 'playwright-core'
import { BASE_URL, SETTINGS, SETTINGS_SCOPE, STATUS, expectStatus, signIn } from './ui.mjs'

const USER = process.env.E2E_USER ?? 'e2e-admin'
const URL_FIELD = '#paperless-unified-search-url'
const LOCAL_NETWORK = '#paperless-unified-search-allow-local'
const TOKEN_FIELD = '#paperless-unified-search-token'
const ARCHIVE_OWNER_FIELD = '#paperless-unified-search-archive-owner'
const SAVE_BUTTON = '#paperless-unified-search-save'
const RESET_BUTTON = '#paperless-unified-search-reset'
const FORM = '#paperless-unified-search-form'
const DIAGNOSTICS = '#paperless-unified-search-diagnostics'
const TRY_STATUS = '#paperless-unified-search-try-status'
const MOCK_URL = 'http://paperless-mock:8080'
const TOKEN = 'e2e-only-token'
// The token of a superuser of the mock, which gives the search account TOKEN.
const SUPERUSER_TOKEN = 'e2e-superuser-token'
const ACCOUNT_BOX = '#paperless-unified-search-account'
const SUPERUSER_TOKEN_FIELD = '#paperless-unified-search-superuser-token'
const CREATE_ACCOUNT_BUTTON = '#paperless-unified-search-create-account'
const ACCOUNT_CREATED = 'Created the account nextcloud-search in Paperless and saved the settings with its token.'
const ACCOUNT_EXISTS = 'The user nextcloud-search exists in Paperless already, and stays as it is. Save the settings with its API token, or delete the user in Paperless and try again.'
const WORKFLOW = 'Nextcloud search: view new documents'
const WORKFLOW_EXISTS = `The workflow “${WORKFLOW}” exists in Paperless already, and stays as it is. Delete it in Paperless and try again, or turn off “Let it view each new document”.`
const REJECTED = 'Paperless rejected the API token (HTTP 401). Check the token, and that its account may view documents.'
const USERS_ALWAYS_SEARCH = '#paperless-unified-search-users-always-search'
// The mock at a port where nothing listens, which Nextcloud can't reach, and what a save
// to it says.
const UNANSWERED_URL = 'http://paperless-mock:9'
const OPTIONS_WITHOUT_CONNECTION = 'Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs. The other settings are saved; the URL, the API token and “Paperless runs in my local network” stay as they were until a test of the connection succeeds.'
// The route of the settings, which saves with POST and disconnects with DELETE.
const isSettingsRoute = (url) => url.pathname.endsWith('/apps/paperless_unified_search/settings')
// A step waits this long, in milliseconds: on a runner of the CI, three versions of
// Nextcloud share its CPUs.
const TIMEOUT = 60000

// The HTML page that Nextcloud shows in maintenance mode, as large as its own, which the
// page used to show as its message, and the headers that mark it (lib/base.php).
const MAINTENANCE_HEADERS = { 'X-Nextcloud-Maintenance-Mode': '1', 'Retry-After': '120' }
const MAINTENANCE_PAGE = `<!DOCTYPE html><html><head><title>Nextcloud</title></head><body>${
	'<p>This Nextcloud instance is currently in maintenance mode, which may take a while.</p>'.repeat(160)
}</body></html>`

// What answers a save instead of the app, and the sentence that the page shows for it.
const FAILURES = [
	{
		name: 'maintenance mode',
		answer: (route) => route.fulfill({ status: 503, headers: MAINTENANCE_HEADERS, contentType: 'text/html; charset=UTF-8', body: MAINTENANCE_PAGE }),
		message: 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.',
	},
	{
		// HAProxy's page when no server of the backend is healthy, which waiting doesn't fix.
		name: 'a proxy without a working Nextcloud',
		answer: (route) => route.fulfill({ status: 503, contentType: 'text/html', body: '<html><body><h1>503 Service Unavailable</h1>No server is available to handle this request.</body></html>' }),
		message: 'The request failed (HTTP 503).',
	},
	{
		name: 'a proxy whose Nextcloud doesn\'t answer',
		answer: (route) => route.fulfill({ status: 504, contentType: 'text/html', body: '<html><body><h1>504 Gateway Time-out</h1></body></html>' }),
		message: 'The request failed (HTTP 504).',
	},
	{
		name: 'an expired session',
		answer: (route) => route.fulfill({ status: 401, json: { message: 'Current user is not logged in' } }),
		message: 'Your session has expired. Reload the page and try again.',
	},
	{
		name: 'a request token that Nextcloud no longer accepts',
		answer: (route) => route.fulfill({ status: 412, json: { message: 'CSRF check failed' } }),
		message: 'Your session has expired. Reload the page and try again.',
	},
	{
		name: 'a network failure',
		answer: (route) => route.abort('internetdisconnected'),
		message: 'Nextcloud could not be reached. Check your connection and try again.',
	},
]

function expectEqual(actual, expected, what) {
	if (actual !== expected) {
		throw new Error(`${what}: ${JSON.stringify(actual)} instead of ${JSON.stringify(expected)}.`)
	}
}

// Clicks the button and waits until the page has the answer of Nextcloud: the form is
// no longer busy, and the message is no longer the one of the progress.
async function act(page, button) {
	await page.locator(STATUS).evaluate((status) => {
		status.textContent = ''
	})
	await page.locator(button).click()
	await page.waitForFunction(
		({ form, status }) => document.querySelector(form).getAttribute('aria-busy') === 'false'
			&& document.querySelector(status).textContent !== ''
			&& !document.querySelector(status).classList.contains('paperless-unified-search-status--pending'),
		{ form: FORM, status: STATUS },
	)
}

// What the form holds and offers.
async function form(page) {
	return await page.locator(SETTINGS_SCOPE).evaluate((root, ids) => {
		const [url, token, localNetwork, reset] = ids.map((id) => root.querySelector(id))
		return {
			url: url.value,
			storedUrl: url.defaultValue,
			tokenRequired: token.required,
			tokenPlaceholder: token.placeholder,
			localNetwork: localNetwork.checked,
			disconnect: reset.disabled ? 'disabled' : 'enabled',
		}
	}, [URL_FIELD, TOKEN_FIELD, LOCAL_NETWORK, RESET_BUTTON])
}

function expectForm(actual, expected, what) {
	expectEqual(JSON.stringify(actual), JSON.stringify(expected), what)
}

const CONFIGURED = {
	url: MOCK_URL,
	storedUrl: MOCK_URL,
	tokenRequired: false,
	tokenPlaceholder: 'Configured — leave blank to keep it',
	localNetwork: true,
	disconnect: 'enabled',
}

// The mock forgets the user of the search account again, as a Paperless whose
// administrator deletes it, and with `workflow` its workflow too, so that settings.mjs
// creates both anew when it runs once more on kept containers.
async function forgetTheSearchAccount({ workflow = true } = {}) {
	const headers = { Accept: 'application/json; version=9', Authorization: `Token ${SUPERUSER_TOKEN}` }
	const users = await (await fetch(`${MOCK_URL}/api/users/?username__iexact=nextcloud-search`, { headers })).json()
	const workflows = workflow ? (await (await fetch(`${MOCK_URL}/api/workflows/`, { headers })).json()).results.filter((each) => each.name === WORKFLOW) : []
	for (const [kind, object] of [...users.results.map((user) => ['users', user]), ...workflows.map((each) => ['workflows', each])]) {
		const removed = await fetch(`${MOCK_URL}/api/${kind}/${object.id}/`, { method: 'DELETE', headers })
		expectEqual(removed.status, 204, `The removal of ${kind} ${object.id} from the mock`)
	}
}

const browser = await chromium.launch()
try {
	const session = await signIn(browser, USER)
	const context = await browser.newContext({ storageState: session, viewport: { width: 1280, height: 900 } })
	const page = await context.newPage()
	page.setDefaultTimeout(TIMEOUT)
	await page.goto(`${BASE_URL}${SETTINGS}`)
	await page.locator(SETTINGS_SCOPE).waitFor()
	expectForm(await form(page), CONFIGURED, 'The settings as accessibility.mjs left them')

	// Try a search as the archive account e2e-user, which has the file of 123; no file
	// stands for 999. The page shows the titles as Paperless sends them, as text, and the
	// tags of the mock without the tag of the inbox.
	await page.locator('#paperless-unified-search-try-term').fill('mobiletest')
	await page.locator('#paperless-unified-search-try-user').fill('e2e-user')
	await page.locator('#paperless-unified-search-try').click()
	await page.locator(`${TRY_STATUS}.paperless-unified-search-status--success`).waitFor()
	const tried = await page.locator('#paperless-unified-search-try-result').evaluate((result, status) => ({
		status: document.querySelector(status).textContent,
		rows: [...result.querySelectorAll('tbody tr')].map((row) => [...row.cells].map((cell) => cell.textContent)),
	}), TRY_STATUS)
	expectEqual(JSON.stringify(tried), JSON.stringify({
		status: 'Shown to e2e-user: 1 of the first 2. Found by Paperless: 2.',
		rows: [['123', 'Mobile viewer test', 'paid', 'Shown'], ['999', 'Inaccessible document', '', 'No file with [P999]']],
	}), 'The result of Try a search')

	// Each answer that isn't the app's gives one short sentence, and the settings stay
	// as they are, with Disconnect.
	for (const failure of FAILURES) {
		await page.route(isSettingsRoute, failure.answer)
		try {
			await act(page, SAVE_BUTTON)
		} finally {
			await page.unroute(isSettingsRoute)
		}
		await expectStatus(page, failure.message, 'error')
		expectForm(await form(page), CONFIGURED, `The settings after ${failure.name}`)
		console.log(`Settings page, ${failure.name}: “${failure.message}”`)
	}

	// While Paperless doesn't answer, here at a port where nothing listens, a save still
	// stores the options that don't depend on it, such as the switch of the users, and
	// keeps the stored connection (#410): the message says so in the colour of a warning,
	// the fields of the connection keep what was typed, and a reload shows the connection
	// as stored with the switch as saved. Then the switch goes back as it was.
	const usersAlwaysSearch = page.locator(USERS_ALWAYS_SEARCH)
	const allowed = await usersAlwaysSearch.isChecked()
	await page.locator(URL_FIELD).fill(UNANSWERED_URL)
	await page.locator(TOKEN_FIELD).fill(TOKEN)
	await usersAlwaysSearch.setChecked(!allowed)
	await act(page, SAVE_BUTTON)
	await expectStatus(page, OPTIONS_WITHOUT_CONNECTION, 'warning')
	expectForm(await form(page), {
		...CONFIGURED,
		url: UNANSWERED_URL,
		tokenRequired: true,
		tokenPlaceholder: 'Required for a new server',
	}, 'The settings after a save that Paperless did not answer')
	await page.reload()
	await page.locator(SETTINGS_SCOPE).waitFor()
	expectForm(await form(page), CONFIGURED, 'The stored settings after a save that Paperless did not answer')
	expectEqual(await usersAlwaysSearch.isChecked(), !allowed, 'The stored switch of the users after a save that Paperless did not answer')
	await usersAlwaysSearch.setChecked(allowed)
	await act(page, SAVE_BUTTON)
	await expectStatus(page, 'Connection successful. Settings saved.', 'success')
	console.log('Settings page, Paperless does not answer: the options saved, the connection kept.')

	// Disconnect, declined, sends nothing and changes nothing.
	const disconnects = []
	page.on('request', (request) => {
		if (request.method() === 'DELETE' && isSettingsRoute(new URL(request.url()))) {
			disconnects.push(request.url())
		}
	})
	const questions = []
	page.once('dialog', async (dialog) => {
		questions.push(dialog.message())
		await dialog.dismiss()
	})
	await page.locator(RESET_BUTTON).click()
	expectEqual(questions.join(), 'Disconnect Paperless and delete the stored API token?', 'The question of Disconnect')
	expectEqual(disconnects.length, 0, 'Requests of a declined Disconnect')
	expectForm(await form(page), CONFIGURED, 'The settings after a declined Disconnect')
	console.log('Settings page, Disconnect declined: nothing sent, nothing changed.')

	// Disconnect, confirmed, forgets the settings and the history of the problems.
	page.once('dialog', (dialog) => dialog.accept())
	await act(page, RESET_BUTTON)
	await expectStatus(page, 'Paperless disconnected.', 'success')
	expectEqual(disconnects.length, 1, 'Requests of a confirmed Disconnect')
	const disconnected = {
		url: '',
		storedUrl: '',
		tokenRequired: true,
		tokenPlaceholder: 'Required',
		localNetwork: false,
		disconnect: 'disabled',
	}
	expectForm(await form(page), disconnected, 'The settings after Disconnect')
	expectEqual((await page.locator(DIAGNOSTICS).textContent()).trim(), 'No problem recorded.', 'The history after Disconnect')
	await page.reload()
	await page.locator(SETTINGS_SCOPE).waitFor()
	expectForm(await form(page), disconnected, 'The settings of a fresh install')
	console.log('Settings page, Disconnect confirmed: the page and Nextcloud forgot the settings.')

	// A failed first save keeps Disconnect off: nothing is stored to disconnect, and the
	// URL that the administrator typed stays for the correction.
	await page.locator(URL_FIELD).fill(`${MOCK_URL}/`)
	await page.locator(LOCAL_NETWORK).check()
	await page.locator(TOKEN_FIELD).fill('e2e-wrong-token')
	await page.locator(ARCHIVE_OWNER_FIELD).fill('e2e-user')
	await act(page, SAVE_BUTTON)
	await expectStatus(page, REJECTED, 'error')
	expectForm(await form(page), {
		...disconnected,
		url: `${MOCK_URL}/`,
		localNetwork: true,
	}, 'The settings after a failed first save')
	console.log('Settings page, a failed first save: Disconnect stays off.')

	// The search account, created in Paperless with the token of a superuser from the box
	// below the token: the settings are saved with the token of the account, which may
	// only view, so no warning shows, and the field forgets the token of the superuser.
	await page.locator(`${ACCOUNT_BOX} summary`).click()
	await page.locator(SUPERUSER_TOKEN_FIELD).fill(SUPERUSER_TOKEN)
	await act(page, CREATE_ACCOUNT_BUTTON)
	await expectStatus(page, ACCOUNT_CREATED, 'success')
	expectForm(await form(page), CONFIGURED, 'The settings after creating the search account')
	expectEqual(await page.locator(SUPERUSER_TOKEN_FIELD).inputValue(), '', 'The token of the superuser after creating the search account')
	expectEqual((await page.locator('#paperless-unified-search-token-warnings').textContent()).trim(), '', 'The warnings about the token of the search account')
	console.log('Settings page, the search account: created in Paperless, and the settings saved with its token.')

	// A second one finds the user in Paperless and leaves it, and the settings, as they are.
	await page.locator(SUPERUSER_TOKEN_FIELD).fill(SUPERUSER_TOKEN)
	await act(page, CREATE_ACCOUNT_BUTTON)
	await expectStatus(page, ACCOUNT_EXISTS, 'error')
	expectForm(await form(page), CONFIGURED, 'The settings after a second search account')
	console.log('Settings page, a second search account: refused, the user stays as it is.')

	// Without the user, the workflow of the first one stays, as Paperless names each
	// workflow once, and the set-up says so before it adds a user.
	await forgetTheSearchAccount({ workflow: false })
	await page.locator(SUPERUSER_TOKEN_FIELD).fill(SUPERUSER_TOKEN)
	await act(page, CREATE_ACCOUNT_BUTTON)
	await expectStatus(page, WORKFLOW_EXISTS, 'error')
	expectForm(await form(page), CONFIGURED, 'The settings after a search account whose workflow exists')
	console.log('Settings page, a search account whose workflow exists: refused, the workflow stays as it is.')
	await forgetTheSearchAccount()

	// A save shows the URL as Nextcloud stored it, without the slash at its end, and
	// offers Disconnect.
	await page.locator(URL_FIELD).fill(`${MOCK_URL}/`)
	await page.locator(TOKEN_FIELD).fill(TOKEN)
	await act(page, SAVE_BUTTON)
	await expectStatus(page, 'Connection successful. Settings saved.', 'success')
	expectForm(await form(page), CONFIGURED, 'The settings after saving')
	expectEqual(await page.locator(TOKEN_FIELD).inputValue(), '', 'The token field after saving')
	console.log('Settings page, saved: the URL as stored, and Disconnect.')
	await context.close()
} finally {
	await browser.close()
}
