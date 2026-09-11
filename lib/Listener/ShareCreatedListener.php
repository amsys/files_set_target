<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Listener;

use OCA\SetTarget\Notification\TargetNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Sends the recipient notification. The target is already applied and
 * persisted by BeforeShareCreatedListener.
 *
 * @template-implements IEventListener<ShareCreatedEvent>
 */
class ShareCreatedListener implements IEventListener {
	public function __construct(
		private readonly TargetNotifier $notifier,
		private readonly IShareManager $shareManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareCreatedEvent) {
			return;
		}

		$share = $event->getShare();
		$targetPath = $share->getAttributes()?->getAttribute('files_set_target', 'target_path');
		if (!is_string($targetPath) || $targetPath === '') {
			return;
		}

		if ($share->getShareType() === IShare::TYPE_GROUP) {
			$this->applyGroupTarget($share);
		}

		$this->notifier->send(
			share: $share,
			targetPath: $targetPath,
			shareName: $share->getNode()->getName(),
		);
	}

	/**
	 * Writes the target into every member's own share row.
	 *
	 * The server ignores the group row target for a member that has a
	 * TYPE_USERGROUP child row. DefaultShareProvider::createUserSpecificGroupShare
	 * makes such a row on auto-accept and computes the target from the node
	 * name, which drops the target this app set. moveShare() writes
	 * getTarget() into the same row, so it corrects the row whichever
	 * listener runs first.
	 */
	private function applyGroupTarget(IShare $share): void {
		$group = $this->groupManager->get($share->getSharedWith());
		if ($group === null) {
			return;
		}

		foreach ($group->getUsers() as $user) {
			try {
				$this->shareManager->moveShare($share, $user->getUID());
			} catch (\Throwable $e) {
				// The share exists. One member that cannot get the target
				// must not stop the others.
				$this->logger->warning('[SetTarget] Could not apply the target for a group member', [
					'user' => $user->getUID(),
					'share' => $share->getFullId(),
					'error' => $e->getMessage(),
				]);
			}
		}
	}
}
