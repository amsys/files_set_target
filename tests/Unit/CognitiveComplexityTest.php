<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Tests\Unit;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cognitive complexity gate for every function in lib/.
 *
 * The metric is the one Campbell defines for SonarSource: a structure that
 * breaks the linear flow adds one point, and a structure nested inside
 * another adds one more point for each level of nesting. A short function
 * with a long flat switch stays cheap; a short function with three levels
 * of nested loops does not. The limit is 15.
 */
final class CognitiveComplexityTest extends TestCase {
	private const LIMIT = 15;

	private int $score = 0;

	/**
	 * @return array<string, array{string, string, array<Stmt>}>
	 */
	public static function functionProvider(): array {
		$parser = (new ParserFactory())->createForNewestSupportedVersion();
		$root = dirname(__DIR__, 2) . '/lib';
		$cases = [];

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$path = substr($file->getPathname(), strlen(dirname($root)) + 1);
			$ast = $parser->parse(file_get_contents($file->getPathname())) ?? [];
			foreach (self::functions($ast) as $name => $stmts) {
				$cases[$path . '::' . $name] = [$path, $name, $stmts];
			}
		}

		self::assertNotEmpty($cases, 'no PHP functions found under lib/');

		return $cases;
	}

	#[DataProvider('functionProvider')]
	public function testFunctionStaysUnderTheLimit(string $path, string $name, array $stmts): void {
		$this->score = 0;
		$this->walkAll($stmts, 0, null);

		$this->assertLessThanOrEqual(
			self::LIMIT,
			$this->score,
			sprintf(
				'%s::%s has a cognitive complexity of %d, the limit is %d. Split it.',
				$path,
				$name,
				$this->score,
				self::LIMIT,
			),
		);
	}

	/**
	 * Every named function and method in an AST, keyed by its name.
	 *
	 * @param array<Node> $nodes
	 * @return array<string, array<Stmt>>
	 */
	private static function functions(array $nodes): array {
		$found = [];
		foreach ($nodes as $node) {
			if (($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) && $node->stmts !== null) {
				$found[$node->name->toString()] = $node->stmts;
				continue;
			}
			foreach ($node->getSubNodeNames() as $sub) {
				$child = $node->$sub;
				if (is_array($child)) {
					$found += self::functions(array_filter($child, static fn ($n) => $n instanceof Node));
				} elseif ($child instanceof Node) {
					$found += self::functions([$child]);
				}
			}
		}

		return $found;
	}

	/**
	 * @param array<Node> $nodes
	 */
	private function walkAll(array $nodes, int $nesting, ?string $parentOp): void {
		foreach ($nodes as $node) {
			if ($node instanceof Node) {
				$this->walk($node, $nesting, $parentOp);
			}
		}
	}

	private function walk(Node $node, int $nesting, ?string $parentOp): void {
		if ($node instanceof Stmt\If_) {
			$this->walkIf($node, $nesting);
			return;
		}
		if ($node instanceof Stmt\Switch_ || $node instanceof Expr\Match_) {
			$this->score += 1 + $nesting;
			$this->walk($node->cond, $nesting, null);
			$this->walkAll($node->arms ?? $node->cases, $nesting + 1, null);
			return;
		}
		if ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
			|| $node instanceof Stmt\While_ || $node instanceof Stmt\Do_) {
			$this->score += 1 + $nesting;
			$this->walkBody($node, $nesting);
			return;
		}
		if ($node instanceof Stmt\TryCatch) {
			$this->walkTry($node, $nesting);
			return;
		}
		if ($node instanceof Expr\Ternary) {
			$this->score += 1 + $nesting;
			$this->walk($node->cond, $nesting, null);
			$this->walkAll(array_filter([$node->if, $node->else]), $nesting + 1, null);
			return;
		}
		if ($this->isLogical($node)) {
			$op = $node::class;
			$this->score += $op === $parentOp ? 0 : 1;
			$this->walk($node->left, $nesting, $op);
			$this->walk($node->right, $nesting, $op);
			return;
		}
		if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
			$this->walkAll(is_array($node->stmts ?? null) ? $node->stmts : [$node->expr], $nesting + 1, null);
			return;
		}

		$this->walkChildren($node, $nesting);
	}

	private function walkIf(Stmt\If_ $node, int $nesting): void {
		$this->score += 1 + $nesting;
		$this->walk($node->cond, $nesting, null);
		$this->walkAll($node->stmts, $nesting + 1, null);

		// An `elseif` and an `else` each cost one point and no nesting: the
		// reader follows one chain, not one more level.
		foreach ($node->elseifs as $elseif) {
			$this->score += 1;
			$this->walk($elseif->cond, $nesting, null);
			$this->walkAll($elseif->stmts, $nesting + 1, null);
		}
		if ($node->else !== null) {
			$this->score += 1;
			$this->walkAll($node->else->stmts, $nesting + 1, null);
		}
	}

	private function walkTry(Stmt\TryCatch $node, int $nesting): void {
		$this->walkAll($node->stmts, $nesting, null);
		foreach ($node->catches as $catch) {
			$this->score += 1 + $nesting;
			$this->walkAll($catch->stmts, $nesting + 1, null);
		}
		if ($node->finally !== null) {
			$this->walkAll($node->finally->stmts, $nesting, null);
		}
	}

	/** The head of a loop stays at the current level; the body goes one deeper. */
	private function walkBody(Node $node, int $nesting): void {
		foreach ($node->getSubNodeNames() as $sub) {
			$child = $node->$sub;
			$deeper = $sub === 'stmts' ? $nesting + 1 : $nesting;
			if (is_array($child)) {
				$this->walkAll($child, $deeper, null);
			} elseif ($child instanceof Node) {
				$this->walk($child, $deeper, null);
			}
		}
	}

	private function walkChildren(Node $node, int $nesting): void {
		foreach ($node->getSubNodeNames() as $sub) {
			$child = $node->$sub;
			if (is_array($child)) {
				$this->walkAll($child, $nesting, null);
			} elseif ($child instanceof Node) {
				$this->walk($child, $nesting, null);
			}
		}
	}

	private function isLogical(Node $node): bool {
		return $node instanceof Expr\BinaryOp\BooleanAnd
			|| $node instanceof Expr\BinaryOp\BooleanOr
			|| $node instanceof Expr\BinaryOp\LogicalAnd
			|| $node instanceof Expr\BinaryOp\LogicalOr;
	}
}
