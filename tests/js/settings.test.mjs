/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// js/settings.js, run in a context of its own with a small stand-in for the page of the
// settings and a fetch that answers as the test says: what the page shows and sends for
// each answer of Nextcloud, from a saved setting to the HTML page of maintenance mode.
// scripts/check-project.sh runs it with the coverage of every line and branch.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { Script, createContext } from 'node:vm'

const url = new URL('../../js/settings.js', import.meta.url)
// The file URL as its name, so that the coverage of the test runner counts the script.
const script = new Script(readFileSync(url, 'utf8'), { filename: url.href })

const SAVE_URL = '/apps/paperless_unified_search/settings'
const CLEAR_URL = '/apps/paperless_unified_search/settings/diagnostics'
const TRY_URL = '/apps/paperless_unified_search/settings/try'
const STORED = 'https://paperless.example.com'
const SUCCESS = 'paperless-unified-search-status--success'
const ERROR = 'paperless-unified-search-status--error'
const PENDING = 'paperless-unified-search-status--pending'

class ClassList {
	#names = new Set()

	add(name) {
		this.#names.add(name)
	}

	toggle(name, force) {
		if (force) {
			this.#names.add(name)
		} else {
			this.#names.delete(name)
		}
	}

	contains(name) {
		return this.#names.has(name)
	}

	toString() {
		return [...this.#names].sort().join(' ')
	}
}

// An element with what the script uses of it.
class Element {
	constructor(id, properties = {}) {
		this.id = id
		this.classList = new ClassList()
		this.dataset = {}
		this.attributes = new Map()
		this.children = []
		this.listeners = new Map()
		this.textContent = ''
		this.value = ''
		this.defaultValue = ''
		this.checked = false
		this.disabled = false
		this.required = false
		this.placeholder = ''
		Object.assign(this, properties)
	}

	addEventListener(type, listener) {
		this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
	}

	setAttribute(name, value) {
		this.attributes.set(name, String(value))
	}

	getAttribute(name) {
		return this.attributes.get(name) ?? null
	}

	replaceChildren(...children) {
		this.children = children
	}

	append(...children) {
		this.children.push(...children)
	}

	// Runs the listeners of the event and waits for them, as the page would until the
	// server has answered.
	async dispatch(type) {
		const event = { type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true } }
		await Promise.all((this.listeners.get(type) ?? []).map((listener) => listener(event)))
		return event
	}
}

// The page as templates/settings.php renders it for $stored, the settings that Nextcloud
// keeps; `history: false` leaves out the button of the history, as without problems.
function page({ url = '', tokenConfigured = false, history = true } = {}) {
	const ids = [
		'form', 'url', 'allow-local', 'token', 'token-unreadable', 'always-search', 'users-always-search', 'archive-owner',
		'archive-owner-missing', 'save', 'reset', 'status', 'diagnostics',
		'try-form', 'try-term', 'try-user', 'try', 'try-status', 'try-result',
	]
	const elements = new Map(ids.map((id) => [`paperless-unified-search-${id}`, new Element(id)]))
	const root = new Element('settings')
	root.dataset = {
		saveUrl: SAVE_URL,
		resetUrl: SAVE_URL,
		clearDiagnosticsUrl: CLEAR_URL,
		tryUrl: TRY_URL,
		tokenConfigured: tokenConfigured ? 'true' : 'false',
	}
	elements.set('paperless-unified-search-settings', root)
	const field = elements.get('paperless-unified-search-url')
	field.value = url
	field.defaultValue = url
	// As the template renders it: off while nothing is stored.
	elements.get('paperless-unified-search-reset').disabled = !tokenConfigured && url === ''
	if (history) {
		elements.set('paperless-unified-search-clear-diagnostics', new Element('clear-diagnostics'))
	}
	elements.get('paperless-unified-search-diagnostics').children = ['table']
	return elements
}

function json(body, status = 200, headers = {}) {
	return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers } })
}

