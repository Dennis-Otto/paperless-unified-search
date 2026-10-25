/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// docs/javascripts/hero.js, which gives a video of the hero of the home page its poster
// once it comes near the screen, against a stand-in for the page and for the
// IntersectionObserver of the browser.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { Script, createContext } from 'node:vm'

const url = new URL('../../docs/javascripts/hero.js', import.meta.url)
const script = new Script(readFileSync(url, 'utf8'), { filename: url.href })

// A video of the hero with the address of its video.
function video(src) {
	return { poster: '', getAttribute: (name) => (name === 'src' ? src : null) }
}

// Runs the script on a page with the videos, and with an IntersectionObserver unless
// observer is false; returns the observers that it made.
function run(videos, { observer = true } = {}) {
	const observers = []
	class IntersectionObserver {
		constructor(callback, options) {
			Object.assign(this, { callback, options, observed: [], unobserved: [] })
			observers.push(this)
		}

		observe(target) {
			this.observed.push(target)
		}

		unobserve(target) {
			this.unobserved.push(target)
		}
	}
	const window = observer ? { IntersectionObserver } : {}
	const document = {
		querySelectorAll(selector) {
			assert.equal(selector, '.pus-hero__media video')
			return videos
		},
	}
	script.runInContext(createContext({ window, document, IntersectionObserver }))
	return observers
}

test('a page without the hero watches nothing', function() {
	assert.deepEqual(run([]), [])
})

test('a browser without IntersectionObserver leaves the videos without a poster', function() {
	const light = video('images/search-and-open.mp4')
	assert.deepEqual(run([light], { observer: false }), [])
	assert.equal(light.poster, '')
})

test('each video gets the first frame beside it once it comes near the screen', function() {
	const light = video('images/search-and-open.mp4')
	const dark = video('../images/search-and-open-dark.mp4')
	const [observer] = run([light, dark])
	assert.deepEqual(observer.observed, [light, dark])
	assert.equal(observer.options.rootMargin, '200px')
	// The video of the other scheme doesn't show, and never comes near the screen.
	observer.callback([{ target: light, isIntersecting: true }, { target: dark, isIntersecting: false }])
	assert.equal(light.poster, 'images/search-and-open-poster.webp')
	assert.equal(dark.poster, '')
	assert.deepEqual(observer.unobserved, [light])
	// The reader switches to the dark scheme.
	observer.callback([{ target: dark, isIntersecting: true }])
	assert.equal(dark.poster, '../images/search-and-open-dark-poster.webp')
	assert.deepEqual(observer.unobserved, [light, dark])
})
