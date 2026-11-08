/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Checks the translations of the app against its code, for every catalog in l10n/:
// each text that the code translates has a translation, none is left that it doesn't,
// a translation keeps the placeholders of its text, such as %s and {account}, a plural
// has as many forms as the "pluralForm" of its catalog names (and, where the third one is
// for millions, repeats the form for many in the middle, the only one besides the first
// that Nextcloud shows), the JavaScript catalog
// that the browser loads holds the same as the JSON one that PHP loads, and
// appinfo/info.xml gives the App Store a summary and a description in the language of
// each catalog. Chinese, in the catalogs and for the App Store, writes its dash as Chinese
// does, and Traditional Chinese the words of Taiwan, as the catalogs of Nextcloud for
// Taiwan have them. The texts come from the calls ->t('…') and ->n('…', '…') in lib/ and
// templates/, and translate('…') in js/, which must therefore name their texts
// literally.
//
//   node scripts/check-l10n.mjs              checks
//   node scripts/check-l10n.mjs --write      writes each l10n/*.js anew from its .json
//                                           and the dashes of Chinese as Chinese writes them
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

// Words of Simplified Chinese that slip into Traditional Chinese in its characters, with
// the words of Taiwan that take their place. The catalogs of Nextcloud for Taiwan,
// core/l10n/zh_TW.json and those of lib and of its apps, such as settings and files, have
// none of them and write the words on the right; those of Paperless come from
// src-ui/src/locale/messages.zh_TW.xlf of Paperless-ngx. Paperless Sync checks its
// Traditional Chinese with the same list, so that the two apps read as one.
const taiwanWords = {
	日誌: '記錄檔 for the log of Nextcloud or of a server, and 事件記錄 for the logs of Paperless',
	用戶: '使用者 for a user, and 客戶端 for a client, as Nextcloud writes them',
	賬號: '帳號',
	賬戶: '帳號',
	文件夾: '資料夾',
	文檔: '文件',
	默認: '預設',
	配置: '設定',
	服務器: '伺服器',
	網絡: '網路',
	鏈接: '連結',
	登錄: '登入',
	緩存: '快取',
	令牌: 'Token, as Paperless-ngx writes it',
	模板: '範本',
	導出: '匯出',
	端口: '連接埠',
	超時: '逾時',
	軟件: '軟體',
	信息: '資訊 or 訊息',
	數據: '資料',
	字段: '欄位',
	刷新: '重新整理',
	搜索: '搜尋',
	激活: '啟用',
	禁用: '停用',
	創建: '建立',
	獲取: '取得',
	回收站: '垃圾桶',
	會話: '工作階段',
	視頻: '影片',
	菜單: '選單',
	窗口: '視窗',
	屏幕: '螢幕',
	實時: '即時',
	兼容: '相容',
	字節: '位元組',
	調試: '除錯',
	打印: '列印',
	粘貼: '貼上',
	郵箱: '電子郵件',
	二維碼: 'QR code',
}

// Words of Taiwan for which the catalogs of Nextcloud for Taiwan write another word, with
// that word: the credentials manager of Nextcloud, which keeps the API token, is its
// 憑證管理器, as Paperless Sync names it too, and no catalog of Nextcloud writes 認證資訊.
const nextcloudWords = {
	認證資訊: '憑證 for credentials, as in 全域憑證 and 憑證管理器',
}

// What a Chinese text writes otherwise than Chinese does, with the line that holds it: a
// dash as one —, where Chinese writes two, ——, a space beside the dash, and in Traditional
// Chinese of Taiwan a word of Simplified Chinese or another word than Nextcloud's.
function chineseProblems(language, where, text) {
	const lineWith = (holds) => JSON.stringify(text.split('\n').find(holds).trim())
	const found = []
	const dash = /(?<!—)—(?!—)/
	if (dash.test(text)) {
		found.push(`${where} writes a dash as one —, but Chinese writes two, ——, without spaces around them: ${lineWith((line) => dash.test(line))}`)
	}
	const spacedDash = /[^\S\n]——|——[^\S\n]/
	if (spacedDash.test(text)) {
		found.push(`${where} writes a space beside the dash ——, but Chinese writes it without spaces around it: ${lineWith((line) => spacedDash.test(line))}`)
	}
	if (language === 'zh_TW') {
		for (const [word, taiwan] of Object.entries(taiwanWords)) {
			if (text.includes(word)) {
				found.push(`${where} writes ${word}, a word of Simplified Chinese, where Taiwan writes ${taiwan}: ${lineWith((line) => line.includes(word))}`)
			}
		}
		for (const [word, nextcloud] of Object.entries(nextcloudWords)) {
			if (text.includes(word)) {
				found.push(`${where} writes ${word}, where the catalogs of Nextcloud for Taiwan write ${nextcloud}: ${lineWith((line) => line.includes(word))}`)
			}
		}
	}
	return found
}

