<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Tests\Unit;

use OCA\SetTarget\Listener\ShareCreatedListener;
use OCA\SetTarget\Notification\TargetNotifier;
use OCP\Files\Node;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IAttributes;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShareCreatedListenerTest extends TestCase {
	private IShareManager&MockObject $shareManager;
	private IGroupManager&MockObject $groupManager;
	private TargetNotifier&MockObject $notifier;

	protected function setUp(): void {
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notifier = $this->createMock(TargetNotifier::class);
	}

	public function testGroupTargetIsWrittenForEveryMember(): void {
		$this->groupManager->method('get')->with('testers')->willReturn(
			$this->group(['bob', 'carol']),
		);

		$share = $this->share(IShare::TYPE_GROUP, 'testers');
		$moved = [];
		$this->shareManager->expects($this->exactly(2))
			->method('moveShare')
			->willReturnCallback(function (IShare $s, string $uid) use (&$moved, $share) {
				$this->assertSame($share, $s);
				$moved[] = $uid;
				return $s;
			});

		$this->listener()->handle(new ShareCreatedEvent($share));

		$this->assertSame(['bob', 'carol'], $moved);
	}

	public function testUserShareIsNotMoved(): void {
		$this->shareManager->expects($this->never())->method('moveShare');

		$this->listener()->handle(new ShareCreatedEvent($this->share(IShare::TYPE_USER, 'bob')));
	}

	public function testShareWithoutATargetIsLeftAlone(): void {
		$this->shareManager->expects($this->never())->method('moveShare');

		$share = $this->share(IShare::TYPE_GROUP, 'testers', null);
		$this->listener()->handle(new ShareCreatedEvent($share));
	}

	public function testOneFailingMemberDoesNotStopTheOthers(): void {
		$this->groupManager->method('get')->willReturn($this->group(['bob', 'carol']));

		$share = $this->share(IShare::TYPE_GROUP, 'testers');
		$moved = [];
		$this->shareManager->method('moveShare')
			->willReturnCallback(function (IShare $s, string $uid) use (&$moved) {
				if ($uid === 'bob') {
					throw new \InvalidArgumentException('Unknown share recipient');
				}
				$moved[] = $uid;
				return $s;
			});

		$this->listener()->handle(new ShareCreatedEvent($share));

		$this->assertSame(['carol'], $moved);
	}

	public function testTheRecipientIsNotified(): void {
		$share = $this->share(IShare::TYPE_USER, 'bob');

		$this->notifier->expects($this->once())
			->method('send')
			->with($share, 'Work/Client Files', 'Docs');

		$this->listener()->handle(new ShareCreatedEvent($share));
	}

	public function testAShareWithoutATargetSendsNoNotification(): void {
		$this->notifier->expects($this->never())->method('send');

		$this->listener()->handle(new ShareCreatedEvent($this->share(IShare::TYPE_USER, 'bob', null)));
	}

	public function testAGroupThatIsGoneStopsTheMemberLoop(): void {
		$this->groupManager->method('get')->with('testers')->willReturn(null);
		$this->shareManager->expects($this->never())->method('moveShare');
		// The share exists, so the recipients still get told about it.
		$this->notifier->expects($this->once())->method('send');

		$this->listener()->handle(new ShareCreatedEvent($this->share(IShare::TYPE_GROUP, 'testers')));
	}

	/** @param list<string> $uids */
	private function group(array $uids): IGroup&MockObject {
		$users = [];
		foreach ($uids as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		return $group;
	}

	private function share(int $type, string $sharedWith, ?string $target = 'Work/Client Files'): IShare&MockObject {
		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('getAttribute')->with('files_set_target', 'target_path')->willReturn($target);

		$node = $this->createMock(Node::class);
		$node->method('getName')->willReturn('Docs');

		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn($type);
		$share->method('getSharedWith')->willReturn($sharedWith);
		$share->method('getAttributes')->willReturn($attributes);
		$share->method('getNode')->willReturn($node);
		$share->method('getFullId')->willReturn('ocinternal:7');
		return $share;
	}

	private function listener(): ShareCreatedListener {
		return new ShareCreatedListener(
			$this->notifier,
			$this->shareManager,
			$this->groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}
}
