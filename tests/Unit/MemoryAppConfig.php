<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use Closure;
use LogicException;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Exceptions\AppConfigUnknownKeyException;
use OCP\IAppConfig;

/**
 * The configuration of the apps in memory, as Nextcloud's AppConfig behaves for a request
 * that has loaded none of it yet:
 *
 * - A value keeps the type that it was stored with; reading or storing it as another type
 *   fails, as in Nextcloud.
 * - A lazy value only loads for a lazy read: one that isn't lazy gets the default instead.
 *   A lazy read finds the values that aren't lazy as well. A fake that ignored the flag
 *   let a lazy value read without it pass every test, and fail in Nextcloud.
 * - Storing the value that is stored already, with the same flags, changes nothing and
 *   returns false, so that Nextcloud writes nothing.
 *
 * It notes each call that reads or writes a value, for the tests that count them, and
 * fails a test for a method of IAppConfig that the app doesn't use.
 */
final class MemoryAppConfig implements IAppConfig {
	/** @var array<string, array<string, array{value: mixed, type: int, lazy: bool, sensitive: bool}>> by app and key */
	private array $values = [];

	/** @var list<array{string, string}> the method and the "app.key" of every call, oldest first */
	private array $calls = [];

	/** @var array<string, Closure(): void> what happens right after the next write of an "app.key" */
	private array $afterNextWrite = [];

	/**
	 * A configuration with these values, by "app.key", as other apps and earlier requests
	 * left them, none of them lazy.
	 *
	 * @param array<string, string|int|float|bool|array<array-key, mixed>> $values
	 */
	public function __construct(array $values = []) {
		foreach ($values as $appKey => $value) {
			[$app, $key] = explode('.', $appKey, 2);
			$this->seed($app, $key, $value);
		}
	}

	/**
	 * Stores a value as another app or an earlier request left it, without noting a call.
	 *
	 * @param string|int|float|bool|array<array-key, mixed> $value
	 */
	public function seed(string $app, string $key, string|int|float|bool|array $value, bool $lazy = false): self {
		$this->values[$app][$key] = ['value' => $value, 'type' => self::typeOf($value), 'lazy' => $lazy, 'sensitive' => false];

		return $this;
	}

	/**
	 * The stored value, whether lazy or not, or null when none is; without noting a call.
	 */
	public function stored(string $app, string $key): mixed {
		return $this->values[$app][$key]['value'] ?? null;
	}

	/**
	 * The stored values of an app by key, without noting a call.
	 *
	 * @return array<string, mixed>
	 */
	public function storedValues(string $app): array {
		return array_map(static fn (array $entry): mixed => $entry['value'], $this->values[$app] ?? []);
	}

	/**
	 * The "app.key" of each call of this method so far, oldest first.
	 *
	 * @return list<string>
	 */
	public function calls(string $method): array {
		$keys = [];
		foreach ($this->calls as [$called, $appKey]) {
			if ($called === $method) {
				$keys[] = $appKey;
			}
		}

		return $keys;
	}

	/**
	 * Runs $then right after the next write of this key, as another request that comes in
	 * between.
	 *
	 * @param Closure(): void $then
	 */
	public function afterNextWrite(string $app, string $key, Closure $then): void {
		$this->afterNextWrite[$app . '.' . $key] = $then;
	}

	public function getApps(): array {
		$apps = array_keys(array_filter($this->values));
		sort($apps);

		return $apps;
	}

	public function getKeys(string $app): array {
		$keys = array_keys($this->values[$app] ?? []);
		sort($keys);

		return $keys;
	}

	public function searchKeys(string $app, string $prefix = '', bool $lazy = false): array {
		throw self::unused(__FUNCTION__);
	}

	public function hasKey(string $app, string $key, ?bool $lazy = false): bool {
		$this->note(__FUNCTION__, $app, $key);
		$entry = $this->values[$app][$key] ?? null;

		return $entry !== null && ($lazy === null || $entry['lazy'] === $lazy);
	}

	public function isSensitive(string $app, string $key, ?bool $lazy = false): bool {
		return $this->entry($app, $key)['sensitive'];
	}

	public function isLazy(string $app, string $key): bool {
		return $this->entry($app, $key)['lazy'];
	}

	public function getAllValues(string $app, string $prefix = '', bool $filtered = false): array {
		throw self::unused(__FUNCTION__);
	}

	public function searchValues(string $key, bool $lazy = false, ?int $typedAs = null): array {
		throw self::unused(__FUNCTION__);
	}

	public function getValueString(string $app, string $key, string $default = '', bool $lazy = false): string {
		return $this->read(__FUNCTION__, $app, $key, $default, $lazy, self::VALUE_STRING);
	}

	public function getValueInt(string $app, string $key, int $default = 0, bool $lazy = false): int {
		return $this->read(__FUNCTION__, $app, $key, $default, $lazy, self::VALUE_INT);
	}

	public function getValueFloat(string $app, string $key, float $default = 0, bool $lazy = false): float {
		return $this->read(__FUNCTION__, $app, $key, $default, $lazy, self::VALUE_FLOAT);
	}

	public function getValueBool(string $app, string $key, bool $default = false, bool $lazy = false): bool {
		return $this->read(__FUNCTION__, $app, $key, $default, $lazy, self::VALUE_BOOL);
	}

