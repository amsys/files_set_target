<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Tests\Unit;

use OCA\SetTarget\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	public function testAOneSegmentTargetLinksToTheRoot(): void {
		$this->assertLinkDir('Docs', '/');
	}

	public function testATargetWithAParentLinksToTheParent(): void {
		$this->assertLinkDir('Work/Client Files', '/Work');
	}

	/** Prepare a notification for the target and check the folder of the link. */
	private function assertLinkDir(string $target, string $expectedDir): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('files_set_target');
		$notification->method('getSubject')->willReturn('share_with_custom_target');
		$notification->method('getSubjectParameters')->willReturn([
			'target_path' => $target,
			'shared_by' => 'alice',
			'share_name' => 'Docs',
		]);
		$notification->method('getObjectId')->willReturn('7');
		$notification->method('setParsedSubject')->willReturnSelf();
		$notification->method('setRichSubject')->willReturnSelf();
		$notification->method('setLink')->willReturnSelf();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('files.view.index', ['dir' => $expectedDir])
			->willReturn('https://example.test/f');

		$notifier = new Notifier($factory, $urlGenerator);

		$this->assertSame($notification, $notifier->prepare($notification, 'en'));
	}
}
