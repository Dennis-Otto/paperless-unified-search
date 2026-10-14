<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit;

use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;
use RuntimeException;

/**
 * The settings of the apps and the credentials manager of a Nextcloud, in memory, for the
 * tests that read and write them as the app does.
 */
trait AppSettings {
	/** @var array<string, mixed> the values of the apps, by app.key */
	private array $settings = [];

	/** The stored token: null for none, false for one that can't be decrypted. */
	private string|false|null $storedToken = null;

	/**
	 * The settings of the apps, in $this->settings.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = ''): string {
			$value = $this->settings[$app . '.' . $key] ?? $default;

			return is_string($value) ? $value : $default;
		});
		$config->method('getValueBool')->willReturnCallback(function (string $app, string $key, bool $default = false): bool {
			$value = $this->settings[$app . '.' . $key] ?? $default;

			return is_bool($value) ? $value : $default;
		});
		$config->method('getValueArray')->willReturnCallback(function (string $app, string $key, array $default = []): array {
			$value = $this->settings[$app . '.' . $key] ?? $default;

			return is_array($value) ? $value : $default;
		});
		$set = function (string $app, string $key, mixed $value): bool {
			$this->settings[$app . '.' . $key] = $value;

			return true;
		};
		$config->method('setValueString')->willReturnCallback($set);
		$config->method('setValueBool')->willReturnCallback($set);
		$config->method('setValueArray')->willReturnCallback($set);
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => array_key_exists($app . '.' . $key, $this->settings));
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->settings[$app . '.' . $key]);
		});

		return $config;
	}

	/**
	 * The credentials manager, with the token in $this->storedToken.
	 */
	private function credentialsManager(): ICredentialsManager {
		$credentials = $this->createStub(ICredentialsManager::class);
		$credentials->method('store')->willReturnCallback(function (string $userId, string $identifier, string $token): void {
			$this->storedToken = $token;
		});
		$credentials->method('retrieve')->willReturnCallback(function (): ?string {
			if ($this->storedToken === false) {
				throw new RuntimeException('HMAC does not match.');
			}

			return $this->storedToken;
		});
		$credentials->method('delete')->willReturnCallback(function (): int {
			$this->storedToken = null;

			return 1;
		});

		return $credentials;
	}
}
