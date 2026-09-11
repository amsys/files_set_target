<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

// The nextcloud/ocp package declares IRootFolder as extending OC\Hooks\Emitter
// but does not ship that interface. Without this stub IRootFolder cannot be
// mocked. The stub needs no methods: only the name must resolve.
namespace OC\Hooks {
	if (!\interface_exists(Emitter::class, false)) {
		interface Emitter {
		}
	}
}

namespace OCA\SetTarget\Tests\Unit {

	use OCA\SetTarget\Listener\BeforeShareCreatedListener;
	use OCA\SetTarget\Service\ShareTargetService;
	use OCA\SetTarget\Validator\PathValidator;
	use OCP\Config\IUserConfig;
	use OCP\Files\File;
	use OCP\Files\Folder;
	use OCP\Files\IRootFolder;
	use OCP\IAppConfig;
	use OCP\IGroupManager;
	use OCP\IL10N;
	use OCP\Share\Events\BeforeShareCreatedEvent;
	use OCP\Share\IAttributes;
	use OCP\Share\IShare;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;

	class BeforeShareCreatedListenerTest extends TestCase {
		private IUserConfig&MockObject $userConfig;
		private IAppConfig&MockObject $appConfig;
		private IRootFolder&MockObject $rootFolder;
		private Folder&MockObject $userFolder;

		protected function setUp(): void {
			$this->rootFolder = $this->createMock(IRootFolder::class);
			$this->userFolder = $this->createMock(Folder::class);
			$this->userFolder->method('newFolder')->willReturn($this->createMock(Folder::class));
			$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);
			$this->userConfig = $this->createMock(IUserConfig::class);
			$this->appConfig = $this->createMock(IAppConfig::class);
			$this->appConfig->method('getValueString')->willReturn(json_encode(['alice'], JSON_THROW_ON_ERROR));
			$this->appConfig->method('getValueBool')->willReturn(true);
		}

