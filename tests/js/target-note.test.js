/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'

import { targetNote } from '../../src/target-note.js'

// Stand in for @nextcloud/l10n: substitute, so the test reads the real line.
const t = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)

const note = (state) => targetNote(state, t)

test('an empty path names the default folder', () => {
	assert.equal(
		note({ who: 'bob', name: 'Docs', path: '' }),
		'bob gets “Docs” in the default folder.',
	)
})

test('a path whose last part is the name does not claim a rename', () => {
	assert.equal(
		note({ who: 'bob', name: 'Special Share', path: 'Work/Special Share' }),
		'bob gets the share at /Work/Special Share.',
	)
})

test('a path whose last part differs names the new name', () => {
	assert.equal(
		note({ who: 'bob', name: 'Docs', path: 'Work/Client Files' }),
		'bob gets the share at /Work/Client Files with the name “Client Files”.',
	)
})

test('a one segment path that matches the name does not claim a rename', () => {
	assert.equal(note({ who: 'bob', name: 'Docs', path: 'Docs' }), 'bob gets the share at /Docs.')
})

test('a one segment path that differs names the new name', () => {
	assert.equal(
		note({ who: 'bob', name: 'Docs', path: 'Archive' }),
		'bob gets the share at /Archive with the name “Archive”.',
	)
})

test('an unknown node name says nothing at all', () => {
	assert.equal(note({ who: 'bob', name: '', path: '' }), '')
	assert.equal(note({ who: 'bob', name: '', path: 'Work/Client Files' }), '')
})

test('the recipient is used verbatim', () => {
	assert.equal(
		note({ who: 'The recipient', name: 'Docs', path: '' }),
		'The recipient gets “Docs” in the default folder.',
	)
})