function html(status, headers = {}, bytes = 14488) {
	return new Response(`<!DOCTYPE html><html><body>${'x'.repeat(bytes)}</body></html>`, {
		status,
		headers: { 'Content-Type': 'text/html; charset=UTF-8', ...headers },
	})
}

// The headers with which Nextcloud answers in maintenance mode (lib/base.php and
// ocs/v1.php), and with which it shows the page of a pending upgrade.
const MAINTENANCE = { 'X-Nextcloud-Maintenance-Mode': '1', 'Retry-After': '120' }
const UPGRADE = { 'Retry-After': '120' }

const CONFIG = {
	url: STORED,
	tokenConfigured: true,
	alwaysSearch: false,
	archiveOwner: '',
	syncAccount: 'sync',
	tokenUnreadable: false,
	archiveOwnerMissing: false,
	allowLocal: false,
	personalAlwaysSearch: true,
}
const EMPTY = { ...CONFIG, url: '', tokenConfigured: false, syncAccount: '' }

// Loads the script into a page of `elements`. `answers` are what fetch answers in turn,
// a Response, or an error that it throws; `t` stands for the translation of Nextcloud,
// `confirm` for the answer of the browser's dialog, `password` for OC.PasswordConfirmation.
function load(elements, { answers = [], t, confirm = true, password } = {}) {
	const requests = []
	const statuses = []
	const tries = []
	const listeners = []
	const window = {
		OC: { requestToken: 'request-token', PasswordConfirmation: password },
		t,
		confirm(message) {
			window.confirmed = message
			return confirm
		},
		async fetch(target, options) {
			const status = elements.get('paperless-unified-search-status')
			// What the page shows while it waits for the answer.
			statuses.push({ text: status.textContent, kind: status.classList.toString() })
			const tryStatus = elements.get('paperless-unified-search-try-status')
			tries.push({
				text: tryStatus.textContent,
				kind: tryStatus.classList.toString(),
				busy: elements.get('paperless-unified-search-try-form').getAttribute('aria-busy'),
				button: elements.get('paperless-unified-search-try').disabled ? 'disabled' : 'enabled',
				result: elements.get('paperless-unified-search-try-result').children.length,
			})
			// A copy in the realm of the test, which compares it with its own objects.
			requests.push({ url: target, ...structuredClone(options), body: options.body === undefined ? undefined : JSON.parse(options.body) })
			const answer = answers.shift()
			if (answer instanceof Error) {
				throw answer
			}
			return answer
		},
	}
	const document = {
		getElementById: (id) => elements.get(id) ?? null,
		addEventListener: (type, listener) => listeners.push(listener),
		createElement: (tag) => new Element(tag, { tagName: tag.toUpperCase() }),
	}
	script.runInContext(createContext({ window, document, URL }))
	for (const listener of listeners) {
		listener()
	}
	const get = (id) => elements.get(`paperless-unified-search-${id}`)
	return { window, requests, statuses, tries, get }
}

function state(get) {
	return {
		status: get('status').textContent,
		kind: get('status').classList.toString(),
		disconnect: get('reset').disabled ? 'disabled' : 'enabled',
		save: get('save').disabled ? 'disabled' : 'enabled',
		busy: get('form').getAttribute('aria-busy'),
	}
}

test('a page without the settings is left alone', function() {
	const elements = new Map()
	const { requests } = load(elements)
	assert.deepEqual(requests, [])
})

test('the token field asks for the token unless the stored one goes to the same server', function() {
	const { get } = load(page({ url: STORED, tokenConfigured: true }))
	assert.equal(get('token').required, false)
	assert.equal(get('token').placeholder, 'Configured — leave blank to keep it')
	assert.equal(get('reset').disabled, false)

	for (const [typed, required] of [
		[`${STORED}/paperless/`, false],
		['HTTPS://Paperless.Example.com', false],
		['http://paperless.example.com', true],
		['https://paperless.example.com:8443', true],
		['not a URL', true],
	]) {
		get('url').value = typed
		get('url').listeners.get('input')[0]()
		assert.equal(get('token').required, required, typed)
		assert.equal(get('token').placeholder, required ? 'Required for a new server' : 'Configured — leave blank to keep it', typed)
	}
})

