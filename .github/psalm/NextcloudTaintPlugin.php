<?php

declare(strict_types=1);

/**
 * What Psalm's taint analysis has to know of Nextcloud to follow untrusted input
 * through an app, and what it can't know on its own.
 *
 * - Nextcloud passes the parameters of a request to a controller as the arguments of
 *   its public methods, so Psalm, which sees no $_GET there, saw no input: every
 *   parameter of a public method of a controller is a source now, as Nextcloud's own
 *   analysis has it (build/psalm/AppFrameworkTainter.php of nextcloud/server).
 * - The public API of Nextcloud, nextcloud/ocp, marks IRequest as a source and the
 *   queries of the database as sinks, but not its files, its HTTP client or its
 *   search: the paths of the files and folders of a user and of the app's data, the
 *   address of a request to another server and the address of a redirection are
 *   sinks now, and the term and the filters of a search and the answers of other
 *   servers are sources.
 *
 * Psalm loads this plugin from psalm.xml, for the analysis of the types as well, where
 * it changes nothing. The canary in .github/psalm/canary/ proves on every run of
 * scripts/check.sh that the analysis still finds each kind of path; a method that
 * nextcloud/ocp renames or removes stops the analysis with the name of the method,
 * instead of leaving it blind to that sink.
 *
 * It works with Psalm 6 and Psalm 7, which hold the kinds of taint differently: Psalm 6
 * as a list of their names, with every kind of input in TaintKindGroup, and Psalm 7 as
 * the bits of an integer, with every kind of input in TaintKind, which has no
 * TaintKindGroup any more; and Psalm 7 tells a source apart by its location, so the
 * input of a parameter takes a path into it. The composer.json of an app is its own,
 * so an app that takes a release of the blueprint keeps the Psalm that it has until it
 * updates Psalm itself.
 *
 * The blueprint keeps this file current: https://github.com/Dennis-Otto/repo-blueprint
 */

use PhpParser\Node\Stmt\ClassMethod;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterFunctionLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterFunctionLikeAnalysisEvent;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\TaintKind;
use Psalm\Type\TaintKindGroup;

final class NextcloudTaintPlugin implements AfterCodebasePopulatedInterface, AfterFunctionLikeAnalysisInterface {
	/** The class of every controller, whose public methods Nextcloud calls with the parameters of a request. */
	public const CONTROLLER = 'OCP\AppFramework\Controller';

	/**
	 * The methods of nextcloud/ocp whose result is untrusted input: what a user searches
	 * for, and the answer of another server, which the app doesn't control.
	 *
	 * @var array<string, list<string>>
	 */
	public const SOURCES = [
		'OCP\Search\ISearchQuery' => ['getTerm'],
		'OCP\Search\IFilter' => ['get'],
		'OCP\Http\Client\IResponse' => ['getBody', 'getHeader', 'getHeaders'],
	];

	/**
	 * The parameters of the methods of nextcloud/ocp that untrusted input must not
	 * reach unescaped, each with the kinds of taint that it takes: their names in Psalm 6,
	 * their bits in Psalm 7.
	 *
	 * @var array<string, array<string, array<string, list<int|string>>>>
	 */
	public const SINKS = [
		// The files and folders of a user, by their path: a path from a request can
		// climb out of the folder with "../".
		'OCP\Files\Folder' => [
			'get' => ['path' => [TaintKind::INPUT_FILE]],
			'getOrCreateFolder' => ['path' => [TaintKind::INPUT_FILE]],
			'nodeExists' => ['path' => [TaintKind::INPUT_FILE]],
			'newFolder' => ['path' => [TaintKind::INPUT_FILE]],
			'newFile' => ['path' => [TaintKind::INPUT_FILE]],
		],
		'OCP\Files\Node' => [
			'move' => ['targetPath' => [TaintKind::INPUT_FILE]],
			'copy' => ['targetPath' => [TaintKind::INPUT_FILE]],
		],
		// The data of the app, by the names of its folders and files.
		'OCP\Files\SimpleFS\ISimpleRoot' => [
			'getFolder' => ['name' => [TaintKind::INPUT_FILE]],
			'newFolder' => ['name' => [TaintKind::INPUT_FILE]],
		],
		'OCP\Files\SimpleFS\ISimpleFolder' => [
			'fileExists' => ['name' => [TaintKind::INPUT_FILE]],
			'getFile' => ['name' => [TaintKind::INPUT_FILE]],
			'newFile' => ['name' => [TaintKind::INPUT_FILE]],
			'getFolder' => ['name' => [TaintKind::INPUT_FILE]],
			'newFolder' => ['path' => [TaintKind::INPUT_FILE]],
		],
		// A request of the server to an address from a request: server-side request
		// forgery, to the network behind the server.
		'OCP\Http\Client\IClient' => [
			'get' => ['uri' => [TaintKind::INPUT_SSRF]],
			'head' => ['uri' => [TaintKind::INPUT_SSRF]],
			'post' => ['uri' => [TaintKind::INPUT_SSRF]],
			'put' => ['uri' => [TaintKind::INPUT_SSRF]],
			'patch' => ['uri' => [TaintKind::INPUT_SSRF]],
			'delete' => ['uri' => [TaintKind::INPUT_SSRF]],
			'options' => ['uri' => [TaintKind::INPUT_SSRF]],
			'request' => ['uri' => [TaintKind::INPUT_SSRF]],
			'getAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
			'headAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
			'postAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
			'putAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
			'deleteAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
			'optionsAsync' => ['uri' => [TaintKind::INPUT_SSRF]],
		],
		// A redirection to an address from a request: an open redirect, which sends
		// the user on to a page of the attacker with the trust of the server.
		'OCP\AppFramework\Http\RedirectResponse' => [
			'__construct' => ['redirectURL' => [TaintKind::INPUT_HEADER]],
		],
	];

