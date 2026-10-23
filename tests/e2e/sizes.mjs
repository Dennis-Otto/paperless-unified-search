// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The sizes of the screen on which flow.mjs goes through the search dialog. It has no
// dependency, so that tests/js/sizes.test.mjs tests the choice without a browser.

// The phone draws one pixel per CSS pixel: its layout is the same, and Chromium draws
// a quarter of the pixels of a screen with twice the density.
export const SIZES = [
	{ name: 'laptop', options: { viewport: { width: 1280, height: 800 } } },
	{ name: 'phone', options: { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } },
]

// The sizes that E2E_FLOW_SIZES names, such as "laptop" or "laptop phone", separated by
// spaces or commas, in the order of SIZES, and every size when it names none: run-all.sh
// tests the phone in a pull request only against the newest Nextcloud
// (E2E_PHONE=newest), where each size costs a version 16 to 19 seconds. A name that is
// no size fails, before the browser starts.
export function flowSizes(value) {
	const names = (value ?? '').split(/[\s,]+/).filter(Boolean)
	const unknown = names.filter((name) => !SIZES.some((size) => size.name === name))
	if (unknown.length > 0) {
		throw new Error(`E2E_FLOW_SIZES names ${unknown.join(', ')}, but the sizes are ${SIZES.map((size) => size.name).join(', ')}.`)
	}
	return names.length === 0 ? SIZES : SIZES.filter((size) => names.includes(size.name))
}
