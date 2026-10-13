<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use Closure;
use OCP\IMemcache;

/**
 * A cache of Nextcloud in memory, as Redis, Memcached and APCu behave for the app: values
 * that expire after their time to live by the clock of the test, add() that sets only a
 * missing value, and inc() that starts a missing value at 0.
 */
final class MemoryCache implements IMemcache {
	/** @var array<string, array{mixed, ?int}> the value and its time of expiry, by key */
	private array $values = [];

	/**
	 * @param Closure(): int $clock the time of the test, in seconds
	 */
	public function __construct(
		private Closure $clock,
	) {
	}

	public function get($key) {
		return $this->hasKey($key) ? $this->values[$key][0] : null;
	}

	public function set($key, $value, $ttl = 0) {
		$this->values[$key] = [$value, $ttl > 0 ? ($this->clock)() + $ttl : null];

		return true;
	}

	public function hasKey($key) {
		if (!isset($this->values[$key])) {
			return false;
		}

		$expiry = $this->values[$key][1];
		if ($expiry !== null && $expiry <= ($this->clock)()) {
			unset($this->values[$key]);

			return false;
		}

		return true;
	}

	public function remove($key) {
		unset($this->values[$key]);

		return true;
	}

	public function clear($prefix = '') {
		foreach (array_keys($this->values) as $key) {
			if (str_starts_with($key, $prefix)) {
				unset($this->values[$key]);
			}
		}

		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}

	public function add($key, $value, $ttl = 0) {
		return !$this->hasKey($key) && $this->set($key, $value, $ttl);
	}

	public function inc($key, $step = 1) {
		$value = (int)$this->get($key) + $step;
		$this->values[$key] = [$value, $this->hasKey($key) ? $this->values[$key][1] : null];

		return $value;
	}

	public function dec($key, $step = 1) {
		return $this->inc($key, -$step);
	}

	public function cas($key, $old, $new) {
		return $this->get($key) === $old && $this->set($key, $new);
	}

	public function cad($key, $old) {
		return $this->get($key) === $old && $this->remove($key);
	}

	public function ncad(string $key, mixed $old): bool {
		return $this->hasKey($key) && $this->get($key) !== $old && $this->remove($key);
	}
}
