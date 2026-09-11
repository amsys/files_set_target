/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The rules of lib/Validator/PathValidator.php, for the browser.
 *
 * The server stays the authority: it validates every path again when it
 * stores it, and a third time when it applies it to the share. This copy
 * only tells the user about a bad path without a round trip. Keep the two
 * files together: a rule that changes on the server changes here.
 */

const MAX_PATH_LENGTH = 500
const MAX_SEGMENT_LENGTH = 255
// eslint-disable-next-line no-control-regex -- control characters are the point
const FORBIDDEN_CHARS = /[\u0000-\u001F\u007F\\:*?"<>|]/
const RESERVED_NAMES = new Set([
	'CON', 'PRN', 'AUX', 'NUL',
	'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
	'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
])

const encoder = new TextEncoder()

// The characters that carry no meaning at the ends of a path.
// eslint-disable-next-line no-control-regex -- NUL is one of them
const TRIM_CHAR = /[\s\u0000/]/

/**
 * Remove slashes, spaces and NUL from both ends of a path.
 *
 * A regular expression does the same in one line, but a repeated character
 * class anchored at the end (`/[\s\0/]+$/`) backtracks: a long run that does
 * not reach the end costs O(n^2). This scan costs O(n).
 *
 * @param {string} value the path
 * @return {string} the path without its outer slashes and spaces
 */
export function trimPath(value) {
	let start = 0
	let end = value.length
	while (start < end && TRIM_CHAR.test(value[start])) {
		start++
	}
	while (end > start && TRIM_CHAR.test(value[end - 1])) {
		end--
	}

	return value.slice(start, end)
}

/**
 * The server counts bytes, not characters.
 *
 * @param {string} value the text
 * @return {number} the number of bytes in UTF-8
 */
function byteLength(value) {
	return encoder.encode(value).length
}

/**
 * Check one segment of a path.
 *
 * @param {string} segment the segment, already left-trimmed
 * @param {(text: string, vars?: object) => string} t the translation function
 * @return {string|null} the error, or null when the segment is good
 */
function checkSegment(segment, t) {
	if (segment === '') {
		return t('Path segment must not be empty (double slash or leading/trailing slash).')
	}
	if (segment === '.' || segment === '..') {
		return t('Path traversal sequences (. and ..) are not allowed.')
	}
	if (segment.endsWith('.') || segment.endsWith(' ')) {
		return t('Path segment "{segment}" must not end with a dot or space.', { segment })
	}
	if (byteLength(segment) > MAX_SEGMENT_LENGTH) {
		return t('Path segment "{segment}" exceeds the maximum length of {max} characters.', {
			segment: segment.slice(0, 40) + '\u2026',
			max: MAX_SEGMENT_LENGTH,
		})
	}
	if (FORBIDDEN_CHARS.test(segment)) {
		return t('Path segment "{segment}" contains forbidden characters.', {
			segment: segment.slice(0, 40),
		})
	}
	if (RESERVED_NAMES.has(segment.toUpperCase())) {
		return t('"{segment}" is a reserved name and cannot be used as a folder name.', { segment })
	}

	return null
}

/**
 * Check a target path.
 *
 * @param {string} path the path from the input
 * @param {(text: string, vars?: object) => string} [t] the translation function
 * @return {{error: (string|null), sanitized: string}} the first error, or the clean path
 */
export function checkPath(path, t = (text) => text) {
	const trimmed = trimPath(path)

	if (trimmed === '') {
		return { error: t('Path must not be empty.'), sanitized: '' }
	}

	if (byteLength(trimmed) > MAX_PATH_LENGTH) {
		return {
			error: t('Path must not exceed {max} characters.', { max: MAX_PATH_LENGTH }),
			sanitized: '',
		}
	}

	const segments = []
	for (const raw of trimmed.split('/')) {
		const segment = raw.replace(/^\s+/, '')
		const error = checkSegment(segment, t)
		if (error !== null) {
			return { error, sanitized: '' }
		}
		segments.push(segment)
	}

	return { error: null, sanitized: segments.join('/') }
}
