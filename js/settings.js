/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function() {
	'use strict'

	// The texts end up as textContent, so the values of {placeholders} stay unescaped.
	function translate(text, vars) {
		if (window.t) {
			return window.t('paperless_unified_search', text, vars, undefined, { escape: false })
		}

		return text.replace(/{(\w+)}/g, function(placeholder, name) {
			return vars && name in vars ? String(vars[name]) : placeholder
		})
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

	// Saving and Disconnect ask for the password of the administrator when it wasn't
	// confirmed in the last 30 minutes, as Nextcloud's own settings do.
	function confirmPassword() {
		const confirmation = window.OC && window.OC.PasswordConfirmation
		if (!confirmation || !confirmation.requiresPasswordConfirmation()) {
			return Promise.resolve()
		}

		return new Promise(function(resolve, reject) {
			confirmation.requirePasswordConfirmation(resolve, {}, function() {
				reject(new Error(translate('Confirm your password to change the settings.')))
			})
		})
	}

	// The server that a URL names, as the app compares it: the scheme, the host and the
	// port. Empty for what is no URL yet.
	function originOf(url) {
		try {
			return new URL(url.trim()).origin
		} catch (error) {
			return ''
		}
	}

	document.addEventListener('DOMContentLoaded', function() {
		const root = document.getElementById('paperless-unified-search-settings')
		if (!root) {
			return
		}

		const form = document.getElementById('paperless-unified-search-form')
		const urlInput = document.getElementById('paperless-unified-search-url')
		const tokenInput = document.getElementById('paperless-unified-search-token')
		const tokenUnreadable = document.getElementById('paperless-unified-search-token-unreadable')
		const alwaysSearchInput = document.getElementById('paperless-unified-search-always-search')
		const archiveOwnerInput = document.getElementById('paperless-unified-search-archive-owner')
		const archiveOwnerMissing = document.getElementById('paperless-unified-search-archive-owner-missing')
		const saveButton = document.getElementById('paperless-unified-search-save')
		const resetButton = document.getElementById('paperless-unified-search-reset')
		const status = document.getElementById('paperless-unified-search-status')
		const diagnostics = document.getElementById('paperless-unified-search-diagnostics')

		function setBusy(busy) {
			saveButton.disabled = busy
			resetButton.disabled = busy
		}

		// The server sends the stored token only to the server it was saved for: the
		// default value of the URL field. For another one, the token has to be entered.
		function updateToken() {
			const storedOrigin = originOf(urlInput.defaultValue)
			const keepsToken = root.dataset.tokenConfigured === 'true'
				&& storedOrigin !== ''
				&& originOf(urlInput.value) === storedOrigin
			tokenInput.required = !keepsToken
			if (keepsToken) {
				tokenInput.placeholder = translate('Configured — leave blank to keep it')
			} else if (root.dataset.tokenConfigured === 'true') {
				tokenInput.placeholder = translate('Required for a new server')
			} else {
				tokenInput.placeholder = translate('Required')
			}
		}

		// The settings as the server stored them, with the warnings that they call for.
		function showConfig(config) {
			urlInput.value = config.url || ''
			urlInput.defaultValue = urlInput.value
			tokenInput.value = ''
			root.dataset.tokenConfigured = config.tokenConfigured ? 'true' : 'false'
			updateToken()
			tokenUnreadable.textContent = config.tokenUnreadable
				? translate('The stored API token can\'t be decrypted, for example because the secret in config.php changed. Enter it again.')
				: ''
			alwaysSearchInput.checked = Boolean(config.alwaysSearch)
			archiveOwnerInput.value = config.archiveOwner || ''
			archiveOwnerMissing.textContent = config.archiveOwnerMissing
				? translate('The archive account {account} does not exist.', { account: config.archiveOwner || config.syncAccount })
				: ''
		}

		// Disconnecting and the button of the history forget the problems of the search.
		function clearDiagnostics() {
			const message = document.createElement('p')
			message.textContent = translate('No problem recorded.')
			diagnostics.replaceChildren(message)
		}

		function showStatus(message, error) {
			status.textContent = message
			status.classList.toggle('paperless-unified-search-status--error', error)
			status.classList.toggle('paperless-unified-search-status--success', !error)
		}

		urlInput.addEventListener('input', updateToken)
		updateToken()

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			setBusy(true)

			try {
				await confirmPassword()
				showStatus(translate('Testing the Paperless connection…'), false)
				const config = await request(root.dataset.saveUrl, 'POST', {
					url: urlInput.value,
					token: tokenInput.value,
					alwaysSearch: alwaysSearchInput.checked,
					archiveOwner: archiveOwnerInput.value,
				})
				showConfig(config)
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
				await confirmPassword()
				showConfig(await request(root.dataset.resetUrl, 'DELETE'))
				clearDiagnostics()
				showStatus(translate('Paperless disconnected.'), false)
			} catch (error) {
				showStatus(error.message || translate('Could not disconnect Paperless.'), true)
			} finally {
				setBusy(false)
				resetButton.disabled = root.dataset.tokenConfigured !== 'true' && urlInput.value === ''
			}
		})

		// The page shows the button only while the history holds problems.
		const clearDiagnosticsButton = document.getElementById('paperless-unified-search-clear-diagnostics')
		if (clearDiagnosticsButton) {
			clearDiagnosticsButton.addEventListener('click', async function() {
				clearDiagnosticsButton.disabled = true
				try {
					await request(root.dataset.clearDiagnosticsUrl, 'DELETE')
					clearDiagnostics()
					showStatus(translate('History cleared.'), false)
				} catch (error) {
					clearDiagnosticsButton.disabled = false
					showStatus(error.message || translate('Could not clear the history.'), true)
				}
			})
		}
	})
})()
