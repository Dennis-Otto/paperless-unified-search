/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// js/files.js, run in a context of its own with a small stand-in for a page of Files and a
// fetch that answers as the test says: which files the action is for, what it asks the search
// of Nextcloud for, and what its dialog shows for each answer, from a page of results to the
// HTML page of maintenance mode. scripts/check-project.sh runs it with the coverage of every
// line and branch.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { Script, createContext } from 'node:vm'

const url = new URL('../../js/files.js', import.meta.url)
// The file URL as its name, so that the coverage of the test runner counts the script.
const script = new Script(readFileSync(url, 'utf8'), { filename: url.href })

const ID = 'paperless_unified_search-similar'
const OCS = 'https://cloud.example.test/ocs/v2.php/search/providers/paperless_unified_search_documents/'
const FILE = { type: 'file', basename: 'Invoice [P412].pdf' }
const FILES = { id: 'files' }

// An element with what the script uses of it.
class Element {
	constructor(tag) {
		this.tagName = tag.toUpperCase()
		this.children = []
		this.parent = null
		this.attributes = {}
		this.listeners = {}
		this.dataset = {}
		this.hidden = false
		this.disabled = false
		this.focused = false
		this.open = false
	}

	get firstChild() {
		return this.children[0] ?? null
	}

	append(...nodes) {
		for (const node of nodes) {
			node.parent = this
			this.children.push(node)
		}
	}

	remove() {
		this.parent.children = this.parent.children.filter((child) => child !== this)
		this.parent = null
	}

	setAttribute(name, value) {
		this.attributes[name] = value
	}

	addEventListener(type, listener) {
		this.listeners[type] = listener
	}

	focus() {
		this.focused = true
	}

	showModal() {
		this.open = true
	}

	// The browser closes the dialog, then tells the page.
	close() {
		this.open = false
		this.listeners.close()
	}

	find(className) {
		if (this.className === className) {
			return this
		}
		for (const child of this.children) {
			const found = child.find(className)
			if (found) {
				return found
			}
		}
		return null
	}
}

