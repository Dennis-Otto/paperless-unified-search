<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Dennis Otto
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\PaperlessUnifiedSearch\Tests\Unit\Notification;

use ArrayObject;
use OCA\PaperlessUnifiedSearch\AppInfo\AppConstants;
use OCA\PaperlessUnifiedSearch\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The words of the notifications about new documents, in the language of the user: one
 * document by its title with a link to its file, several by their number with a link per
 * file that the notification names.
 */
final class NotifierTest extends TestCase {
	private const ELECTRICITY = ['title' => 'Electricity invoice', 'fileId' => 41, 'path' => '/Paperless/Electricity [P412].pdf'];
	private const INSURANCE = ['title' => 'Home insurance', 'fileId' => 42, 'path' => '/Paperless/Insurance [P389].pdf'];
	private const INTERNET = ['title' => 'Internet {document0}', 'fileId' => 43, 'path' => 'Internet [P371].pdf'];

	private ?string $language = null;

	public function testItNamesTheAppItSpeaksFor(): void {
		self::assertSame(AppConstants::APP_ID, $this->notifier()->getID());
		self::assertSame('[Paperless Unified Search]', $this->notifier()->getName());
	}

	public function testOneDocumentIsNamedAndLinked(): void {
		$values = new ArrayObject();

		$prepared = $this->notifier()->prepare($this->notification($values, ['count' => 1, 'documents' => [self::ELECTRICITY]]), 'de');

		self::assertSame('de', $this->language);
		self::assertSame([
			'icon' => 'https://cloud.example.com/apps/paperless_unified_search/img/app-dark.svg',
			'parsedSubject' => '[New in Paperless: Electricity invoice]',
			'richSubject' => ['[New in Paperless: {document}]', ['document' => [
				'type' => 'file',
				'id' => '41',
				'name' => 'Electricity invoice',
				'path' => 'Paperless/Electricity [P412].pdf',
				'link' => 'https://cloud.example.com/f/41',
			]]],
			'link' => 'https://cloud.example.com/f/41',
		], $values->getArrayCopy());
		self::assertInstanceOf(INotification::class, $prepared);
	}

	public function testSeveralDocumentsAreCountedAndEachOneLinked(): void {
		$values = new ArrayObject();

		$this->notifier()->prepare($this->notification($values, ['count' => 3, 'documents' => [self::ELECTRICITY, self::INSURANCE, self::INTERNET]]), 'en');

		self::assertSame('[%n new document in Paperless|%n new documents in Paperless|3]', $values['parsedSubject']);
		// A title that looks like a placeholder stays as it is.
		self::assertSame('Electricity invoice, Home insurance, Internet {document0}', $values['parsedMessage']);
		[$message, $parameters] = $values['richMessage'];
		self::assertSame('{document0}, {document1}, {document2}', $message);
		self::assertSame(['document0', 'document1', 'document2'], array_keys($parameters));
		self::assertSame('Internet {document0}', $parameters['document2']['name']);
		self::assertSame('Internet [P371].pdf', $parameters['document2']['path']);
		self::assertSame('https://cloud.example.com/f/42', $parameters['document1']['link']);
		self::assertArrayNotHasKey('link', $values->getArrayCopy(), 'No single file stands for several documents.');
	}

	public function testMoreDocumentsThanItNamesSayHowManyMore(): void {
		$values = new ArrayObject();

		$this->notifier()->prepare($this->notification($values, ['count' => 7, 'documents' => [self::ELECTRICITY, self::INSURANCE]]), 'en');

		self::assertSame('Electricity invoice, Home insurance [and %n more|and %n more|5]', $values['parsedMessage']);
		self::assertSame('{document0}, {document1} [and %n more|and %n more|5]', $values['richMessage'][0]);
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function wrongParameters(): array {
		return [
			'nothing' => [[]],
			'no count' => [['documents' => [self::ELECTRICITY]]],
			'a count as text' => [['count' => '1', 'documents' => [self::ELECTRICITY]]],
			'no documents' => [['count' => 1, 'documents' => []]],
			'documents as text' => [['count' => 1, 'documents' => 'none']],
			'a document as text' => [['count' => 1, 'documents' => ['none']]],
			'a title that is none' => [['count' => 1, 'documents' => [['title' => 7] + self::ELECTRICITY]]],
			'a file ID as text' => [['count' => 1, 'documents' => [['fileId' => '41'] + self::ELECTRICITY]]],
			'a path that is none' => [['count' => 1, 'documents' => [['path' => null] + self::ELECTRICITY]]],
		];
	}

	/**
	 * What the notification holds wrongly hides it, as Nextcloud does for an unknown one.
	 *
	 * @param array<string, mixed> $parameters
	 */
	#[DataProvider('wrongParameters')]
	public function testANotificationThatHoldsSomethingElseIsUnknown(array $parameters): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier()->prepare($this->notification(new ArrayObject(), $parameters), 'en');
	}

	public function testADocumentHeldWronglyIsLeftOut(): void {
		$values = new ArrayObject();

		$this->notifier()->prepare($this->notification($values, ['count' => 2, 'documents' => ['none', self::INSURANCE]]), 'en');

		self::assertSame('Home insurance [and %n more|and %n more|1]', $values['parsedMessage']);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function others(): array {
		return [
			'another app' => ['files', Notifier::SUBJECT],
			'another subject' => [AppConstants::APP_ID, 'something_else'],
		];
	}

	#[DataProvider('others')]
	public function testTheNotificationOfAnotherAppOrSubjectIsUnknown(string $app, string $subject): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier()->prepare($this->notification(new ArrayObject(), ['count' => 1, 'documents' => [self::ELECTRICITY]], $app, $subject), 'en');
	}

	private function notifier(): Notifier {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');
		$l10n->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count): string => '[' . $singular . '|' . $plural . '|' . $count . ']');
		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $language = null) use ($l10n): IL10N {
			self::assertSame(AppConstants::APP_ID, $app);
			$this->language = $language;

			return $l10n;
		});
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/apps/' . $app . '/img/' . $image);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(static function (string $route, array $parameters): string {
			self::assertSame('files.view.showFile', $route);

			return 'https://cloud.example.com/f/' . $parameters['fileid'];
		});

		return new Notifier($factory, $urlGenerator);
	}

	/**
	 * A notification with these parameters, whose words land in $values.
	 *
	 * @param ArrayObject<string, mixed> $values
	 * @param array<string, mixed> $parameters
	 */
	private function notification(ArrayObject $values, array $parameters, string $app = AppConstants::APP_ID, string $subject = Notifier::SUBJECT): INotification {
		$notification = $this->createStub(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($parameters);
		$keep = static function (string $name) use ($values, $notification): callable {
			return static function (string $value, array ...$more) use ($values, $notification, $name): INotification {
				$values[$name] = $more === [] ? $value : [$value, $more[0]];

				return $notification;
			};
		};
		$notification->method('setIcon')->willReturnCallback($keep('icon'));
		$notification->method('setParsedSubject')->willReturnCallback($keep('parsedSubject'));
		$notification->method('setRichSubject')->willReturnCallback($keep('richSubject'));
		$notification->method('setLink')->willReturnCallback($keep('link'));
		$notification->method('setParsedMessage')->willReturnCallback($keep('parsedMessage'));
		$notification->method('setRichMessage')->willReturnCallback($keep('richMessage'));

		return $notification;
	}
}
