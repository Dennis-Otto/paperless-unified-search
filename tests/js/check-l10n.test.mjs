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

// The appinfo/info.xml of an app, with a summary and a description for the App Store in
// English and in each language of $languages.
function info(...languages) {
	const texts = languages.map((language) => `\t<summary lang="${language}">Summary</summary>\n\t<description lang="${language}"><![CDATA[\nDescription\n]]></description>\n`)
	return `<?xml version="1.0"?>\n<info>\n\t<summary>Summary</summary>\n\t<description><![CDATA[\nDescription\n]]></description>\n${texts.join('')}</info>\n`
}

// An app with a template that translates "Paperless URL", the texts of the App Store in
// German, the files $files by path, of which null leaves one out, and a JSON catalog for
// each language of $catalogs.
function app(files, catalogs = { de: { 'Paperless URL': 'Paperless-URL' } }) {
	const root = mkdtempSync(join(tmpdir(), 'check-l10n-'))
	folders.push(root)
	const all = { 'templates/settings.php': '<?php p($l->t(\'Paperless URL\'));', 'appinfo/info.xml': info('de'), ...files }
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

test('fails on a plural with another number of forms than its "pluralForm" names', function() {
	const root = app({ 'lib/Settings/AdminSettings.php': '<?php $l->n(\'%n problem\', \'%n problems\', $count);', 'appinfo/info.xml': info('fr') }, {})
	mkdirSync(join(root, 'l10n'))
	writeFileSync(join(root, 'l10n/fr.json'), JSON.stringify({
		translations: { 'Paperless URL': 'URL de Paperless', '_%n problem_::_%n problems_': ['%n problème', '%n problèmes'] },
		pluralForm: 'nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
	}))
	written(root)

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/fr\.json translates "_%n problem_::_%n problems_" with 2 form\(s\) instead of the 3 of its "pluralForm"/)
})

test('fails on a middle plural form for millions, which Nextcloud would show for every count above one', function() {
	const root = app({ 'lib/Settings/AdminSettings.php': '<?php $l->n(\'%n user\', \'%n users\', $count);', 'appinfo/info.xml': info('fr') }, {})
	mkdirSync(join(root, 'l10n'))
	const catalog = (forms) => JSON.stringify({
		translations: { 'Paperless URL': 'URL de Paperless', '_%n user_::_%n users_': forms },
		pluralForm: 'nplurals=3; plural=(n == 0 || n == 1) ? 0 : n != 0 && n % 1000000 == 0 ? 1 : 2;',
	})
	writeFileSync(join(root, 'l10n/fr.json'), catalog(['%n utilisateur', '%n d’utilisateurs', '%n utilisateurs']))
	written(root)

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/fr\.json translates "_%n user_::_%n users_" with a middle form "%n d’utilisateurs" that Nextcloud shows for every count but one/)

	writeFileSync(join(root, 'l10n/fr.json'), catalog(['%n utilisateur', '%n utilisateurs', '%n utilisateurs']))
	written(root)
	assert.equal(check(root).status, 0)
})

test('fails on a "pluralForm" that names no number of forms', function() {
	const root = app({})
	writeFileSync(join(root, 'l10n/de.json'), JSON.stringify({ translations: { 'Paperless URL': 'Paperless-URL' }, pluralForm: 'plural=(n != 1);' }))
	written(root)

	assert.match(check(root).output, /l10n\/de\.json has a "pluralForm" without the number of its forms/)
})

test('fails on a language without a summary or a description for the App Store', function() {
	const root = written(app({}, {
		de: { 'Paperless URL': 'Paperless-URL' },
		fr: { 'Paperless URL': 'URL de Paperless' },
	}))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /appinfo\/info\.xml has no <summary lang="fr">, so the App Store shows its English summary to whoever reads the app in l10n\/fr\.json/)
	assert.match(result.output, /appinfo\/info\.xml has no <description lang="fr">/)
	assert.doesNotMatch(result.output, /lang="de"/)
})

test('takes the texts of the App Store of a language for its variants, but not an empty one', function() {
	const root = written(app({
		'appinfo/info.xml': info('de').replace('<description lang="de"><![CDATA[\nDescription\n]]>', '<description lang="de"><![CDATA[ ]]>'),
	}, {
		de_DE: { 'Paperless URL': 'Paperless-URL' },
	}))

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /appinfo\/info\.xml has no <description lang="de">, so the App Store shows its English description to whoever reads the app in l10n\/de_DE\.json/)
	assert.doesNotMatch(result.output, /<summary/)
})

