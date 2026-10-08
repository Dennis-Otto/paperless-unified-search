<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Model;

use JsonSerializable;

/**
 * The last search that failed, as the administration settings show it: when it failed,
 * in which step, with which error and after how long.
 */
final class SearchFailure implements JsonSerializable {
	/** Asking Paperless for the documents of the term. */
	public const STEP_PAPERLESS = 'paperless';
	/** Looking for the files of the documents in the folders of the user. */
	public const STEP_FILES = 'files';

	public function __construct(
		public readonly int $time,
		public readonly string $step,
		public readonly string $error,
		public readonly string $message,
		public readonly int $durationMs,
	) {
	}

	/**
	 * The failure of the stored values, or null when they describe none.
	 *
	 * @param array<array-key, mixed> $values
	 */
	public static function fromArray(array $values): ?self {
		$time = $values['time'] ?? null;
		$step = $values['step'] ?? null;
		$error = $values['error'] ?? null;
		$message = $values['message'] ?? null;
		$durationMs = $values['durationMs'] ?? null;

		if (!is_int($time) || !in_array($step, [self::STEP_PAPERLESS, self::STEP_FILES], true)
			|| !is_string($error) || !is_string($message) || !is_int($durationMs)) {
			return null;
		}

		return new self($time, $step, $error, $message, $durationMs);
	}

	/**
	 * @return array{time: int, step: string, error: string, message: string, durationMs: int}
	 */
	public function jsonSerialize(): array {
		return [
			'time' => $this->time,
			'step' => $this->step,
			'error' => $this->error,
			'message' => $this->message,
			'durationMs' => $this->durationMs,
		];
	}
}
