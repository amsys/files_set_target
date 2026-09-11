<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Listener;

use OCA\SetTarget\Service\ShareTargetService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Removes the folder that BeforeShareCreatedListener made, after the share
 * goes.
 *
 * The listener removes a folder only when the app made it for this share and
 * the folder holds nothing. It never removes a folder of the recipient.
 *
 * @template-implements IEventListener<ShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener {
	public function __construct(
		private readonly ShareTargetService $service,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareDeletedEvent) {
			return;
		}

		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_USER) {
			return;
		}

		$createdDir = $share->getAttributes()?->getAttribute('files_set_target', 'created_dir');
		if (!is_string($createdDir) || $createdDir === '') {
			return;
		}

		if (!$this->service->isAutoRemoveEnabled()) {
			return;
		}

		try {
			$this->removeEmptyFolders($share, $createdDir);
		} catch (\Throwable $e) {
			// A share goes away even when the cleanup fails.
			$this->logger->warning('[SetTarget] Could not remove the folder of a share target', [
				'user' => $share->getSharedWith(),
				'path' => $createdDir,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Remove each empty folder from the folder of the share up to the highest
	 * folder that the app made. Stop at the first folder that holds something.
	 */
	private function removeEmptyFolders(IShare $share, string $createdDir): void {
		$recipientId = (string)$share->getSharedWith();
		$userFolder = $this->rootFolder->getUserFolder($recipientId);

		// The recipient can move a share. When the share is no longer in the
		// folder that the app made, look at that folder itself.
		$start = dirname(trim($share->getTarget(), '/'));
		if (!$this->isInside($start, $createdDir)) {
			$start = $createdDir;
		}

		for ($dir = $start; $dir !== '.' && $dir !== '/' && $dir !== ''; $dir = dirname($dir)) {
			// Never go above the folders that the app made.
			if (!$this->isInside($dir, $createdDir)) {
				return;
			}

			if ($userFolder->nodeExists($dir)) {
				$node = $userFolder->get($dir);
				if (!$node instanceof Folder || $node->getDirectoryListing() !== []) {
					return;
				}

				$node->delete();
				$this->logger->info('[SetTarget] Removed the empty folder of a share target', [
					'user' => $recipientId,
					'path' => $dir,
				]);
			}

			if ($dir === $createdDir) {
				return;
			}
		}
	}

	/** True when $dir is the folder $top, or a folder in it. */
	private function isInside(string $dir, string $top): bool {
		return $dir === $top || str_starts_with($dir, $top . '/');
	}
}
