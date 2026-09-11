<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Controller;

use OCA\SetTarget\Service\ShareTargetService;
use OCA\SetTarget\Validator\PathValidator;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ShareTargetApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ShareTargetService $service,
		private readonly PathValidator $validator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Store the target the next share of this file must use.
	 *
	 * @throws OCSForbiddenException the caller must not set a target
	 * @throws OCSBadRequestException the path is not valid, or the recipient is missing
	 */
	#[NoAdminRequired]
	public function setTarget(int $fileId, string $path = '', string $shareWith = ''): DataResponse {
		$uid = $this->requireAllowedUser();
		$this->requireRecipient($shareWith);

		$result = $this->validator->validate($path);
		if (!$result->isValid()) {
			throw new OCSBadRequestException((string)$result->getError());
		}

		$sanitized = (string)$result->getSanitized();
		$this->service->setPendingTarget($uid, $fileId, $sanitized, $shareWith);

		return new DataResponse(['sanitized' => $sanitized]);
	}

	/**
	 * Remove the target stored for this file.
	 *
	 * @throws OCSForbiddenException the caller must not set a target
	 * @throws OCSBadRequestException the recipient is missing
	 */
	#[NoAdminRequired]
	public function deleteTarget(int $fileId, string $shareWith = ''): DataResponse {
		$uid = $this->requireAllowedUser();
		$this->requireRecipient($shareWith);
		$this->service->clearPendingTarget($uid, $fileId, $shareWith);

		return new DataResponse([]);
	}

	/**
	 * @throws OCSForbiddenException
	 */
	private function requireAllowedUser(): string {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->service->canUserSetTarget($user->getUID())) {
			throw new OCSForbiddenException('Not authorized');
		}

		return $user->getUID();
	}

	/**
	 * @throws OCSBadRequestException
	 */
	private function requireRecipient(string $shareWith): void {
		if ($shareWith === '') {
			throw new OCSBadRequestException('A recipient is required.');
		}
	}
}
