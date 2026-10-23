/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tests/e2e/sizes.mjs, which picks the sizes of the search dialog in flow.mjs from
// E2E_FLOW_SIZES, as run-all.sh sets it for each version of Nextcloud.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { SIZES, flowSizes } from '../e2e/sizes.mjs'

function names(sizes) {
	return sizes.map((size) => size.name)
}

test('every size without E2E_FLOW_SIZES', function() {
	assert.deepEqual(names(flowSizes(undefined)), ['laptop', 'phone'])
	assert.deepEqual(names(flowSizes('')), ['laptop', 'phone'])
	assert.deepEqual(names(flowSizes('  ')), ['laptop', 'phone'])
})

test('only the sizes it names', function() {
	assert.deepEqual(names(flowSizes('laptop')), ['laptop'])
	assert.deepEqual(names(flowSizes('phone')), ['phone'])
})

test('names apart by spaces or commas, in the order of SIZES', function() {
	assert.deepEqual(names(flowSizes('laptop phone')), ['laptop', 'phone'])
	assert.deepEqual(names(flowSizes('laptop,phone')), ['laptop', 'phone'])
	assert.deepEqual(names(flowSizes('phone, laptop')), ['laptop', 'phone'])
})

test('the sizes are the objects of SIZES', function() {
	assert.equal(flowSizes('phone')[0], SIZES[1])
})

test('a name that is no size fails', function() {
	assert.throws(() => flowSizes('laptop tablet'), {
		message: 'E2E_FLOW_SIZES names tablet, but the sizes are laptop, phone.',
	})
})
