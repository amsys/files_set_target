<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Notification;

use OCP\Notification\IManager as INotificationManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Sends the target notification to the recipient of a share.
 *
 * Notifier renders a notification that the server gives back to this app.
 * This class makes one. The two directions stay apart.
 */
class TargetNotifier {
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function send(IShare $share, string $targetPath, string $shareName): void {
		$recipientId = $share->getSharedWith();
		$sharerId = $share->getSharedBy();

		// Only send notifications for user shares
		if ($share->getShareType() !== IShare::TYPE_USER) {
			return;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp('files_set_target')
				->setUser($recipientId)
				->setDateTime(new \DateTime())
				->setObject('share', (string)$share->getId())
				->setSubject('share_with_custom_target', [
					'target_path' => $targetPath,
					'shared_by' => $sharerId,
					'share_name' => $shareName,
				]);

			$this->notificationManager->notify($notification);

			$this->logger->info('[SetTarget] Sent target notification to recipient', [
				'recipient' => $recipientId,
				'sharer' => $sharerId,
				'target' => $targetPath,
				'share_name' => $shareName,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('[SetTarget] Failed to send notification', [
				'error' => $e->getMessage(),
				'recipient' => $recipientId,
			]);
		}
	}
}
