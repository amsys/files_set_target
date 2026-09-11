<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Tests\Unit;

use OCA\SetTarget\Validator\PathValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PathValidatorTest extends TestCase {
	private PathValidator $validator;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []) => $text);
		$this->validator = new PathValidator($l);
	}

	public function testAcceptsSimplePath(): void {
		$result = $this->validator->validate('Shared/Folder');
		$this->assertTrue($result->isValid());
		$this->assertSame('Shared/Folder', $result->getSanitized());
	}

	public function testRejectsEmptyPath(): void {
		$result = $this->validator->validate('   ');
		$this->assertFalse($result->isValid());
	}

	public function testRejectsPathTraversal(): void {
		$result = $this->validator->validate('foo/../bar');
		$this->assertFalse($result->isValid());
	}

	public function testRejectsForbiddenCharacters(): void {
		$result = $this->validator->validate('bad:name');
		$this->assertFalse($result->isValid());
	}

	public function testRejectsANullByteAsAForbiddenCharacter(): void {
		$result = $this->validator->validate("Work/Cli\0ent");
		$this->assertFalse($result->isValid());
		$this->assertStringContainsString('forbidden characters', (string)$result->getError());
	}

	public function testRejectsPathOverMaxLength(): void {
		$result = $this->validator->validate(str_repeat('a', 501));
		$this->assertFalse($result->isValid());
	}

	public function testAcceptsPathAtMaxLength(): void {
		// 500 chars total, split across segments so no single segment
		// exceeds MAX_SEGMENT_LENGTH (255).
		$path = str_repeat('a', 249) . '/' . str_repeat('b', 249);
		$result = $this->validator->validate($path);
		$this->assertTrue($result->isValid());
	}

	public function testRejectsASegmentOverTheSegmentLimit(): void {
		$result = $this->validator->validate('Work/' . str_repeat('a', 256));
		$this->assertFalse($result->isValid());
	}

	public function testAcceptsASegmentAtTheSegmentLimit(): void {
		$result = $this->validator->validate('Work/' . str_repeat('a', 255));
		$this->assertTrue($result->isValid());
	}

	public function testRejectsADoubleSlash(): void {
		// An empty segment is refused, not repaired: a path the sharer did not
		// mean must never become a folder in the account of the recipient.
		$result = $this->validator->validate('Work//Client Files');
		$this->assertFalse($result->isValid());
	}

	public function testAcceptsAPathWithAParent(): void {
		$result = $this->validator->validate('Work/Client Files');
		$this->assertTrue($result->isValid());
		$this->assertTrue($result->hasParentDir());
	}

	public function testASingleSegmentHasNoParent(): void {
		$result = $this->validator->validate('/Client Files/');
		$this->assertTrue($result->isValid());
		$this->assertSame('Client Files', $result->getSanitized());
		$this->assertFalse($result->hasParentDir());
	}

	public function testRejectsReservedName(): void {
		$result = $this->validator->validate('CON');
		$this->assertFalse($result->isValid());
	}

	public function testRejectsInvalidUtf8Bytes(): void {
		// A malformed byte (\xFF is never valid UTF-8) mixed with characters
		// that are also individually forbidden. Must fail closed: rejected,
		// not silently passed through.
		$result = $this->validator->validate("\xFFbad:name*?<>|");
		$this->assertFalse($result->isValid());
	}
}
