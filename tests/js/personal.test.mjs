/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// js/personal.js, run in a context of its own with a small stand-in for the section
// Paperless of the personal settings and a fetch that answers as the test says: what the
// section shows and sends for each answer of Nextcloud, from a stored choice to the HTML
// page of maintenance mode. scripts/check-project.sh runs it with the coverage of every
// line and branch.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { Script, createContext } from 'node:vm'

const url = new URL('../../js/personal.js', import.meta.url)
// The file URL as its name, so that the coverage of the test runner counts the script.
const script = new Script(readFileSync(url, 'utf8'), { filename: url.href })

const SAVE_URL = '/apps/paperless_unified_search/personal'
const ALERTS_URL = '/apps/paperless_unified_search/personal/alerts'
const SUCCESS = 'paperless-unified-search-status--success'
const ERROR = 'paperless-unified-search-status--error'
const PENDING = 'paperless-unified-search-status--pending'
const ON = 'Saved. Your searches include Paperless from the next page that you open.'
const OFF = 'Saved. From the next page that you open, your searches include Paperless only with “Search connected services”.'

class ClassList {
	#names = new Set()

	toggle(name, force) {
		if (force) {
			this.#names.add(name)
		} else {
			this.#names.delete(name)
		}
	}

	toString() {
		return [...this.#names].sort().join(' ')
	}
}

// An element with what the script uses of it.
class Element {
	constructor(properties = {}) {
		this.classList = new ClassList()
		this.dataset = {}
		this.listeners = []
		this.textContent = ''
		this.checked = false
		this.disabled = false
		Object.assign(this, properties)
	}

	addEventListener(type, listener) {
		assert.equal(type, this.event ?? 'change')
		this.listeners.push(listener)
	}

	// The browser ticks a checkbox before it tells the page.
	async click() {
		this.checked = !this.checked
		await Promise.all(this.listeners.map((listener) => listener()))
	}
}

// The section as templates/personal.php renders it, with the choice as stored, while the
// administrators let users choose the search alone.
function section({ checked = false } = {}) {
	return new Map([
		['paperless-unified-search-personal', new Element({ dataset: { saveUrl: SAVE_URL } })],
		['paperless-unified-search-personal-always-search', new Element({ checked })],
		['paperless-unified-search-personal-status', new Element()],
	])
}

// The section while the administrators let users ask for notifications alone.
function alertsSection({ every = false, terms = '' } = {}) {
	return new Map([
		['paperless-unified-search-personal', new Element({ dataset: { alertsUrl: ALERTS_URL } })],
		['paperless-unified-search-personal-every-document', new Element({ checked: every })],
		['paperless-unified-search-personal-terms', new Element({ value: terms, disabled: every })],
		['paperless-unified-search-personal-alerts-save', new Element({ event: 'click' })],
		['paperless-unified-search-personal-alerts-status', new Element()],
	])
}

// The parts of the section about the notifications, and what they show.
function alertsOf(elements) {
	return {
		every: elements.get('paperless-unified-search-personal-every-document'),
		terms: elements.get('paperless-unified-search-personal-terms'),
		button: elements.get('paperless-unified-search-personal-alerts-save'),
		status: elements.get('paperless-unified-search-personal-alerts-status'),
	}
}

function shownAlerts(elements) {
	const { every, terms, button, status } = alertsOf(elements)
	return { every: every.checked, terms: terms.value, termsDisabled: terms.disabled, button: button.disabled, text: status.textContent, kind: status.classList.toString() }
}

// A click on the button, which leaves the checkbox of the button alone.
async function save(elements) {
	const { button } = alertsOf(elements)
	await Promise.all(button.listeners.map((listener) => listener()))
}

function json(body, status = 200, headers = {}) {
	return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers } })
}

function html(status, headers = {}) {
	return new Response('<!DOCTYPE html><html><body>Maintenance</body></html>', {
		status,
		headers: { 'Content-Type': 'text/html; charset=UTF-8', ...headers },
	})
}

// The translation of Nextcloud for English, which fills in the {placeholders}.
function english(app, text, vars) {
	return text.replace(/{(\w+)}/g, (placeholder, name) => String(vars[name]))
}

