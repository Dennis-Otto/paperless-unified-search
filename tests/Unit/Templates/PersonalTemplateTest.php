<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Templates;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TemplateAssets;

require_once __DIR__ . '/../../stubs/template-functions.php';

/**
 * templates/personal.php, the section Paperless of the personal settings, rendered as
 * Nextcloud renders it for each choice of the user and the trusted-service mode of the
 * administrators.
 */
final class PersonalTemplateTest extends TestCase {
	private const CHECKBOX = 'paperless-unified-search-personal-always-search';
	private const EVERY = 'paperless-unified-search-personal-every-document';
	private const TERMS = 'paperless-unified-search-personal-terms';

	protected function setUp(): void {
		TemplateAssets::$loaded = [];
	}

	public function testTheSectionLoadsItsScriptAndTheStylesOfTheSettings(): void {
		$page = $this->render(false, false, '/apps/paperless_unified_search/personal?a="b"&c');

		self::assertSame(['script paperless_unified_search/personal', 'style paperless_unified_search/settings'], TemplateAssets::$loaded);
		$root = $this->element($page, 'paperless-unified-search-personal');
		// The classes of the administration settings bring their styles.
		self::assertSame('section paperless-unified-search-settings', $root->getAttribute('class'));
		self::assertSame('/apps/paperless_unified_search/personal?a="b"&c', $root->getAttribute('data-save-url'));
		self::assertSame('Paperless', $this->text($page->getElementsByTagName('h2')->item(0)));
		self::assertStringStartsWith('Nextcloud searches your Paperless documents only while', $this->text($this->query($page, '//p[@class="settings-hint"]')));
	}

	/**
	 * @return array<string, array{bool, bool, bool, bool, string}>
	 */
	public static function states(): array {
		return [
			// chosen, trusted mode, checked, disabled, the start of the hint
			'not chosen' => [false, false, false, false, 'Every search of yours then includes Paperless, without the switch. The terms that you search for then go to the Paperless server'],
			'chosen' => [true, false, true, false, 'Every search of yours then includes Paperless, without the switch.'],
			'trusted mode' => [false, true, true, true, 'Your administrator includes Paperless in every search of every user'],
			'chosen, and trusted mode' => [true, true, true, true, 'Your administrator includes Paperless in every search of every user'],
		];
	}

	/**
	 * The checkbox shows the choice of the user, and while the administrators include
	 * Paperless in every search of every user, it is ticked and can't be changed, with
	 * the hint that says why.
	 */
	#[DataProvider('states')]
	public function testTheCheckboxShowsTheChoiceUnlessTheAdministratorsTrustPaperless(bool $chosen, bool $trusted, bool $checked, bool $disabled, string $hint): void {
		$page = $this->render($chosen, $trusted);

		$checkbox = $this->element($page, self::CHECKBOX);
		self::assertSame('checkbox', $checkbox->getAttribute('type'));
		self::assertSame($checked, $checkbox->hasAttribute('checked'));
		self::assertSame($disabled, $checkbox->hasAttribute('disabled'));
		self::assertSame(self::CHECKBOX . '-hint', $checkbox->getAttribute('aria-describedby'));
		self::assertStringStartsWith($hint, $this->text($this->element($page, self::CHECKBOX . '-hint')));
		self::assertSame('Always search my Paperless documents', $this->text($this->query($page, '//label[@for="' . self::CHECKBOX . '"]')));
	}

	public function testWithoutAChoiceTheNotificationsOfferBothWays(): void {
		$page = $this->render(false, false);

		self::assertSame('/personal/alerts', $this->element($page, 'paperless-unified-search-personal')->getAttribute('data-alerts-url'));
		self::assertSame('Notify me about new documents', $this->text($this->query($page, '//h3')));
		$every = $this->element($page, self::EVERY);
		self::assertSame('checkbox', $every->getAttribute('type'));
		self::assertFalse($every->hasAttribute('checked'));
		self::assertSame('Every new document that I can open', $this->text($this->query($page, '//label[@for="' . self::EVERY . '"]')));
		$terms = $this->element($page, self::TERMS);
		self::assertFalse($terms->hasAttribute('disabled'));
		self::assertSame('', $terms->textContent);
		self::assertSame(self::TERMS . '-hint', $terms->getAttribute('aria-describedby'));
		self::assertSame("correspondent:\"tax office\"\ntag:unpaid", $terms->getAttribute('placeholder'));
		self::assertSame('Or only those that these searches find, one per line', $this->text($this->query($page, '//label[@for="' . self::TERMS . '"]')));
		// It says that the searches go to Paperless, which is what the user agrees to.
		self::assertStringStartsWith('Up to 10 searches in the syntax of Paperless', $this->text($this->element($page, self::TERMS . '-hint')));
		self::assertStringContainsString('sends them to the Paperless server', $this->text($this->element($page, self::TERMS . '-hint')));
		$status = $this->element($page, 'paperless-unified-search-personal-alerts-status');
		self::assertSame('status', $status->getAttribute('role'));
		self::assertSame('polite', $status->getAttribute('aria-live'));
		self::assertSame('Save', $this->text($this->element($page, 'paperless-unified-search-personal-alerts-save')));
	}

