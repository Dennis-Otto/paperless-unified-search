/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// openSearch of tests/e2e/ui.mjs, the step of the checks in the browser that opens the
// search of Nextcloud and waits until it can search (#223). flow.mjs shows that it does
// so in a real Nextcloud; this shows the order of its steps without a browser.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { openSearch } from '../e2e/ui.mjs'

const PROVIDERS = 'http://nextcloud/ocs/v2.php/search/providers?from=%2Fsettings%2Fuser'
const CONTACTS = 'http://nextcloud/contactsmenu/contacts'

// A stand-in for a page of Playwright, as far as openSearch reaches: it notes what the
// step does in `steps`, and the test answers the requests with answer().
function standIn({ field = false } = {}) {
	const steps = []
	const listeners = []
	const opener = {
		or: () => opener,
		waitFor: async () => steps.push('shown'),
		click: async () => steps.push('click'),
		isVisible: async () => field,
	}
	const page = {
		locator: () => ({ getByRole: () => opener }),
		waitForResponse(matches) {
			steps.push('listen')
			return new Promise((resolve, reject) => listeners.push({ matches, resolve, reject }))
		},
	}
	// The answer to the request for the url: its headers arrive at once, as for
	// waitForResponse of Playwright, and the returned function delivers the rest of it.
	function answer(url) {
		const response = { url: () => url }
		let finish
		const finished = new Promise((resolve) => {
			finish = resolve
		})
		response.finished = () => finished
		for (const listener of listeners) {
			if (listener.matches(response)) {
				listener.resolve(response)
			}
		}
		return finish
	}
	return { page, steps, listeners, answer }
}

// Lets everything that can go on now go on, and says whether the promise has settled.
async function settled(promise) {
	let done = false
	const note = () => {
		done = true
	}
	promise.then(note, note)
	await new Promise((resolve) => setImmediate(resolve))
	return done
}

test('listens for both answers before the click, or it would miss them', async function() {
	const { page, steps, answer } = standIn()
	const opened = openSearch(page)
	await settled(opened)
	assert.deepEqual(steps, ['shown', 'listen', 'listen', 'click'])
	answer(PROVIDERS)()
	answer(CONTACTS)()
	await opened
})

test('goes on only when both answers have arrived whole', async function() {
	const { page, answer } = standIn()
	const opened = openSearch(page)
	await settled(opened)
	assert.equal(await settled(opened), false, 'it waits for the requests')

	const finishProviders = answer(PROVIDERS)
	assert.equal(await settled(opened), false, 'it waits for the contacts, which Nextcloud awaits with the providers')

	const finishContacts = answer(CONTACTS)
	assert.equal(await settled(opened), false, 'the headers of an answer are not the answer')

	finishProviders()
	assert.equal(await settled(opened), false, 'it waits for the rest of the contacts')

	finishContacts()
	assert.equal(await settled(opened), true)
})

test('tells the answers of the search apart from other requests', async function() {
	const { page, listeners, answer } = standIn()
	const opened = openSearch(page)
	await settled(opened)
	const [providers, contacts] = listeners
	const matches = (listener, url) => listener.matches({ url: () => url })

	assert.equal(matches(providers, PROVIDERS), true)
	assert.equal(matches(providers, 'http://nextcloud/ocs/v2.php/search/providers'), true)
	assert.equal(matches(providers, 'http://nextcloud/ocs/v2.php/search/providers/files/search?term=mobiletest'), false)
	assert.equal(matches(providers, CONTACTS), false)

	assert.equal(matches(contacts, CONTACTS), true)
	assert.equal(matches(contacts, 'http://nextcloud/index.php/contactsmenu/contacts'), true)
	assert.equal(matches(contacts, 'http://nextcloud/contactsmenu/teams'), false)
	assert.equal(matches(contacts, PROVIDERS), false)

	answer(PROVIDERS)()
	answer(CONTACTS)()
	await opened
})

test('clicks the way the caller asks, on what opens the search', async function() {
	const { page, steps, answer } = standIn()
	const clicked = []
	const opened = openSearch(page, async (target) => {
		clicked.push(target)
		await target.click()
	})
	await settled(opened)
	assert.equal(clicked.length, 1)
	assert.deepEqual(steps, ['shown', 'listen', 'listen', 'click'])
	answer(PROVIDERS)()
	answer(CONTACTS)()
	await opened
})

test('the field of Nextcloud 35 asks with the first key, so nothing waits for the answers', async function() {
	const { page, steps } = standIn({ field: true })
	await openSearch(page)
	assert.deepEqual(steps, ['shown', 'click'])
})

test('a field takes the click of the caller too', async function() {
	const { page, steps } = standIn({ field: true })
	let clicks = 0
	await openSearch(page, async (target) => {
		clicks++
		await target.click()
	})
	assert.equal(clicks, 1)
	assert.deepEqual(steps, ['shown', 'click'])
})

test('a click that fails names its own error, whatever happens to the requests later', async function() {
	const { page, listeners } = standIn()
	const failure = new Error('The button is covered.')
	await assert.rejects(openSearch(page, async () => {
		throw failure
	}), failure)
	// The page closes after the error: the listeners fail then. An unhandled rejection
	// would fail this test and hide the error of the click in a real run.
	for (const listener of listeners) {
		listener.reject(new Error('The page has closed.'))
	}
	await new Promise((resolve) => setImmediate(resolve))
})

test('a request that gets no answer fails the step', async function() {
	const { page, listeners } = standIn()
	const timeout = new Error('Timeout 60000ms exceeded while waiting for the answer.')
	const opened = openSearch(page)
	await settled(opened)
	listeners[0].reject(timeout)
	await assert.rejects(opened, timeout)
})