// A Chinese text with its dashes written as Chinese writes them: a lone — becomes two,
// ——, and a dash loses the spaces around it, the one fix of chineseProblems() that is
// certain; a word of Simplified Chinese can have more than one word of Taiwan, so a person
// picks it.
function chineseDashes(text) {
	return text.replace(/[^\S\n]*(?<!—)—(?!—)[^\S\n]*/g, '——').replace(/[^\S\n]*——[^\S\n]*/g, '——')
}

// With --write, the dashes of Chinese in the translations of the zh_* catalogs and in the
// Chinese texts of appinfo/info.xml as Chinese writes them, before the check reads them.
// The catalogs change only in their translations, never in a text of the code, and keep
// their layout.
function writeChineseDashes(catalogFiles) {
	for (const file of catalogFiles.filter((name) => name.startsWith('zh_'))) {
		const path = join(catalogFolder, file)
		const json = readFileSync(path, 'utf8')
		const fixed = json.replace(/"(?:[^"\\]|\\.)*"(\s*:)?/g, (string, key) => key === undefined ? chineseDashes(string) : string)
		if (fixed !== json) {
			writeFileSync(path, fixed)
		}
	}
	const infoPath = join(root, 'appinfo', 'info.xml')
	if (existsSync(infoPath)) {
		const xml = readFileSync(infoPath, 'utf8')
		const fixed = xml.replace(/(<(summary|description)\s+lang="zh-[^"]*"\s*>)([\s\S]*?)(<\/\2>)/g, (element, start, name, text, end) => `${start}${chineseDashes(text)}${end}`)
		if (fixed !== xml) {
			writeFileSync(infoPath, fixed)
		}
	}
}

// The summaries and the descriptions in appinfo/info.xml that have a text, each by its
// language, or null without the file. The App Store shows them to whoever speaks a
// language, and the English ones, without lang, to everyone else.
function storeTexts() {
	const path = join(root, 'appinfo', 'info.xml')
	if (!existsSync(path)) {
		return null
	}
	const byLanguage = { summary: new Map(), description: new Map() }
	for (const match of readFileSync(path, 'utf8').matchAll(/<(summary|description)\s+lang="([^"]+)"\s*>([\s\S]*?)<\/\1>/g)) {
		const text = match[3].replace(/^\s*<!\[CDATA\[([\s\S]*)\]\]>\s*$/, '$1').trim()
		if (text !== '') {
			byLanguage[match[1]].set(match[2], text)
		}
	}
	return byLanguage
}

