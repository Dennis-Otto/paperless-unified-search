/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function() {
	'use strict'

	function translate(text) {
		return window.t ? window.t('paperless_unified_search', text) : text
	}

	async function request(url, method, body) {
		const response = await window.fetch(url, {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: window.OC.requestToken,
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})

		const text = await response.text()
		let payload = {}
		if (text !== '') {
			try {
				payload = JSON.parse(text)
			} catch (error) {
				payload = { message: text }
			}
		}

		if (!response.ok) {
			throw new Error(payload.message || translate('The request failed.'))
		}

		return payload
	}

	document.addEventListener('DOMContentLoaded', function() {
		const root = document.getElementById('paperless-unified-search-settings')
		if (!root) {
			return
		}

		const form = document.getElementById('paperless-unified-search-form')
		const urlInput = document.getElementById('paperless-unified-search-url')
		const tokenInput = document.getElementById('paperless-unified-search-token')
		const saveButton = document.getElementById('paperless-unified-search-save')
		const resetButton = document.getElementById('paperless-unified-search-reset')
		const status = document.getElementById('paperless-unified-search-status')

		function setBusy(busy) {
			saveButton.disabled = busy
			resetButton.disabled = busy
		}

		function showStatus(message, error) {
			status.textContent = message
			status.classList.toggle('paperless-unified-search-status--error', error)
			status.classList.toggle('paperless-unified-search-status--success', !error)
		}

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			setBusy(true)
			showStatus(translate('Testing the Paperless connection…'), false)

			try {
				const config = await request(root.dataset.saveUrl, 'POST', {
					url: urlInput.value,
					token: tokenInput.value,
				})
				tokenInput.value = ''
				tokenInput.placeholder = translate('Configured — leave blank to keep it')
				root.dataset.tokenConfigured = config.tokenConfigured ? 'true' : 'false'
				resetButton.disabled = false
				showStatus(translate('Connection successful. Settings saved.'), false)
			} catch (error) {
				showStatus(error.message || translate('Could not connect to Paperless.'), true)
			} finally {
				setBusy(false)
			}
		})

		resetButton.addEventListener('click', async function() {
			if (!window.confirm(translate('Disconnect Paperless and delete the stored API token?'))) {
				return
			}

			setBusy(true)
			try {
				await request(root.dataset.resetUrl, 'DELETE')
				urlInput.value = ''
				tokenInput.value = ''
				tokenInput.placeholder = translate('Required')
				root.dataset.tokenConfigured = 'false'
				showStatus(translate('Paperless disconnected.'), false)
			} catch (error) {
				showStatus(error.message || translate('Could not disconnect Paperless.'), true)
			} finally {
				setBusy(false)
				resetButton.disabled = root.dataset.tokenConfigured !== 'true' && urlInput.value === ''
			}
		})
	})
})()
