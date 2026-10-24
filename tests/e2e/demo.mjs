// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The animation of the demo of the README, screenshots/demo-search-and-open.gif, which
// `bash scripts/screenshots.sh --demo` records from the real Paperless-ngx and
// Nextcloud of `bash demo/seed.sh`: what it shows, and how it stays the same from one
// run to the next. It has no dependency, so that tests/js/demo.test.mjs tests it
// without a browser; screenshots.mjs records it.
//
// The demo has no fixed times: Paperless Sync writes the archive when seed.sh runs, and
// Nextcloud copies the templates of jamie at the first sign-in. So the clock of the
// pages starts two days after the last change of the archive, and Files shows "2 days
// ago" in every run, as in the other pictures; and the two templates that the search
// for "invoice" finds, which Nextcloud copies in the same second, get times an hour
// apart, before the archive, so that the search of Files, the newest first, lists them
// in the same order, after the documents.

// The reader of the demo, the folder that the archive account shares with them, a
// folder in it that shows that the archive is there, and the term of the search, as
// demo/seed.sh and demo/README.md name them.
export const DEMO = {
	user: 'jamie',
	folder: 'Archive',
	shows: 'Northstar Energy',
	term: 'invoice',
	gif: 'demo-search-and-open.gif',
}

// The documents of demo/documents that Paperless finds for the term, by their titles:
// the two invoices, and the home insurance renewal, whose text says "invoice" while
// its title doesn't. The animation opens that one, by the name that Paperless Sync
// gives its file, the date of the document in front. They are in the order in which
// Paperless ranks them: the electricity invoice says the term twice, the internet
// invoice once, so that their scores differ and the order doesn't follow the order in
// which Paperless happened to read them.
export const RESULTS = ['Electricity invoice - August 2026', 'Internet invoice - July 2026', 'Home insurance renewal']
export const OPENED = { title: 'Home insurance renewal', file: '2026-07-21 - Home insurance renewal' }

const DAY = 24 * 60 * 60 * 1000

// The last change of the entries of a WebDAV PROPFIND answer with getlastmodified,
// such as the folders of the archive.
export function lastChange(answer) {
	const times = [...answer.matchAll(/<d:getlastmodified>([^<]+)<\/d:getlastmodified>/g)]
		.map((match) => Date.parse(match[1]))
		.filter((time) => !Number.isNaN(time))
	if (times.length === 0) {
		throw new Error(`The archive of ${DEMO.user} has no time of a change: is the demo set up? Run bash demo/seed.sh to its end.`)
	}
	return new Date(Math.max(...times))
}

// The moment of the pictures: two days after the last change of the archive.
export function demoClock(changed) {
	return new Date(changed.getTime() + 2 * DAY)
}

// The templates that the search for the term finds besides the archive, with their
// times in seconds since 1970: an hour and two before the last change of the archive.
export function templateTimes(changed) {
	const seconds = Math.floor(changed.getTime() / 1000)
	return [['Templates/Invoice.ods', seconds - 3600], ['Templates/Invoice.odt', seconds - 7200]]
}

// Why the Paperless results differ from those of the demo, or '' when they don't: a
// document added to Paperless, or one missing, would make another animation than the
// one that the README describes. Each result is the text of its link, which starts with
// the title of its document, followed by its date and its excerpt.
export function unexpectedResults(texts) {
	const shown = texts.map((text) => text.replace(/\s+/g, ' ').trim())
	const starts = (text, title) => text === title || text.startsWith(`${title} `)
	const extra = shown.filter((text) => !RESULTS.some((title) => starts(text, title)))
	const missing = RESULTS.filter((title) => !shown.some((text) => starts(text, title)))
	if (extra.length === 0 && missing.length === 0) {
		return ''
	}
	const quoted = (list) => list.map((text) => JSON.stringify(text.length > 60 ? `${text.slice(0, 59)}…` : text)).join(', ')
	const parts = [
		extra.length > 0 ? `also ${quoted(extra)}` : '',
		missing.length > 0 ? `not ${quoted(missing)}` : '',
	].filter(Boolean)
	return `The search for "${DEMO.term}" shows ${DEMO.user} ${parts.join(' and ')} among the Paperless documents. `
		+ 'Record the animation from a demo with its five documents alone: '
		+ 'docker compose -f demo/compose.yaml down --volumes && bash demo/seed.sh'
}
