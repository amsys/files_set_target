<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Service;

use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;

class ShareTargetService {
	private const SETTING_ALLOWED_USERS = 'allowed_users';
	private const SETTING_ALLOWED_GROUPS = 'allowed_groups';
	private const SETTING_RESTRICT_MODE = 'restrict_mode';
	private const SETTING_ENABLE_AUTO_CREATE = 'enable_auto_create_dir';
	private const SETTING_ENABLE_AUTO_REMOVE = 'enable_auto_remove_dir';
	private const APP_ID = 'files_set_target';

	/** Every account may set a target. */
	public const MODE_ALL = 'all';
	/** Only the listed accounts, and members of the listed groups, may. */
	public const MODE_SELECTED = 'selected';

	private const TARGET_KEY_PREFIX = 'target.';
	/** A stored target that is older than this many seconds is ignored. */
	private const TARGET_MAX_AGE = 3600;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserConfig $userConfig,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * Store the target that the next share of this file must use.
	 * A second call for the same file and recipient replaces the first value.
	 */
	public function setPendingTarget(string $uid, int $fileId, string $path, string $shareWith): void {
		$this->userConfig->setValueArray($uid, self::APP_ID, $this->targetKey($fileId, $shareWith), [
			'path' => $path,
			'ts' => time(),
		]);
	}

	/**
	 * Read the target for the next share of this file and remove it.
	 *
	 * The entry is always removed, also when it is too old. Returns null when
	 * no entry exists, the entry is older than TARGET_MAX_AGE, or the stored
	 * path is empty.
	 *
	 * @return array{path: string}|null
	 */
	public function takePendingTarget(string $uid, int $fileId, string $shareWith): ?array {
		return $this->takeValue($uid, $this->targetKey($fileId, $shareWith));
	}

	/** Remove the target stored for this file. */
	public function clearPendingTarget(string $uid, int $fileId, string $shareWith): void {
		$this->userConfig->deleteUserConfig($uid, self::APP_ID, $this->targetKey($fileId, $shareWith));
	}

	/**
	 * Read one entry and remove it.
	 *
	 * @return array{path: string}|null
	 */
	private function takeValue(string $uid, string $key): ?array {
		$value = $this->userConfig->getValueArray($uid, self::APP_ID, $key);
		if ($value === []) {
			return null;
		}

		$this->userConfig->deleteUserConfig($uid, self::APP_ID, $key);

		$path = (string)($value['path'] ?? '');
		$ts = (int)($value['ts'] ?? 0);
		if ($path === '' || $ts < time() - self::TARGET_MAX_AGE) {
			return null;
		}

		return ['path' => $path];
	}

	/**
	 * Build the config key of a pending target.
	 *
	 * The recipient gets a hash, because oc_preferences.configkey holds 64
	 * characters and an account name or a group name can be longer.
	 */
	private function targetKey(int $fileId, string $shareWith): string {
		return self::TARGET_KEY_PREFIX . $fileId . '.' . substr(sha1($shareWith), 0, 16);
	}

	public function canUserSetTarget(string $uid): bool {
		if ($this->getRestrictMode() === self::MODE_ALL) {
			return true;
		}

		if (in_array($uid, $this->getAllowedUsers(), true)) {
			return true;
		}

		foreach ($this->getAllowedGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read the restriction mode. An unknown or missing value means
	 * MODE_SELECTED, so a misread never opens the feature to everybody.
	 */
	public function getRestrictMode(): string {
		$mode = $this->appConfig->getValueString(self::APP_ID, self::SETTING_RESTRICT_MODE, self::MODE_SELECTED);
		return $mode === self::MODE_ALL ? self::MODE_ALL : self::MODE_SELECTED;
	}

	/** @return list<string> */
	public function getAllowedUsers(): array {
		return $this->getStringList(self::SETTING_ALLOWED_USERS);
	}

	/** @return list<string> */
	public function getAllowedGroups(): array {
		return $this->getStringList(self::SETTING_ALLOWED_GROUPS);
	}

	/** @return list<string> */
	private function getStringList(string $key): array {
		$json = $this->appConfig->getValueString(self::APP_ID, $key, '[]');
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_filter($decoded, 'is_string'));
	}

	public function isAutoCreateEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, self::SETTING_ENABLE_AUTO_CREATE, false);
	}

	/** Remove a folder that the app created, after the share goes. */
	public function isAutoRemoveEnabled(): bool {
		return $this->appConfig->getValueBool(self::APP_ID, self::SETTING_ENABLE_AUTO_REMOVE, false);
	}

	/**
	 * @param self::MODE_* $restrictMode
	 * @param list<string> $allowedUsers
	 * @param list<string> $allowedGroups
	 */
	public function saveSettings(
		string $restrictMode,
		array $allowedUsers,
		array $allowedGroups,
		bool $enableAutoCreateDir,
		bool $enableAutoRemoveDir,
	): void {
		$this->appConfig->setValueString(
			self::APP_ID,
			self::SETTING_RESTRICT_MODE,
			$restrictMode === self::MODE_ALL ? self::MODE_ALL : self::MODE_SELECTED,
		);
		$this->appConfig->setValueString(
			self::APP_ID,
			self::SETTING_ALLOWED_USERS,
			json_encode(array_values($allowedUsers), JSON_THROW_ON_ERROR),
		);
		$this->appConfig->setValueString(
			self::APP_ID,
			self::SETTING_ALLOWED_GROUPS,
			json_encode(array_values($allowedGroups), JSON_THROW_ON_ERROR),
		);
		$this->appConfig->setValueBool(self::APP_ID, self::SETTING_ENABLE_AUTO_CREATE, $enableAutoCreateDir);
		$this->appConfig->setValueBool(self::APP_ID, self::SETTING_ENABLE_AUTO_REMOVE, $enableAutoRemoveDir);
	}
}
