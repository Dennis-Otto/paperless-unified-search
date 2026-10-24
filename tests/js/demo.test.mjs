/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/demo.mjs, with which screenshots.mjs records the animation of the demo of
// the README from the real Paperless-ngx and Nextcloud of demo/seed.sh: the moment of
// its clock, the times of the templates and the check of the Paperless results.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { DEMO, OPENED, RESULTS, demoClock, lastChange, templateTimes, unexpectedResults } from '../e2e/demo.mjs'

const answer = (...times) => `<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">${times.map((time, index) => `
<d:response><d:href>/remote.php/dav/files/jamie/Archive/${index}/</d:href>
<d:propstat><d:prop><d:getlastmodified>${time}</d:getlastmodified></d:prop>
<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>`).join('')}
</d:multistatus>`

test('the last change of the archive is the newest time of its answer', function() {
	const changed = lastChange(answer('Sat, 24 Oct 2026 18:53:28 GMT', 'Sat, 24 Oct 2026 18:53:29 GMT', 'Sat, 24 Oct 2026 18:52:59 GMT'))
	assert.equal(changed.toISOString(), '2026-10-24T18:53:29.000Z')
})

test('a time that is no date counts for nothing', function() {
	assert.equal(lastChange(answer('soon', 'Fri, 23 Oct 2026 08:00:00 GMT')).toISOString(), '2026-10-23T08:00:00.000Z')
})

test('an archive without a time of a change sends to seed.sh', function() {
	assert.throws(() => lastChange(answer()), /The archive of jamie has no time of a change: is the demo set up\? Run bash demo\/seed\.sh to its end\./)
	assert.throws(() => lastChange(answer('never')), /no time of a change/)
})

test('the clock starts two days after the last change, so that Files shows "2 days ago"', function() {
	const changed = new Date('2026-10-24T18:53:29Z')
	assert.equal(demoClock(changed).toISOString(), '2026-10-26T18:53:29.000Z')
})

test('the templates are an hour and two older than the archive, in seconds', function() {
	const changed = new Date('2026-10-24T18:53:29.750Z')
	const seconds = Math.floor(Date.parse('2026-10-24T18:53:29Z') / 1000)
	assert.deepEqual(templateTimes(changed), [
		['Templates/Invoice.ods', seconds - 3600],
		['Templates/Invoice.odt', seconds - 7200],
	])
})

test('the Paperless results of the demo pass, in any order', function() {
	assert.equal(unexpectedResults([
		'  Internet invoice - July 2026\n Jul 3, 2026 · Fiberlink · Bill — Fiberlink 22 Glass Lane',
		'Electricity invoice - August 2026 Aug 12, 2026 · Northstar Energy',
		'Home insurance renewal',
	]), '')
})

test('a document added to Paperless, or one missing, fails with the way to a fresh demo', function() {
	const problem = unexpectedResults([
		'Electricity invoice - August 2026 Aug 12, 2026',
		'Internet invoice - July 2026 Jul 3, 2026',
		`Water invoice - September 2026 ${'Sep 30, 2026 · Sampletown Water · Bill — '.repeat(3)}`,
	])
	assert.match(problem, /^The search for "invoice" shows jamie also "Water invoice - September 2026 Sep 30, 2026 · Sampletown Wa…" and not "Home insurance renewal" among the Paperless documents\. /)
	assert.match(problem, /docker compose -f demo\/compose\.yaml down --volumes && bash demo\/seed\.sh$/)
	assert.match(unexpectedResults(RESULTS.slice(1)), /shows jamie not "Electricity invoice - August 2026" among/)
	assert.match(unexpectedResults([...RESULTS, 'Home insurance renewals']), /shows jamie also "Home insurance renewals" among/)
})

test('the demo is the one of demo/seed.sh and its documents', function() {
	const seed = readFileSync(new URL('../../demo/seed.sh', import.meta.url), 'utf8')
	assert.match(seed, new RegExp(`^DEMO_USER="${DEMO.user}"$`, 'm'))
	assert.match(seed, new RegExp(`^TERM_OF_SEARCH="${DEMO.term}"$`, 'm'))
	assert.match(seed, new RegExp(`^EXPECTED_RESULTS=${RESULTS.length}$`, 'm'))
	const python = readFileSync(new URL('../../demo/seed.py', import.meta.url), 'utf8')
	assert.match(python, new RegExp(`^ARCHIVE_FOLDER = "${DEMO.folder}"$`, 'm'))
	const letters = ['electricity-invoice', 'internet-invoice', 'home-insurance-renewal']
		.map((name) => readFileSync(new URL(`../../demo/documents/${name}.txt`, import.meta.url), 'utf8'))
	const field = (letter, name) => letter.match(new RegExp(`^${name}: (.+)$`, 'm'))[1]
	assert.deepEqual(letters.map((letter) => field(letter, 'Title')), RESULTS)
	assert.ok(letters.some((letter) => field(letter, 'Correspondent') === DEMO.shows), `no document of ${DEMO.shows}`)
	const opened = letters.find((letter) => field(letter, 'Title') === OPENED.title)
	assert.equal(OPENED.file, `${field(opened, 'Created')} - ${OPENED.title}`)
	assert.doesNotMatch(OPENED.title, /invoice/i, 'the opened document is the one that only Paperless finds by its text')
	assert.match(opened, /invoice/i)
	assert.equal(DEMO.gif, 'demo-search-and-open.gif')
})
