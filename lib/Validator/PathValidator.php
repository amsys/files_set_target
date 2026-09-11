<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Martin <dev@amsys.cz>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SetTarget\Validator;

use OCP\IL10N;

/**
 * Validates and sanitizes share target paths.
 *
 * Rules:
 * - Maximum length: 500 characters total, each segment max 255 characters
 * - No null bytes
 * - No path traversal sequences (.. or .)
 * - No leading/trailing slashes (we strip them)
 * - No double slashes
 * - Only printable, non-control characters allowed
 * - Forbidden characters (same as Nextcloud enforces): \ : * ? " < > |
 * - No leading/trailing whitespace in any segment
 */
class PathValidator {
	private const MAX_PATH_LENGTH = 500;
	private const MAX_SEGMENT_LENGTH = 255;
	// Characters forbidden by most filesystems and Nextcloud itself
	private const FORBIDDEN_CHARS_PATTERN = '/[\x00-\x1F\x7F\\\\:*?"<>|]/u';
	// Reserved names on Windows (cross-platform safety)
	private const RESERVED_NAMES = [
		'CON', 'PRN', 'AUX', 'NUL',
		'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
		'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
	];

	public function __construct(
		private readonly IL10N $l,
	) {
	}

	public function validate(string $path): ValidationResult {
		// Strip leading/trailing whitespace and slashes from the overall path
		$path = trim($path, " \t\n\r\0\x0B/");

		if ($path === '') {
			return ValidationResult::error($this->l->t('Path must not be empty.'));
		}

		// Reject invalid UTF-8 up front. The forbidden-character regex below
		// uses the /u modifier, which fails silently (no match, no error) on
		// invalid UTF-8 — without this check a malformed byte sequence would
		// skip the forbidden-character test entirely and pass as valid.
		if (!mb_check_encoding($path, 'UTF-8')) {
			return ValidationResult::error($this->l->t('Path contains invalid UTF-8.'));
		}

		if (strlen($path) > self::MAX_PATH_LENGTH) {
			return ValidationResult::error(
				$this->l->t('Path must not exceed %d characters.', [self::MAX_PATH_LENGTH])
			);
		}

		// Split into segments and validate each
		$segments = explode('/', $path);
		$sanitizedSegments = [];

		foreach ($segments as $segment) {
			$segmentResult = $this->validateSegment($segment);
			if (!$segmentResult->isValid()) {
				return $segmentResult;
			}
			$sanitizedSegments[] = $segmentResult->getSanitized();
		}

		$sanitized = implode('/', $sanitizedSegments);
		$hasParentDir = str_contains($sanitized, '/');

		return ValidationResult::ok($sanitized, $hasParentDir);
	}

	private function validateSegment(string $segment): ValidationResult {
		// Trim leading whitespace (e.g. from user input like "  folder")
		$segment = ltrim($segment);

		if ($segment === '') {
			return ValidationResult::error(
				$this->l->t('Path segment must not be empty (double slash or leading/trailing slash).')
			);
		}

		// Path traversal check FIRST — most critical security check
		if ($segment === '.' || $segment === '..') {
			return ValidationResult::error(
				$this->l->t('Path traversal sequences (. and ..) are not allowed.')
			);
		}

		// Reject trailing dot or space AFTER ltrim (catches "foo.", "foo ")
		if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
			return ValidationResult::error(
				$this->l->t('Path segment "%s" must not end with a dot or space.', [mb_substr($segment, 0, 40)])
			);
		}

		if (strlen($segment) > self::MAX_SEGMENT_LENGTH) {
			return ValidationResult::error(
				$this->l->t(
					'Path segment "%s" exceeds the maximum length of %d characters.',
					[mb_substr($segment, 0, 40) . '…', self::MAX_SEGMENT_LENGTH],
				)
			);
		}

		// Check for forbidden characters
		if (preg_match(self::FORBIDDEN_CHARS_PATTERN, $segment)) {
			return ValidationResult::error(
				$this->l->t('Path segment "%s" contains forbidden characters.', [mb_substr($segment, 0, 40)])
			);
		}

		// Check for reserved names (case-insensitive)
		if (in_array(strtoupper($segment), self::RESERVED_NAMES, true)) {
			return ValidationResult::error(
				$this->l->t('"%s" is a reserved name and cannot be used as a folder name.', [$segment])
			);
		}

		return ValidationResult::ok($segment, false);
	}
}
