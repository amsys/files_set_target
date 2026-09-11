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

	use OCA\SetTarget\Listener\ShareDeletedListener;
	use OCA\SetTarget\Service\ShareTargetService;
	use OCP\Config\IUserConfig;
	use OCP\Files\File;
	use OCP\Files\Folder;
	use OCP\Files\IRootFolder;
	use OCP\IAppConfig;
	use OCP\IGroupManager;
	use OCP\Share\Events\ShareDeletedEvent;
	use OCP\Share\IAttributes;
	use OCP\Share\IShare;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;

	class ShareDeletedListenerTest extends TestCase {
		private IRootFolder&MockObject $rootFolder;
		private Folder&MockObject $userFolder;
		private IAppConfig&MockObject $appConfig;

		protected function setUp(): void {
			$this->rootFolder = $this->createMock(IRootFolder::class);
			$this->userFolder = $this->createMock(Folder::class);
			$this->rootFolder->method('getUserFolder')->willReturn($this->userFolder);
			$this->appConfig = $this->createMock(IAppConfig::class);
			$this->appConfig->method('getValueBool')->willReturn(true);
		}

		public function testAnEmptyFolderOfTheAppGoes(): void {
			$folder = $this->folderWith([]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->with('Work')->willReturn($folder);
			$folder->expects($this->once())->method('delete');

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', 'Work')));
		}

		public function testAFolderWithContentStays(): void {
			$folder = $this->folderWith([$this->createMock(File::class)]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->with('Work')->willReturn($folder);
			$folder->expects($this->never())->method('delete');

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', 'Work')));
		}

		public function testAFolderOfTheRecipientStays(): void {
			// The app made "Work/Clients", so "Work" is not its folder.
			$clients = $this->folderWith([]);
			$work = $this->folderWith([]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->willReturnMap([
				['Work/Clients', $clients],
				['Work', $work],
			]);
			$clients->expects($this->once())->method('delete');
			$work->expects($this->never())->method('delete');

			$share = $this->share('/Work/Clients/Files', 'Work/Clients');
			$this->listener()->handle(new ShareDeletedEvent($share));
		}

		public function testAMovedShareStillFreesTheFolderOfTheApp(): void {
			// The recipient moved the share out of "Work", so the target no
			// longer names that folder.
			$folder = $this->folderWith([]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->with('Work')->willReturn($folder);
			$folder->expects($this->once())->method('delete');

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Elsewhere/Client Files', 'Work')));
		}

		public function testEveryFolderOfTheAppGoesFromTheDeepestUp(): void {
			// The app made "Work/Clients" and "Work/Clients/2026". Both go,
			// and "Work", which was there before, stays.
			$year = $this->folderWith([]);
			$clients = $this->folderWith([]);
			$work = $this->folderWith([]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->willReturnMap([
				['Work/Clients/2026', $year],
				['Work/Clients', $clients],
				['Work', $work],
			]);
			$year->expects($this->once())->method('delete');
			$clients->expects($this->once())->method('delete');
			$work->expects($this->never())->method('delete');

			$share = $this->share('/Work/Clients/2026/Files', 'Work/Clients');
			$this->listener()->handle(new ShareDeletedEvent($share));
		}

		public function testADeepFolderWithContentStopsTheWalk(): void {
			// "2026" still holds a file, so neither it nor "Clients" may go.
			$year = $this->folderWith([$this->createMock(File::class)]);
			$clients = $this->folderWith([]);
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->willReturnMap([
				['Work/Clients/2026', $year],
				['Work/Clients', $clients],
			]);
			$year->expects($this->never())->method('delete');
			$clients->expects($this->never())->method('delete');

			$share = $this->share('/Work/Clients/2026/Files', 'Work/Clients');
			$this->listener()->handle(new ShareDeletedEvent($share));
		}

		public function testAFileWithTheNameOfTheFolderStopsTheWalk(): void {
			$this->userFolder->method('nodeExists')->willReturn(true);
			$this->userFolder->method('get')->with('Work')->willReturn($this->createMock(File::class));

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', 'Work')));
			$this->addToAssertionCount(1);
		}

		public function testAnAccountThatIsGoneDoesNotBreakTheDelete(): void {
			$rootFolder = $this->createMock(IRootFolder::class);
			$rootFolder->method('getUserFolder')->willThrowException(new \RuntimeException('no such account'));
			$this->rootFolder = $rootFolder;

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', 'Work')));
			$this->addToAssertionCount(1);
		}

		public function testAShareWithoutAFolderOfTheAppIsLeftAlone(): void {
			$this->userFolder->expects($this->never())->method('get');

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', null)));
		}

		public function testTheAdminSwitchStopsTheRemoval(): void {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueBool')->willReturn(false);
			$this->appConfig = $appConfig;
			$this->userFolder->expects($this->never())->method('get');

			$this->listener()->handle(new ShareDeletedEvent($this->share('/Work/Client Files', 'Work')));
		}

		public function testAGroupShareIsLeftAlone(): void {
			$this->userFolder->expects($this->never())->method('get');

			$share = $this->share('/Work/Client Files', 'Work', IShare::TYPE_GROUP);

			$this->listener()->handle(new ShareDeletedEvent($share));
		}

		/** @param list<mixed> $listing */
		private function folderWith(array $listing): Folder&MockObject {
			$folder = $this->createMock(Folder::class);
			$folder->method('getDirectoryListing')->willReturn($listing);
			return $folder;
		}

		private function share(string $target, ?string $createdDir, int $type = IShare::TYPE_USER): IShare&MockObject {
			$attributes = $this->createMock(IAttributes::class);
			$attributes->method('getAttribute')
				->with('files_set_target', 'created_dir')
				->willReturn($createdDir);

			$share = $this->createMock(IShare::class);
			$share->method('getShareType')->willReturn($type);
			$share->method('getSharedWith')->willReturn('bob');
			$share->method('getTarget')->willReturn($target);
			$share->method('getAttributes')->willReturn($attributes);
			return $share;
		}

		private function listener(): ShareDeletedListener {
			$service = new ShareTargetService(
				$this->appConfig,
				$this->createMock(IUserConfig::class),
				$this->createMock(IGroupManager::class),
			);

			return new ShareDeletedListener(
				$service,
				$this->rootFolder,
				$this->createMock(LoggerInterface::class),
			);
		}
	}
}
