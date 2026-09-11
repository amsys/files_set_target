<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'files_set_target';
	}

	public function getName(): string {
		return $this->l10nFactory->get('files_set_target')->t('Share Target');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'files_set_target') {
			throw new UnknownNotificationException('Unknown app: ' . $notification->getApp());
		}

		$l = $this->l10nFactory->get('files_set_target', $languageCode);

		if ($notification->getSubject() === 'share_with_custom_target') {
			$params = $notification->getSubjectParameters();
			$targetPath = $params['target_path'] ?? '';
			$sharedBy = $params['shared_by'] ?? '';
			$shareName = $params['share_name'] ?? '';

			$notification->setParsedSubject(
				$l->t('%1$s shared "%2$s" with you in "%3$s"', [$sharedBy, $shareName, $targetPath])
			);
			$notification->setRichSubject(
				'{user} shared {share} with you in {target}',
				[
					'user' => [
						'type' => 'user',
						'id' => $sharedBy,
						'name' => $sharedBy,
					],
					'share' => [
						'type' => 'highlight',
						'id' => $notification->getObjectId(),
						'name' => $shareName,
					],
					'target' => [
						'type' => 'highlight',
						'id' => $targetPath,
						'name' => $targetPath,
					],
				],
			);

			// dirname() of a one-segment path is '.', which is not a folder.
			$parent = dirname($targetPath);
			$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.view.index', [
				'dir' => $parent === '.' ? '/' : '/' . $parent,
			]));

			return $notification;
		}

		throw new UnknownNotificationException('Unknown subject: ' . $notification->getSubject());
	}
}
