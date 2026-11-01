<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Service;

use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Model\AlertChoice;
use OCA\PaperlessUnifiedSearch\Service\SavedSearches;
use OCA\PaperlessUnifiedSearch\Tests\Unit\UserPreferences;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each user wants to be notified about, a preference that exists only while they want
 * something, and what the model makes of whatever it holds.
 */
final class SavedSearchesTest extends TestCase {
	use UserPreferences;

	private const JAMIE = 'jamie.' . AppConstants::APP_ID . '.new_document_alerts';

	public function testNothingStoredIsNothingChosen(): void {
		$choice = $this->service()->of('jamie');

		self::assertFalse($choice->everyDocument);
		self::assertSame([], $choice->terms);
		self::assertTrue($choice->isNothing());
	}

	public function testAChoiceIsStoredAndReadBack(): void {
		$this->service()->choose('jamie', new AlertChoice(true, ['tag:unpaid']));

		self::assertSame(['everyDocument' => true, 'terms' => ['tag:unpaid']], $this->preferences[self::JAMIE]);
		$choice = $this->service()->of('jamie');
		self::assertTrue($choice->everyDocument);
		self::assertSame(['tag:unpaid'], $choice->terms);
		self::assertFalse($choice->isNothing());
	}

	public function testTheChoiceIsALazyValue(): void {
		$config = $this->createMock(IUserConfig::class);
		$config->expects(self::once())->method('getValueArray')->with('jamie', AppConstants::APP_ID, SavedSearches::PREFERENCE, [], true)->willReturn([]);
		$config->expects(self::once())->method('setValueArray')->with('jamie', AppConstants::APP_ID, SavedSearches::PREFERENCE, ['everyDocument' => true, 'terms' => []], true);

		$service = new SavedSearches($config);
		$service->of('jamie');
		$service->choose('jamie', new AlertChoice(true));
	}

	public function testChoosingNothingRemovesThePreference(): void {
		$this->preferences[self::JAMIE] = ['everyDocument' => true, 'terms' => []];

		$this->service()->choose('jamie', new AlertChoice());

		self::assertSame([], $this->preferences);
	}

	public function testEveryoneIsWhoeverWantsSomething(): void {
		$this->preferences = [
			self::JAMIE => ['everyDocument' => false, 'terms' => ['tag:unpaid']],
			'sam.' . AppConstants::APP_ID . '.new_document_alerts' => ['everyDocument' => true, 'terms' => []],
			'alex.' . AppConstants::APP_ID . '.new_document_alerts' => ['everyDocument' => false, 'terms' => []],
			'kim.' . AppConstants::APP_ID . '.new_document_alerts' => 'something else',
			'jamie.' . AppConstants::APP_ID . '.always_search' => true,
		];

		$everyone = $this->service()->ofEveryone();

		self::assertSame(['jamie', 'sam'], array_keys($everyone));
		self::assertSame(['tag:unpaid'], $everyone['jamie']->terms);
		self::assertTrue($everyone['sam']->everyDocument);
	}

	public function testEveryoneAsksForTheValuesAsTheyAreStored(): void {
		$config = $this->createMock(IUserConfig::class);
		$config->expects(self::once())->method('getValuesByUsers')->with(AppConstants::APP_ID, SavedSearches::PREFERENCE, ValueType::ARRAY)->willReturn([]);

		self::assertSame([], (new SavedSearches($config))->ofEveryone());
	}

	/**
	 * @return array<string, array{array<array-key, mixed>, list<string>}>
	 */
	public static function entered(): array {
		return [
			'nothing' => [[], []],
			'blank lines' => [['', '   ', "\t"], []],
			'each as one line' => [["  tag:unpaid\r", "correspondent:\"tax\n office\" "], ['tag:unpaid', 'correspondent:"tax office"']],
			'a repeat' => [['tax', 'invoice', ' tax '], ['tax', 'invoice']],
			'not as text' => [[7, null, ['tax'], 'invoice'], ['invoice']],
			'a number as text' => [['2026'], ['2026']],
		];
	}

	/**
	 * @param array<array-key, mixed> $entered
	 * @param list<string> $terms
	 */
	#[DataProvider('entered')]
	public function testWhatAUserEntersBecomesOneLineEachWithoutRepeats(array $entered, array $terms): void {
		self::assertSame($terms, SavedSearches::termsOf($entered));
	}

	/**
	 * @return array<string, array{mixed, bool, list<string>}>
	 */
	public static function stored(): array {
		return [
			'not an array' => ['something', false, []],
			'an empty one' => [[], false, []],
			'every document as text' => [['everyDocument' => 'yes'], false, []],
			'every document' => [['everyDocument' => true], true, []],
			'terms as text' => [['terms' => 'tax'], false, []],
			'a term that is none' => [['terms' => ['tax', 7]], false, ['tax']],
		];
	}

	/**
	 * @param list<string> $terms
	 */
	#[DataProvider('stored')]
	public function testTheModelTakesOnlyWhatItKnows(mixed $stored, bool $everyDocument, array $terms): void {
		$choice = AlertChoice::fromStored($stored);

		self::assertSame($everyDocument, $choice->everyDocument);
		self::assertSame($terms, $choice->terms);
	}

	public function testTheModelAnswersAsJson(): void {
		self::assertSame('{"everyDocument":true,"terms":["tax"]}', json_encode(new AlertChoice(true, ['tax']), JSON_THROW_ON_ERROR));
	}

	/**
	 * Only every document or a search makes it something.
	 */
	public function testAChoiceOfSearchesAloneIsSomething(): void {
		self::assertFalse((new AlertChoice(false, ['tax']))->isNothing());
		self::assertFalse((new AlertChoice(true, []))->isNothing());
		self::assertTrue((new AlertChoice(false, []))->isNothing());
	}

	private function service(): SavedSearches {
		return new SavedSearches($this->userConfig());
	}
}
