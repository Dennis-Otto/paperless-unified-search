<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Controller;

use InvalidArgumentException;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IRequest;
use SensitiveParameter;
use Throwable;

/**
 * The routes of the settings page. Every one of them passes Nextcloud's checks of the
 * login, the administrator and the CSRF token, as it opts out of none of them;
 * SettingsControllerRoutesTest makes sure it stays so.
 */
final class SettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private SearchDiagnostics $diagnostics,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Saves the settings once Paperless answers at the URL with the token. Like
	 * Disconnect, it asks for the password of the administrator when it wasn't confirmed
	 * in the last 30 minutes. $allowLocal lets Nextcloud reach this Paperless, and only
	 * this one, at an address of the local network.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(string $url, #[SensitiveParameter] string $token = '', bool $alwaysSearch = false, string $archiveOwner = '', bool $allowLocal = false): JSONResponse {
		try {
			$normalizedUrl = $this->configService->normalizeUrl($url);
			$effectiveToken = $this->configService->resolveToken($token, $normalizedUrl);
			$owner = $this->configService->resolveArchiveOwner($archiveOwner);
			$this->paperlessApi->testConnection($normalizedUrl, $effectiveToken, $allowLocal);

			return new JSONResponse($this->configService->save($normalizedUrl, $effectiveToken, $alwaysSearch, $owner, $allowLocal));
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				['message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (LocalServerException) {
			// Nextcloud refused the address before any request left it.
			return new JSONResponse(
				['message' => 'Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.'],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (Throwable) {
			return new JSONResponse(
				['message' => 'Could not connect to Paperless. Check the URL, API token, and Nextcloud outbound connection policy.'],
				Http::STATUS_BAD_REQUEST,
			);
		}
	}

	#[FrontpageRoute(verb: 'DELETE', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function reset(): JSONResponse {
		$config = $this->configService->reset();
		$this->diagnostics->clear();

		return new JSONResponse($config);
	}

	#[FrontpageRoute(verb: 'DELETE', url: '/settings/diagnostics')]
	public function clearDiagnostics(): JSONResponse {
		$this->diagnostics->clear();

		return new JSONResponse([]);
	}
}
