/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// scripts/check-l10n.mjs, run against small apps in temporary folders: it passes for
// catalogs that match the code and names each kind of mismatch.

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { afterEach, test } from 'node:test'
import { fileURLToPath } from 'node:url'

const script = fileURLToPath(new URL('../../scripts/check-l10n.mjs', import.meta.url))
const folders = []

afterEach(function() {
	for (const folder of folders.splice(0)) {
		rmSync(folder, { recursive: true, force: true })
	}
})

// An app with a template that translates "Paperless URL", the files $files by path, and
// a JSON catalog for each language of $catalogs.
function app(files, catalogs = { de: { 'Paperless URL': 'Paperless-URL' } }) {
	const root = mkdtempSync(join(tmpdir(), 'check-l10n-'))
	folders.push(root)
	const all = { 'templates/settings.php': '<?php p($l->t(\'Paperless URL\'));', ...files }
	for (const [language, translations] of Object.entries(catalogs)) {
		all[`l10n/${language}.json`] = `${JSON.stringify({ translations, pluralForm: 'nplurals=2; plural=(n != 1);' }, null, '\t')}\n`
	}
	for (const [path, content] of Object.entries(all)) {
		if (content !== null) {
			mkdirSync(dirname(join(root, path)), { recursive: true })
			writeFileSync(join(root, path), content)
		}
	}
	return root
}

function check(root, ...options) {
	const result = spawnSync(process.execPath, [script, `--root=${root}`, ...options], { encoding: 'utf8' })
	return { status: result.status, output: result.stdout + result.stderr }
}

// The app with its JavaScript catalogs written, which leaves the other problems.
function written(root) {
	check(root, '--write')
	return root
}

test('passes when every text of the code has its translation', function() {
	const root = written(app({
		'lib/Controller/SettingsController.php': '<?php $this->l10n->t(\'Paperless rejected the API token (HTTP %s).\', [$status]); $this->l10n->t("Say \\"hi\\"");',
		'js/settings.js': 'function translate(text, vars) {}\ntranslate(\'The archive account {account} does not exist.\', { account })\nwindow.t(\'paperless_unified_search\', \'Can\\\'t\')',
	}, {
		de: {
			'Paperless URL': 'Paperless-URL',
			'Paperless rejected the API token (HTTP %s).': 'Paperless hat das API-Token abgelehnt (HTTP %s).',
			'Say "hi"': 'Sag „hallo“',
			'The archive account {account} does not exist.': 'Das Archiv-Konto {account} existiert nicht.',
			'Can\'t': 'Geht nicht',
		},
	}))

	const result = check(root)

	assert.equal(result.status, 0, result.output)
	assert.match(result.output, /Each of the 5 texts of the code has a translation in de/)
})

test('fails on a text that a catalog lacks, and names where the code uses it', function() {
	const root = written(app({ 'lib/AdminSection.php': '<?php\n\n$this->l10n->t(\'New string\');' }))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/de\.json has no translation of "New string", which lib\/AdminSection\.php:3 translates/)
})

test('fails on a translation that no code uses any more', function() {
	const root = written(app({}, { de: { 'Paperless URL': 'Paperless-URL', 'Gone': 'Weg' } }))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/de\.json translates "Gone", which no code translates any more/)
})

test('checks every catalog, such as the formal German of de_DE', function() {
	const root = written(app({ 'templates/settings.php': '<?php p($l->t(\'Paperless Sync: %s\', [$account]));' }, {
		de: { 'Paperless Sync: %s': 'Paperless Sync: %s' },
		de_DE: { 'Paperless Sync: %s': 'Paperless Sync' },
	}))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/de_DE\.json translates "Paperless Sync: %s" with the placeholders none instead of %s/)
	assert.doesNotMatch(result.output, /l10n\/de\.json/)
})

