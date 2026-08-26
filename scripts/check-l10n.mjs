/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const appId = 'paperless_unified_search'
const javaScriptPath = new URL('../l10n/de.js', import.meta.url)
const jsonPath = new URL('../l10n/de.json', import.meta.url)
let registered = null

runInNewContext(readFileSync(javaScriptPath, 'utf8'), {
	OC: {
		L10N: {
			register(id, translations, pluralForm) {
				if (id !== appId) {
					throw new Error(`Unexpected translation app ID: ${id}`)
				}
				registered = { translations, pluralForm }
			},
		},
	},
})

if (registered === null) {
	throw new Error('The JavaScript translation catalog did not register itself.')
}

const jsonCatalog = JSON.parse(readFileSync(jsonPath, 'utf8'))
if (JSON.stringify(registered) !== JSON.stringify(jsonCatalog)) {
	throw new Error('l10n/de.js and l10n/de.json must contain identical translations.')
}

process.stdout.write(`${Object.keys(jsonCatalog.translations).length} German translations are consistent.\n`)