	public function getValueArray(string $app, string $key, array $default = [], bool $lazy = false): array {
		return $this->read(__FUNCTION__, $app, $key, $default, $lazy, self::VALUE_ARRAY);
	}

	public function getValueType(string $app, string $key, ?bool $lazy = null): int {
		return $this->entry($app, $key)['type'];
	}

	public function setValueString(string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
		return $this->write(__FUNCTION__, $app, $key, $value, $lazy, $sensitive);
	}

	public function setValueInt(string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool {
		return $this->write(__FUNCTION__, $app, $key, $value, $lazy, $sensitive);
	}

	public function setValueFloat(string $app, string $key, float $value, bool $lazy = false, bool $sensitive = false): bool {
		return $this->write(__FUNCTION__, $app, $key, $value, $lazy, $sensitive);
	}

	public function setValueBool(string $app, string $key, bool $value, bool $lazy = false): bool {
		return $this->write(__FUNCTION__, $app, $key, $value, $lazy, false);
	}

	public function setValueArray(string $app, string $key, array $value, bool $lazy = false, bool $sensitive = false): bool {
		return $this->write(__FUNCTION__, $app, $key, $value, $lazy, $sensitive);
	}

	public function updateSensitive(string $app, string $key, bool $sensitive): bool {
		throw self::unused(__FUNCTION__);
	}

	public function updateLazy(string $app, string $key, bool $lazy): bool {
		throw self::unused(__FUNCTION__);
	}

	public function getDetails(string $app, string $key): array {
		throw self::unused(__FUNCTION__);
	}

	public function getKeyDetails(string $app, string $key): array {
		throw self::unused(__FUNCTION__);
	}

	public function convertTypeToInt(string $type): int {
		throw self::unused(__FUNCTION__);
	}

	public function convertTypeToString(int $type): string {
		throw self::unused(__FUNCTION__);
	}

	public function deleteKey(string $app, string $key): void {
		$this->note(__FUNCTION__, $app, $key);
		unset($this->values[$app][$key]);
	}

	public function deleteApp(string $app): void {
		unset($this->values[$app]);
	}

	public function clearCache(bool $reload = false): void {
	}

	public function getValues($app, $key) {
		throw self::unused(__FUNCTION__);
	}

	public function getFilteredValues($app) {
		throw self::unused(__FUNCTION__);
	}

	public function getAppInstalledVersions(bool $onlyEnabled = false): array {
		throw self::unused(__FUNCTION__);
	}

	/**
	 * A value as Nextcloud reads it: the default for a key that isn't stored, and for a lazy
	 * one that a read which isn't lazy asks for, since such a read loads no lazy value.
	 */
	private function read(string $method, string $app, string $key, mixed $default, bool $lazy, int $type): mixed {
		$this->note($method, $app, $key);
		$entry = $this->values[$app][$key] ?? null;
		if ($entry === null || ($entry['lazy'] && !$lazy)) {
			return $default;
		}

		if ($entry['type'] !== $type) {
			throw new AppConfigTypeConflictException('conflict with value type from database');
		}

		return $entry['value'];
	}

	/**
	 * Stores a value as Nextcloud does: false, and nothing written, when the value is
	 * stored with these flags already; a value that is stored lazily or not becomes so.
	 */
	private function write(string $method, string $app, string $key, mixed $value, bool $lazy, bool $sensitive): bool {
		$this->note($method, $app, $key);
		$type = self::typeOf($value);
		$entry = $this->values[$app][$key] ?? null;
		if ($entry !== null && $entry['type'] !== $type) {
			throw new AppConfigTypeConflictException('conflict between new type and old type');
		}

		$sensitive = $sensitive || ($entry['sensitive'] ?? false);
		if ($entry !== null && $entry['value'] === $value && $entry['lazy'] === $lazy && $entry['sensitive'] === $sensitive) {
			return false;
		}

		$this->values[$app][$key] = ['value' => $value, 'type' => $type, 'lazy' => $lazy, 'sensitive' => $sensitive];
		$then = $this->afterNextWrite[$app . '.' . $key] ?? null;
		unset($this->afterNextWrite[$app . '.' . $key]);
		if ($then !== null) {
			$then();
		}

		return true;
	}

	/**
	 * @return array{value: mixed, type: int, lazy: bool, sensitive: bool}
	 * @throws AppConfigUnknownKeyException for a key that isn't stored, as Nextcloud
	 */
	private function entry(string $app, string $key): array {
		return $this->values[$app][$key] ?? throw new AppConfigUnknownKeyException('unknown config key');
	}

	private function note(string $method, string $app, string $key): void {
		$this->calls[] = [$method, $app . '.' . $key];
	}

	private static function typeOf(mixed $value): int {
		return match (true) {
			is_string($value) => self::VALUE_STRING,
			is_int($value) => self::VALUE_INT,
			is_float($value) => self::VALUE_FLOAT,
			is_bool($value) => self::VALUE_BOOL,
			is_array($value) => self::VALUE_ARRAY,
			default => throw new LogicException('Nextcloud stores no value of type ' . get_debug_type($value) . '.'),
		};
	}

	private static function unused(string $method): LogicException {
		return new LogicException('The app uses no IAppConfig::' . $method . '(); the fake would have to learn it first.');
	}
}
