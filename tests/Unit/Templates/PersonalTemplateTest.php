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

	public function testTheMessageAfterAChangeIsReadOut(): void {
		$status = $this->element($this->render(false, false), 'paperless-unified-search-personal-status');

		self::assertSame('status', $status->getAttribute('role'));
		self::assertSame('polite', $status->getAttribute('aria-live'));
		self::assertSame('paperless-unified-search-status', $status->getAttribute('class'));
		self::assertSame('', $this->text($status));
	}

	private function render(bool $alwaysSearch, bool $trusted, string $saveUrl = '/personal'): DOMDocument {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$parameters = ['alwaysSearch' => $alwaysSearch, 'trusted' => $trusted, 'saveUrl' => $saveUrl];

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