test('takes the code of the App Store for a variant that its schema lists, such as zh-hans for zh_CN', function() {
	const catalogs = { zh_CN: { 'Paperless URL': 'Paperless URL' } }

	assert.equal(check(written(app({ 'appinfo/info.xml': info('zh-hans') }, catalogs))).status, 0)
	// The schema of the App Store knows neither the code of Nextcloud nor the language alone.
	for (const language of ['zh_CN', 'zh']) {
		const result = check(written(app({ 'appinfo/info.xml': info(language) }, catalogs)))

		assert.equal(result.status, 1)
		assert.match(result.output, /appinfo\/info\.xml has no <summary lang="zh-hans">, so the App Store shows its English summary to whoever reads the app in l10n\/zh_CN\.json/)
		assert.match(result.output, /appinfo\/info\.xml has no <description lang="zh-hans">/)
	}
})

test('takes zh-hant for Traditional Chinese, zh_TW, which shares no code of the App Store with Simplified Chinese', function() {
	const catalogs = { zh_TW: { 'Paperless URL': 'Paperless URL' } }

	assert.equal(check(written(app({ 'appinfo/info.xml': info('zh-hant') }, catalogs))).status, 0)
	// zh-hans is the code of Simplified Chinese: Taiwan reading it would get the English texts.
	for (const language of ['zh-hans', 'zh_TW', 'zh']) {
		const result = check(written(app({ 'appinfo/info.xml': info(language) }, catalogs)))

		assert.equal(result.status, 1)
		assert.match(result.output, /appinfo\/info\.xml has no <summary lang="zh-hant">, so the App Store shows its English summary to whoever reads the app in l10n\/zh_TW\.json/)
		assert.match(result.output, /appinfo\/info\.xml has no <description lang="zh-hant">/)
	}
})

// An app whose settings translate $texts, with a catalog of one form for every count for
// each language of $catalogs and the texts of the App Store in Chinese, of which $store
// replaces a summary or a description by its lang and element, such as
// { 'zh-hant description': '…' }, with its JavaScript catalogs written unless $javaScript
// is false, since --write also writes the dashes of Chinese anew.
function chineseApp(texts, catalogs, store = {}, javaScript = true) {
	let xml = info('zh-hans', 'zh-hant')
	for (const [where, text] of Object.entries(store)) {
		const [language, element] = where.split(' ')
		xml = element === 'summary'
			? xml.replace(`<summary lang="${language}">Summary`, `<summary lang="${language}">${text}`)
			: xml.replace(`<description lang="${language}"><![CDATA[\nDescription`, `<description lang="${language}"><![CDATA[\n${text}`)
	}
	const root = app({
		'templates/settings.php': `<?php ${texts.map((text) => Array.isArray(text) ? `$l->n('${text[0]}', '${text[1]}', $count);` : `p($l->t('${text}'));`).join(' ')}`,
		'appinfo/info.xml': xml,
	}, {})
	mkdirSync(join(root, 'l10n'))
	for (const [language, translations] of Object.entries(catalogs)) {
		writeFileSync(join(root, `l10n/${language}.json`), JSON.stringify({ translations, pluralForm: 'nplurals=1; plural=0;' }))
	}
	return javaScript ? written(root) : root
}

test('fails on a dash of Chinese written as one —, or with a space beside ——', function() {
	const root = chineseApp(['Configured — leave blank to keep it'], {
		zh_CN: { 'Configured — leave blank to keep it': '已配置 — 留空则保持不变' },
		zh_TW: { 'Configured — leave blank to keep it': '已設定 —— 留空即保持不變' },
	}, {}, false)

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/zh_CN\.json writes a dash as one —, but Chinese writes two, ——, without spaces around them: "已配置 — 留空则保持不变"/)
	assert.match(result.output, /l10n\/zh_TW\.json writes a space beside the dash ——, but Chinese writes it without spaces around it: "已設定 —— 留空即保持不變"/)
	assert.doesNotMatch(result.output, /zh_CN\.json writes a space/)
	assert.doesNotMatch(result.output, /zh_TW\.json writes a dash as one/)
})

