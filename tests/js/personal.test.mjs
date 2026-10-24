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
		assert.equal(type, 'change')
		this.listeners.push(listener)
	}

	// The browser ticks the checkbox before it tells the page.
	async click() {
		this.checked = !this.checked
		await Promise.all(this.listeners.map((listener) => listener()))
	}
}

// The section as templates/personal.php renders it, with the choice as stored.
function section({ checked = false } = {}) {
	return new Map([
		['paperless-unified-search-personal', new Element({ dataset: { saveUrl: SAVE_URL } })],
		['paperless-unified-search-personal-always-search', new Element({ checked })],
		['paperless-unified-search-personal-status', new Element()],
	])
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
	const status = elements.get('paperless-unified-search-personal-status')
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