test('a fresh install asks for the token and keeps Disconnect off', function() {
	const { get } = load(page())
	assert.equal(get('token').required, true)
	assert.equal(get('token').placeholder, 'Required')
	assert.equal(get('reset').disabled, true)
})

test('saving sends the form, shows the progress, and then the settings as stored', async function() {
	const { get, requests, statuses } = load(page(), {
		answers: [json({ ...CONFIG, url: STORED, allowLocal: true, alwaysSearch: true, archiveOwner: 'archive', personalAlwaysSearch: false })],
	})
	get('url').value = ` ${STORED}/ `
	get('allow-local').checked = true
	get('token').value = 'secret-token'
	get('always-search').checked = true
	// The personal choice of the users, which the page shows as stored, on by default.
	get('users-always-search').checked = false
	get('archive-owner').value = 'Archive'

	const event = await get('form').dispatch('submit')

	assert.equal(event.defaultPrevented, true)
	assert.deepEqual(requests, [{
		url: SAVE_URL,
		method: 'POST',
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: 'request-token' },
		body: { url: ` ${STORED}/ `, allowLocal: true, token: 'secret-token', alwaysSearch: true, personalAlwaysSearch: false, archiveOwner: 'Archive' },
	}])
	assert.deepEqual(statuses, [{ text: 'Testing the Paperless connection…', kind: PENDING }])
	assert.deepEqual(state(get), { status: 'Connection successful. Settings saved.', kind: SUCCESS, disconnect: 'enabled', save: 'enabled', busy: 'false' })
	// The URL as the server stored it, which the token now belongs to.
	assert.equal(get('url').value, STORED)
	assert.equal(get('url').defaultValue, STORED)
	assert.equal(get('token').value, '')
	assert.equal(get('token').required, false)
	assert.equal(get('allow-local').checked, true)
	assert.equal(get('always-search').checked, true)
	assert.equal(get('users-always-search').checked, false)
	assert.equal(get('archive-owner').value, 'archive')
	assert.equal(get('token-unreadable').textContent, '')
	assert.equal(get('archive-owner-missing').textContent, '')
})

test('the form is busy while the server works', async function() {
	let release
	const answer = new Promise((resolve) => {
		release = resolve
	})
	const elements = page({ url: STORED, tokenConfigured: true })
	const { get } = load(elements, { answers: [answer] })

	const saving = get('form').dispatch('submit')
	await new Promise((resolve) => setImmediate(resolve))
	assert.deepEqual(state(get), { status: 'Testing the Paperless connection…', kind: PENDING, disconnect: 'disabled', save: 'disabled', busy: 'true' })

	release(json(CONFIG))
	await saving
	assert.deepEqual(state(get), { status: 'Connection successful. Settings saved.', kind: SUCCESS, disconnect: 'enabled', save: 'enabled', busy: 'false' })
})

test('a refused first save shows the reason and keeps Disconnect off', async function() {
	const message = 'Nextcloud could not find the host of this URL. Check its spelling.'
	const { get } = load(page(), { answers: [json({ message, code: 'unknown_host' }, 400)] })
	get('url').value = 'https://paperless.invalid'

	await get('form').dispatch('submit')

	assert.deepEqual(state(get), { status: message, kind: ERROR, disconnect: 'disabled', save: 'enabled', busy: 'false' })
	// What the administrator typed stays for the correction.
	assert.equal(get('url').value, 'https://paperless.invalid')
})

test('a refused save of a configured app keeps Disconnect on', async function() {
	const { get } = load(page({ url: STORED, tokenConfigured: true }), {
		answers: [json({ message: 'Paperless rejected the API token (HTTP 401).' }, 400)],
	})

	await get('form').dispatch('submit')

	assert.deepEqual(state(get), { status: 'Paperless rejected the API token (HTTP 401).', kind: ERROR, disconnect: 'enabled', save: 'enabled', busy: 'false' })
})

