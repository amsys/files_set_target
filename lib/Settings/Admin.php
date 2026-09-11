<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Settings;

use OCA\SetTarget\AppInfo\Application;
use OCA\SetTarget\Service\ShareTargetService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private readonly ShareTargetService $service,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'files_set_target-admin');

		return new TemplateResponse(Application::APP_ID, 'admin', [
			'restrict_mode' => $this->service->getRestrictMode(),
			'allowed_users' => $this->service->getAllowedUsers(),
			'allowed_groups' => $this->service->getAllowedGroups(),
			'enable_auto_create_dir' => $this->service->isAutoCreateEnabled(),
			'enable_auto_remove_dir' => $this->service->isAutoRemoveEnabled(),
		]);
	}

	public function getSection(): string {
		return 'files_set_target';
	}

	public function getPriority(): int {
		return 10;
	}
}
