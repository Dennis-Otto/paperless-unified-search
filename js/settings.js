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

	// Whether Nextcloud answered 503 for maintenance mode, which it marks with
	// X-Nextcloud-Maintenance-Mode and Retry-After, or for a pending upgrade, which it marks
	// with Retry-After alone. A 503 without them, from a proxy whose Nextcloud is down or
	// from Nextcloud for a broken configuration, doesn't go away by waiting.
	function isMaintenance(response) {
		return response.status === 503
			&& (response.headers.get('X-Nextcloud-Maintenance-Mode') === '1' || response.headers.has('Retry-After'))
	}

	// One short sentence for an answer that isn't the one asked for. The app's own refusals
	// bring their message in the language of the administrator; Nextcloud answers an expired
	// session with 401 or 412 and maintenance mode with 503, in English or as an HTML page.
	function failureMessage(response, payload) {
		if (response.status === 401 || response.status === 412) {
			return translate('Your session has expired. Reload the page and try again.')
		}
		if (isMaintenance(response)) {
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

	// The warnings about the account of the API token, by the value of each that the
	// server names, as lib/Service/SettingsMessages.php words them. They keep nothing from
	// being saved.
	const TOKEN_WARNINGS = {
		superuser: () => translate('The API token belongs to a Superuser of Paperless: whoever gets hold of it can change and delete every document. Use the token of an account that may only view documents.'),
		staff: () => translate('The API token belongs to an Admin of Paperless, who may also read its logs: whoever gets hold of the token can too. Use the token of an account that is neither Admin nor Superuser.'),
		changes_documents: () => translate('The account of the API token may add, change or delete documents in Paperless: whoever gets hold of the token can too. Use the token of an account that may only view documents.'),
		hides_correspondents: () => translate('The account of the API token may not view correspondents in Paperless, so results show without them. Give the account the permission View for Correspondents.'),
		hides_document_types: () => translate('The account of the API token may not view document types in Paperless, so results show without them. Give the account the permission View for Document Types.'),
	}

	// The text of the warnings of the settings about the account of the token, without a
	// value that this version of the page doesn't know.
	function tokenWarningsOf(config) {
		return (config.tokenWarnings || [])
			.filter((warning) => Object.hasOwn(TOKEN_WARNINGS, warning))
			.map((warning) => TOKEN_WARNINGS[warning]())
			.join(' ')
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
		const tokenWarnings = document.getElementById('paperless-unified-search-token-warnings')
		const alwaysSearchInput = document.getElementById('paperless-unified-search-always-search')
		const personalAlwaysSearchInput = document.getElementById('paperless-unified-search-users-always-search')
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
			tokenWarnings.textContent = tokenWarningsOf(config)
			alwaysSearchInput.checked = Boolean(config.alwaysSearch)
			personalAlwaysSearchInput.checked = Boolean(config.personalAlwaysSearch)
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

		// A message below the buttons of a form, in the style of its kind: pending while the
		// server works, then success or error. The style adds an icon to each, so that the
		// kind doesn't rest on the colour alone.
		function showStatusIn(target, message, kind) {
			target.textContent = message
			for (const each of ['pending', 'success', 'error']) {
				target.classList.toggle('paperless-unified-search-status--' + each, each === kind)
			}
		}

		function showStatus(message, kind) {
			showStatusIn(status, message, kind)
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
					personalAlwaysSearch: personalAlwaysSearchInput.checked,
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

		// Try a search: the documents that Paperless ranks first for a term, each with
		// whether it shows to the user and why not. The answer names them by their IDs and
		// titles, which the page shows as text.
		const tryForm = document.getElementById('paperless-unified-search-try-form')
		const tryTerm = document.getElementById('paperless-unified-search-try-term')
		const tryUser = document.getElementById('paperless-unified-search-try-user')
		const tryButton = document.getElementById('paperless-unified-search-try')
		const tryStatus = document.getElementById('paperless-unified-search-try-status')
		const tryResult = document.getElementById('paperless-unified-search-try-result')

		// The verdict on a document, in the words of the reasons below the result.
		function verdictOf(found, user) {
			switch (found.verdict) {
			case 'shown':
				return translate('Shown')
			case 'writable':
				return translate('Shared with write permission')
			case 'not_shared':
				return translate('Not shared with {user}', { user })
			case 'not_owned':
				return translate('Not a file of the archive account')
			case 'no_file':
				return translate('No file with {marker}', { marker: '[P' + found.id + ']' })
			default:
				return translate('Hidden')
			}
		}

		function element(tag, text, role) {
			const made = document.createElement(tag)
			made.textContent = text
			made.setAttribute('role', role)
			return made
		}

		function row(cells) {
			const made = element('tr', '', 'row')
			made.append(...cells)
			return made
		}

		// The documents as a table, which a phone shows as cards whose cells name their
		// column, as the history of the problems does.
		function documentsTable(documents, user) {
			const columns = [translate('ID'), translate('Title'), translate('Shown or why not')]
			const table = element('table', '', 'table')
			table.classList.add('paperless-unified-search-documents')
			const caption = document.createElement('caption')
			caption.textContent = translate('The documents that Paperless ranks first')
			const head = element('thead', '', 'rowgroup')
			head.append(row(columns.map(function(column) {
				const heading = element('th', column, 'columnheader')
				heading.setAttribute('scope', 'col')
				return heading
			})))
			const body = element('tbody', '', 'rowgroup')
			body.append(...documents.map(function(found) {
				return row([String(found.id), found.title, verdictOf(found, user)].map(function(text, index) {
					const cell = element('td', text, 'cell')
					cell.dataset.label = columns[index]
					return cell
				}))
			}))
			table.append(caption, head, body)
			return table
		}

		function showTry(answer) {
			if (!Array.isArray(answer.documents)) {
				throw new Error(translate('Nextcloud gave an unexpected answer. Reload the page and try again.'))
			}

			const documents = answer.documents
			const shown = documents.filter(function(found) { return found.verdict === 'shown' }).length
			showStatusIn(tryStatus, documents.length === 0
				? translate('Paperless found no document for this term.')
				: translate('Shown to {user}: {shown} of the first {listed}. Found by Paperless: {count}.', {
					user: answer.user,
					shown,
					listed: documents.length,
					count: answer.count,
				}), 'success')
			const parts = []
			if (answer.fileError) {
				const warning = document.createElement('p')
				warning.classList.add('paperless-unified-search-warning')
				warning.textContent = translate('Nextcloud failed to read a file, so a document without a file below may have one.')
				parts.push(warning)
			}
			if (documents.length > 0) {
				parts.push(documentsTable(documents, answer.user))
			}
			tryResult.replaceChildren(...parts)
		}

		tryForm.addEventListener('submit', async function(event) {
			event.preventDefault()
			tryForm.setAttribute('aria-busy', 'true')
			tryButton.disabled = true
			tryResult.replaceChildren()
			showStatusIn(tryStatus, translate('Searching Paperless as {user}…', { user: tryUser.value.trim() }), 'pending')

			try {
				showTry(await request(root.dataset.tryUrl, 'POST', { term: tryTerm.value, user: tryUser.value }))
			} catch (error) {
				showStatusIn(tryStatus, messageOf(error), 'error')
			} finally {
				tryForm.setAttribute('aria-busy', 'false')
				tryButton.disabled = false
			}
		})
	})
})()
