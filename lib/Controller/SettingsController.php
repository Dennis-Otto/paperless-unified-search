<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Controller;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Exception\InvalidSettingException;
use OCA\PaperlessUnifiedSearch\Exception\PaperlessRequestException;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
use OCA\PaperlessUnifiedSearch\Service\SettingsMessages;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The routes of the settings page. Every one of them passes Nextcloud's checks of the
 * login, the administrator and the CSRF token, as it opts out of none of them;
 * SettingsControllerRoutesTest makes sure it stays so.
 */
final class SettingsController extends Controller {
	/**
	 * The code of a refusal when something failed that the settings don't know of, such as
	 * the database.
	 */
	private const CODE_INTERNAL = 'internal';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private PaperlessApiService $paperlessApi,
		private SearchDiagnostics $diagnostics,
		private SettingsMessages $messages,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Saves the settings once Paperless answers at the URL with the token. Like
	 * Disconnect, it asks for the password of the administrator when it wasn't confirmed
	 * in the last 30 minutes. $allowLocal lets Nextcloud reach this Paperless, and only
	 * this one, at an address of the local network.
	 *
	 * A refusal answers the message to show, in the language of the administrator, and a
	 * code that says what failed, such as unauthorized or invalid_url. A failed test of the
	 * connection also goes into the log, with the error of the request but without the
	 * token and the query of a URL.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/settings')]
	#[PasswordConfirmationRequired]
	public function save(string $url, #[SensitiveParameter] string $token = '', bool $alwaysSearch = false, string $archiveOwner = '', bool $allowLocal = false): JSONResponse {
		$effectiveToken = '';
		try {
			$normalizedUrl = $this->configService->normalizeUrl($url);
			$effectiveToken = $this->configService->resolveToken($token, $normalizedUrl);
			$owner = $this->configService->resolveArchiveOwner($archiveOwner);
			$version = $this->paperlessApi->testConnection($normalizedUrl, $effectiveToken, $allowLocal);

			return new JSONResponse($this->configService->save($normalizedUrl, $effectiveToken, $alwaysSearch, $owner, $allowLocal, $version));
		} catch (InvalidSettingException $exception) {
			return $this->refusal($this->messages->forSetting($exception->problem, trim($archiveOwner)), $exception->problem->value);
		} catch (PaperlessRequestException $exception) {
			$cause = $exception->getPrevious() ?? $exception;
			$this->logger->warning('The test of the connection to Paperless failed: {reason} ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'reason' => $exception->reason->value,
				'errorType' => $cause::class,
				'detail' => SearchDiagnostics::describe($cause->getMessage(), [$effectiveToken]),
			]);

			return $this->refusal($this->messages->forFailure($exception), $exception->reason->value);
		} catch (Throwable $exception) {
			$this->logger->error('The settings of Paperless Unified Search could not be saved ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'errorType' => $exception::class,
				'detail' => SearchDiagnostics::describe($exception->getMessage(), [$effectiveToken]),
			]);

			return $this->refusal(
				$this->l10n->t('The settings could not be saved. The log of Nextcloud has the details.'),
				self::CODE_INTERNAL,
				Http::STATUS_INTERNAL_SERVER_ERROR,
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

	/**
	 * @param Http::STATUS_BAD_REQUEST|Http::STATUS_INTERNAL_SERVER_ERROR $status
	 */
	private function refusal(string $message, string $code, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
		return new JSONResponse(['message' => $message, 'code' => $code], $status);
	}
}