// The code of the App Store for a language of Nextcloud, in the lang of <summary> and
// <description> of appinfo/info.xml: its own for the variants that the schema of the App
// Store, apps.nextcloud.com/schema/apps/info.xsd, lists, such as zh-hans for Simplified
// Chinese, and the language alone for every other one, such as de for de_DE, since the
// schema knows no other variant.
const storeVariants = {
	es_AR: 'es-ar',
	es_CO: 'es-co',
	es_MX: 'es-mx',
	es_NI: 'es-ni',
	es_VE: 'es-ve',
	pt_BR: 'pt-br',
	'sr@latin': 'sr-latn',
	zh_CN: 'zh-hans',
	zh_HK: 'zh-hant',
	zh_TW: 'zh-hant',
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

if (write) {
	writeChineseDashes(catalogs)
}
const texts = usedTexts()
const store = storeTexts()
if (store === null && catalogs.length > 0) {
	problems.push('appinfo/info.xml is missing, whose summary and description the App Store shows in the language of each catalog.')
}
for (const file of catalogs) {
	const name = `l10n/${file}`
	const javaScriptName = name.replace(/\.json$/, '.js')
	const javaScriptPath = join(root, javaScriptName)
	const catalog = JSON.parse(readFileSync(join(catalogFolder, file), 'utf8'))
	if (typeof catalog.translations !== 'object' || catalog.translations === null || typeof catalog.pluralForm !== 'string') {
		problems.push(`${name} needs an object "translations" and a string "pluralForm".`)
		continue
	}

	// A variant of a language, such as de_DE, shares the texts of the App Store of its
	// language, such as de, which the App Store knows alone; a variant that the App Store
	// knows, such as zh_CN, has them under the code of the App Store, zh-hans.
	const language = file.replace(/\.json$/, '')
	const storeLanguage = storeVariants[language] ?? language.split(/[_@]/)[0]
	for (const element of ['summary', 'description']) {
		if (store !== null && !store[element].has(storeLanguage)) {
			problems.push(`appinfo/info.xml has no <${element} lang="${storeLanguage}">, so the App Store shows its English ${element} to whoever reads the app in ${name}; add one.`)
		} else if (store !== null && language.startsWith('zh_')) {
			problems.push(...chineseProblems(language, `appinfo/info.xml, in its <${element} lang="${storeLanguage}">,`, store[element].get(storeLanguage)))
		}
	}
	const pluralForms = /^nplurals=(\d+);/.exec(catalog.pluralForm.replace(/\s/g, ''))
	if (pluralForms === null) {
		problems.push(`${name} has a "pluralForm" without the number of its forms, such as "nplurals=2; plural=(n != 1);".`)
	}
	const millions = catalog.pluralForm.replace(/\s/g, '').includes('n%1000000==0')

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
		const plural = /^_.*_::_.*_$/s.test(text)
		const count = Array.isArray(translation) ? translation.length : 1
		if (plural && pluralForms !== null && count !== Number(pluralForms[1])) {
			problems.push(`${name} translates ${JSON.stringify(text)} with ${count} form(s) instead of the ${pluralForms[1]} of its "pluralForm".`)
		}
		// Nextcloud picks a plural form by its own rules, not by "pluralForm": for a
		// language whose third form is the one for millions (French, Spanish, ...) only
		// form 0 (one) or 1 (other), so form 1 must be the "other" form, as in Nextcloud's
		// own catalogs.
		if (plural && millions && count === 3 && translation[1] !== translation[2]) {
			problems.push(`${name} translates ${JSON.stringify(text)} with a middle form ${JSON.stringify(translation[1])} that Nextcloud shows for every count but one, though it is not the form ${JSON.stringify(translation[2])} for many; repeat that form in the middle.`)
		}
		for (const [source, form] of forms(text, translation)) {
			if (typeof form !== 'string' || form.trim() === '') {
				problems.push(`${name} has an empty translation of ${JSON.stringify(text)}.`)
				continue
			}
			if (JSON.stringify(placeholders(form)) !== JSON.stringify(placeholders(source))) {
				problems.push(`${name} translates ${JSON.stringify(text)} with the placeholders ${placeholders(form).join(' ') || 'none'} instead of ${placeholders(source).join(' ') || 'none'}: ${JSON.stringify(form)}`)
			}
			// Chinese writes its dash, and Taiwan its words, as Nextcloud does there.
			if (language.startsWith('zh_')) {
				problems.push(...chineseProblems(language, name, form))
			}
		}
	}
}

if (problems.length > 0) {
	process.stderr.write(`${problems.map((problem) => `- ${problem}`).join('\n')}\n`)
	process.stderr.write(`The translations don't match the code, with ${problems.length} problem(s). Correct l10n/*.json, and appinfo/info.xml for the App Store, as each line says, then write the JavaScript catalogs anew: node scripts/check-l10n.mjs --write\n`)
	process.exit(1)
}

const languages = catalogs.map((file) => file.replace(/\.json$/, '')).join(', ')
process.stdout.write(`Each of the ${texts.size} texts of the code has a translation in ${languages}, with the same placeholders and the forms of its plurals, the JavaScript catalogs match the JSON ones, appinfo/info.xml has a summary and a description for the App Store in each language, and Chinese writes its dashes, and in Taiwan its words, as Nextcloud does.\n`)