test('fails on placeholders of another name', function() {
	const root = written(app({ 'js/settings.js': 'translate(\'The archive account {account} does not exist.\', { account })' }, {
		de: { 'Paperless URL': 'Paperless-URL', 'The archive account {account} does not exist.': 'Das Archiv-Konto {konto} existiert nicht.' },
	}))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /with the placeholders \{konto\} instead of \{account\}/)
})

test('fails on an empty translation', function() {
	const root = written(app({}, { de: { 'Paperless URL': ' ' } }))

	assert.match(check(root).output, /l10n\/de\.json has an empty translation of "Paperless URL"/)
})

test('fails on a call that translates no literal string', function() {
	const root = written(app({ 'lib/Controller/SettingsController.php': '<?php $this->l10n->t($message);' }))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /lib\/Controller\/SettingsController\.php:1 translates a text that isn't a literal string/)
})

test('accepts a call that wraps its text onto the next line', function() {
	const root = written(app({
		'lib/Controller/SettingsController.php': "<?php $this->l10n->t(\n\t'Paperless rejected the API token (HTTP %s).',\n\t[$status],\n);\n$l->n( '%n problem', '%n problems', $count);",
		'js/settings.js': "function translate(text, vars) {}\ntranslate(\n\t'The archive account {account} does not exist.',\n\t{ account },\n)",
	}, {
		de: {
			'Paperless URL': 'Paperless-URL',
			'Paperless rejected the API token (HTTP %s).': 'Paperless hat das API-Token abgelehnt (HTTP %s).',
			'_%n problem_::_%n problems_': ['%n Problem', '%n Probleme'],
			'The archive account {account} does not exist.': 'Das Archiv-Konto {account} existiert nicht.',
		},
	}))

	const result = check(root)

	assert.equal(result.status, 0, result.output)
	assert.match(result.output, /Each of the 4 texts of the code has a translation in de/)
})

test('checks plurals form by form', function() {
	const root = written(app({ 'lib/Settings/AdminSettings.php': '<?php $l->n(\'%n problem\', \'%n problems\', $count);' }, {
		de: { 'Paperless URL': 'Paperless-URL', '_%n problem_::_%n problems_': ['%n Problem', 'Probleme'] },
	}))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /translates "_%n problem_::_%n problems_" with the placeholders none instead of %n: "Probleme"/)
	assert.doesNotMatch(result.output, /"%n Problem"/)
})

test('fails until the JavaScript catalog holds the same as the JSON one, which --write repairs', function() {
	const root = written(app({}))
	writeFileSync(join(root, 'l10n/de.json'), `${JSON.stringify({ translations: { 'Paperless URL': 'URL von Paperless' }, pluralForm: 'nplurals=2; plural=(n != 1);' }, null, '\t')}\n`)

	const stale = check(root)
	assert.equal(stale.status, 1)
	assert.match(stale.output, /l10n\/de\.js doesn't hold the translations of l10n\/de\.json; write it anew with: node scripts\/check-l10n\.mjs --write/)

	assert.equal(check(root, '--write').status, 0)
	assert.equal(check(root).status, 0)
	assert.equal(readFileSync(join(root, 'l10n/de.js'), 'utf8'), 'OC.L10N.register(\n\t\'paperless_unified_search\',\n\t{\n\t\t\'Paperless URL\': \'URL von Paperless\',\n\t},\n\t\'nplurals=2; plural=(n != 1);\')\n')
})

test('fails on a missing JavaScript catalog, and on one without its JSON catalog', function() {
	const root = app({ 'l10n/fr.js': 'OC.L10N.register(\'paperless_unified_search\', {}, \'\')' })

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/de\.js, which the browser loads, is missing/)
	assert.match(result.output, /l10n\/fr\.js has no l10n\/fr\.json/)
})

test('fails without any catalog', function() {
	const root = app({}, {})

	assert.match(check(root).output, /l10n\/ holds no catalog/)
})

test('the catalogs of the app match its code', function() {
	const result = spawnSync(process.execPath, [script], { encoding: 'utf8' })

	assert.equal(result.status, 0, result.stdout + result.stderr)
	assert.match(result.stdout, /in de, de_DE,/)
})