	public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void {
		$codebase = $event->getCodebase();
		foreach (self::SOURCES as $class => $methods) {
			foreach ($methods as $method) {
				self::method($codebase, $class, $method)->taint_source_types = self::input();
			}
		}
		foreach (self::SINKS as $class => $methods) {
			foreach ($methods as $method => $parameters) {
				$storage = self::method($codebase, $class, $method);
				foreach ($parameters as $name => $kinds) {
					$found = false;
					foreach ($storage->params as $parameter) {
						if ($parameter->name === $name) {
							$parameter->sinks = self::bits()
								? array_reduce($kinds, static fn (int $sinks, int $kind): int => $sinks | $kind, $parameter->sinks)
								: array_values(array_unique([...$parameter->sinks ?? [], ...$kinds]));
							$found = true;
						}
					}
					if (!$found) {
						throw self::changed("$class::$method() of nextcloud/ocp has no parameter \$$name");
					}
				}
			}
		}
	}

	/**
	 * Marks each parameter of a public method of a controller as input of a user.
	 */
	public static function afterStatementAnalysis(AfterFunctionLikeAnalysisEvent $event): ?bool {
		$codebase = $event->getCodebase();
		$source = $event->getStatementsSource();
		$class = $source->getFQCLN();
		$method = $event->getStmt();
		if ($class === null
			|| !$codebase->taint_flow_graph
			|| !$method instanceof ClassMethod
			|| !$method->isPublic()
			|| $method->isMagic()
			|| !$codebase->classExtendsOrImplements($class, self::CONTROLLER)
		) {
			return null;
		}
		$function = $class . '::' . (string)$event->getFunctionlikeStorage()->cased_name;
		foreach ($method->params as $offset => $parameter) {
			// By name: Psalm 7 takes the location before the kinds of taint, Psalm 6 after them.
			$input = $codebase->addTaintSource(
				expr_type: Type::getString(),
				taint_id: strtolower($function) . '#' . ($offset + 1),
				taints: self::input(),
				code_location: new CodeLocation($source, $parameter),
			);
			if (!self::bits()) {
				// Psalm 6 gives the source the id of the node of the parameter, so the
				// parameter carries the input on.
				continue;
			}
			// Psalm 7 adds the location of a source to its id: the input flows into the
			// node of the parameter by a path of its own.
			$node = $codebase->getTaintArgumentNode($source, $function, $offset);
			if ($node !== null) {
				foreach ($input->parent_nodes as $from) {
					$codebase->addTaintPath($source, $from, $node);
				}
			}
		}
		return null;
	}

	/**
	 * Whether Psalm holds the kinds of taint as the bits of an integer, as Psalm 7 does,
	 * or as a list of their names, as Psalm 6 does.
	 */
	private static function bits(): bool {
		return is_int(TaintKind::INPUT_FILE);
	}

	/**
	 * Every kind of taint of untrusted input, in the form of the Psalm that runs.
	 *
	 * @return int|list<string>
	 */
	private static function input(): int|array {
		return self::bits() ? TaintKind::ALL_INPUT : TaintKindGroup::ALL_INPUT;
	}

	private static function method(Codebase $codebase, string $class, string $method): MethodStorage {
		if (!$codebase->classlike_storage_provider->has($class)) {
			throw self::changed("nextcloud/ocp has no $class");
		}
		$storage = $codebase->classlike_storage_provider->get($class)->methods[strtolower($method)] ?? null;
		if ($storage === null) {
			throw self::changed("nextcloud/ocp has no $class::$method()");
		}
		return $storage;
	}

	private static function changed(string $what): RuntimeException {
		return new RuntimeException(
			"The taint analysis can't follow untrusted input through Nextcloud as before: $what, which "
			. '.github/psalm/NextcloudTaintPlugin.php names as a source or a sink of untrusted input. The file '
			. 'belongs to the blueprint, which adapts it to the new nextcloud/ocp: '
			. 'https://github.com/Dennis-Otto/repo-blueprint/issues'
		);
	}
}
