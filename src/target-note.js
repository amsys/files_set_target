/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The line under the field: what the recipient will see.
 *
 * The last part of the path becomes the name of the share, so a path can
 * rename it. The note says so only when the name really changes. It stays
 * empty while the name of the node is still unknown, because a sentence
 * about “” helps nobody.
 *
 * @param {object} state the current state of the field
 * @param {string} state.who the recipient, as the sharer sees them
 * @param {string} state.name the name of the node, or '' when not known yet
 * @param {string} state.path the target path, without the outer slashes
 * @param {(text: string, vars?: object) => string} [t] the translation function
 * @return {string} the note, or '' when there is nothing to say
 */
export function targetNote({ who, name, path }, t = (text) => text) {
	if (name === '') {
		return ''
	}

	if (path === '') {
		return t('{who} gets “{name}” in the default folder.', { who, name })
	}

	// One whole sentence per case: a translator cannot reorder a sentence
	// that arrives in two pieces.
	const last = path.split('/').pop()
	if (last === name) {
		return t('{who} gets the share at /{path}.', { who, path })
	}

	return t('{who} gets the share at /{path} with the name “{last}”.', { who, path, last })
}
