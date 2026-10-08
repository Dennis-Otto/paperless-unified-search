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
	private const ALWAYS_SEARCH_KEY = 'always_search';
	private const ARCHIVE_OWNER_KEY = 'archive_owner';
	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';
	// Paperless Sync writes the archive into the files of this account.
	private const SYNC_APP_ID = 'paperless_sync';
	private const SYNC_ACCOUNT_KEY = 'target_user';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
		private ICredentialsManager $credentialsManager,
	) {
	}

	public function getPublicConfig(): PublicConfig {
		return new PublicConfig(
			$this->getUrl(),
			$this->getToken() !== '',
			$this->isAlwaysSearchEnabled(),
			$this->getArchiveOwnerSetting(),
			$this->getSyncAccount(),
		);
	}

	/**
	 * The account whose files stand for the Paperless documents: the one of the
	 * settings, or else the account that Paperless Sync writes the archive with.
	 * Empty when neither is known, and then the search shows no document.
	 */
	public function getArchiveOwner(): string {
		$owner = $this->getArchiveOwnerSetting();

		return $owner !== '' ? $owner : $this->getSyncAccount();
	}

	public function getArchiveOwnerSetting(): string {
		return trim($this->config->getValueString(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY, ''));
	}

	public function getSyncAccount(): string {
		return trim($this->config->getValueString(self::SYNC_APP_ID, self::SYNC_ACCOUNT_KEY, ''));
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

	public function isAlwaysSearchEnabled(): bool {
		return $this->config->getValueBool(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY, false);
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

	public function save(string $url, string $token, bool $alwaysSearch = false, string $archiveOwner = ''): PublicConfig {
		$normalizedUrl = $this->normalizeUrl($url);
		$normalizedToken = trim($token);
		if ($normalizedToken === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		$normalizedOwner = trim($archiveOwner);
		$this->config->setValueString(AppConstants::APP_ID, self::URL_KEY, $normalizedUrl);
		$this->config->setValueBool(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY, $alwaysSearch);
		if ($normalizedOwner === '') {
			$this->config->deleteKey(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY);
		} else {
			$this->config->setValueString(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY, $normalizedOwner);
		}
		$this->credentialsManager->store('', self::TOKEN_IDENTIFIER, $normalizedToken);

		return new PublicConfig($normalizedUrl, true, $alwaysSearch, $normalizedOwner, $this->getSyncAccount());
	}

	public function reset(): PublicConfig {
		$this->config->deleteKey(AppConstants::APP_ID, self::URL_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY);
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);

		return new PublicConfig('', false, false, '', $this->getSyncAccount());
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
