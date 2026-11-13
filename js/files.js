/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// "Find similar documents in Paperless" in the menu of a file in Files whose name carries the
// marker of a document of Paperless, such as "Invoice [P412].pdf": it shows, in a dialog of its
// own, the documents that Paperless finds similar to that one, as the term like:412 finds them in
// the search of Nextcloud, with the files that the user can open, the same results and the same
// rules of access, as it asks the search of Nextcloud for them.
//
// It asks the search provider of the app through the API of the unified search of Nextcloud,
// instead of opening the search dialog with the term: no Nextcloud from 33 to 35 lets a script
// hand that dialog a term, and it searches Paperless only once its switch "Search connected
// services" is on, which it turns off with every page.
//
// Like settings.js, it needs no build: it registers the action in the registry of file actions
// of @nextcloud/files 4, which Files of Nextcloud 33 to 35 reads, as registerFileAction() of
// that library does. docs/decisions/0020-similar-documents-from-files.md says why.
(function() {
	'use strict'

	const APP = 'paperless_unified_search'
	// The ID of the search provider of the app, PaperlessSearchProvider::ID.
	const PROVIDER = APP + '_documents'
	// The results of a page, as many as the search of Nextcloud asks for with "Load more results".
	const PAGE = 10
	const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.5 3a6.5 6.5 0 0 1 5.25 10.33l5.46 5.46-1.42 1.42-5.46-5.46A6.5 6.5 0 1 1 9.5 3zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9zM8 8h3v1H8V8zm0 2h3v1H8v-1z"/></svg>'
	const CLOSE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.4 5 12 10.6 17.6 5 19 6.4 13.4 12 19 17.6 17.6 19 12 13.4 6.4 19 5 17.6 10.6 12 5 6.4z"/></svg>'

	// The translation of Nextcloud. The texts end up as textContent, so the values of
	// {placeholders} stay unescaped.
	function translate(text, vars) {
		return window.t(APP, text, vars, undefined, { escape: false })
	}

	// The ID of the document of Paperless that the name names with its marker, such as 412 for
	// "2026-03-01 - Invoice [P412].pdf", the last one when it has several, or null. The search
	// takes an ID without a leading zero alone, as Paperless gives them.
	function documentId(name) {
		const markers = [...String(name).matchAll(/\[P([1-9][0-9]{0,17})\]/g)]
		return markers.length === 0 ? null : markers[markers.length - 1][1]
	}

	function element(tag, className, text = '') {
		const node = document.createElement(tag)
		node.className = className
		node.textContent = text
		return node
	}

	// An address of a result that the dialog may link or show: one of the web, as the provider
	// gives them, and never one such as javascript:, whatever an answer holds.
	function webAddress(address) {
		if (typeof address !== 'string' || address === '') {
			return null
		}
		try {
			const url = new URL(address, window.location.href)
			return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null
		} catch {
			return null
		}
	}

	// The message for an answer of Nextcloud that holds no page of results, as on the pages of
	// the settings: an expired session, maintenance mode, a pending upgrade, any other failure.
	function failureMessage(response) {
		if (response.status === 401 || response.status === 412) {
			return translate('Your session has expired. Reload the page and try again.')
		}
		if (response.status === 503) {
			return translate('Nextcloud is in maintenance mode, for example for an upgrade. Try again in a few minutes.')
		}
		if (response.ok) {
			return translate('Nextcloud gave an unexpected answer. Reload the page and try again.')
		}

		return translate('The request failed (HTTP {status}).', { status: response.status })
	}

	// A page of the documents similar to the one of the ID, from the search provider of the app,
	// with the cursor of the next page, or null when none follows. Throws the message to show.
	async function similarPage(id, cursor) {
		const parameters = new URLSearchParams({ term: 'like:' + id, limit: String(PAGE), from: '/apps/files/' })
		if (cursor !== null) {
			parameters.set('cursor', String(cursor))
		}
		let response
		try {
			response = await window.fetch(window.OC.linkToOCS('search/providers/' + PROVIDER, 2) + 'search?' + parameters, {
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'OCS-APIRequest': 'true',
					requesttoken: window.OC.requestToken,
				},
			})
		} catch {
			throw new Error(translate('Nextcloud could not be reached. Check your connection and try again.'))
		}
		let data = null
		try {
			data = response.ok ? (await response.json()).ocs.data : null
		} catch {
			// The HTML page of maintenance mode or of a proxy, which failureMessage() names.
		}
		if (data === null || typeof data !== 'object' || !Array.isArray(data.entries)) {
			throw new Error(failureMessage(response))
		}
		// The provider hands out a cursor also after a short page, when a later page of Paperless
		// failed or its time ran out, so a next page follows whenever there is a cursor.
		const more = data.isPaginated === true && data.cursor !== null && data.cursor !== undefined

		return { entries: data.entries, cursor: more ? data.cursor : null }
	}

	// A result as the search of Nextcloud shows it: the preview of the file, the title of the
	// document and the line below it, as a link that opens the file. Null for one without an
	// address that it may link.
	function resultItem(entry) {
		const address = webAddress(entry.resourceUrl)
		if (address === null) {
			return null
		}
		const item = document.createElement('li')
		const link = element('a', 'paperless-unified-search-similar__result')
		link.href = address
		const thumbnail = webAddress(entry.thumbnailUrl)
		if (thumbnail !== null) {
			const image = element('img', 'paperless-unified-search-similar__thumbnail')
			image.src = thumbnail
			image.alt = ''
			image.loading = 'lazy'
			link.append(image)
		}
		const text = element('span', 'paperless-unified-search-similar__text')
		text.append(
			element('span', 'paperless-unified-search-similar__title', String(entry.title ?? '')),
			element('span', 'paperless-unified-search-similar__subline', String(entry.subline ?? '')),
		)
		link.append(text)
		item.append(link)
		return item
	}

	// Opens the dialog for the file of the node and the document of the ID, and fills it with
	// the first page. The dialog is modal, so Files can't open a second one while it is open,
	// and it leaves the page as it closes.
	function showSimilar(node, id) {
		const dialog = element('dialog', 'paperless-unified-search-similar')
		dialog.id = 'paperless-unified-search-similar'
		dialog.setAttribute('aria-labelledby', 'paperless-unified-search-similar-heading')
		const header = element('div', 'paperless-unified-search-similar__header')
		const heading = element('h2', 'paperless-unified-search-similar__heading', translate('Documents similar to {file}', { file: node.basename }))
		heading.id = 'paperless-unified-search-similar-heading'
		const close = element('button', 'paperless-unified-search-similar__close')
		close.type = 'button'
		close.setAttribute('aria-label', translate('Close'))
		close.title = translate('Close')
		close.innerHTML = CLOSE_ICON
		header.append(heading, close)
		const list = element('ul', 'paperless-unified-search-similar__results')
		const status = element('p', 'paperless-unified-search-similar__status')
		status.setAttribute('role', 'status')
		const more = element('button', 'paperless-unified-search-similar__more', translate('Show more'))
		more.type = 'button'
		more.hidden = true
		dialog.append(header, list, status, more)

		let cursor = null
		let shown = 0
		// Loads a page below the ones before it. After a failure, the button tries the same page
		// again; after a click on it, the focus goes to the first new result, as the button may go.
		async function load(focus) {
			more.disabled = true
			status.dataset.kind = 'pending'
			status.textContent = translate('Looking for similar documents…')
			let page
			try {
				page = await similarPage(id, cursor)
			} catch (error) {
				status.dataset.kind = 'error'
				status.textContent = error.message
				more.textContent = translate('Try again')
				more.hidden = false
				more.disabled = false
				return
			}
			const items = page.entries.map(resultItem).filter((item) => item !== null)
			list.append(...items)
			shown += items.length
			cursor = page.cursor
			more.textContent = translate('Show more')
			more.hidden = cursor === null
			more.disabled = false
			status.dataset.kind = shown === 0 ? 'empty' : 'done'
			status.textContent = shown === 0 ? translate('Paperless found no similar document with a file that you can open.') : ''
			if (focus && items.length > 0) {
				items[0].firstChild.focus()
			}
		}

		close.addEventListener('click', () => dialog.close())
		more.addEventListener('click', () => load(true))
		dialog.addEventListener('close', () => dialog.remove())
		// The keys stay in the dialog: Files acts on keys anywhere on its page, such as Delete on
		// the file that it marks as the active one. Escape still closes the dialog, as the browser
		// does that by itself.
		dialog.addEventListener('keydown', (event) => event.stopPropagation())
		document.body.append(dialog)
		dialog.showModal()
		return load(false)
	}

	const action = {
		id: APP + '-similar',
		displayName: () => translate('Find similar documents in Paperless'),
		iconSvgInline: () => ICON,
		order: 62,
		// A single file with a marker, outside the trash bin, whose files the search doesn't find.
		enabled: (context) => context.view.id !== 'trashbin'
			&& context.nodes.length === 1
			&& context.nodes[0].type === 'file'
			&& documentId(context.nodes[0].basename) !== null,
		exec: async (context) => {
			await showSimilar(context.nodes[0], documentId(context.nodes[0].basename))
			return null
		},
	}

	// The registry of @nextcloud/files 4, which Files creates when this script was first, and the
	// event by which Files learns of an action that comes after it started.
	window._nc_files_scope = window._nc_files_scope || {}
	const scope = window._nc_files_scope.v4_0 = window._nc_files_scope.v4_0 || {}
	scope.fileActions = scope.fileActions || new Map()
	if (!scope.fileActions.has(action.id)) {
		scope.fileActions.set(action.id, action)
		if (scope.registry && typeof scope.registry.dispatchEvent === 'function') {
			scope.registry.dispatchEvent(new CustomEvent('register:action', { detail: action }))
		}
	}
})()
