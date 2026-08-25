<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Service;

use InvalidArgumentException;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCP\IAppConfig;
use OCP\Security\ICredentialsManager;

final class ConfigService {
	private const URL_KEY = 'paperless_url';
	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
		private ICredentialsManager $credentialsManager,
	) {
	}

	public function getPublicConfig(): PublicConfig {
		return new PublicConfig($this->getUrl(), $this->getToken() !== '');
	}

	public function isConfigured(): bool {
		return $this->getUrl() !== '' && $this->getToken() !== '';
	}

	public function getUrl(): string {
		return $this->config->getValueString(AppConstants::APP_ID, self::URL_KEY, '');
	}

	public function getToken(): string {
		/** @psalm-suppress MixedAssignment */
		$token = $this->credentialsManager->retrieve('', self::TOKEN_IDENTIFIER);

		return is_string($token) ? $token : '';
	}

	public function resolveToken(string $candidate): string {
		$token = trim($candidate);
		if ($token === '') {
			$token = $this->getToken();
		}

		if ($token === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		return $token;
	}

	public function save(string $url, string $token): PublicConfig {
		$normalizedUrl = $this->normalizeUrl($url);
		$normalizedToken = trim($token);
		if ($normalizedToken === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		$this->config->setValueString(AppConstants::APP_ID, self::URL_KEY, $normalizedUrl);
		$this->credentialsManager->store('', self::TOKEN_IDENTIFIER, $normalizedToken);

		return new PublicConfig($normalizedUrl, true);
	}

	public function reset(): PublicConfig {
		$this->config->deleteKey(AppConstants::APP_ID, self::URL_KEY);
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);

		return new PublicConfig('', false);
	}

	public function normalizeUrl(string $url): string {
		$normalizedUrl = rtrim(trim($url), '/');
		if ($normalizedUrl === '' || filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('Enter a valid Paperless URL.');
		}

		$parts = parse_url($normalizedUrl);
		$scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new InvalidArgumentException('The Paperless URL must use HTTP or HTTPS.');
		}

		if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))) {
			throw new InvalidArgumentException('The Paperless URL must not contain credentials, a query, or a fragment.');
		}

		return $normalizedUrl;
	}
}
