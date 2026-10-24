<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Controller;

use OCA\PaperlessUnifiedSearch\Service\AlwaysSearch;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The route of the personal settings, which every signed-in user reaches: it opts out of
 * Nextcloud's check of the administrator, and of no other check, such as those of the
 * login and the CSRF token; PersonalSettingsControllerRoutesTest makes sure it stays so.
 */
final class PersonalSettingsController extends Controller {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $configService,
		private AlwaysSearch $alwaysSearch,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Stores whether every search of the signed-in user includes Paperless, without the
	 * switch Search connected services. It is refused while the administrators don't let
	 * users choose it, and a refusal answers the message to show, in the language of the
	 * user, and a code that says why.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/personal')]
	public function save(bool $alwaysSearch = false): JSONResponse {
		$user = $this->userSession->getUser();
		// Nextcloud lets no request without a login reach the route.
		if ($user === null) {
			return $this->refusal($this->l10n->t('Your session has expired. Reload the page and try again.'), 'not_signed_in', Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->configService->isPersonalAlwaysSearchAllowed()) {
			return $this->refusal($this->l10n->t('Your administrator does not let users include Paperless in all their searches.'), 'not_allowed', Http::STATUS_FORBIDDEN);
		}

		$this->alwaysSearch->choose($user->getUID(), $alwaysSearch);

		return new JSONResponse(['alwaysSearch' => $alwaysSearch]);
	}

	/**
	 * @param Http::STATUS_UNAUTHORIZED|Http::STATUS_FORBIDDEN $status
	 */
	private function refusal(string $message, string $code, int $status): JSONResponse {
		return new JSONResponse(['message' => $message, 'code' => $code], $status);
	}
}
