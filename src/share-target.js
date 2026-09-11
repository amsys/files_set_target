/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { Permission } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ShareType } from '@nextcloud/sharing'
import { registerSidebarAction } from '@nextcloud/sharing/ui'

import { checkPath, trimPath } from './path-check.js'
import { targetNote } from './target-note.js'

const APP_ID = 'files_set_target'
const TAG_NAME = 'oca_files_set_target-share-target'
const DEBOUNCE_MS = 500

let instanceCount = 0

const settings = loadState(APP_ID, 'user_settings', null)

/**
 * Build the OCS url of the pending target endpoint.
 *
 * @param {number} [fileId] the file, for DELETE only
 * @return {string} the url
 */
function targetUrl(fileId) {
	if (fileId === undefined) {
		return generateOcsUrl('apps/{appId}/api/v1/target', { appId: APP_ID })
	}
	return generateOcsUrl('apps/{appId}/api/v1/target/{fileId}', { appId: APP_ID, fileId })
}

/**
 * The field inside the settings of one share. The sidebar sets `node` and
 * `share` as properties on this element.
 */
class SetTargetTab extends HTMLElement {

	_node = null
	_share = null
	_shareWith = null
	_timer = null
	_seq = 0

	connectedCallback() {
		if (!this._input) {
			this._build()
		}
	}

	// Vue removes the element when the share panel closes. A pending debounce
	// would then POST for a panel that is gone, and its error branch would
	// write into detached DOM.
	disconnectedCallback() {
		clearTimeout(this._timer)
	}

	set node(node) {
		const changed = node?.fileid !== this._node?.fileid
		this._node = node
		// The sidebar writes both properties again on every share update, so
		// the field must only reset when the file really changed.
		if (changed) {
			this._clear()
		}
	}

	get node() {
		return this._node
	}

	set share(share) {
		const shareWith = share?.shareWith ?? null
		const changed = shareWith !== this._shareWith
		this._share = share
		this._shareWith = shareWith
		if (changed) {
			this._clear()
		}
	}

	get share() {
		return this._share
	}

	_build() {
		// Every mount needs its own ids: `for` and `aria-describedby` must point
		// at this instance.
		const uid = `set-target-${++instanceCount}`

		const row = document.createElement('div')
		row.className = 'set-target-row'

		const label = document.createElement('label')
		label.className = 'set-target-label'
		label.setAttribute('for', `${uid}-path`)
		label.textContent = t(APP_ID, 'Path in the recipient\u2019s account')

		const input = document.createElement('input')
		input.type = 'text'
		input.id = `${uid}-path`
		input.className = 'set-target-input'
		input.placeholder = t(APP_ID, 'e.g. Work/Client Files')
		input.maxLength = 500
		input.autocomplete = 'off'
		input.spellcheck = false
		input.setAttribute('aria-describedby', `${uid}-note ${uid}-error`)

		const error = document.createElement('span')
		error.className = 'set-target-error'
		error.id = `${uid}-error`
		error.setAttribute('aria-live', 'polite')

		const note = document.createElement('p')
		note.className = 'set-target-note'
		note.id = `${uid}-note`

		// The error belongs to the input and the note to the result, so both
		// stay under the field, in that order.
		row.append(label, input, error, note)

		this.append(row)

		this._input = input
		this._error = error
		this._note = note
		this._preview()

		input.addEventListener('input', () => {
			// The check is local, so the user sees a bad path at once. Only
			// the request waits for the pause in the typing.
			this._validate()
			clearTimeout(this._timer)
			this._timer = setTimeout(() => this._send(), DEBOUNCE_MS)
		})
		// "Save share" takes the focus away from the input first, so a change
		// event runs before the share is created.
		// ponytail: the POST can still be in flight when the share is created.
		// If that becomes a problem, apply the target after creation instead.
		input.addEventListener('change', () => {
			clearTimeout(this._timer)
			this._send()
		})
	}

	_clear() {
		clearTimeout(this._timer)
		if (!this._input) {
			return
		}
		this._input.value = ''
		this._error.textContent = ''
		this._input.removeAttribute('aria-invalid')
		this._preview()
	}

	/**
	 * Check the path with the rules of the server and show the result.
	 *
	 * @return {?string} the error, or null when the path is good or empty
	 */
	_validate() {
		const path = this._path()
		const check = path === '' ? null : checkPath(path, (text, vars) => t(APP_ID, text, vars))

		this._error.textContent = check?.error ?? ''
		if (check?.error) {
			this._input.setAttribute('aria-invalid', 'true')
			this._note.textContent = ''
		} else {
			this._input.removeAttribute('aria-invalid')
			this._preview()
		}

		return check?.error ?? null
	}

	/**
	 * Show what the recipient will get, for the current path.
	 *
	 * @param {string} [sanitized] the path as the server stored it
	 */
	_preview(sanitized) {
		if (!this._note) {
			return
		}

		// IShare calls it `shareWithDisplayname`. Older payloads spell it with
		// a capital N, so read both before falling back.
		const who = this._share?.shareWithDisplayname
			|| this._share?.shareWithDisplayName
			|| t(APP_ID, 'The recipient')
		// `displayname` is the name the sharer sees, and it differs from
		// `basename` for a shared folder. The note is for the sharer, so it
		// must compare against what the sharer reads on screen.
		const name = this._node?.displayname || this._node?.basename || ''
		const path = sanitized === undefined ? this._path() : trimPath(sanitized)

		this._note.textContent = targetNote({ who, name, path }, (text, vars) => t(APP_ID, text, vars))
	}

	async _send() {
		const fileId = this._node?.fileid
		if (fileId === undefined) {
			return
		}

		const shareWith = this._share?.shareWith ?? ''
		const path = this._path()
		// The server checks the path again when it stores it, and once more
		// when it applies it. This check only saves the user the wait.
		const error = this._validate()
		// Requests can answer out of order. Only the newest one may write to
		// the field, or a slow answer overwrites what the user typed after it.
		const seq = ++this._seq

		try {
			if (path === '' || error !== null) {
				// A path the server would refuse must not leave an older
				// target behind: the field and the share must agree.
				await axios.delete(targetUrl(fileId), { params: { shareWith } })
				return
			}
			const response = await axios.post(targetUrl(), { fileId, path, shareWith })
			if (seq === this._seq) {
				this._preview(response?.data?.ocs?.data?.sanitized)
			}
		} catch (e) {
			if (seq !== this._seq) {
				return
			}
			this._error.textContent = e?.response?.data?.ocs?.meta?.message
				?? t(APP_ID, 'Could not save the target folder.')
			this._input.setAttribute('aria-invalid', 'true')
		}
	}

	/** The path in the input, without the outer slashes. */
	_path() {
		return trimPath(this._input.value)
	}

}

// registerSidebarAction rejects an element that is not defined yet, so the
// custom element has to exist before the action is registered.
if (!customElements.get(TAG_NAME)) {
	customElements.define(TAG_NAME, SetTargetTab)
}

// The action shows inside the settings of one share, under "Advanced
// settings". A target applies at share creation only, so a share that exists
// already does not get the field.
registerSidebarAction({
	id: 'files-set-target',
	element: TAG_NAME,
	order: 50,
	enabled: (share, node) => Boolean(settings?.can_use)
		&& !share?.id
		&& [ShareType.User, ShareType.Group].includes(share?.type)
		&& ((node?.permissions ?? 0) & Permission.SHARE) !== 0,
})
