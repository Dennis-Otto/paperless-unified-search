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
use OCP\IUserManager;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

final class ConfigService {
	private const URL_KEY = 'paperless_url';
	private const ALWAYS_SEARCH_KEY = 'always_search';
	private const ARCHIVE_OWNER_KEY = 'archive_owner';
	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';
	// Paperless Sync writes the archive into the files of this account.
	private const SYNC_APP_ID = 'paperless_sync';
	private const SYNC_ACCOUNT_KEY = 'target_user';
	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

	/**
	 * The stored token as this request read it: '' when there is none or it can't be
	 * decrypted, and null until the request first needs it. The credentials manager
	 * decrypts it on every call.
	 */
	private ?string $token = null;
	private bool $tokenUnreadable = false;

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private IAppConfig $config,
		private ICredentialsManager $credentialsManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function getPublicConfig(): PublicConfig {
		$archiveOwner = $this->getArchiveOwnerSetting();
		$syncAccount = $this->getSyncAccount();

		return new PublicConfig(
			$this->getUrl(),
			$this->getToken() !== '',
			$this->isAlwaysSearchEnabled(),
			$archiveOwner,
			$syncAccount,
			$this->isTokenUnreadable(),
			$this->isMissing($archiveOwner !== '' ? $archiveOwner : $syncAccount),
		);
	}

	/**
	 * The account whose files stand for the Paperless documents: the one of the settings,
	 * or else the account that Paperless Sync writes the archive with. Nextcloud finds an
	 * account whatever the case of its ID, and Paperless Sync stores the ID as typed, but
	 * a file carries the exact ID of its owner: so this is the ID as Nextcloud spells it.
	 * Empty when neither is known or the account doesn't exist, and then the search shows
	 * no document.
	 */
	public function getArchiveOwner(): string {
		$owner = $this->getArchiveOwnerSetting();

		return $this->accountId($owner !== '' ? $owner : $this->getSyncAccount());
	}

	public function getArchiveOwnerSetting(): string {
		return trim($this->config->getValueString(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY, ''));
	}

	public function getSyncAccount(): string {
		return trim($this->config->getValueString(self::SYNC_APP_ID, self::SYNC_ACCOUNT_KEY, ''));
	}

	/**
	 * The archive account to store for what an administrator entered: its ID as Nextcloud
	 * spells it, or '' to use the account of Paperless Sync.
	 *
	 * @throws InvalidArgumentException when no such account exists
	 */
	public function resolveArchiveOwner(string $candidate): string {
		$owner = trim($candidate);
		if ($owner === '') {
			return '';
		}

		$accountId = $this->accountId($owner);
		if ($accountId === '') {
			throw new InvalidArgumentException('The archive account does not exist.');
		}

		return $accountId;
	}

	public function isConfigured(): bool {
		return $this->getUrl() !== '' && $this->getToken() !== '';
	}

	public function getUrl(): string {
		return $this->config->getValueString(AppConstants::APP_ID, self::URL_KEY, '');
	}

	/**
	 * The stored token, or '' when there is none or it can't be decrypted, read once per
	 * request.
	 */
	public function getToken(): string {
		if ($this->token === null) {
			$this->token = $this->readToken();
		}

		return $this->token;
	}

	/**
	 * Whether the stored token can't be decrypted, so that it has to be entered again.
	 */
	public function isTokenUnreadable(): bool {
		$this->getToken();

		return $this->tokenUnreadable;
	}

	public function isAlwaysSearchEnabled(): bool {
		return $this->config->getValueBool(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY, false);
	}

	/**
	 * The token to test and save with a URL: the one entered, or else the stored one, but
	 * only for the Paperless server that it was saved for. Otherwise whoever can use the
	 * session of an administrator could send the stored token to a server of their own,
	 * or over plain HTTP, just by changing the URL.
	 *
	 * @param string $normalizedUrl the new URL, as normalizeUrl() returns it
	 * @throws InvalidArgumentException when the token has to be entered
	 */
	public function resolveToken(#[SensitiveParameter] string $candidate, string $normalizedUrl): string {
		$token = trim($candidate);
		if ($token !== '') {
			return $token;
		}

		$token = $this->getToken();
		if ($token === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		$storedUrl = $this->getUrl();
		if ($storedUrl === '' || $this->originOf($storedUrl) !== $this->originOf($normalizedUrl)) {
			throw new InvalidArgumentException('Enter the API token again: a stored token is only sent to the Paperless server it was saved for.');
		}

		return $token;
	}

	/**
	 * The origin of a URL, the server that it names: its scheme and host in lower case and
	 * its port, the one of the scheme when it names none, such as
	 * https://paperless.example.com:443. The path doesn't count.
	 */
	public function originOf(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return '';
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		$host = strtolower($parts['host'] ?? '');
		$port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme] ?? 0;

		return $scheme . '://' . $host . ':' . $port;
	}

	public function save(string $url, #[SensitiveParameter] string $token, bool $alwaysSearch = false, string $archiveOwner = ''): PublicConfig {
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
		$this->token = $normalizedToken;
		$this->tokenUnreadable = false;

		$syncAccount = $this->getSyncAccount();

		return new PublicConfig(
			$normalizedUrl,
			true,
			$alwaysSearch,
			$normalizedOwner,
			$syncAccount,
			false,
			$this->isMissing($normalizedOwner !== '' ? $normalizedOwner : $syncAccount),
		);
	}

	public function reset(): PublicConfig {
		$this->config->deleteKey(AppConstants::APP_ID, self::URL_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY);
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);
		$this->token = '';
		$this->tokenUnreadable = false;

		$syncAccount = $this->getSyncAccount();

		return new PublicConfig('', false, false, '', $syncAccount, false, $this->isMissing($syncAccount));
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

	/**
	 * A token that the credentials manager can't decrypt, as after a move or a restore of
	 * Nextcloud with another secret in config.php, counts as none: the settings ask for it
	 * again, and the search finds no Paperless documents instead of failing.
	 */
	private function readToken(): string {
		try {
			/** @psalm-suppress MixedAssignment */
			$token = $this->credentialsManager->retrieve('', self::TOKEN_IDENTIFIER);
		} catch (Throwable $exception) {
			$this->tokenUnreadable = true;
			$this->logger->warning('The stored Paperless API token can\'t be decrypted, for example after the secret of config.php changed; enter it again ({errorType}).', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
			]);

			return '';
		}

		return is_string($token) ? $token : '';
	}

	/**
	 * The ID of an account as Nextcloud spells it, or '' when there is no such account.
	 */
	private function accountId(string $account): string {
		if ($account === '') {
			return '';
		}

		return $this->userManager->get($account)?->getUID() ?? '';
	}

	/**
	 * Whether an account is named that doesn't exist.
	 */
	private function isMissing(string $account): bool {
		return $account !== '' && $this->accountId($account) === '';
	}
}
