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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Throwable;

final class SettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private IUserManager $userManager,
		private SearchDiagnostics $diagnostics,
	) {
		parent::__construct($appName, $request);
	}

	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	public function save(string $url, string $token = '', bool $alwaysSearch = false, string $archiveOwner = ''): JSONResponse {
		try {
			$normalizedUrl = $this->configService->normalizeUrl($url);
			$effectiveToken = $this->configService->resolveToken($token);
			$owner = trim($archiveOwner);
			if ($owner !== '' && !$this->userManager->userExists($owner)) {
				throw new InvalidArgumentException('The archive account does not exist.');
			}
			$this->paperlessApi->testConnection($normalizedUrl, $effectiveToken);
			$config = $this->configService->save($normalizedUrl, $effectiveToken, $alwaysSearch, $owner);
			// The failures of the former settings say nothing about the new ones.
			$this->diagnostics->clear();

			return new JSONResponse($config);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				['message' => $exception->getMessage()],
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
	public function reset(): JSONResponse {
		$config = $this->configService->reset();
		$this->diagnostics->clear();

		return new JSONResponse($config);
	}
}
