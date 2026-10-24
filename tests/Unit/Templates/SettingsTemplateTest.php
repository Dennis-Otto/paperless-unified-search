<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Templates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\PaperlessUnifiedSearch\Model\PublicConfig;
use OCA\PaperlessUnifiedSearch\Model\SearchEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use TemplateAssets;

require_once __DIR__ . '/../../stubs/template-functions.php';

/**
 * templates/settings.php, the HTML of the administration settings, rendered as Nextcloud renders it for
 * each state of the settings and of the history of the problems of the search, with values
 * that would break out of the page if they weren't escaped.
 */
final class SettingsTemplateTest extends TestCase {
	private const HOSTILE = '"><script>alert(1)</script>';
	private const HOSTILE_TAG = '<img src=x onerror=1>';
	private const STORED = 'https://paperless.example.com';

	protected function setUp(): void {
		TemplateAssets::$loaded = [];
	}

	public function testAFreshInstallAsksForEverythingAndKeepsDisconnectOff(): void {
		[$html, $page] = $this->render(new PublicConfig('', false, false));

		self::assertSame(['script paperless_unified_search/settings', 'style paperless_unified_search/settings'], TemplateAssets::$loaded);
		$root = $this->element($page, 'paperless-unified-search-settings');
		self::assertSame('false', $root->getAttribute('data-token-configured'));
		self::assertSame('/settings/save', $root->getAttribute('data-save-url'));
		self::assertSame('/settings/reset', $root->getAttribute('data-reset-url'));
		self::assertSame('/settings/diagnostics', $root->getAttribute('data-clear-diagnostics-url'));
		self::assertSame('', $this->element($page, 'paperless-unified-search-url')->getAttribute('value'));
		$token = $this->element($page, 'paperless-unified-search-token');
		self::assertTrue($token->hasAttribute('required'));
		self::assertSame('Required', $token->getAttribute('placeholder'));
		self::assertSame('User ID of the account that owns the synchronized files', $this->element($page, 'paperless-unified-search-archive-owner')->getAttribute('placeholder'));
		self::assertFalse($this->element($page, 'paperless-unified-search-allow-local')->hasAttribute('checked'));
		self::assertFalse($this->element($page, 'paperless-unified-search-always-search')->hasAttribute('checked'));
		// Users may choose to include Paperless in every search of their own by default.
		$personal = $this->element($page, 'paperless-unified-search-users-always-search');
		self::assertTrue($personal->hasAttribute('checked'));
		self::assertSame('personalAlwaysSearch', $personal->getAttribute('name'));
		// Nothing stored, nothing to disconnect.
		self::assertTrue($this->element($page, 'paperless-unified-search-reset')->hasAttribute('disabled'));
		self::assertSame('', $this->text($this->element($page, 'paperless-unified-search-token-unreadable')));
		self::assertSame('', $this->text($this->element($page, 'paperless-unified-search-archive-owner-missing')));
		self::assertSame('', $this->text($this->element($page, 'paperless-unified-search-status')));
		self::assertSame('No problem recorded.', $this->text($this->element($page, 'paperless-unified-search-diagnostics')));
		self::assertNull($page->getElementById('paperless-unified-search-clear-diagnostics'));
		self::assertStringContainsString('The history keeps the latest 20 problems', $html);
	}

