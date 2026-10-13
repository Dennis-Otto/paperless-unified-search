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
use OCA\PaperlessUnifiedSearch\Exception\RequestFailure;
use OCA\PaperlessUnifiedSearch\Exception\SettingProblem;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCA\PaperlessUnifiedSearch\Service\PaperlessApiService;
use OCA\PaperlessUnifiedSearch\Service\SearchDiagnostics;
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
			return $this->refusal($this->settingMessage($exception->problem, trim($archiveOwner)), $exception->problem->value);
		} catch (PaperlessRequestException $exception) {
			$cause = $exception->getPrevious() ?? $exception;
			$this->logger->warning('The test of the connection to Paperless failed: {reason} ({errorType}: {detail})', [
				'app' => AppConstants::APP_ID,
				'reason' => $exception->reason->value,
				'errorType' => $cause::class,
				'detail' => SearchDiagnostics::describe($cause->getMessage(), [$effectiveToken]),
			]);

			return $this->refusal($this->failureMessage($exception), $exception->reason->value);
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

	/**
	 * The message for a setting that can't be saved. Each text stands in a call of t() of
	 * its own, where scripts/check-l10n.mjs finds it.
	 */
	private function settingMessage(SettingProblem $problem, string $archiveOwner): string {
		return match ($problem) {
			SettingProblem::InvalidUrl => $this->l10n->t('Enter a valid Paperless URL.'),
			SettingProblem::UnsupportedScheme => $this->l10n->t('The Paperless URL must use HTTP or HTTPS.'),
			SettingProblem::UrlWithExtras => $this->l10n->t('The Paperless URL must not contain credentials, a query, or a fragment.'),
			SettingProblem::HostNotAscii => $this->l10n->t('Enter the host of the Paperless URL in its ASCII form (punycode), such as xn--bcher-kva.example for bücher.example.'),
			SettingProblem::TokenRequired => $this->l10n->t('A Paperless API token is required.'),
			SettingProblem::TokenForOtherServer => $this->l10n->t('Enter the API token again: a stored token is only sent to the Paperless server it was saved for.'),
			SettingProblem::UnknownArchiveAccount => $this->l10n->t('The archive account %s does not exist.', [$archiveOwner]),
		};
	}

	/**
	 * The message for a test of the connection that failed, which says what to correct.
	 */
	private function failureMessage(PaperlessRequestException $exception): string {
		$status = (string)$exception->status;

		return match ($exception->reason) {
			RequestFailure::Unauthorized => $this->l10n->t('Paperless rejected the API token (HTTP %s). Check the token, and that its account may view documents.', [$status]),
			RequestFailure::NotFound => $this->l10n->t('This URL answers, but not with the API of Paperless (HTTP 404). Check the address and its path.'),
			RequestFailure::Redirect => $this->l10n->t('This URL forwards to another address (HTTP %s). Enter the address that it forwards to.', [$status]),
			RequestFailure::Http => $this->l10n->t('Paperless answered with an error (HTTP %s). Check its log, or try again later.', [$status]),
			RequestFailure::NotPaperless => $this->l10n->t('This URL answers, but not like Paperless-ngx. Check the address and its path.'),
			RequestFailure::LocalAddress => $this->l10n->t('Nextcloud does not connect to addresses of the local network unless you allow it. If Paperless runs in your local network or in Docker, select “Paperless runs in my local network”.'),
			RequestFailure::Certificate => $this->l10n->t('Nextcloud does not trust the certificate of Paperless at this URL. If Paperless uses a certificate of its own, import it with occ security:certificates:import.'),
			RequestFailure::Handshake => $this->l10n->t('Nextcloud could not set up an encrypted connection to Paperless at this URL. If Paperless answers plain HTTP at this port, enter the URL with http://.'),
			RequestFailure::UnknownHost => $this->l10n->t('Nextcloud could not find the host of this URL. Check its spelling; the name of a Docker service only works when Nextcloud runs in the same Docker network.'),
			RequestFailure::Timeout => $this->l10n->t('Paperless did not answer in time. Check that it runs and is not overloaded, then try again.'),
			RequestFailure::Unreachable => $this->l10n->t('Nextcloud could not reach Paperless at this URL. Check the address and the port, and that Paperless runs.'),
		};
	}
}
