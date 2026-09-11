<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Controller;

use OCA\SetTarget\Service\ShareTargetService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class AdminController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ShareTargetService $service,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(\OCA\SetTarget\Settings\Admin::class)]
	public function saveSettings(
		string $restrict_mode = ShareTargetService::MODE_SELECTED,
		array $allowed_users = [],
		array $allowed_groups = [],
		bool $enable_auto_create_dir = false,
		bool $enable_auto_remove_dir = false,
	): DataResponse {
		$mode = $restrict_mode === ShareTargetService::MODE_ALL
			? ShareTargetService::MODE_ALL
			: ShareTargetService::MODE_SELECTED;

		$validatedUsers = $this->filterExisting(
			$allowed_users,
			fn (string $id): bool => $this->userManager->userExists($id),
			'account',
		);
		$validatedGroups = $this->filterExisting(
			$allowed_groups,
			fn (string $id): bool => $this->groupManager->groupExists($id),
			'group',
		);

		$this->service->saveSettings(
			restrictMode: $mode,
			allowedUsers: $validatedUsers,
			allowedGroups: $validatedGroups,
			enableAutoCreateDir: $enable_auto_create_dir,
			enableAutoRemoveDir: $enable_auto_remove_dir,
		);

		$this->logger->info('[SetTarget] Admin settings updated', [
			'restrict_mode' => $mode,
			'allowed_users_count' => count($validatedUsers),
			'allowed_groups_count' => count($validatedGroups),
			'enable_auto_create_dir' => $enable_auto_create_dir,
			'enable_auto_remove_dir' => $enable_auto_remove_dir,
		]);

		return new DataResponse(['status' => 'ok']);
	}

	/**
	 * Keep the ids that pass the shape check and still exist. An id that
	 * fails either test is dropped with a log line, never saved.
	 *
	 * @param array<mixed> $ids
	 * @param callable(string): bool $exists
	 * @return list<string>
	 */
	private function filterExisting(array $ids, callable $exists, string $kind): array {
		$result = [];
		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id === '' || in_array($id, $result, true)) {
				continue;
			}
			// Nextcloud ids are alphanumeric plus a small set of separators.
			if (!preg_match('/^[a-zA-Z0-9._@\- ]{1,64}$/', $id)) {
				$this->logger->warning('[SetTarget] Skipping invalid id', ['kind' => $kind, 'id' => $id]);
				continue;
			}
			if (!$exists($id)) {
				$this->logger->warning('[SetTarget] Id not found, skipping', ['kind' => $kind, 'id' => $id]);
				continue;
			}
			$result[] = $id;
		}

		return $result;
	}
}
