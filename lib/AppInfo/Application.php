<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\AppInfo;

use OCA\SetTarget\Listener\BeforeShareCreatedListener;
use OCA\SetTarget\Listener\ShareCreatedListener;
use OCA\SetTarget\Listener\ShareDeletedListener;
use OCA\SetTarget\Notification\Notifier;
use OCA\SetTarget\Service\ShareTargetService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_set_target';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeShareCreatedEvent::class, BeforeShareCreatedListener::class);
		$context->registerEventListener(ShareCreatedEvent::class, ShareCreatedListener::class);
		$context->registerEventListener(ShareDeletedEvent::class, ShareDeletedListener::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (
			IUserSession $userSession,
			IInitialState $initialState,
			ShareTargetService $service,
		): void {
			$user = $userSession->getUser();
			if ($user === null) {
				return;
			}

			$initialState->provideInitialState('user_settings', [
				'can_use' => $service->canUserSetTarget($user->getUID()),
			]);

			Util::addScript(self::APP_ID, 'files_set_target-share-target');
			Util::addStyle(self::APP_ID, 'share-target');
		});
	}
}
