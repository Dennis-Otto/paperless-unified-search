<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\AppInfo;

use OCA\PaperlessUnifiedSearch\Dashboard\RecentDocumentsWidget;
use OCA\PaperlessUnifiedSearch\Notification\Notifier;
use OCA\PaperlessUnifiedSearch\Reference\PaperlessReferenceProvider;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCA\PaperlessUnifiedSearch\SetupCheck\PaperlessSetupCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(AppConstants::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(PaperlessSearchProvider::class);
		// "Paperless documents" in the smart picker, which searches through the provider above.
		$context->registerReferenceProvider(PaperlessReferenceProvider::class);
		// The state of the search in the overview of the administration settings.
		$context->registerSetupCheck(PaperlessSetupCheck::class);
		// "New in Paperless" on the dashboard, which offers itself only once the
		// app is set up: Nextcloud asks the widget, as it builds the dashboard.
		$context->registerDashboardWidget(RecentDocumentsWidget::class);
		// The words of the notifications about new documents, which Nextcloud asks for as a
		// client fetches them, in the language of the user.
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
