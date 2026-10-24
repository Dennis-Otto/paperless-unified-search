/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The section Paperless of the personal settings: whether every search of the user
// includes Paperless, without the switch Search connected services. The checkbox stores
// the choice as soon as it changes, as the personal settings of Nextcloud do.
(function() {
	'use strict'

	// The translation of Nextcloud, which every page of its settings loads. The texts end
	// up as textContent, so the values of {placeholders} stay unescaped.
	function translate(text, vars) {
		return window.t('paperless_unified_search', text, vars, undefined, { escape: false })
	}

	// The answer as JSON, or null for anything else, such as the HTML page that Nextcloud
	// shows in maintenance mode or that a proxy shows when Nextcloud doesn't answer.
	async function readJson(response) {
		if (!/^application\/json\b/i.test(response.headers.get('Content-Type') || '')) {
			return null
		}

		try {
			return await response.json()
		} catch (error) {
			return null
		}
	}

	// One short sentence for an answer that isn't the one asked for, as on the page of the
	// administration settings: the app's own refusals bring their message in the language
	// of the user; Nextcloud answers an expired session with 401 or 412, and maintenance
	// mode with 503, which it marks with X-Nextcloud-Maintenance-Mode and Retry-After, or
	// a pending upgrade with Retry-After alone.
	function failureMessage(response, payload) {
		if (response.status === 401 || response.status === 412) {
			return translate('Your session has expired. Reload the page and try again.')
		}
		if (response.status === 503
			&& (response.headers.get('X-Nextcloud-Maintenance-Mode') === '1' || response.headers.has('Retry-After'))) {
			return translate('Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.')
		}
		if (typeof payload?.message === 'string' && payload.message.trim() !== '') {
			return payload.message
		}
		if (response.ok) {
			return translate('Nextcloud gave an unexpected answer. Reload the page and try again.')
		}

		return translate('The request failed (HTTP {status}).', { status: response.status })
	}

	// Stores the choice and returns it as the server stored it.
	async function save(url, alwaysSearch) {
		let response
		try {
			response = await window.fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				},
				body: JSON.stringify({ alwaysSearch }),
			})
		} catch (error) {
			throw new Error(translate('Nextcloud could not be reached. Check your connection and try again.'))
		}

		const payload = await readJson(response)
		if (!response.ok || typeof payload?.alwaysSearch !== 'boolean') {
			throw new Error(failureMessage(response, payload))
		}

		return payload.alwaysSearch
	}

	document.addEventListener('DOMContentLoaded', function() {
		const root = document.getElementById('paperless-unified-search-personal')
		if (!root) {
			return
		}

		const checkbox = document.getElementById('paperless-unified-search-personal-always-search')
		const status = document.getElementById('paperless-unified-search-personal-status')

		// The message below the checkbox, in the style of its kind, which adds an icon to
		// each, so that the kind doesn't rest on the colour alone.
		function showStatus(message, kind) {
			status.textContent = message
			for (const each of ['pending', 'success', 'error']) {
				status.classList.toggle('paperless-unified-search-status--' + each, each === kind)
			}
		}

		// Nextcloud lists the providers of its search as a page loads, so the choice
		// applies to the pages that load after it.
		checkbox.addEventListener('change', async function() {
			const wanted = checkbox.checked
			checkbox.disabled = true
			showStatus(translate('Saving…'), 'pending')
			try {
				const saved = await save(root.dataset.saveUrl, wanted)
				checkbox.checked = saved
				showStatus(saved
					? translate('Saved. Your searches include Paperless from the next page that you open.')
					: translate('Saved. From the next page that you open, your searches include Paperless only with “Search connected services”.'), 'success')
			} catch (error) {
				checkbox.checked = !wanted
				showStatus(error.message, 'error')
			} finally {
				checkbox.disabled = false
			}
		})
	})
})()