	public function testTheFieldShowsTheSavedSearchesAndKeepsThemAsText(): void {
		$page = $this->render(false, false, alerts: ['terms' => "tag:unpaid\n<b>tax</b>"]);

		self::assertSame("tag:unpaid\n<b>tax</b>", $this->element($page, self::TERMS)->textContent);
		self::assertSame(0, $this->element($page, self::TERMS)->getElementsByTagName('b')->length);
	}

	public function testEveryDocumentIsTickedAndTheSearchesRestMeanwhile(): void {
		$page = $this->render(false, false, alerts: ['everyDocument' => true, 'terms' => 'tag:unpaid']);

		self::assertTrue($this->element($page, self::EVERY)->hasAttribute('checked'));
		self::assertTrue($this->element($page, self::TERMS)->hasAttribute('disabled'));
		self::assertSame('tag:unpaid', $this->element($page, self::TERMS)->textContent);
	}

	/**
	 * Each part shows while the administrators let users choose it.
	 */
	public function testOnlyWhatTheAdministratorsAllowShows(): void {
		$onlyAlerts = $this->render(false, false, alerts: ['alwaysSearchAllowed' => false]);
		self::assertNull($onlyAlerts->getElementById(self::CHECKBOX));
		self::assertNull($onlyAlerts->getElementById('paperless-unified-search-personal-status'));
		self::assertNotNull($onlyAlerts->getElementById(self::EVERY));
		self::assertStringNotContainsString('Nextcloud searches your Paperless documents', (string)$onlyAlerts->textContent);

		$onlySearch = $this->render(false, false, alerts: ['alertsAllowed' => false]);
		self::assertNotNull($onlySearch->getElementById(self::CHECKBOX));
		self::assertNull($onlySearch->getElementById(self::EVERY));
		self::assertNull($onlySearch->getElementById(self::TERMS));
		self::assertSame(0, $onlySearch->getElementsByTagName('h3')->length);
	}

	public function testTheMessageAfterAChangeIsReadOut(): void {
		$status = $this->element($this->render(false, false), 'paperless-unified-search-personal-status');

		self::assertSame('status', $status->getAttribute('role'));
		self::assertSame('polite', $status->getAttribute('aria-live'));
		self::assertSame('paperless-unified-search-status', $status->getAttribute('class'));
		self::assertSame('', $this->text($status));
	}

	/**
	 * @param array<string, mixed> $alerts what differs from a user without a choice
	 */
	private function render(bool $alwaysSearch, bool $trusted, string $saveUrl = '/personal', array $alerts = []): DOMDocument {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$parameters = $alerts + [
			'alwaysSearchAllowed' => true,
			'alwaysSearch' => $alwaysSearch,
			'trusted' => $trusted,
			'saveUrl' => $saveUrl,
			'alertsAllowed' => true,
			'everyDocument' => false,
			'terms' => '',
			'mostTerms' => 10,
			'alertsUrl' => '/personal/alerts',
		];

		ob_start();
		try {
			(static function (array $_, IL10N $l): void {
				require __DIR__ . '/../../../templates/personal.php';
			})($parameters, $l10n);
			$html = (string)ob_get_contents();
		} finally {
			ob_end_clean();
		}

		$page = new DOMDocument();
		$errors = libxml_use_internal_errors(true);
		try {
			self::assertTrue($page->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET));
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($errors);
		}

		return $page;
	}

	private function element(DOMDocument $page, string $id): DOMElement {
		$element = $page->getElementById($id);
		self::assertNotNull($element, "The page has no element {$id}.");

		return $element;
	}

	private function query(DOMDocument $page, string $path): DOMNode {
		$node = (new DOMXPath($page))->query($path)?->item(0);
		self::assertNotNull($node, "The page has nothing at {$path}.");

		return $node;
	}

	private function text(?DOMNode $node): string {
		self::assertNotNull($node);

		return trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
	}
}
