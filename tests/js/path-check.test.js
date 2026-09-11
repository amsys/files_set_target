/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'

import { checkPath, trimPath } from '../../src/path-check.js'

test('accepts a simple path', () => {
	assert.deepEqual(checkPath('Shared/Folder'), { error: null, sanitized: 'Shared/Folder' })
})

test('strips the outer slashes and spaces', () => {
	assert.equal(checkPath('  /Work/Client Files/ ').sanitized, 'Work/Client Files')
})

test('refuses an empty path', () => {
	assert.ok(checkPath('   ').error)
	assert.ok(checkPath('///').error)
})

test('refuses path traversal', () => {
	assert.ok(checkPath('foo/../bar').error)
	assert.ok(checkPath('foo/./bar').error)
	assert.ok(checkPath('..').error)
})

test('refuses the characters the server refuses', () => {
	for (const path of ['bad:name', 'back\\slash', 'q?mark', 'a<b', 'a>b', 'a|b', 'a"b', 'star*']) {
		assert.ok(checkPath(path).error, `${path} must be refused`)
	}
})

test('refuses control characters', () => {
	assert.ok(checkPath('tab\u0009here').error)
	assert.ok(checkPath('null\u0000byte').error)
	assert.ok(checkPath('del\u007Fchar').error)
})

test('accepts the characters a folder name may hold', () => {
	for (const path of ['Work 2026', 'a-b_c', 'Ordner (neu)', 'é accent', '日本語', "O'Brien", 'a&b', 'a+b', 'a,b', 'a=b', 'a@b', 'a#b', 'a%b']) {
		assert.equal(checkPath(path).error, null, `${path} must be accepted`)
	}
})

test('refuses a double slash instead of repairing it', () => {
	// The server refuses it too. A path the sharer did not mean must never
	// become a folder in the account of the recipient.
	assert.ok(checkPath('Work//Client Files').error)
})

test('refuses a segment that ends with a dot or a space', () => {
	assert.ok(checkPath('Work./Files').error)
	assert.ok(checkPath('Work /Files').error)
})

test('refuses a reserved name in any case', () => {
	assert.ok(checkPath('CON').error)
	assert.ok(checkPath('con').error)
	assert.ok(checkPath('Work/lpt9').error)
	assert.equal(checkPath('Work/CONTRACTS').error, null)
})

test('counts bytes, like the server', () => {
	// Exactly 500 bytes over two segments: the limit, not over it.
	assert.equal(checkPath('a'.repeat(249) + '/' + 'a'.repeat(250)).error, null)
	assert.ok(checkPath('a'.repeat(250) + '/' + 'a'.repeat(251)).error)
	// 255 characters, but 509 bytes. A check that counts characters takes
	// this one and the server then refuses it.
	assert.ok(checkPath('é'.repeat(127) + '/' + 'é'.repeat(127)).error)
})

test('holds the segment limit', () => {
	assert.equal(checkPath('Work/' + 'a'.repeat(255)).error, null)
	assert.ok(checkPath('Work/' + 'a'.repeat(256)).error)
})

test('uses the translation function it gets', () => {
	const result = checkPath('..', (text) => `[${text}]`)
	assert.ok(result.error.startsWith('['))
})

test('trimPath removes slashes, spaces and NUL from both ends', () => {
	assert.equal(trimPath('/Work/Docs/'), 'Work/Docs')
	assert.equal(trimPath('  Work/Docs  '), 'Work/Docs')
	assert.equal(trimPath('\u0000//  Work/Docs  //\u0000'), 'Work/Docs')
	assert.equal(trimPath('Work/Docs'), 'Work/Docs')
	assert.equal(trimPath('///'), '')
	assert.equal(trimPath(''), '')
})

test('trimPath stays linear on a long inner run of spaces', () => {
	// The regular expression it replaced backtracked on this shape: a run of
	// trim characters that does not reach either end. Measured on the old
	// code: 1.1s at 25k spaces, 5.2s at 50k, 20s at 100k.
	const value = 'x' + ' '.repeat(50000) + 'y'
	const start = process.hrtime.bigint()
	assert.equal(trimPath(value), value)
	const ms = Number(process.hrtime.bigint() - start) / 1e6
	assert.ok(ms < 500, `trimPath took ${ms}ms`)
})