// Loads the script into a page of `elements`. `answers` are what fetch answers in turn,
// a Response, or an error that it throws; `t` stands for the translation of Nextcloud.
function load(elements, { answers = [], t = english } = {}) {
	const requests = []
	const pending = []
	const listeners = []
	const checkbox = elements.get('paperless-unified-search-personal-always-search')
	const status = elements.get('paperless-unified-search-personal-status') ?? elements.get('paperless-unified-search-personal-alerts-status')
	const window = {
		OC: { requestToken: 'request-token' },
		t,
		async fetch(target, options) {
			// What the section shows while it waits for the answer.
			pending.push({ text: status?.textContent, kind: status?.classList.toString(), disabled: checkbox?.disabled })
			requests.push({ url: target, ...structuredClone(options), body: JSON.parse(options.body) })
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
	}
	script.runInContext(createContext({ window, document }))
	for (const listener of listeners) {
		listener()
	}
	return { requests, pending, checkbox, status }
}

function shown({ checkbox, status }) {
	return { checked: checkbox.checked, disabled: checkbox.disabled, text: status.textContent, kind: status.classList.toString() }
}

test('a page without the section is left alone', function() {
	const { requests } = load(new Map())
	assert.deepEqual(requests, [])
})

test('ticking the checkbox stores the choice and says when it applies', async function() {
	const page = load(section(), { answers: [json({ alwaysSearch: true })] })

	await page.checkbox.click()

	assert.deepEqual(page.requests, [{
		url: SAVE_URL,
		method: 'POST',
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: 'request-token' },
		body: { alwaysSearch: true },
	}])
	// The checkbox waits for the server, so that a second click can't overtake the first.
	assert.deepEqual(page.pending, [{ text: 'Saving…', kind: PENDING, disabled: true }])
	assert.deepEqual(shown(page), { checked: true, disabled: false, text: ON, kind: SUCCESS })
})

test('unticking the checkbox stores that the switch decides again', async function() {
	const page = load(section({ checked: true }), { answers: [json({ alwaysSearch: false })] })

	await page.checkbox.click()

	assert.deepEqual(page.requests.map(({ body }) => body), [{ alwaysSearch: false }])
	assert.deepEqual(shown(page), { checked: false, disabled: false, text: OFF, kind: SUCCESS })
})

test('the checkbox shows the choice as the server stored it', async function() {
	const page = load(section(), { answers: [json({ alwaysSearch: false })] })

	await page.checkbox.click()

	assert.deepEqual(shown(page), { checked: false, disabled: false, text: OFF, kind: SUCCESS })
})

test('a refusal shows its reason and puts the checkbox back', async function() {
	const page = load(section(), {
		answers: [json({ message: 'Your administrator does not let users include Paperless in all their searches.', code: 'not_allowed' }, 403)],
	})

	await page.checkbox.click()

	assert.deepEqual(shown(page), {
		checked: false,
		disabled: false,
		text: 'Your administrator does not let users include Paperless in all their searches.',
		kind: ERROR,
	})
})

