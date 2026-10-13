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
			return vars?.[name] === undefined ? placeholder : String(vars[name])
		})
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

	// One short sentence for an answer that isn't the one asked for. The app's own refusals
	// bring their message in the language of the administrator; Nextcloud answers an expired
	// session with 401 or 412 and maintenance mode with 503, in English or as an HTML page.
	function failureMessage(response, payload) {
		if (response.status === 401 || response.status === 412) {
			return translate('Your session has expired. Reload the page and try again.')
		}
		if (response.status === 503) {
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

	async function request(url, method, body) {
		let response
		try {
			response = await window.fetch(url, {
				method,
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: window.OC.requestToken,
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
		} catch (error) {
			throw new Error(translate('Nextcloud could not be reached. Check your connection and try again.'))
		}

		const payload = await readJson(response)
		if (!response.ok || payload === null) {
			throw new Error(failureMessage(response, payload))
		}

		return payload
	}

	// The message of what failed; every error of this script has one.
	function messageOf(error) {
		return typeof error?.message === 'string' && error.message !== ''
			? error.message
			: translate('The request failed.')
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
		const allowLocalInput = document.getElementById('paperless-unified-search-allow-local')
		const tokenInput = document.getElementById('paperless-unified-search-token')
		const tokenUnreadable = document.getElementById('paperless-unified-search-token-unreadable')
		const alwaysSearchInput = document.getElementById('paperless-unified-search-always-search')
		const archiveOwnerInput = document.getElementById('paperless-unified-search-archive-owner')
		const archiveOwnerMissing = document.getElementById('paperless-unified-search-archive-owner-missing')
		const saveButton = document.getElementById('paperless-unified-search-save')
		const resetButton = document.getElementById('paperless-unified-search-reset')
		const status = document.getElementById('paperless-unified-search-status')
		const diagnostics = document.getElementById('paperless-unified-search-diagnostics')

		// Disconnect has something to forget only while a token or a URL is stored: the
		// default value of the URL field, not what the administrator typed.
		function updateResetButton() {
			resetButton.disabled = root.dataset.tokenConfigured !== 'true' && urlInput.defaultValue === ''
		}

		function setBusy(busy) {
			form.setAttribute('aria-busy', busy ? 'true' : 'false')
			saveButton.disabled = busy
			if (busy) {
				resetButton.disabled = true
			} else {
				updateResetButton()
			}
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

		// The settings as the server stored them, such as the URL in its normalized form,
		// with the warnings that they call for.
		function showConfig(config) {
			urlInput.value = config.url || ''
			urlInput.defaultValue = urlInput.value
			allowLocalInput.checked = Boolean(config.allowLocal)
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

		// The message below the buttons, in the style of its kind: pending while the server
		// works, then success or error. The style adds an icon to each, so that the kind
		// doesn't rest on the colour alone.
		function showStatus(message, kind) {
			status.textContent = message
			for (const each of ['pending', 'success', 'error']) {
				status.classList.toggle('paperless-unified-search-status--' + each, each === kind)
			}
		}

		urlInput.addEventListener('input', updateToken)
		updateToken()
		updateResetButton()

		form.addEventListener('submit', async function(event) {
			event.preventDefault()
			setBusy(true)

			try {
				await confirmPassword()
				showStatus(translate('Testing the Paperless connection…'), 'pending')
				showConfig(await request(root.dataset.saveUrl, 'POST', {
					url: urlInput.value,
					allowLocal: allowLocalInput.checked,
					token: tokenInput.value,
					alwaysSearch: alwaysSearchInput.checked,
					archiveOwner: archiveOwnerInput.value,
				}))
				showStatus(translate('Connection successful. Settings saved.'), 'success')
			} catch (error) {
				showStatus(messageOf(error), 'error')
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
				showStatus(translate('Disconnecting Paperless…'), 'pending')
				showConfig(await request(root.dataset.resetUrl, 'DELETE'))
				clearDiagnostics()
				showStatus(translate('Paperless disconnected.'), 'success')
			} catch (error) {
				showStatus(messageOf(error), 'error')
			} finally {
				setBusy(false)
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
					showStatus(translate('History cleared.'), 'success')
				} catch (error) {
					clearDiagnosticsButton.disabled = false
					showStatus(messageOf(error), 'error')
				}
			})
		}
	})
})()
