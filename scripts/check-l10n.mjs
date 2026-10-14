/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Checks the translations of the app against its code, for every catalog in l10n/:
// each text that the code translates has a translation, none is left that it doesn't,
// a translation keeps the placeholders of its text, such as %s and {account}, and
// the JavaScript catalog that the browser loads holds the same as the JSON one that
// PHP loads. The texts come from the calls ->t('…') and ->n('…', '…') in lib/ and
// templates/, and translate('…') in js/, which must therefore name their texts
// literally.
//
//   node scripts/check-l10n.mjs              checks
//   node scripts/check-l10n.mjs --write      writes each l10n/*.js anew from its .json
//   node scripts/check-l10n.mjs --root=DIR   checks the app in DIR, as the tests do

import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { runInNewContext } from 'node:vm'

const appId = 'paperless_unified_search'
const rootArgument = process.argv.find((argument) => argument.startsWith('--root='))
const root = rootArgument === undefined ? fileURLToPath(new URL('..', import.meta.url)) : resolve(rootArgument.slice('--root='.length))
const catalogFolder = join(root, 'l10n')
const write = process.argv.includes('--write')
const problems = []

// A string literal in single or double quotes, and two of them for a plural.
const literal = String.raw`(?:'(?<single>(?:[^'\\]|\\.)*)'|"(?<double>(?:[^"\\]|\\.)*)")`
const literalPair = String.raw`${literal}\s*,\s*(?:'(?<pluralSingle>(?:[^'\\]|\\.)*)'|"(?<pluralDouble>(?:[^"\\]|\\.)*)")`
const sources = [
	{
		folders: ['lib', 'templates'],
		extension: '.php',
		language: 'php',
		calls: [
			{ pattern: new RegExp(String.raw`->t\(\s*${literal}`, 'g'), plural: false },
			{ pattern: new RegExp(String.raw`->n\(\s*${literalPair}`, 'g'), plural: true },
		],
		nonLiteral: /->[tn]\((?!\s*['"])/g,
	},
	{
		folders: ['js'],
		extension: '.js',
		language: 'javascript',
		calls: [
			{ pattern: new RegExp(String.raw`(?<!function\s+)\btranslate\(\s*${literal}`, 'g'), plural: false },
			{ pattern: new RegExp(String.raw`\bt\(\s*['"]${appId}['"]\s*,\s*${literal}`, 'g'), plural: false },
			{ pattern: new RegExp(String.raw`\bn\(\s*['"]${appId}['"]\s*,\s*${literalPair}`, 'g'), plural: true },
		],
		nonLiteral: /(?<!function\s+)\btranslate\((?!\s*['"])/g,
	},
]

// The text of a string literal as the code means it. In single quotes, PHP knows only
// the escapes \\ and \'; in double quotes and in JavaScript, a backslash escapes any
// character.
function unescape(text, language, quote) {
	if (language === 'php' && quote === 'single') {
		return text.replace(/\\([\\'])/g, '$1')
	}
	const escapes = { n: '\n', r: '\r', t: '\t' }
	return text.replace(/\\(u\{[0-9a-fA-F]+\}|u[0-9a-fA-F]{4}|.)/gs, function(sequence, escaped) {
		if (escaped.length > 1) {
			return String.fromCodePoint(parseInt(escaped.replace(/[u{}]/g, ''), 16))
		}
		return escapes[escaped] ?? escaped
	})
}

function lineOf(text, index) {
	return text.slice(0, index).split('\n').length
}

function filesIn(folder, extension) {
	const path = join(root, folder)
	if (!existsSync(path)) {
		return []
	}
	return readdirSync(path, { recursive: true, encoding: 'utf8' })
		.filter((file) => file.endsWith(extension))
		.sort()
		.map((file) => join(path, file))
}

// The texts that the code translates, each with the first place that uses it. The key
// of a plural is the one of Nextcloud's catalogs: _singular_::_plural_.
function usedTexts() {
	const texts = new Map()
	for (const source of sources) {
		for (const folder of source.folders) {
			for (const file of filesIn(folder, source.extension)) {
				const code = readFileSync(file, 'utf8')
				const place = (index) => `${relative(root, file).replaceAll('\\', '/')}:${lineOf(code, index)}`
				for (const call of source.calls) {
					for (const match of code.matchAll(call.pattern)) {
						const { single, double, pluralSingle, pluralDouble } = match.groups
						const text = unescape(single ?? double, source.language, single === undefined ? 'double' : 'single')
						const key = call.plural
							? `_${text}_::_${unescape(pluralSingle ?? pluralDouble, source.language, pluralSingle === undefined ? 'double' : 'single')}_`
							: text
						if (!texts.has(key)) {
							texts.set(key, place(match.index))
						}
					}
				}
				for (const match of code.matchAll(source.nonLiteral)) {
					problems.push(`${place(match.index)} translates a text that isn't a literal string, which no catalog can be checked for; write the text itself into the call.`)
				}
			}
		}
	}
	return texts
}

// The placeholders of a text, sorted: %s, %1$s, %n and {name}.
function placeholders(text) {
	return (text.match(/%(?:\d+\$)?[sdn]|\{\w+\}/g) ?? []).sort()
}

function quote(text) {
	return `'${text.replaceAll('\\', '\\\\').replaceAll('\'', '\\\'').replaceAll('\n', '\\n')}'`
}

// The JavaScript catalog of a JSON one, as Nextcloud loads it in the browser.
function javaScriptCatalog(catalog) {
	const entries = Object.entries(catalog.translations).map(function([text, translation]) {
		const value = Array.isArray(translation) ? `[${translation.map(quote).join(', ')}]` : quote(translation)
		return `\t\t${quote(text)}: ${value},\n`
	})
	return `OC.L10N.register(\n\t${quote(appId)},\n\t{\n${entries.join('')}\t},\n\t${quote(catalog.pluralForm)})\n`
}

// What a JavaScript catalog registers, or null when it registers nothing.
function registered(path, name) {
	let catalog = null
	runInNewContext(readFileSync(path, 'utf8'), {
		OC: {
			L10N: {
				register(id, translations, pluralForm) {
					if (id !== appId) {
						throw new Error(`${name} registers the translations of ${id} instead of ${appId}.`)
					}
					catalog = { translations, pluralForm }
				},
			},
		},
	})
	return catalog
}

// Each form of a translation, with the text whose placeholders it keeps: the singular
// for the first form of a plural, the plural for the others.
function forms(text, translation) {
	if (!Array.isArray(translation)) {
		return [[text, translation]]
	}
	const plural = /^_(.*)_::_(.*)_$/s.exec(text)
	return translation.map((form, index) => [plural === null ? text : plural[index === 0 ? 1 : 2], form])
}

const files = existsSync(catalogFolder) ? readdirSync(catalogFolder) : []
const catalogs = files.filter((file) => file.endsWith('.json')).sort()
for (const file of files.filter((name) => name.endsWith('.js'))) {
	const json = file.replace(/\.js$/, '.json')
	if (!catalogs.includes(json)) {
		problems.push(`l10n/${file} has no l10n/${json}, which PHP loads; add it, or delete l10n/${file}.`)
	}
}
if (catalogs.length === 0) {
	problems.push('l10n/ holds no catalog.')
}

const texts = usedTexts()
for (const file of catalogs) {
	const name = `l10n/${file}`
	const javaScriptName = name.replace(/\.json$/, '.js')
	const javaScriptPath = join(root, javaScriptName)
	const catalog = JSON.parse(readFileSync(join(catalogFolder, file), 'utf8'))
	if (typeof catalog.translations !== 'object' || catalog.translations === null || typeof catalog.pluralForm !== 'string') {
		problems.push(`${name} needs an object "translations" and a string "pluralForm".`)
		continue
	}

	if (write) {
		writeFileSync(javaScriptPath, javaScriptCatalog(catalog))
	}
	if (!existsSync(javaScriptPath)) {
		problems.push(`${javaScriptName}, which the browser loads, is missing; write it with: node scripts/check-l10n.mjs --write`)
	} else if (JSON.stringify(registered(javaScriptPath, javaScriptName)) !== JSON.stringify(catalog)) {
		problems.push(`${javaScriptName} doesn't hold the translations of ${name}; write it anew with: node scripts/check-l10n.mjs --write`)
	}

	for (const [text, place] of texts) {
		if (!Object.hasOwn(catalog.translations, text)) {
			problems.push(`${name} has no translation of ${JSON.stringify(text)}, which ${place} translates; add one.`)
		}
	}
	for (const [text, translation] of Object.entries(catalog.translations)) {
		if (!texts.has(text)) {
			problems.push(`${name} translates ${JSON.stringify(text)}, which no code translates any more; remove it.`)
		}
		for (const [source, form] of forms(text, translation)) {
			if (typeof form !== 'string' || form.trim() === '') {
				problems.push(`${name} has an empty translation of ${JSON.stringify(text)}.`)
			} else if (JSON.stringify(placeholders(form)) !== JSON.stringify(placeholders(source))) {
				problems.push(`${name} translates ${JSON.stringify(text)} with the placeholders ${placeholders(form).join(' ') || 'none'} instead of ${placeholders(source).join(' ') || 'none'}: ${JSON.stringify(form)}`)
			}
		}
	}
}

if (problems.length > 0) {
	process.stderr.write(`${problems.map((problem) => `- ${problem}`).join('\n')}\n`)
	process.stderr.write(`The translations don't match the code, with ${problems.length} problem(s). Correct l10n/*.json, then write the JavaScript catalogs anew: node scripts/check-l10n.mjs --write\n`)
	process.exit(1)
}

const languages = catalogs.map((file) => file.replace(/\.json$/, '')).join(', ')
process.stdout.write(`Each of the ${texts.size} texts of the code has a translation in ${languages}, with the same placeholders, and the JavaScript catalogs match the JSON ones.\n`)
