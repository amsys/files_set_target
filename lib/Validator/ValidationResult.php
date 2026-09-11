<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Validator;

final class ValidationResult {
	private function __construct(
		private readonly bool $valid,
		private readonly ?string $sanitized,
		private readonly ?string $error,
		private readonly bool $hasParentDir,
	) {
	}

	public static function ok(string $sanitized, bool $hasParentDir): self {
		return new self(true, $sanitized, null, $hasParentDir);
	}

	public static function error(string $error): self {
		return new self(false, null, $error, false);
	}

	public function isValid(): bool {
		return $this->valid;
	}

	public function getSanitized(): ?string {
		return $this->sanitized;
	}

	public function getError(): ?string {
		return $this->error;
	}

	public function hasParentDir(): bool {
		return $this->hasParentDir;
	}
}
