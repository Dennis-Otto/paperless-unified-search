<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\AppInfo;

use OCA\PaperlessUnifiedSearch\Reference\PaperlessReferenceProvider;
use OCA\PaperlessUnifiedSearch\Search\PaperlessSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'paperless_unified_search';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(PaperlessSearchProvider::class);
		// "Paperless documents" in the smart picker, which searches through the provider above.
		$context->registerReferenceProvider(PaperlessReferenceProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
