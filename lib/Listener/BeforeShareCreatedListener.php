<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Listener;

use OCA\SetTarget\Service\ShareTargetService;
use OCA\SetTarget\Validator\PathValidator;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Applies the pending target to the share before the server writes it.
 *
 * Manager::createShare computes the default target before it sends this event,
 * and the provider persists getTarget(). A target set here needs no move
 * after creation.
 *
 * @template-implements IEventListener<BeforeShareCreatedEvent>
 */
class BeforeShareCreatedListener implements IEventListener {
	public function __construct(
		private readonly ShareTargetService $service,
		private readonly PathValidator $validator,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeShareCreatedEvent) {
			return;
		}

		$share = $event->getShare();

		if (!in_array($share->getShareType(), [IShare::TYPE_USER, IShare::TYPE_GROUP], true)) {
			return;
		}

		$sharerId = $share->getSharedBy();
		$pending = $this->service->takePendingTarget($sharerId, $share->getNodeId(), (string)$share->getSharedWith());
		if ($pending === null) {
			return;
		}

		if (!$this->service->canUserSetTarget($sharerId)) {
			$this->logger->warning('[SetTarget] User is not allowed to set a share target', [
				'user' => $sharerId,
			]);
			$this->abort($event, 'You are not allowed to set a share target.');
			return;
		}

		$result = $this->validator->validate($pending['path']);
		if (!$result->isValid()) {
			$this->logger->warning('[SetTarget] Invalid target path', [
				'user' => $sharerId,
				'path' => $pending['path'],
				'error' => $result->getError(),
			]);
			$this->abort($event, $result->getError() ?? 'Invalid target path.');
			return;
		}

		$path = (string)$result->getSanitized();

		// Only a user share gets its parent folder created. A group share
		// keeps one row for all members, and the app writes no member home.
		$createdDir = null;
		if ($result->hasParentDir() && $share->getShareType() === IShare::TYPE_USER) {
			try {
				$createdDir = $this->service->isAutoCreateEnabled()
					? $this->createParentFolder($share->getSharedWith(), $path)
					: $this->requireParentFolder($share->getSharedWith(), $path);
			} catch (\Throwable $e) {
				$this->logger->error('[SetTarget] No parent folder for the share target', [
					'user' => $share->getSharedWith(),
					'path' => $path,
					'error' => $e->getMessage(),
				]);
				$this->abort($event, $e->getMessage());
				return;
			}
		}

		$share->setTarget('/' . $path);

		// The notifier reads this attribute after creation.
		$attributes = $share->getAttributes() ?? $share->newAttributes();
		$attributes->setAttribute('files_set_target', 'target_path', $path);
		if ($createdDir !== null) {
			// ShareDeletedListener removes this folder again when the share
			// goes and the folder is empty.
			$attributes->setAttribute('files_set_target', 'created_dir', $createdDir);
		}
		$share->setAttributes($attributes);
	}

	/**
	 * Manager::createShare throws only when the event carries an error AND
	 * propagation is stopped. Both calls are necessary to stop the share.
	 */
	private function abort(BeforeShareCreatedEvent $event, string $message): void {
		$event->setError($message);
		$event->stopPropagation();
	}

	/**
	 * Refuse a target whose parent folder is not in the recipient account.
	 *
	 * The server puts a share with an unreachable target somewhere else, so
	 * the sharer must get an error instead of a silent move.
	 *
	 * @return null this method makes no folder
	 */
	private function requireParentFolder(string $recipientId, string $path): ?string {
		$parent = dirname($path);
		if ($parent === '.' || $parent === '' || $parent === '/') {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($recipientId);
		if ($userFolder->nodeExists($parent) && $userFolder->get($parent) instanceof Folder) {
			return null;
		}

		throw new \RuntimeException('The folder \'' . $parent . '\' is not in the account of the recipient.');
	}

	/**
	 * Create the parent folder of the target in the recipient account.
	 *
	 * @return string|null the highest folder that this call created, or null
	 *                     when every folder of the path was there already
	 */
	private function createParentFolder(string $recipientId, string $path): ?string {
		$parent = dirname($path);
		if ($parent === '.' || $parent === '' || $parent === '/') {
			return null;
		}

		$userFolder = $this->rootFolder->getUserFolder($recipientId);
		if ($userFolder->nodeExists($parent)) {
			if ($userFolder->get($parent) instanceof Folder) {
				return null;
			}
			// A file holds the name. The share would land on an unreachable
			// path, so the caller must abort the share.
			throw new \RuntimeException(
				'A file has the name of the folder \'' . $parent
				. '\' in the account of the recipient.'
			);
		}

		// newFolder makes every missing folder of the path. Find the highest
		// one, because only the new folders may go again later.
		$created = $parent;
		$walk = '';
		foreach (explode('/', $parent) as $segment) {
			$walk = $walk === '' ? $segment : $walk . '/' . $segment;
			if (!$userFolder->nodeExists($walk)) {
				$created = $walk;
				break;
			}
		}

		$userFolder->newFolder($parent);
		$this->logger->info('[SetTarget] Created the parent folder for a share target', [
			'user' => $recipientId,
			'path' => $parent,
		]);

		return $created;
	}
}
