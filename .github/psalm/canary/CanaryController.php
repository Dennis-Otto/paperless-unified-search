<?php

declare(strict_types=1);

/**
 * The canary of the taint analysis: a controller that lets the input of a request reach
 * each kind of sink of a Nextcloud app. scripts/check.sh analyses it on every run, and
 * fails unless the analysis finds the path at each line that ends in "expect:" with the
 * issue, and no other. An analysis that stops seeing the parameters of a controller, or
 * a sink, fails here instead of passing every app in silence.
 *
 * Nextcloud never loads this file: it is no part of lib/ or of the package of the app.
 *
 * The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
 */

namespace OCA\TaintCanary;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClient;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Search\ISearchQuery;

final class CanaryController extends Controller {
	public function __construct(
		IRequest $request,
		private Folder $folder,
		private File $file,
		private ISimpleFolder $appData,
		private IClient $client,
		private IDBConnection $db,
	) {
		parent::__construct('canary', $request);
	}

	public function newFile(string $name): DataResponse {
		$this->folder->newFile($name); // expect: TaintedFile
		return new DataResponse();
	}

	public function move(string $target): DataResponse {
		$this->file->move('/user/files/' . $target); // expect: TaintedFile
		return new DataResponse();
	}

	public function appData(string $name): DataResponse {
		$this->appData->getFile($name); // expect: TaintedFile
		return new DataResponse();
	}

	public function fetch(string $url): DataResponse {
		$this->client->get($url); // expect: TaintedSSRF
		return new DataResponse();
	}

	public function redirect(string $url): RedirectResponse {
		return new RedirectResponse($url); // expect: TaintedHeader
	}

	/**
	 * Nextcloud routes to a public static method of a controller as well.
	 */
	public static function back(string $url): RedirectResponse {
		return new RedirectResponse($url); // expect: TaintedHeader
	}

	public function query(string $table): DataResponse {
		$this->db->executeQuery('SELECT * FROM ' . $table); // expect: TaintedSql
		return new DataResponse();
	}

	public function search(ISearchQuery $query): DataResponse {
		$this->folder->get($query->getTerm()); // expect: TaintedFile
		return new DataResponse();
	}

	/**
	 * Input that the app has made safe for its sink passes.
	 */
	public function newFileSafely(string $name): DataResponse {
		$this->folder->newFile(self::fileName($name));
		return new DataResponse();
	}

	/**
	 * @psalm-taint-escape file
	 */
	private static function fileName(string $name): string {
		return str_replace(['/', '\\'], '', $name);
	}
}

/**
 * A class that is no controller: Nextcloud calls it with no input of a request, and the
 * parameters of its methods are no sources; the answer of another server is one.
 */
final class CanaryService {
	public function __construct(
		private Folder $folder,
		private IClient $client,
	) {
	}

	public function newFile(string $name): void {
		$this->folder->newFile($name);
	}

	public function download(): void {
		$name = $this->client->get('https://paperless.example/api/name')->getBody();
		if (is_string($name)) {
			$this->folder->newFile($name); // expect: TaintedFile
		}
	}
}