const failures = {
	'an expired session (401)': [json({ message: 'Current user is not logged in' }, 401), 'Your session has expired. Reload the page and try again.'],
	'an expired session (412)': [json({ message: 'CSRF check failed' }, 412), 'Your session has expired. Reload the page and try again.'],
	'maintenance mode': [html(503, { 'X-Nextcloud-Maintenance-Mode': '1' }), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
	'a pending upgrade': [html(503, { 'Retry-After': '120' }), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
	'a 503 of a proxy': [html(503), 'The request failed (HTTP 503).'],
	'a refusal with a blank message': [json({ message: ' ' }, 400), 'The request failed (HTTP 400).'],
	'an HTML page': [html(200), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	'JSON that is broken': [new Response('{', { status: 200, headers: { 'Content-Type': 'application/json' } }), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	'an answer without the choice': [json({}), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	'an answer without a type': [new Response(null, { status: 200 }), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	'no answer at all': [new TypeError('Failed to fetch'), 'Nextcloud could not be reached. Check your connection and try again.'],
}

for (const [name, [answer, message]] of Object.entries(failures)) {
	test(`${name} shows a message and puts the checkbox back`, async function() {
		const page = load(section({ checked: true }), { answers: [answer] })

		await page.checkbox.click()

		assert.deepEqual(shown(page), { checked: true, disabled: false, text: message, kind: ERROR })
	})
}

test('the messages go through the translation of Nextcloud, with their placeholders', async function() {
	const calls = []
	const t = (app, text, vars, count, options) => {
		// A copy in the realm of the test, which compares it with its own objects.
		calls.push({ app, text, vars: vars === undefined ? undefined : { ...vars }, count, options })
		return `[${text.replace('{status}', vars?.status)}]`
	}
	const page = load(section(), { answers: [json({ alwaysSearch: true }), html(500)], t })

	await page.checkbox.click()
	await page.checkbox.click()

	assert.deepEqual(calls.map(({ text, vars }) => ({ text, vars })), [
		{ text: 'Saving…', vars: undefined },
		{ text: ON, vars: undefined },
		{ text: 'Saving…', vars: undefined },
		{ text: 'The request failed (HTTP {status}).', vars: { status: 500 } },
	])
	assert.ok(calls.every(({ app, options }) => app === 'paperless_unified_search' && options.escape === false))
	assert.equal(page.status.textContent, '[The request failed (HTTP 500).]')
})

test('without the checkbox of the search, the notifications still work', async function() {
	const elements = alertsSection({ terms: '' })
	const { terms } = alertsOf(elements)
	const page = load(elements, { answers: [json({ everyDocument: false, terms: ['tag:unpaid', 'tax'] })] })
	terms.value = ' tag:unpaid \n\ntax\ntax'

	await save(elements)

	assert.deepEqual(page.requests, [{
		url: ALERTS_URL,
		method: 'POST',
		credentials: 'same-origin',
		headers: { Accept: 'application/json', 'Content-Type': 'application/json', requesttoken: 'request-token' },
		body: { everyDocument: false, terms: [' tag:unpaid ', '', 'tax', 'tax'] },
	}])
	// What the page shows while it waits: the button rests, so no second save overtakes it.
	assert.deepEqual(page.pending, [{ text: 'Saving…', kind: PENDING, disabled: undefined }])
	assert.deepEqual(shownAlerts(elements), {
		every: false,
		terms: 'tag:unpaid\ntax',
		termsDisabled: false,
		button: false,
		text: 'Saved. You get a notification about the next new documents.',
		kind: SUCCESS,
	})
})

test('the button rests while the server stores the searches', async function() {
	const elements = alertsSection()
	const { button } = alertsOf(elements)
	let during
	const answer = json({ everyDocument: false, terms: [] })
	const page = load(elements, { answers: [answer] })
	const clicked = save(elements)
	during = button.disabled
	await clicked

	assert.equal(during, true)
	assert.equal(button.disabled, false)
	assert.equal(page.requests.length, 1)
})

test('every document rests the searches, and keeps them for the way back', async function() {
	const elements = alertsSection({ terms: 'tag:unpaid' })
	const { every, terms } = alertsOf(elements)
	load(elements)

	await every.click()
	assert.equal(terms.disabled, true)
	assert.equal(terms.value, 'tag:unpaid')

	await every.click()
	assert.equal(terms.disabled, false)
})

test('saving every document shows it as the server stored it', async function() {
	const elements = alertsSection({ terms: 'tag:unpaid' })
	const { every } = alertsOf(elements)
	const page = load(elements, { answers: [json({ everyDocument: true, terms: ['tag:unpaid'] })] })
	every.checked = true

	await save(elements)

	assert.deepEqual(page.requests.map(({ body }) => body), [{ everyDocument: true, terms: ['tag:unpaid'] }])
	assert.deepEqual(shownAlerts(elements), {
		every: true,
		terms: 'tag:unpaid',
		termsDisabled: true,
		button: false,
		text: 'Saved. You get a notification about the next new documents.',
		kind: SUCCESS,
	})
})

test('saving nothing says that no notifications come', async function() {
	const elements = alertsSection({ terms: 'tag:unpaid' })
	alertsOf(elements).terms.value = ''
	load(elements, { answers: [json({ everyDocument: false, terms: [] })] })

	await save(elements)

	assert.equal(shownAlerts(elements).text, 'Saved. You get no notifications about new documents.')
	assert.equal(shownAlerts(elements).kind, SUCCESS)
})

test('a refused save shows its reason and keeps what the user typed', async function() {
	const elements = alertsSection()
	alertsOf(elements).terms.value = 'one\ntwo'
	load(elements, { answers: [json({ message: 'Save at most 10 searches.', code: 'too_many_searches' }, 400)] })

	await save(elements)

	assert.deepEqual(shownAlerts(elements), {
		every: false,
		terms: 'one\ntwo',
		termsDisabled: false,
		button: false,
		text: 'Save at most 10 searches.',
		kind: ERROR,
	})
})

for (const [name, answer] of [
	['without the terms', { everyDocument: true }],
	['without the choice', { terms: [] }],
	['with terms as text', { everyDocument: true, terms: 'tax' }],
]) {
	test(`an answer ${name} is no save`, async function() {
		const elements = alertsSection()
		load(elements, { answers: [json(answer)] })

		await save(elements)

		assert.equal(shownAlerts(elements).text, 'Nextcloud gave an unexpected answer. Reload the page and try again.')
		assert.equal(shownAlerts(elements).kind, ERROR)
	})
}

test('without a section of notifications, the checkbox of the search works alone', async function() {
	const page = load(section(), { answers: [json({ alwaysSearch: true })] })

	await page.checkbox.click()

	assert.equal(shown(page).text, ON)
})