		public function testValidTargetReachesSetTarget(): void {
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()]);

			$written = [];
			$attributes = $this->createMock(IAttributes::class);
			$attributes->method('setAttribute')
				->willReturnCallback(function (string $scope, string $key, $value) use (&$written, $attributes) {
					$written[$key] = $value;
					return $attributes;
				});

			$share = $this->share();
			$share->method('newAttributes')->willReturn($attributes);
			$share->expects($this->once())->method('setTarget')->with('/Work/Client Files');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertFalse($event->isPropagationStopped());
			$this->assertSame('Work/Client Files', $written['target_path']);
			// The app made "Work", so it may remove it again later.
			$this->assertSame('Work', $written['created_dir']);
		}

		public function testInvalidPathAbortsTheShare(): void {
			$this->storePendingTarget(['path' => 'foo/../bar', 'ts' => time()]);

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNotNull($event->getError());
			$this->assertTrue($event->isPropagationStopped());
		}

		public function testPendingEntryIsDeletedOnRead(): void {
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()]);

			$this->userConfig->expects($this->once())
				->method('deleteUserConfig')
				->with('alice', 'files_set_target', 'target.42.' . substr(sha1('bob'), 0, 16));

			$this->listener()->handle(new BeforeShareCreatedEvent($this->share()));
		}

		public function testStaleEntryIsIgnoredAndDeleted(): void {
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time() - 3601]);

			$this->userConfig->expects($this->once())
				->method('deleteUserConfig')
				->with('alice', 'files_set_target', 'target.42.' . substr(sha1('bob'), 0, 16));

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertFalse($event->isPropagationStopped());
		}

		public function testNoPendingTargetLeavesTheShareAlone(): void {
			$this->userConfig->method('getValueArray')->willReturn([]);
			$this->userConfig->expects($this->never())->method('deleteUserConfig');

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
		}

		public function testTargetOfADisallowedUserAbortsTheShare(): void {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn(json_encode(['carol'], JSON_THROW_ON_ERROR));
			$this->appConfig = $appConfig;
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()]);

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNotNull($event->getError());
			$this->assertTrue($event->isPropagationStopped());
		}

		public function testAFileOnTheParentPathAbortsTheShare(): void {
			$this->storePendingTarget(['path' => 'Blocked/Sub', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->with('Blocked')->willReturn(true);
			$this->userFolder->method('get')->with('Blocked')->willReturn($this->createMock(File::class));

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNotNull($event->getError());
			$this->assertTrue($event->isPropagationStopped());
		}

		public function testAMissingParentFolderAbortsTheShareWhenTheAppMayNotMakeIt(): void {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn(json_encode(['alice'], JSON_THROW_ON_ERROR));
			$appConfig->method('getValueBool')->willReturn(false);
			$this->appConfig = $appConfig;
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->with('Work')->willReturn(false);
			$this->userFolder->expects($this->never())->method('newFolder');

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertStringContainsString('Work', (string)$event->getError());
			$this->assertTrue($event->isPropagationStopped());
		}

		public function testAnExistingParentFolderIsNotMadeAgain(): void {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn(json_encode(['alice'], JSON_THROW_ON_ERROR));
			$appConfig->method('getValueBool')->willReturn(false);
			$this->appConfig = $appConfig;
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->with('Work')->willReturn(true);
			$this->userFolder->method('get')->with('Work')->willReturn($this->createMock(Folder::class));
			$this->userFolder->expects($this->never())->method('newFolder');

			$share = $this->share();
			$share->method('newAttributes')->willReturn($this->createMock(IAttributes::class));
			$share->expects($this->once())->method('setTarget')->with('/Work/Client Files');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
		}

		public function testTheHighestNewFolderIsRecorded(): void {
			// "A" is there, "A/B" and "A/B/C" are not. The app makes both, so
			// it may remove "A/B" later, and never "A".
			$this->storePendingTarget(['path' => 'A/B/C/Files', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->willReturnMap([
				['A', true],
				['A/B', false],
				['A/B/C', false],
			]);
			$this->userFolder->expects($this->once())->method('newFolder')->with('A/B/C');

			$written = [];
			$share = $this->share();
			$share->method('newAttributes')->willReturn($this->attributesWriting($written));
			$share->expects($this->once())->method('setTarget')->with('/A/B/C/Files');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertSame('A/B', $written['created_dir']);
		}

		public function testTheHighestNewFolderIsTheFirstSegmentWhenTheChainIsMissing(): void {
			// Nothing of "A/B/C" is there. The app makes all three, so it may
			// remove "A" later.
			$this->storePendingTarget(['path' => 'A/B/C/Files', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->willReturnMap([
				['A', false],
				['A/B', false],
				['A/B/C', false],
			]);
			$this->userFolder->expects($this->once())->method('newFolder')->with('A/B/C');

			$written = [];
			$share = $this->share();
			$share->method('newAttributes')->willReturn($this->attributesWriting($written));
			$share->expects($this->once())->method('setTarget')->with('/A/B/C/Files');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertSame('A', $written['created_dir']);
		}

		public function testAGroupShareGetsTheTargetAndNoFolder(): void {
			$this->storePendingTarget(['path' => 'Work/Client Files', 'ts' => time()], 'testers');

			$this->userFolder->expects($this->never())->method('newFolder');

			$written = [];
			$share = $this->share(IShare::TYPE_GROUP);
			$share->method('newAttributes')->willReturn($this->attributesWriting($written));
			$share->expects($this->once())->method('setTarget')->with('/Work/Client Files');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertSame('Work/Client Files', $written['target_path']);
			// A group share has many homes, so the app makes no folder and
			// records none. ShareDeletedListener must find nothing to remove.
			$this->assertArrayNotHasKey('created_dir', $written);
		}

		public function testAFileOnTheParentPathAbortsTheShareWhenTheAppMayNotMakeIt(): void {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturn(json_encode(['alice'], JSON_THROW_ON_ERROR));
			$appConfig->method('getValueBool')->willReturn(false);
			$this->appConfig = $appConfig;
			$this->storePendingTarget(['path' => 'Blocked/Sub', 'ts' => time()]);

			$this->userFolder->method('nodeExists')->with('Blocked')->willReturn(true);
			$this->userFolder->method('get')->with('Blocked')->willReturn($this->createMock(File::class));

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNotNull($event->getError());
			$this->assertTrue($event->isPropagationStopped());
		}

		public function testAShareTypeOutsideUserAndGroupIsLeftAlone(): void {
			$this->userConfig->expects($this->never())->method('getValueArray');

			$share = $this->share(IShare::TYPE_LINK);
			$share->expects($this->never())->method('setTarget');

			$this->listener()->handle(new BeforeShareCreatedEvent($share));
		}

		public function testAPathWithNoParentRecordsNoFolder(): void {
			// The seam with ShareDeletedListener: without a parent segment the
			// app makes no folder, so it must record none to remove.
			$this->storePendingTarget(['path' => 'Client Files', 'ts' => time()]);

			$this->userFolder->expects($this->never())->method('newFolder');

			$written = [];
			$share = $this->share();
			$share->method('newAttributes')->willReturn($this->attributesWriting($written));
			$share->expects($this->once())->method('setTarget')->with('/Client Files');

			$this->listener()->handle(new BeforeShareCreatedEvent($share));

			$this->assertArrayNotHasKey('created_dir', $written);
		}

		public function testTheTargetOfTheRecipientIsUsed(): void {
			$this->storePendingTarget(['path' => 'For Bob', 'ts' => time()], 'bob');

			$share = $this->share();
			$share->method('newAttributes')->willReturn($this->createMock(IAttributes::class));
			$share->expects($this->once())->method('setTarget')->with('/For Bob');

			$this->listener()->handle(new BeforeShareCreatedEvent($share));
		}

		public function testTheTargetOfAnotherRecipientIsNotUsed(): void {
			$this->storePendingTarget(['path' => 'For Carol', 'ts' => time()], 'carol');

			$share = $this->share();
			$share->expects($this->never())->method('setTarget');

			$event = new BeforeShareCreatedEvent($share);
			$this->listener()->handle($event);

			$this->assertNull($event->getError());
			$this->assertFalse($event->isPropagationStopped());
		}

		/**
		 * An IAttributes double that records what the listener writes.
		 *
		 * @param array<string, mixed> $written
		 */
		private function attributesWriting(array &$written): IAttributes&MockObject {
			$attributes = $this->createMock(IAttributes::class);
			$attributes->method('setAttribute')
				->willReturnCallback(function (string $scope, string $key, $value) use (&$written, $attributes) {
					$written[$key] = $value;
					return $attributes;
				});
			return $attributes;
		}

		/** @param array{path: string, ts: int} $value */
		private function storePendingTarget(array $value, string $shareWith = 'bob'): void {
			$key = 'target.42.' . substr(sha1($shareWith), 0, 16);

			$this->userConfig->method('getValueArray')
				->willReturnCallback(static function (string $uid, string $app, string $k) use ($key, $value): array {
					return $k === $key ? $value : [];
				});
		}

		private function share(int $type = IShare::TYPE_USER): IShare&MockObject {
			$share = $this->createMock(IShare::class);
			$share->method('getShareType')->willReturn($type);
			$share->method('getSharedBy')->willReturn('alice');
			$share->method('getSharedWith')->willReturn($type === IShare::TYPE_GROUP ? 'testers' : 'bob');
			$share->method('getNodeId')->willReturn(42);
			return $share;
		}

		private function listener(): BeforeShareCreatedListener {
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $text);

			$service = new ShareTargetService(
				$this->appConfig,
				$this->userConfig,
				$this->createMock(IGroupManager::class),
			);

			return new BeforeShareCreatedListener(
				$service,
				new PathValidator($l),
				$this->rootFolder,
				$this->createMock(LoggerInterface::class),
			);
		}
	}
}
