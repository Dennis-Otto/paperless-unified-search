<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Listener;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Service\ConfigService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Brings "Find similar documents in Paperless" into Files when Files loads the scripts of other
 * apps: js/files.js, which registers the action and shows the documents in a dialog, and
 * css/files.css, the look of that dialog. Only for a user, and only while the search can find
 * documents at all: with the address and the token of Paperless and an archive account, as
 * PaperlessSearchProvider::search() needs them.
 *
 * @template-implements IEventListener<Event>
 */
final class LoadAdditionalScriptsListener implements IEventListener {
	/**
	 * The event of Files, which is not part of the public API of Nextcloud: Application registers
	 * the listener by its name, so that the app needs no class of Files.
	 */
	public const EVENT = 'OCA\Files\Event\LoadAdditionalScriptsEvent';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct(
		private ConfigService $configService,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if ($this->userSession->getUser() === null
			|| !$this->configService->isConfigured()
			|| $this->configService->getArchiveOwner() === '') {
			return;
		}
		// An init script, which Nextcloud loads before Files reads the registered actions, with
		// the translations of the app before it.
		Util::addInitScript(AppConstants::APP_ID, 'files');
		Util::addStyle(AppConstants::APP_ID, 'files');
	}
}