test('writes the dashes of Chinese anew with --write, in the translations and for the App Store, but not in a text of the code', function() {
	const root = chineseApp(['Configured — leave blank to keep it'], {
		zh_CN: { 'Configured — leave blank to keep it': '已配置 — 留空则保持不变' },
		zh_TW: { 'Configured — leave blank to keep it': '已設定 —— 留空即保持不變' },
	}, { 'zh-hans summary': '在 Nextcloud 中搜索 Paperless — 并打开文件', 'zh-hant description': '搜尋 Paperless ——\n並開啟檔案' }, false)

	const result = check(root, '--write')

	assert.equal(result.status, 0, result.output)
	assert.deepEqual(JSON.parse(readFileSync(join(root, 'l10n/zh_CN.json'), 'utf8')).translations, { 'Configured — leave blank to keep it': '已配置——留空则保持不变' })
	assert.deepEqual(JSON.parse(readFileSync(join(root, 'l10n/zh_TW.json'), 'utf8')).translations, { 'Configured — leave blank to keep it': '已設定——留空即保持不變' })
	const xml = readFileSync(join(root, 'appinfo/info.xml'), 'utf8')
	assert.match(xml, /<summary lang="zh-hans">在 Nextcloud 中搜索 Paperless——并打开文件<\/summary>/)
	assert.match(xml, /搜尋 Paperless——\n並開啟檔案/)
	assert.match(xml, /<summary>Summary<\/summary>/)
})

test('fails on a word of Simplified Chinese in Traditional Chinese, in a text and in each form of a plural, with the word of Taiwan', function() {
	const root = chineseApp(['Check the log of Paperless.', ['%n new document', '%n new documents']], {
		zh_TW: {
			'Check the log of Paperless.': '請檢查 Paperless 的日誌。',
			'_%n new document_::_%n new documents_': ['%n 個新文檔'],
		},
	})

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /l10n\/zh_TW\.json writes 日誌, a word of Simplified Chinese, where Taiwan writes 記錄檔 for the log of Nextcloud or of a server, and 事件記錄 for the logs of Paperless: "請檢查 Paperless 的日誌。"/)
	assert.match(result.output, /l10n\/zh_TW\.json writes 文檔, a word of Simplified Chinese, where Taiwan writes 文件: "%n 個新文檔"/)
})

test('leaves the words of Simplified Chinese, such as 配置 and 搜索, to Simplified Chinese', function() {
	const root = chineseApp(['Configured — leave blank to keep it'], {
		zh_CN: { 'Configured — leave blank to keep it': '已配置——留空则保持不变，搜索照常' },
		zh_TW: { 'Configured — leave blank to keep it': '已設定——留空即保持不變，搜尋照常' },
	})

	const result = check(root)

	assert.equal(result.status, 0, result.output)
	assert.match(result.output, /and Chinese writes its dashes, and in Taiwan its words, as Nextcloud does/)
})

test('reads the Chinese of the App Store as well, with its dashes and the words of Taiwan and of Nextcloud', function() {
	const root = chineseApp([], { zh_CN: {}, zh_TW: {} }, {
		'zh-hans summary': '在 Nextcloud 中搜索 Paperless — 并打开文件',
		'zh-hant description': '- 只向有權開啟其已同步檔案的用戶顯示文件\n\nAPI Token 儲存在 Nextcloud 加密的認證資訊管理器中。',
	}, false)

	const result = check(root)

	assert.equal(result.status, 1)
	assert.match(result.output, /appinfo\/info\.xml, in its <summary lang="zh-hans">, writes a dash as one —, but Chinese writes two, ——, without spaces around them: "在 Nextcloud 中搜索 Paperless — 并打开文件"/)
	assert.match(result.output, /appinfo\/info\.xml, in its <description lang="zh-hant">, writes 用戶, a word of Simplified Chinese, where Taiwan writes 使用者 for a user, and 客戶端 for a client, as Nextcloud writes them: "- 只向有權開啟其已同步檔案的用戶顯示文件"/)
	assert.match(result.output, /appinfo\/info\.xml, in its <description lang="zh-hant">, writes 認證資訊, where the catalogs of Nextcloud for Taiwan write 憑證 for credentials, as in 全域憑證 and 憑證管理器: "API Token 儲存在 Nextcloud 加密的認證資訊管理器中。"/)
	assert.doesNotMatch(result.output, /lang="zh-hans">, writes 搜索/)
})

test('fails without appinfo/info.xml', function() {
	const root = written(app({ 'appinfo/info.xml': null }))

	assert.match(check(root).output, /appinfo\/info\.xml is missing, whose summary and description the App Store shows/)
})

test('the catalogs of the app match its code', function() {
	const result = spawnSync(process.execPath, [script], { encoding: 'utf8' })

	assert.equal(result.status, 0, result.stdout + result.stderr)
	assert.match(result.stdout, /in de, de_DE, es, fr, nl,/)
})
