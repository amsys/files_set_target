<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Tests\Unit;

use OCA\SetTarget\Controller\ShareTargetApiController;
use OCA\SetTarget\Service\ShareTargetService;
use OCA\SetTarget\Validator\PathValidator;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ShareTargetServiceTest extends TestCase {
	private IUserConfig $userConfig;
	private ShareTargetService $service;

	protected function setUp(): void {
		$this->userConfig = $this->createMock(IUserConfig::class);
		$this->service = new ShareTargetService(
			$this->createMock(IAppConfig::class),
			$this->userConfig,
			$this->createMock(IGroupManager::class),
		);
	}

	public function testSetPendingTargetStoresTheArray(): void {
		$stored = null;
		$this->userConfig->expects($this->once())
			->method('setValueArray')
			->willReturnCallback(function (string $uid, string $app, string $key, array $value) use (&$stored): bool {
				$stored = [$uid, $app, $key, $value];
				return true;
			});

		$this->service->setPendingTarget('alice', 42, 'Work/Client Files', 'bob');

		$this->assertSame('alice', $stored[0]);
		$this->assertSame('files_set_target', $stored[1]);
		$this->assertSame('target.42.' . substr(sha1('bob'), 0, 16), $stored[2]);
		$this->assertSame(['path', 'ts'], array_keys($stored[3]));
		$this->assertSame('Work/Client Files', $stored[3]['path']);
		$this->assertIsInt($stored[3]['ts']);
	}

	public function testClearPendingTargetDeletesTheKey(): void {
		$this->userConfig->expects($this->once())
			->method('deleteUserConfig')
			->with('alice', 'files_set_target', 'target.42.' . substr(sha1('bob'), 0, 16));

		$this->service->clearPendingTarget('alice', 42, 'bob');
		$this->addToAssertionCount(1);
	}

	public function testTwoRecipientsKeepSeparateTargets(): void {
		$this->useInMemoryStore();

		$this->service->setPendingTarget('alice', 42, 'For Bob', 'bob');
		$this->service->setPendingTarget('alice', 42, 'For Carol', 'carol');

		$this->assertSame('For Bob', $this->service->takePendingTarget('alice', 42, 'bob')['path']);
		$this->assertSame('For Carol', $this->service->takePendingTarget('alice', 42, 'carol')['path']);
	}

	public function testASecondCallForTheSameRecipientReplacesTheTarget(): void {
		$this->useInMemoryStore();

		$this->service->setPendingTarget('alice', 42, 'First', 'bob');
		$this->service->setPendingTarget('alice', 42, 'Second', 'bob');

		$target = $this->service->takePendingTarget('alice', 42, 'bob');
		$this->assertSame('Second', $target['path']);
	}

	public function testTakeIgnoresTheTargetOfAnotherFile(): void {
		$this->useInMemoryStore();

		$this->service->setPendingTarget('alice', 42, 'For Bob', 'bob');

		$this->assertNull($this->service->takePendingTarget('alice', 43, 'bob'));
	}

	public function testControllerRoundTripsThroughTheStore(): void {
		$stored = null;
		$this->userConfig->method('setValueArray')
			->willReturnCallback(function (string $uid, string $app, string $key, array $value) use (&$stored): bool {
				$stored = [$uid, $app, $key, $value];
				return true;
			});

		$response = $this->controllerFor('alice', ['alice'])->setTarget(42, '/Work/Client Files/', 'bob');

		$this->assertSame('target.42.' . substr(sha1('bob'), 0, 16), $stored[2]);
		$this->assertSame('Work/Client Files', $stored[3]['path']);
		$this->assertSame(['sanitized' => 'Work/Client Files'], $response->getData());
	}

	public function testSetTargetIsForbiddenForOtherUsers(): void {
		$this->userConfig->expects($this->never())->method('setValueArray');

		$this->expectException(OCSForbiddenException::class);
		$this->controllerFor('bob', ['alice'])->setTarget(42, 'Work/Client Files', 'bob');
	}

	public function testDeleteTargetIsForbiddenForOtherUsers(): void {
		$this->userConfig->expects($this->never())->method('deleteUserConfig');

		$this->expectException(OCSForbiddenException::class);
		$this->controllerFor('bob', ['alice'])->deleteTarget(42, 'bob');
	}

	public function testSetTargetWithoutARecipientIsRefused(): void {
		$this->userConfig->expects($this->never())->method('setValueArray');

		$this->expectException(OCSBadRequestException::class);
		$this->controllerFor('alice', ['alice'])->setTarget(42, 'Work/Client Files');
	}

	public function testDeleteTargetWithoutARecipientIsRefused(): void {
		$this->userConfig->expects($this->never())->method('deleteUserConfig');

		$this->expectException(OCSBadRequestException::class);
		$this->controllerFor('alice', ['alice'])->deleteTarget(42);
	}

	/** @param list<string> $allowed */
	private function controllerFor(string $uid, array $allowed): ShareTargetApiController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(json_encode($allowed, JSON_THROW_ON_ERROR));
		$service = new ShareTargetService(
			$appConfig,
			$this->userConfig,
			$this->createMock(IGroupManager::class),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $text);

		return new ShareTargetApiController(
			'files_set_target',
			$this->createMock(IRequest::class),
			$session,
			$service,
			new PathValidator($l),
		);
	}

	/**
	 * Build a service whose app config answers the restriction settings.
	 *
	 * @param list<string> $users
	 * @param list<string> $groups
	 * @param list<string> $memberOf groups the tested account belongs to
	 */
	private function serviceWithRestriction(
		string $mode,
		array $users,
		array $groups,
		array $memberOf = [],
	): ShareTargetService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($mode, $users, $groups): string {
				return match ($key) {
					'restrict_mode' => $mode,
					'allowed_users' => json_encode($users),
					'allowed_groups' => json_encode($groups),
					default => $default,
				};
			},
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => in_array($gid, $memberOf, true),
		);

		return new ShareTargetService(
			$appConfig,
			$this->createMock(IUserConfig::class),
			$groupManager,
		);
	}

	public function testModeAllLetsEverybodySetATarget(): void {
		$service = $this->serviceWithRestriction(ShareTargetService::MODE_ALL, [], []);
		$this->assertTrue($service->canUserSetTarget('nobody-in-any-list'));
	}

	public function testModeSelectedAllowsAListedAccount(): void {
		$service = $this->serviceWithRestriction(ShareTargetService::MODE_SELECTED, ['alice'], []);
		$this->assertTrue($service->canUserSetTarget('alice'));
		$this->assertFalse($service->canUserSetTarget('bob'));
	}

	public function testModeSelectedAllowsAMemberOfAListedGroup(): void {
		$service = $this->serviceWithRestriction(
			ShareTargetService::MODE_SELECTED,
			[],
			['sharers'],
			['sharers'],
		);
		$this->assertTrue($service->canUserSetTarget('carol'));
	}

	public function testModeSelectedRefusesANonMember(): void {
		$service = $this->serviceWithRestriction(ShareTargetService::MODE_SELECTED, [], ['sharers'], ['others']);
		$this->assertFalse($service->canUserSetTarget('dave'));
	}

	public function testAnUnknownModeFallsBackToSelected(): void {
		$service = $this->serviceWithRestriction('something-else', [], []);
		$this->assertSame(ShareTargetService::MODE_SELECTED, $service->getRestrictMode());
		$this->assertFalse($service->canUserSetTarget('alice'));
	}


	/** Make the IUserConfig mock keep the values in memory. */
	private function useInMemoryStore(): void {
		$store = [];
		$this->userConfig->method('setValueArray')
			->willReturnCallback(function (string $uid, string $app, string $key, array $value) use (&$store): bool {
				$store[$uid . '/' . $app . '/' . $key] = $value;
				return true;
			});
		$this->userConfig->method('getValueArray')
			->willReturnCallback(function (string $uid, string $app, string $key) use (&$store): array {
				return $store[$uid . '/' . $app . '/' . $key] ?? [];
			});
		$this->userConfig->method('deleteUserConfig')
			->willReturnCallback(function (string $uid, string $app, string $key) use (&$store): void {
				unset($store[$uid . '/' . $app . '/' . $key]);
			});
	}

}