// What Nextcloud or a proxy answers instead of the app, and the sentence that the page
// shows for it in place of the answer.
for (const [name, answer, expected] of [
	['maintenance mode, as HTML', html(503, MAINTENANCE), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
	['maintenance mode, as JSON', json({ message: 'Service unavailable' }, 503, MAINTENANCE), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
	['a pending upgrade', html(503, UPGRADE), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
	['a proxy whose Nextcloud is down, or a broken configuration', html(503), 'The request failed (HTTP 503).'],
	['an app that is unavailable', json({ message: 'Paperless is unavailable.' }, 503), 'Paperless is unavailable.'],
	['an expired session', json({ message: 'Current user is not logged in' }, 401), 'Your session has expired. Reload the page and try again.'],
	['a failed check of the request token', json({ message: 'CSRF check failed' }, 412), 'Your session has expired. Reload the page and try again.'],
	['a proxy whose Nextcloud times out', html(504), 'The request failed (HTTP 504).'],
	['an error without a message', json({ message: ' ' }, 500), 'The request failed (HTTP 500).'],
	['an error with a message that is no text', json({ message: { text: 'no' } }, 500), 'The request failed (HTTP 500).'],
	['an error whose JSON is null', json(null, 500), 'The request failed (HTTP 500).'],
	['JSON that does not parse', new Response('{', { status: 500, headers: { 'Content-Type': 'application/json' } }), 'The request failed (HTTP 500).'],
	['an answer without a type', new Response(null, { status: 502 }), 'The request failed (HTTP 502).'],
	['the HTML page of a login in front of Nextcloud', html(200), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	['a network failure', new TypeError('Failed to fetch'), 'Nextcloud could not be reached. Check your connection and try again.'],
]) {
	test(`saving shows one short sentence for ${name}`, async function() {
		const { get } = load(page(), { answers: [answer] })
		get('url').value = STORED

		await get('form').dispatch('submit')

		assert.deepEqual(state(get), { status: expected, kind: ERROR, disconnect: 'disabled', save: 'enabled', busy: 'false' })
	})
}

test('the messages go through the translation of Nextcloud, with their placeholders', async function() {
	const calls = []
	const t = (app, text, vars, count, options) => {
		calls.push(structuredClone({ app, text, vars, count, options }))
		return `[de] ${text.replace('{status}', vars?.status)}`
	}
	const { get } = load(page(), { answers: [html(500)], t })

	await get('form').dispatch('submit')

	assert.equal(get('status').textContent, '[de] The request failed (HTTP 500).')
	assert.deepEqual(calls.at(-1), {
		app: 'paperless_unified_search',
		text: 'The request failed (HTTP {status}).',
		vars: { status: 500 },
		count: undefined,
		options: { escape: false },
	})
})

test('the settings show the warnings that the stored settings call for', async function() {
	const { get } = load(page(), {
		answers: [
			json({ ...CONFIG, tokenUnreadable: true, archiveOwnerMissing: true, archiveOwner: 'gone' }),
			json({ ...CONFIG, archiveOwnerMissing: true, archiveOwner: '' }),
		],
	})

	await get('form').dispatch('submit')
	assert.equal(get('token-unreadable').textContent, 'The stored API token can\'t be decrypted, for example because the secret in config.php changed. Enter it again.')
	assert.equal(get('archive-owner-missing').textContent, 'The archive account gone does not exist.')

	await get('form').dispatch('submit')
	assert.equal(get('token-unreadable').textContent, '')
	assert.equal(get('archive-owner-missing').textContent, 'The archive account sync does not exist.')
})

test('a placeholder without its value stays as it is', async function() {
	const { get } = load(page(), { answers: [json({ ...CONFIG, archiveOwnerMissing: true, archiveOwner: '', syncAccount: undefined })] })

	await get('form').dispatch('submit')

	assert.equal(get('archive-owner-missing').textContent, 'The archive account {account} does not exist.')
})

test('saving asks for the password first when Nextcloud wants it confirmed', async function() {
	const asked = []
	const password = {
		requiresPasswordConfirmation: () => true,
		requirePasswordConfirmation(confirmed, options, cancelled) {
			asked.push(structuredClone(options))
			confirmed()
		},
	}
	const { get, requests } = load(page(), { answers: [json(CONFIG)], password })

	await get('form').dispatch('submit')

	assert.deepEqual(asked, [{}])
	assert.equal(requests.length, 1)
	assert.equal(get('status').textContent, 'Connection successful. Settings saved.')
})

test('saving stops when the password is not confirmed', async function() {
	const password = {
		requiresPasswordConfirmation: () => true,
		requirePasswordConfirmation(confirmed, options, cancelled) {
			cancelled()
		},
	}
	const { get, requests } = load(page(), { password })

	await get('form').dispatch('submit')

	assert.deepEqual(requests, [])
	assert.deepEqual(state(get), { status: 'Confirm your password to change the settings.', kind: ERROR, disconnect: 'disabled', save: 'enabled', busy: 'false' })
})

test('saving goes on without asking when the password was confirmed lately', async function() {
	const password = {
		requiresPasswordConfirmation: () => false,
		requirePasswordConfirmation() {
			throw new Error('not asked')
		},
	}
	const { get } = load(page(), { answers: [json(CONFIG)], password })

	await get('form').dispatch('submit')

	assert.equal(get('status').textContent, 'Connection successful. Settings saved.')
})

test('an error without a message gets a general one', async function() {
	const password = {
		requiresPasswordConfirmation: () => true,
		requirePasswordConfirmation() {
			// A dialog of Nextcloud that fails in its own way.
			throw 'broken'
		},
	}
	const { get } = load(page(), { password })

	await get('form').dispatch('submit')

	assert.deepEqual(state(get), { status: 'The request failed.', kind: ERROR, disconnect: 'disabled', save: 'enabled', busy: 'false' })
})

test('Disconnect does nothing when the administrator declines', async function() {
	const { get, requests, window } = load(page({ url: STORED, tokenConfigured: true }), { confirm: false })

	await get('reset').dispatch('click')

	assert.equal(window.confirmed, 'Disconnect Paperless and delete the stored API token?')
	assert.deepEqual(requests, [])
	assert.equal(get('url').value, STORED)
	assert.deepEqual(state(get), { status: '', kind: '', disconnect: 'enabled', save: 'enabled', busy: null })
})

test('Disconnect forgets the settings and the history of the problems', async function() {
	const { get, requests, statuses } = load(page({ url: STORED, tokenConfigured: true }), { answers: [json(EMPTY)] })
	get('users-always-search').checked = false

	await get('reset').dispatch('click')

	assert.deepEqual(requests.map(({ url, method, body }) => ({ url, method, body })), [{ url: SAVE_URL, method: 'DELETE', body: undefined }])
	assert.deepEqual(statuses, [{ text: 'Disconnecting Paperless…', kind: PENDING }])
	assert.deepEqual(state(get), { status: 'Paperless disconnected.', kind: SUCCESS, disconnect: 'disabled', save: 'enabled', busy: 'false' })
	assert.equal(get('url').value, '')
	assert.equal(get('token').placeholder, 'Required')
	// Users may choose again, as on a fresh install.
	assert.equal(get('users-always-search').checked, true)
	assert.equal(get('diagnostics').children.length, 1)
	assert.equal(get('diagnostics').children[0].tagName, 'P')
	assert.equal(get('diagnostics').children[0].textContent, 'No problem recorded.')
})

test('a failed Disconnect keeps the settings and Disconnect', async function() {
	const { get } = load(page({ url: STORED, tokenConfigured: true }), { answers: [html(503, MAINTENANCE)] })

	await get('reset').dispatch('click')

	assert.deepEqual(state(get), {
		status: 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.',
		kind: ERROR,
		disconnect: 'enabled',
		save: 'enabled',
		busy: 'false',
	})
	assert.equal(get('url').value, STORED)
	assert.deepEqual(get('diagnostics').children, ['table'])
})

test('Clear history forgets the problems', async function() {
	const { get, requests } = load(page({ url: STORED, tokenConfigured: true }), { answers: [json([])] })

	await get('clear-diagnostics').dispatch('click')

	assert.deepEqual(requests.map(({ url, method }) => ({ url, method })), [{ url: CLEAR_URL, method: 'DELETE' }])
	assert.equal(get('clear-diagnostics').disabled, true)
	assert.equal(get('diagnostics').children[0].textContent, 'No problem recorded.')
	assert.deepEqual(state(get), { status: 'History cleared.', kind: SUCCESS, disconnect: 'enabled', save: 'enabled', busy: null })
})

test('a failed Clear history keeps the history and its button', async function() {
	const { get } = load(page({ url: STORED, tokenConfigured: true }), { answers: [new TypeError('NetworkError when attempting to fetch resource.')] })

	await get('clear-diagnostics').dispatch('click')

	assert.equal(get('clear-diagnostics').disabled, false)
	assert.deepEqual(get('diagnostics').children, ['table'])
	assert.equal(get('status').textContent, 'Nextcloud could not be reached. Check your connection and try again.')
	assert.equal(get('status').classList.toString(), ERROR)
})

test('a page without problems has no button of the history', function() {
	const { get } = load(page({ history: false }))
	assert.equal(get('clear-diagnostics'), undefined)
})

// What Try a search answers for jamie and "invoice", as SettingsController::trySearch
// sends it: the IDs, the titles and the verdicts of the documents, nothing more.
const TRIED = {
	user: 'jamie',
	archiveAccount: 'archive',
	count: 12,
	documents: [
		{ id: 412, title: 'Electricity invoice', verdict: 'shown' },
		{ id: 377, title: 'Receipt', verdict: 'writable' },
		{ id: 389, title: 'Home insurance', verdict: 'not_shared' },
		{ id: 371, title: '<b>Internet</b> invoice', verdict: 'not_owned' },
		{ id: 360, title: 'Letter', verdict: 'no_file' },
		{ id: 7, title: 'Old', verdict: 'hidden' },
	],
	fileError: false,
}

// The rows of the table of a try: the texts of the headings, then of each row's cells,
// with the column that each cell names on a phone.
function rowsOf(table) {
	const [caption, head, body] = table.children
	return {
		caption: caption.textContent,
		roles: [table, head, body].map((part) => part.getAttribute('role')),
		headings: head.children[0].children.map((cell) => [cell.textContent, cell.getAttribute('scope'), cell.getAttribute('role')]),
		rows: body.children.map((row) => row.children.map((cell) => `${cell.dataset.label}: ${cell.textContent}`)),
	}
}

test('Try a search sends the term and the user, and shows why each document shows or not', async function() {
	const { get, requests, tries, statuses } = load(page({ url: STORED, tokenConfigured: true }), { answers: [json(TRIED)] })
	get('try-term').value = ' invoice '
	get('try-user').value = ' jamie '
	get('try-result').children = ['an earlier result']

	const event = await get('try-form').dispatch('submit')

	assert.equal(event.defaultPrevented, true)
	assert.deepEqual(requests, [{
		url: TRY_URL,
		method: 'POST',
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: 'request-token' },
		body: { term: ' invoice ', user: ' jamie ' },
	}])
	// While the server works, the form is busy and the earlier result gone.
	assert.deepEqual(tries, [{ text: 'Searching Paperless as jamie…', kind: PENDING, busy: 'true', button: 'disabled', result: 0 }])
	assert.equal(get('try-status').textContent, 'Shown to jamie: 1 of the first 6. Found by Paperless: 12.')
	assert.equal(get('try-status').classList.toString(), SUCCESS)
	assert.equal(get('try-form').getAttribute('aria-busy'), 'false')
	assert.equal(get('try').disabled, false)
	// The message of the settings above stays as it was.
	assert.deepEqual(statuses, [{ text: '', kind: '' }])
	assert.equal(get('status').textContent, '')

	const [table] = get('try-result').children
	assert.equal(table.tagName, 'TABLE')
	assert.equal(table.classList.toString(), 'paperless-unified-search-documents')
	assert.deepEqual(rowsOf(table), {
		caption: 'The documents that Paperless ranks first',
		roles: ['table', 'rowgroup', 'rowgroup'],
		headings: [['ID', 'col', 'columnheader'], ['Title', 'col', 'columnheader'], ['Shown or why not', 'col', 'columnheader']],
		rows: [
			['ID: 412', 'Title: Electricity invoice', 'Shown or why not: Shown'],
			['ID: 377', 'Title: Receipt', 'Shown or why not: Shared with write permission'],
			['ID: 389', 'Title: Home insurance', 'Shown or why not: Not shared with jamie'],
			// A title is text, never markup.
			['ID: 371', 'Title: <b>Internet</b> invoice', 'Shown or why not: Not a file of the archive account'],
			['ID: 360', 'Title: Letter', 'Shown or why not: No file with [P360]'],
			['ID: 7', 'Title: Old', 'Shown or why not: Hidden'],
		],
	})
})

test('Try a search says when Paperless finds nothing', async function() {
	const { get } = load(page(), { answers: [json({ ...TRIED, count: 0, documents: [] })] })

	await get('try-form').dispatch('submit')

	assert.equal(get('try-status').textContent, 'Paperless found no document for this term.')
	assert.equal(get('try-status').classList.toString(), SUCCESS)
	assert.deepEqual(get('try-result').children, [])
})

test('Try a search warns when Nextcloud failed to read a file', async function() {
	const { get } = load(page(), { answers: [json({ ...TRIED, documents: TRIED.documents.slice(0, 1), fileError: true })] })

	await get('try-form').dispatch('submit')

	const [warning, table] = get('try-result').children
	assert.equal(warning.tagName, 'P')
	assert.equal(warning.classList.toString(), 'paperless-unified-search-warning')
	assert.equal(warning.textContent, 'Nextcloud failed to read a file, so a document without a file below may have one.')
	assert.deepEqual(rowsOf(table).rows, [['ID: 412', 'Title: Electricity invoice', 'Shown or why not: Shown']])
	assert.equal(get('try-status').textContent, 'Shown to jamie: 1 of the first 1. Found by Paperless: 12.')
})

test('a refused try shows the reason and no result', async function() {
	const { get } = load(page(), { answers: [json({ message: 'There is no account nobody.', code: 'unknown_user' }, 400)] })
	get('try-user').value = 'nobody'

	await get('try-form').dispatch('submit')

	assert.equal(get('try-status').textContent, 'There is no account nobody.')
	assert.equal(get('try-status').classList.toString(), ERROR)
	assert.deepEqual(get('try-result').children, [])
	assert.equal(get('try-form').getAttribute('aria-busy'), 'false')
	assert.equal(get('try').disabled, false)
})

test('a try that gets no list of documents says that the answer was unexpected', async function() {
	const { get } = load(page(), { answers: [json({ user: 'jamie' })] })

	await get('try-form').dispatch('submit')

	assert.equal(get('try-status').textContent, 'Nextcloud gave an unexpected answer. Reload the page and try again.')
	assert.equal(get('try-status').classList.toString(), ERROR)
	assert.deepEqual(get('try-result').children, [])
})

test('the verdicts of a try go through the translation of Nextcloud', async function() {
	const t = (app, text, vars) => `DE:${text.replace(/{(\w+)}/g, (placeholder, name) => vars?.[name] ?? placeholder)}`
	const { get } = load(page(), { answers: [json(TRIED)], t })

	await get('try-form').dispatch('submit')

	assert.equal(get('try-status').textContent, 'DE:Shown to jamie: 1 of the first 6. Found by Paperless: 12.')
	assert.deepEqual(rowsOf(get('try-result').children[0]).rows[2], ['DE:ID: 389', 'DE:Title: Home insurance', 'DE:Shown or why not: DE:Not shared with jamie'])
})
