/**
 * SPDX-FileCopyrightText: Martin <dev@amsys.cz>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

const APP_ID = 'files_set_target'
const SEARCH_DEBOUNCE_MS = 300
const SEARCH_LIMIT = 10
const OCS = { headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }

/**
 * Show a transient message under the settings form.
 *
 * @param {string} msg the message
 * @param {boolean} isError whether it reports a failure
 */
function showStatus(msg, isError = false) {
	const el = document.getElementById('files_set_target_status')
	if (!el) {
		return
	}
	el.textContent = msg
	el.className = 'set-target-status ' + (isError ? 'set-target-status--error' : 'set-target-status--success')
	el.hidden = false
	clearTimeout(el._hideTimer)
	el._hideTimer = setTimeout(() => { el.hidden = true }, 4000)
}

/**
 * Read a JSON array from a data attribute.
 *
 * @param {HTMLElement} root the element carrying the attribute
 * @param {string} name the attribute name
 * @return {string[]} the ids, empty when the attribute is absent or broken
 */
function readList(root, name) {
	try {
		const parsed = JSON.parse(root.dataset[name] ?? '[]')
		return Array.isArray(parsed) ? parsed.filter((v) => typeof v === 'string') : []
	} catch {
		return []
	}
}

/**
 * Search accounts by name. Admin only.
 *
 * @param {string} term the search term
 * @return {Promise<string[]>} matching account ids
 */
async function searchUsers(term) {
	const { data } = await axios.get(generateOcsUrl('cloud/users'), {
		params: { search: term, limit: SEARCH_LIMIT },
		...OCS,
	})
	return data?.ocs?.data?.users ?? []
}

/**
 * Search groups by name. Admin only.
 *
 * @param {string} term the search term
 * @return {Promise<string[]>} matching group ids
 */
async function searchGroups(term) {
	const { data } = await axios.get(generateOcsUrl('cloud/groups'), {
		params: { search: term, limit: SEARCH_LIMIT },
		...OCS,
	})
	return data?.ocs?.data?.groups ?? []
}

/**
 * A chip list with a search box. The datalist offers matches; picking one
 * turns it into a chip. The picker owns its own selection.
 *
 * @param {HTMLElement} root the picker container
 * @param {string[]} initial the ids already selected
 * @param {(term: string) => Promise<string[]>} search the lookup
 * @return {{get: () => string[]}} reads the current selection
 */
function createPicker(root, initial, search) {
	const chips = root.querySelector('.set-target-chips')
	const input = root.querySelector('.set-target-search')
	const options = root.querySelector('datalist')
	const selection = new Set(initial)
	let timer = null

	const render = () => {
		chips.textContent = ''
		if (selection.size === 0) {
			const empty = document.createElement('li')
			empty.className = 'set-target-chips__empty'
			empty.textContent = t(APP_ID, 'Nobody selected yet.')
			chips.append(empty)
			return
		}
		for (const id of selection) {
			const chip = document.createElement('li')
			chip.className = 'set-target-chip'
			chip.textContent = id

			const remove = document.createElement('button')
			remove.type = 'button'
			remove.className = 'set-target-chip__remove'
			remove.textContent = '×'
			remove.setAttribute('aria-label', t(APP_ID, 'Remove {id}', { id }))
			remove.addEventListener('click', () => {
				selection.delete(id)
				render()
			})

			chip.append(remove)
			chips.append(chip)
		}
	}

	const take = (value) => {
		selection.add(value)
		input.value = ''
		options.textContent = ''
		render()
	}

	input.addEventListener('input', () => {
		const term = input.value.trim()

		// Picking from the datalist fires 'input' with the full option value.
		if (term !== '' && Array.from(options.options).some((o) => o.value === term)) {
			take(term)
			return
		}

		clearTimeout(timer)
		if (term === '') {
			options.textContent = ''
			return
		}
		timer = setTimeout(async () => {
			try {
				const found = await search(term)
				options.textContent = ''
				for (const id of found) {
					if (selection.has(id)) {
						continue
					}
					const option = document.createElement('option')
					option.value = id
					options.append(option)
				}
			} catch (err) {
				showStatus(t(APP_ID, 'Could not search: {error}', {
					error: err.response?.data?.ocs?.meta?.message ?? err.message,
				}), true)
			}
		}, SEARCH_DEBOUNCE_MS)
	})

	// Typing an exact id and pressing Enter also adds it.
	input.addEventListener('keydown', (e) => {
		if (e.key !== 'Enter') {
			return
		}
		e.preventDefault()
		const term = input.value.trim()
		if (term !== '') {
			take(term)
		}
	})

	render()
	return { get: () => Array.from(selection) }
}

document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('files_set_target_admin')
	if (!root) {
		return
	}

	const users = createPicker(
		document.getElementById('files_set_target_users'),
		readList(root, 'allowedUsers'),
		searchUsers,
	)
	const groups = createPicker(
		document.getElementById('files_set_target_groups'),
		readList(root, 'allowedGroups'),
		searchGroups,
	)

	const selectedBlock = document.getElementById('files_set_target_selected')
	const modeInputs = Array.from(root.querySelectorAll('input[name="files_set_target_mode"]'))
	const currentMode = () => modeInputs.find((i) => i.checked)?.value ?? 'selected'
	for (const input of modeInputs) {
		input.addEventListener('change', () => {
			selectedBlock.hidden = currentMode() !== 'selected'
		})
	}

	document.getElementById('files_set_target_save').addEventListener('click', async () => {
		try {
			await axios.post(generateOcsUrl('apps/{appId}/api/v1/admin/settings', { appId: APP_ID }), {
				restrict_mode: currentMode(),
				allowed_users: users.get(),
				allowed_groups: groups.get(),
				enable_auto_create_dir: document.getElementById('files_set_target_auto_create').checked,
				enable_auto_remove_dir: document.getElementById('files_set_target_auto_remove').checked,
			})
			showStatus(t(APP_ID, 'Settings saved.'))
		} catch (err) {
			showStatus(t(APP_ID, 'Failed to save settings: {error}', {
				error: err.response?.data?.ocs?.meta?.message ?? err.message,
			}), true)
		}
	})
})