	public function testAConfiguredAppKeepsItsTokenAndOffersDisconnect(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, true, 'archive', 'sync', allowLocal: true));

		self::assertSame('true', $this->element($page, 'paperless-unified-search-settings')->getAttribute('data-token-configured'));
		self::assertSame(self::STORED, $this->element($page, 'paperless-unified-search-url')->getAttribute('value'));
		$token = $this->element($page, 'paperless-unified-search-token');
		self::assertFalse($token->hasAttribute('required'));
		self::assertSame('Configured — leave blank to keep it', $token->getAttribute('placeholder'));
		self::assertSame('archive', $this->element($page, 'paperless-unified-search-archive-owner')->getAttribute('value'));
		self::assertTrue($this->element($page, 'paperless-unified-search-allow-local')->hasAttribute('checked'));
		self::assertTrue($this->element($page, 'paperless-unified-search-always-search')->hasAttribute('checked'));
		self::assertFalse($this->element($page, 'paperless-unified-search-reset')->hasAttribute('disabled'));
	}

	public function testTheSettingsShowWhenUsersMayNotAlwaysSearchPaperless(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, personalAlwaysSearch: false));

		self::assertFalse($this->element($page, 'paperless-unified-search-users-always-search')->hasAttribute('checked'));
		$label = (new DOMXPath($page))->query('//label[@for="paperless-unified-search-users-always-search"]')?->item(0);
		self::assertInstanceOf(DOMElement::class, $label);
		self::assertSame('Let users include Paperless in all their searches', $this->text($label));
	}

	public function testAStoredUrlWithoutTokenCanBeDisconnected(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, false, false));

		self::assertTrue($this->element($page, 'paperless-unified-search-token')->hasAttribute('required'));
		self::assertFalse($this->element($page, 'paperless-unified-search-reset')->hasAttribute('disabled'));
	}

	public function testTheArchiveAccountOfPaperlessSyncIsTheDefault(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, '', 'sync', archiveOwnerMissing: true));

		self::assertSame('Paperless Sync: sync — leave blank to use it', $this->element($page, 'paperless-unified-search-archive-owner')->getAttribute('placeholder'));
		self::assertSame('The archive account sync does not exist.', $this->text($this->element($page, 'paperless-unified-search-archive-owner-missing')));
	}

	public function testTheWarningsNameWhatToCorrect(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, 'gone', 'sync', tokenUnreadable: true, archiveOwnerMissing: true));

		self::assertSame(
			'The stored API token can\'t be decrypted, for example because the secret in config.php changed. Enter it again.',
			$this->text($this->element($page, 'paperless-unified-search-token-unreadable')),
		);
		self::assertSame('The archive account gone does not exist.', $this->text($this->element($page, 'paperless-unified-search-archive-owner-missing')));
	}

	/**
	 * Screen readers read the hint of a field with the field, among them the hint that the
	 * trusted mode sends every search term to Paperless.
	 */
	public function testEveryFieldIsDescribedByItsHintsAndWarnings(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false));

		$described = [];
		foreach ((new DOMXPath($page))->query('//*[@aria-describedby]') ?: [] as $field) {
			self::assertInstanceOf(DOMElement::class, $field);
			$ids = explode(' ', $field->getAttribute('aria-describedby'));
			foreach ($ids as $id) {
				self::assertNotNull($page->getElementById($id), "{$field->getAttribute('id')} is described by {$id}, which is missing.");
			}
			$described[$field->getAttribute('id')] = $ids;
		}

		self::assertSame([
			'paperless-unified-search-allow-local' => ['paperless-unified-search-allow-local-hint'],
			'paperless-unified-search-token' => ['paperless-unified-search-token-unreadable', 'paperless-unified-search-token-hint'],
			'paperless-unified-search-archive-owner' => ['paperless-unified-search-archive-owner-missing', 'paperless-unified-search-archive-owner-hint'],
			'paperless-unified-search-always-search' => ['paperless-unified-search-always-search-hint'],
			'paperless-unified-search-users-always-search' => ['paperless-unified-search-users-always-search-hint'],
			'paperless-unified-search-try-term' => ['paperless-unified-search-try-hint'],
		], $described);
		self::assertStringStartsWith(
			'Treat this Paperless server as a trusted service. When enabled, every global search term',
			$this->text($this->element($page, 'paperless-unified-search-always-search-hint')),
		);
	}

	/**
	 * Try a search sits at the end of the page, with its route, a field for the term and
	 * one for the user, and says that the audit log records who tried a search as whom.
	 */
	public function testTrySearchAsksForATermAndAUser(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false));

		self::assertSame('/settings/try', $this->element($page, 'paperless-unified-search-settings')->getAttribute('data-try-url'));
		foreach (['term' => 'search', 'user' => 'text'] as $field => $type) {
			$input = $this->element($page, "paperless-unified-search-try-{$field}");
			self::assertSame($type, $input->getAttribute('type'));
			self::assertTrue($input->hasAttribute('required'), "The {$field} of Try a search is not required.");
		}
		self::assertStringContainsString('The answer lists the first 20 documents', $this->text($this->element($page, 'paperless-unified-search-try-hint')));
		self::assertStringContainsString('The audit log of Nextcloud records who tried a search as whom.', $this->text($this->element($page, 'paperless-unified-search-try-hint')));
		self::assertSame('', $this->text($this->element($page, 'paperless-unified-search-try-result')));
		self::assertSame('status', $this->element($page, 'paperless-unified-search-try-status')->getAttribute('role'));
	}

	/**
	 * Below the archive account, the page says how it shares its files, and names what is
	 * wrong with each share that allows changes; without an archive account it says
	 * nothing about shares.
	 */
	public function testTheSharesOfTheArchiveShowBelowItsAccount(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, 'archive'), shares: [
			'summary' => 'Shared: 1 user, 1 group.',
			'warnings' => ['/Paperless is shared with the user sam with write permission.', '/Inbox has a public link that allows uploads or changes.'],
		]);

		self::assertSame('Shared: 1 user, 1 group.', $this->text($this->element($page, 'paperless-unified-search-shares-summary')));
		$warnings = [];
		foreach ($this->element($page, 'paperless-unified-search-shares-warnings')->getElementsByTagName('li') as $warning) {
			self::assertSame('paperless-unified-search-warning', $warning->getAttribute('class'));
			$warnings[] = $this->text($warning);
		}
		self::assertSame(['/Paperless is shared with the user sam with write permission.', '/Inbox has a public link that allows uploads or changes.'], $warnings);
		self::assertSame(
			'paperless-unified-search-archive-owner-hint',
			$this->element($page, 'paperless-unified-search-shares')->previousElementSibling?->getAttribute('id'),
		);

		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, 'archive'), shares: ['summary' => 'Shared read-only: 2 groups.', 'warnings' => []]);

		self::assertSame('Shared read-only: 2 groups.', $this->text($this->element($page, 'paperless-unified-search-shares-summary')));
		self::assertNull($page->getElementById('paperless-unified-search-shares-warnings'));

		[, $page] = $this->render(new PublicConfig(self::STORED, true, false));

		self::assertNull($page->getElementById('paperless-unified-search-shares'));
	}

	/**
	 * The reasons below Try a search name each verdict that it shows, in its order.
	 */
	public function testTheReasonsNameEachVerdict(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false));

		$reasons = [];
		foreach ((new DOMXPath($page))->query('//ul[@class="paperless-unified-search-reasons"]/li') ?: [] as $reason) {
			$reasons[] = explode(':', $this->text($reason))[0];
		}
		self::assertSame(['Shared with write permission', 'Not shared', 'Not a file of the archive account', 'No file'], $reasons);
	}

	public function testEveryIdIsUnique(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false, 'archive'), $this->history('', ''), shares: ['summary' => 'Shared: 1 user.', 'warnings' => ['One', 'Two']]);

		$ids = [];
		foreach ((new DOMXPath($page))->query('//*[@id]') ?: [] as $element) {
			self::assertInstanceOf(DOMElement::class, $element);
			$ids[] = $element->getAttribute('id');
		}
		self::assertSame(array_unique($ids), $ids);
	}

	public function testAFailureWithoutRecoverySaysThatNoSearchHasWorkedSince(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false), $this->history('10/12/26, 8:05:00 AM', ''));

		self::assertSame([
			['Recorded since', '10/12/26, 8:00:00 AM'],
			['Failed searches', '1'],
			['Answered on the second try', '1'],
			['Last failed search', '10/12/26, 8:05:00 AM'],
			['Searches work again since', 'No search has worked since.'],
		], $this->rows($page, 'paperless-unified-search-summary'));
		self::assertNotNull($page->getElementById('paperless-unified-search-clear-diagnostics'));
	}

	public function testARecoveryShowsItsTime(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false), $this->history('10/12/26, 8:05:00 AM', '10/12/26, 8:07:00 AM'));

		self::assertSame(['Searches work again since', '10/12/26, 8:07:00 AM'], $this->rows($page, 'paperless-unified-search-summary')[4]);
	}

	public function testOnlyRetriesShowNoLastFailure(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false), $this->history('', ''));

		self::assertSame([
			['Recorded since', '10/12/26, 8:00:00 AM'],
			['Failed searches', '1'],
			['Answered on the second try', '1'],
		], $this->rows($page, 'paperless-unified-search-summary'));
	}

	/**
	 * On a phone, each problem is a card whose cells name their column: each cell's
	 * data-label is the heading of its column.
	 */
	public function testEachCellOfTheProblemsNamesItsColumn(): void {
		[, $page] = $this->render(new PublicConfig(self::STORED, true, false), $this->history('', ''));

		$xpath = new DOMXPath($page);
		$headings = [];
		foreach ($xpath->query('//table[contains(@class, "paperless-unified-search-events")]/thead/tr/th') ?: [] as $heading) {
			$headings[] = $this->text($heading);
		}
		self::assertSame(['Time', 'Event', 'Step', 'Error', 'Duration'], $headings);
		self::assertSame([
			['10/12/26, 8:05:00 AM', 'Second try answered', 'Asking Paperless', 'GuzzleHttp\\Exception\\ConnectException: timeout', '30001 ms'],
			['10/12/26, 8:00:00 AM', 'Search failed', 'Looking for the files in Nextcloud', 'RuntimeException: database gone', '12 ms'],
		], $this->rows($page, 'paperless-unified-search-events'));

		$rows = $xpath->query('//table[contains(@class, "paperless-unified-search-events")]/tbody/tr') ?: [];
		self::assertCount(2, $rows);
		foreach ($rows as $row) {
			self::assertInstanceOf(DOMElement::class, $row);
			self::assertSame('row', $row->getAttribute('role'));
			$labels = [];
			foreach ($row->getElementsByTagName('td') as $cell) {
				self::assertSame('cell', $cell->getAttribute('role'));
				$labels[] = $cell->getAttribute('data-label');
			}
			self::assertSame($headings, $labels);
		}
	}

	/**
	 * Every value that an administrator or Paperless controls reaches the page escaped, in
	 * attributes and in text: none of them adds an element or an attribute.
	 */
	public function testHostileValuesAppearOnlyEscaped(): void {
		$history = [
			'since' => self::HOSTILE,
			'failed' => 1,
			'retried' => 0,
			'lastFailure' => self::HOSTILE_TAG,
			'recovery' => self::HOSTILE,
			'events' => [[
				'time' => self::HOSTILE_TAG,
				'kind' => SearchEvent::KIND_FAILED,
				'step' => SearchEvent::STEP_PAPERLESS,
				'error' => 'RuntimeException: ' . self::HOSTILE_TAG . self::HOSTILE,
				'durationMs' => 5,
			]],
		];
		[$html, $page] = $this->render(
			new PublicConfig(self::HOSTILE, true, false, self::HOSTILE_TAG, self::HOSTILE, archiveOwnerMissing: true),
			$history,
			self::HOSTILE,
			['summary' => 'Shared: ' . self::HOSTILE_TAG, 'warnings' => [self::HOSTILE . ' is shared with ' . self::HOSTILE_TAG]],
		);

		self::assertStringNotContainsString('<script>alert', $html);
		self::assertStringNotContainsString(self::HOSTILE_TAG, $html);
		self::assertStringContainsString('&lt;img src=x onerror=1&gt;', $html);
		self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
		$xpath = new DOMXPath($page);
		self::assertCount(0, $xpath->query('//script | //img | //*[@onerror]') ?: []);
		// The browser reads the values back as they were.
		self::assertSame(self::HOSTILE, $this->element($page, 'paperless-unified-search-url')->getAttribute('value'));
		self::assertSame(self::HOSTILE, $this->element($page, 'paperless-unified-search-settings')->getAttribute('data-save-url'));
		self::assertSame(self::HOSTILE_TAG, $this->element($page, 'paperless-unified-search-archive-owner')->getAttribute('value'));
		self::assertSame('Paperless Sync: ' . self::HOSTILE . ' — leave blank to use it', $this->element($page, 'paperless-unified-search-archive-owner')->getAttribute('placeholder'));
		self::assertSame('The archive account ' . self::HOSTILE_TAG . ' does not exist.', $this->text($this->element($page, 'paperless-unified-search-archive-owner-missing')));
		self::assertSame('Shared: ' . self::HOSTILE_TAG, $this->text($this->element($page, 'paperless-unified-search-shares-summary')));
		self::assertSame(self::HOSTILE . ' is shared with ' . self::HOSTILE_TAG, $this->text($this->element($page, 'paperless-unified-search-shares-warnings')));
		self::assertSame([
			['Recorded since', self::HOSTILE],
			['Failed searches', '1'],
			['Answered on the second try', '0'],
			['Last failed search', self::HOSTILE_TAG],
			['Searches work again since', self::HOSTILE],
		], $this->rows($page, 'paperless-unified-search-summary'));
		self::assertSame([
			[self::HOSTILE_TAG, 'Search failed', 'Asking Paperless', 'RuntimeException: ' . self::HOSTILE_TAG . self::HOSTILE, '5 ms'],
		], $this->rows($page, 'paperless-unified-search-events'));
	}

	/**
	 * A history of a failed search and of a request that only its second try answered, the
	 * newest first, as AdminSettings formats it.
	 *
	 * @return array{since: string, failed: int, retried: int, lastFailure: string, recovery: string, events: list<array{time: string, kind: string, step: string, error: string, durationMs: int}>}
	 */
	private function history(string $lastFailure, string $recovery): array {
		return [
			'since' => '10/12/26, 8:00:00 AM',
			'failed' => 1,
			'retried' => 1,
			'lastFailure' => $lastFailure,
			'recovery' => $recovery,
			'events' => [
				[
					'time' => '10/12/26, 8:05:00 AM',
					'kind' => SearchEvent::KIND_RETRIED,
					'step' => SearchEvent::STEP_PAPERLESS,
					'error' => 'GuzzleHttp\\Exception\\ConnectException: timeout',
					'durationMs' => 30001,
				],
				[
					'time' => '10/12/26, 8:00:00 AM',
					'kind' => SearchEvent::KIND_FAILED,
					'step' => SearchEvent::STEP_FILES,
					'error' => 'RuntimeException: database gone',
					'durationMs' => 12,
				],
			],
		];
	}

	/**
	 * The template rendered as Nextcloud renders it, with $_ and $l, and the page that a
	 * browser reads from it.
	 *
	 * @param ?array{since: string, failed: int, retried: int, lastFailure: string, recovery: string, events: list<array{time: string, kind: string, step: string, error: string, durationMs: int}>} $history
	 * @param ?array{summary: string, warnings: list<string>} $shares
	 * @return array{string, DOMDocument}
	 */
	private function render(PublicConfig $config, ?array $history = null, string $saveUrl = '/settings/save', ?array $shares = null): array {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$parameters = [
			'config' => $config,
			'saveUrl' => $saveUrl,
			'resetUrl' => '/settings/reset',
			'clearDiagnosticsUrl' => '/settings/diagnostics',
			'tryUrl' => '/settings/try',
			'history' => $history,
			'shares' => $shares,
		];

		ob_start();
		try {
			(static function (array $_, IL10N $l): void {
				require __DIR__ . '/../../../templates/settings.php';
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

		return [$html, $page];
	}

	private function element(DOMDocument $page, string $id): DOMElement {
		$element = $page->getElementById($id);
		self::assertNotNull($element, "The page has no element {$id}.");

		return $element;
	}

	/**
	 * The text of an element as a browser shows it, with its blanks collapsed.
	 */
	private function text(\DOMNode $node): string {
		return trim((string)preg_replace('/\s+/u', ' ', $node->textContent));
	}

	/**
	 * The texts of the cells of the rows of the body of the table with the class.
	 *
	 * @return list<list<string>>
	 */
	private function rows(DOMDocument $page, string $class): array {
		$rows = [];
		foreach ((new DOMXPath($page))->query("//table[contains(@class, '{$class}')]/tbody/tr") ?: [] as $row) {
			self::assertInstanceOf(DOMElement::class, $row);
			$cells = [];
			foreach ($row->childNodes as $cell) {
				if ($cell instanceof DOMElement) {
					$cells[] = $this->text($cell);
				}
			}
			$rows[] = $cells;
		}

		return $rows;
	}
}
