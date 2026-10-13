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
	private const ALLOW_LOCAL_KEY = 'allow_local';
	private const TOKEN_IDENTIFIER = AppConstants::APP_ID . '.api-token';
	// Paperless Sync writes the archive into the files of this account.
	private const SYNC_APP_ID = 'paperless_sync';
	private const SYNC_ACCOUNT_KEY = 'target_user';
	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];
	/**
	 * A name of a host as DNS, a hosts file or the network of Docker know it: letters and
	 * digits of any script, with dots, hyphens and underscores between them, such as
	 * paperless.example.com, 192.168.1.20, paperless_ngx or bücher.example.
	 */
	private const HOST_NAME = '/^[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}._-]*[\p{L}\p{M}\p{N}])?$/u';
	/**
	 * IDNA_NONTRANSITIONAL_TO_ASCII and INTL_IDNA_VARIANT_UTS46 of PHP's intl, which only
	 * exist with the extension or the polyfill of Nextcloud: punycode as browsers write it,
	 * which keeps ß instead of turning it into ss.
	 */
	private const IDNA_NONTRANSITIONAL_TO_ASCII = 16;
	private const INTL_IDNA_VARIANT_UTS46 = 1;

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
			$this->isLocalNetworkAllowed(),
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
	 * Whether Nextcloud may reach the stored Paperless at an address of the local network,
	 * such as 192.168.1.20 or the name of a service of Docker. Nextcloud refuses such
	 * addresses to every request that doesn't allow them itself, unless
	 * allow_local_remote_servers in config.php allows them to all apps at once.
	 */
	public function isLocalNetworkAllowed(): bool {
		return $this->config->getValueBool(AppConstants::APP_ID, self::ALLOW_LOCAL_KEY, false);
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

	public function save(string $url, #[SensitiveParameter] string $token, bool $alwaysSearch = false, string $archiveOwner = '', bool $allowLocal = false): PublicConfig {
		$normalizedUrl = $this->normalizeUrl($url);
		$normalizedToken = trim($token);
		if ($normalizedToken === '') {
			throw new InvalidArgumentException('A Paperless API token is required.');
		}

		$normalizedOwner = trim($archiveOwner);
		$this->config->setValueString(AppConstants::APP_ID, self::URL_KEY, $normalizedUrl);
		$this->config->setValueBool(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY, $alwaysSearch);
		$this->config->setValueBool(AppConstants::APP_ID, self::ALLOW_LOCAL_KEY, $allowLocal);
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
			$allowLocal,
		);
	}

	public function reset(): PublicConfig {
		$this->config->deleteKey(AppConstants::APP_ID, self::URL_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ALWAYS_SEARCH_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ARCHIVE_OWNER_KEY);
		$this->config->deleteKey(AppConstants::APP_ID, self::ALLOW_LOCAL_KEY);
		$this->credentialsManager->delete('', self::TOKEN_IDENTIFIER);
		$this->token = '';
		$this->tokenUnreadable = false;

		$syncAccount = $this->getSyncAccount();

		return new PublicConfig('', false, false, '', $syncAccount, false, $this->isMissing($syncAccount));
	}

	/**
	 * The URL to store for what an administrator entered, without the blanks and slashes
	 * at its end: http or https, a host, and maybe a port and a path. The host is a name, an
	 * IPv4 address or an IPv6 address in brackets. A name may be one of the local network
	 * or of Docker, such as paperless_ngx, which cURL and Docker resolve although it isn't
	 * a name of the internet, or an internationalized one, such as bücher.example, which
	 * is stored in the ASCII form that DNS knows, xn--bcher-kva.example.
	 *
	 * @throws InvalidArgumentException when the URL is none of these, or carries
	 *                                  credentials, a query or a fragment
	 */
	public function normalizeUrl(string $url): string {
		$normalizedUrl = rtrim(trim($url), '/');
		// No part of a URL holds blanks or control characters, and the pattern fails for
		// text that isn't UTF-8.
		$parts = $normalizedUrl !== '' && preg_match('/^[^\s\x00-\x1f\x7f]+$/u', $normalizedUrl) === 1 ? parse_url($normalizedUrl) : false;
		if (!is_array($parts) || !isset($parts['scheme'])) {
			throw new InvalidArgumentException('Enter a valid Paperless URL.');
		}

		if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new InvalidArgumentException('The Paperless URL must use HTTP or HTTPS.');
		}

		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
			throw new InvalidArgumentException('The Paperless URL must not contain credentials, a query, or a fragment.');
		}

		// PHP refuses a port above 65535 itself.
		$host = $parts['host'] ?? '';
		if (!$this->isHost($host) || ($parts['port'] ?? null) === 0) {
			throw new InvalidArgumentException('Enter a valid Paperless URL.');
		}

		// Without credentials, the host follows right after "scheme://".
		return substr_replace($normalizedUrl, $this->asciiHost($host), strlen($parts['scheme']) + 3, strlen($host));
	}

	/**
	 * Whether the host of a URL is an IPv6 address in brackets, or else a name or an IPv4
	 * address.
	 */
	private function isHost(string $host): bool {
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
		}

		return preg_match(self::HOST_NAME, $host) === 1;
	}

	/**
	 * The ASCII form of a host, the one that DNS knows: an internationalized name in
	 * punycode, as browsers write it. Nextcloud brings the functions of PHP's intl for it
	 * where PHP lacks the extension; without them, a name that isn't ASCII has to be
	 * entered in punycode.
	 *
	 * @throws InvalidArgumentException when the name has no ASCII form
	 */
	private function asciiHost(string $host): string {
		if (mb_check_encoding($host, 'ASCII')) {
			return $host;
		}

		$asciiHost = function_exists('idn_to_ascii') ? idn_to_ascii($host, self::IDNA_NONTRANSITIONAL_TO_ASCII, self::INTL_IDNA_VARIANT_UTS46) : false;

		return $asciiHost !== false ? $asciiHost : throw new InvalidArgumentException('Enter the host of the Paperless URL in its ASCII form (punycode), such as xn--bcher-kva.example for bücher.example.');
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