function json(body, status = 200) {
	return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

// A page of results as the API of the unified search of Nextcloud answers with it.
function results(entries, { cursor = null, isPaginated = true } = {}) {
	return json({ ocs: { meta: { status: 'ok' }, data: { name: 'Paperless documents', isPaginated, entries, cursor } } })
}

function entry(n, properties = {}) {
	return {
		thumbnailUrl: `/core/preview?fileId=${n}`,
		title: `Letter ${n}`,
		subline: `Mar ${n}, 2026 · ACME`,
		resourceUrl: `/index.php/apps/files/files/${n}?openfile=true`,
		icon: '',
		rounded: false,
		attributes: {},
		...properties,
	}
}

// The translation of Nextcloud for English, which fills in the {placeholders}.
function english(app, text, vars) {
	assert.equal(app, 'paperless_unified_search')
	return text.replace(/{(\w+)}/g, (placeholder, name) => String(vars[name]))
}

// Loads the script into a page of Files. `answers` are what fetch answers in turn, a
// Response, or an error that it throws; `scope` is the registry that Files made before it.
function load({ answers = [], files } = {}) {
	const requests = []
	const body = new Element('body')
	const window = {
		OC: {
			requestToken: 'request-token',
			linkToOCS: (service, version) => `https://cloud.example.test/ocs/v${version}.php/${service}/`,
		},
		location: { href: 'https://cloud.example.test/index.php/apps/files/files' },
		t: english,
		async fetch(target, options) {
			requests.push({ url: target, ...structuredClone(options) })
			const answer = answers.shift()
			if (answer instanceof Error) {
				throw answer
			}
			return answer
		},
	}
	if (files !== undefined) {
		window._nc_files_scope = files
	}
	const document = { body, createElement: (tag) => new Element(tag) }
	script.runInContext(createContext({ window, document, URL, URLSearchParams, CustomEvent }))
	return { window, body, requests, action: window._nc_files_scope.v4_0.fileActions.get(ID) }
}

// Runs the action for the file, as a click in its menu does, and returns the dialog.
async function run(page, node = FILE) {
	assert.equal(await page.action.exec({ nodes: [node], view: FILES }), null)
	return page.body.find('paperless-unified-search-similar')
}

function shown(dialog) {
	const status = dialog.find('paperless-unified-search-similar__status')
	const more = dialog.find('paperless-unified-search-similar__more')
	return {
		results: dialog.find('paperless-unified-search-similar__results').children.map((item) => {
			const link = item.firstChild
			const image = link.find('paperless-unified-search-similar__thumbnail')
			return {
				href: link.href,
				thumbnail: image?.src ?? null,
				title: link.find('paperless-unified-search-similar__title').textContent,
				subline: link.find('paperless-unified-search-similar__subline').textContent,
			}
		}),
		status: status.textContent,
		kind: status.dataset.kind,
		more: more.hidden ? 'hidden' : (more.disabled ? 'disabled' : 'shown'),
	}
}

test('registers the action in the registry of Files, and tells a Files that started before', function() {
	const events = []
	const registry = { dispatchEvent: (event) => events.push(event) }
	const page = load({ files: { v4_0: { registry } } })

	assert.equal(page.action.id, ID)
	assert.equal(page.action.displayName({ nodes: [FILE], view: FILES }), 'Find similar documents in Paperless')
	assert.match(page.action.iconSvgInline({ nodes: [FILE], view: FILES }), /^<svg [^>]*aria-hidden="true"/)
	assert.equal(page.action.order, 62)
	assert.deepEqual(events.map((event) => [event.type, event.detail]), [['register:action', page.action]])
})

test('registers the action once, and into a registry without the event of a later Files', function() {
	const registered = { id: ID }
	const once = load({ files: { v4_0: { fileActions: new Map([[ID, registered]]), registry: { dispatchEvent: () => assert.fail('registered twice') } } } })
	assert.equal(once.action, registered)

	const quiet = load({ files: { v4_0: { registry: {} } } })
	assert.equal(quiet.action.id, ID)
	const other = load({ files: { v3: {} } })
	assert.equal(other.action.id, ID)
})

test('offers itself for a single file with the marker of a document, outside the trash bin', function() {
	const { action } = load()
	const enabled = (nodes, view = FILES) => action.enabled({ nodes, view })

	assert.equal(enabled([FILE]), true)
	assert.equal(enabled([{ type: 'file', basename: 'Scan [P7] [P12].pdf' }]), true)
	assert.equal(enabled([FILE], { id: 'trashbin' }), false)
	assert.equal(enabled([FILE, FILE]), false)
	assert.equal(enabled([]), false)
	assert.equal(enabled([{ type: 'folder', basename: 'Case [P412]' }]), false)
	assert.equal(enabled([{ type: 'file', basename: 'Invoice.pdf' }]), false)
	// An ID with a leading zero is no ID of Paperless, and like:[P012] a search for words.
	assert.equal(enabled([{ type: 'file', basename: 'Invoice [P012].pdf' }]), false)
	assert.equal(enabled([{ type: 'file', basename: 'Invoice [p412].pdf' }]), false)
})

test('asks the search provider of the app for the documents similar to the last marker', async function() {
	const page = load({ answers: [results([])] })

	const dialog = await run(page, { type: 'file', basename: 'Scan [P7] [P12].pdf' })

	assert.equal(page.requests.length, 1)
	const request = new URL(page.requests[0].url)
	assert.equal(request.origin + request.pathname, OCS + 'search')
	assert.deepEqual(Object.fromEntries(request.searchParams), { term: 'like:12', limit: '10', from: '/apps/files/' })
	assert.deepEqual(page.requests[0].headers, { Accept: 'application/json', 'OCS-APIRequest': 'true', requesttoken: 'request-token' })
	assert.equal(page.requests[0].credentials, 'same-origin')
	assert.equal(dialog.open, true)
	assert.equal(dialog.attributes['aria-labelledby'], 'paperless-unified-search-similar-heading')
	assert.equal(dialog.find('paperless-unified-search-similar__heading').textContent, 'Documents similar to Scan [P7] [P12].pdf')
	assert.equal(dialog.find('paperless-unified-search-similar__heading').id, 'paperless-unified-search-similar-heading')
	assert.equal(dialog.find('paperless-unified-search-similar__close').attributes['aria-label'], 'Close')
})

test('shows the results with their previews and links, and only links of the web', async function() {
	const page = load({ answers: [results([
		entry(1),
		entry(2, { thumbnailUrl: '' }),
		entry(3, { resourceUrl: 'javascript:alert(1)' }),
		entry(4, { thumbnailUrl: 'http://[invalid', title: undefined, subline: null }),
		entry(5, { resourceUrl: undefined }),
	])] })

	const dialog = await run(page)

	assert.deepEqual(shown(dialog), {
		results: [
			{ href: 'https://cloud.example.test/index.php/apps/files/files/1?openfile=true', thumbnail: 'https://cloud.example.test/core/preview?fileId=1', title: 'Letter 1', subline: 'Mar 1, 2026 · ACME' },
			{ href: 'https://cloud.example.test/index.php/apps/files/files/2?openfile=true', thumbnail: null, title: 'Letter 2', subline: 'Mar 2, 2026 · ACME' },
			{ href: 'https://cloud.example.test/index.php/apps/files/files/4?openfile=true', thumbnail: null, title: '', subline: '' },
		],
		status: '',
		kind: 'done',
		more: 'hidden',
	})
	const image = dialog.find('paperless-unified-search-similar__thumbnail')
	assert.equal(image.alt, '')
	assert.equal(image.loading, 'lazy')
})

test('says when Paperless finds no similar document with a file that the user can open', async function() {
	const dialog = await run(load({ answers: [results([entry(3, { resourceUrl: 'data:text/html,x' })])] }))

	assert.deepEqual(shown(dialog), {
		results: [],
		status: 'Paperless found no similar document with a file that you can open.',
		kind: 'empty',
		more: 'hidden',
	})
})

test('shows more after a full page, from its cursor, with the focus on the first new result', async function() {
	const first = Array.from({ length: 10 }, (unused, index) => entry(index + 1))
	const page = load({ answers: [results(first, { cursor: 'sealed-10' }), results([entry(11)], { cursor: 'sealed-11' })] })
	const dialog = await run(page)

	assert.equal(shown(dialog).results.length, 10)
	assert.equal(shown(dialog).more, 'shown')
	assert.equal(dialog.find('paperless-unified-search-similar__results').firstChild.firstChild.focused, false)

	const more = dialog.find('paperless-unified-search-similar__more')
	const loading = more.listeners.click()
	assert.deepEqual({ more: shown(dialog).more, status: shown(dialog).status, kind: shown(dialog).kind }, { more: 'disabled', status: 'Looking for similar documents…', kind: 'pending' })
	await loading

	assert.equal(new URL(page.requests[1].url).searchParams.get('cursor'), 'sealed-10')
	const items = dialog.find('paperless-unified-search-similar__results').children
	assert.equal(items.length, 11)
	assert.equal(items[10].firstChild.focused, true)
	assert.equal(shown(dialog).more, 'hidden')
})

test('leaves the focus where it is after a further page without a result to show', async function() {
	const first = Array.from({ length: 10 }, (unused, index) => entry(index + 1))
	const page = load({ answers: [results(first, { cursor: 2 }), results([entry(11, { resourceUrl: 'javascript:x' })])] })
	const dialog = await run(page)

	await dialog.find('paperless-unified-search-similar__more').listeners.click()

	assert.equal(shown(dialog).results.length, 10)
	assert.equal(shown(dialog).kind, 'done')
	assert.equal(new URL(page.requests[1].url).searchParams.get('cursor'), '2')
})

test('offers no further page without a cursor, without pages or after a page that is not full', async function() {
	const full = Array.from({ length: 10 }, (unused, index) => entry(index + 1))
	for (const answer of [
		results(full),
		json({ ocs: { data: { isPaginated: true, entries: full } } }),
		results(full, { cursor: 'next', isPaginated: false }),
		results(full.slice(1), { cursor: 'next' }),
	]) {
		assert.equal(shown(await run(load({ answers: [answer] }))).more, 'hidden')
	}
})

test('names what went wrong when Nextcloud answers without results, and can try again', async function() {
	const html = (status) => new Response('<!DOCTYPE html><html><body>Maintenance</body></html>', { status, headers: { 'Content-Type': 'text/html' } })
	for (const [answer, message] of [
		[new TypeError('Failed to fetch'), 'Nextcloud could not be reached. Check your connection and try again.'],
		[json({ message: 'Current user is not logged in' }, 401), 'Your session has expired. Reload the page and try again.'],
		[json({}, 412), 'Your session has expired. Reload the page and try again.'],
		[html(503), 'Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.'],
		[json({ ocs: { data: [] } }, 500), 'The request failed (HTTP 500).'],
		[html(200), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
		[json({ ocs: { data: 'Paperless documents' } }), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
		[json({ ocs: { data: { entries: null } } }), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
		[json({ ocs: { data: null } }), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
		[json({}), 'Nextcloud gave an unexpected answer. Reload the page and try again.'],
	]) {
		const dialog = await run(load({ answers: [answer] }))
		assert.deepEqual(shown(dialog), { results: [], status: message, kind: 'error', more: 'shown' }, message)
		assert.equal(dialog.find('paperless-unified-search-similar__more').textContent, 'Try again')
	}
})

test('tries the same page again after a failure', async function() {
	const page = load({ answers: [new TypeError('Failed to fetch'), results([entry(1)])] })
	const dialog = await run(page)

	await dialog.find('paperless-unified-search-similar__more').listeners.click()

	assert.equal(page.requests[1].url, page.requests[0].url)
	assert.equal(shown(dialog).results.length, 1)
	assert.equal(shown(dialog).more, 'hidden')
	assert.equal(dialog.find('paperless-unified-search-similar__more').textContent, 'Show more')
	assert.equal(dialog.find('paperless-unified-search-similar__results').firstChild.firstChild.focused, true)
})

test('keeps the keys from Files, and the close button closes the dialog, which leaves the page', async function() {
	const page = load({ answers: [results([entry(1)])] })
	const dialog = await run(page)
	assert.deepEqual(page.body.children, [dialog])
	// A key in the dialog doesn't reach the shortcuts of Files.
	let stopped = false
	dialog.listeners.keydown({ key: 'Delete', stopPropagation: () => {
		stopped = true
	} })
	assert.equal(stopped, true)

	dialog.find('paperless-unified-search-similar__close').listeners.click()

	assert.equal(dialog.open, false)
	assert.deepEqual(page.body.children, [])
})
