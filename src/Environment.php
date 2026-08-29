<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Holds the registries a template renders against. Liqx's extension points
 * mirror phpmystic/liquid's:
 *
 *   - filters  — value-first callables for `a | f(b)` pipelines;
 *   - globals  — callables for `name(...)` in `{ }` expressions (the Liqx
 *                equivalent of Liquid's custom *tags*);
 *   - FileSystems — how bare section/snippet names resolve to sources.
 *
 * A global may declare a leading `Context` parameter (detected by
 * reflection) to receive the current evaluation context.
 */
final class Environment {

	/** @var array<string, array{callable, bool}> */
	private array $globals = [];

	/** @var array<string, callable> */
	private array $filters = [];

	private ?FileSystem $snippetFileSystem = null;

	private ?FileSystem $sectionFileSystem = null;

	/**
	 * Parsed named templates, keyed by the FileSystem that resolved them.
	 *
	 * Weakly keyed on purpose: hosts commonly build a FileSystem per render, and
	 * PHP reuses an object id once the original is collected — an id-keyed cache
	 * could hand a fresh FileSystem the entry of a freed one and serve the wrong
	 * source under the same name. A WeakMap keys on identity and drops the entry
	 * with the object, so it cannot go stale and cannot grow unboundedly.
	 *
	 * @var \WeakMap<FileSystem, array<string, Template>>
	 */
	private \WeakMap $partials;

	private ?string $compiledTemplateDir = null;

	/**
	 * Compiled named templates, keyed by FileSystem — see {@see $partials} for
	 * why this is weak.
	 *
	 * @var \WeakMap<FileSystem, array<string, CompiledTemplate>>
	 */
	private \WeakMap $compiledTemplates;

	public function __construct() {
		$this->partials          = new \WeakMap();
		$this->compiledTemplates = new \WeakMap();
	}

	private static ?Environment $default = null;

	public static function default(): Environment {
		return self::$default ??= self::create();
	}

	/** A full standard environment without the singleton. */
	public static function create(): Environment {
		$environment = new self();

		foreach ( StandardFilters::all() as $name => $filter ) {
			$environment->registerFilter( $name, $filter );
		}

		$environment->registerGlobal( 'render', $environment->makeRenderGlobal() );
		$environment->registerGlobal( 'section', $environment->makeSectionGlobal() );
		$environment->registerGlobal( 'now', static fn (): int => time() );

		return $environment;
	}

	public function registerFilter( string $name, callable $filter ): void {
		$this->filters[ $name ] = $filter;
	}

	public function filter( string $name ): ?callable {
		return $this->filters[ $name ] ?? null;
	}

	public function hasFilter( string $name ): bool {
		return isset( $this->filters[ $name ] );
	}

	/**
	 * Register a callable usable as `name(...)` inside `{ }` expressions.
	 * Whether it wants the Context is intrinsic — detected once here.
	 */
	public function registerGlobal( string $name, callable $global ): void {
		$this->globals[ $name ] = [ $global, self::wantsContext( $global ) ];
	}

	public function global( string $name ): ?callable {
		return $this->globals[ $name ][0] ?? null;
	}

	/** @return array{callable, bool}|null `[callable, wantsContext]` */
	public function globalEntry( string $name ): ?array {
		return $this->globals[ $name ] ?? null;
	}

	/**
	 * The registered surface of this environment, for tooling (LSP completion /
	 * hover). Filters and globals returned by name so a client can suggest and
	 * document exactly what a template can call here — including anything a host
	 * registered on top of the standard set.
	 *
	 * @return array{ filters: list<string>, globals: list<string> }
	 */
	public function capabilities(): array {
		return [
			'filters' => array_keys( $this->filters ),
			'globals' => array_keys( $this->globals ),
		];
	}

	public function setSnippetFileSystem( FileSystem $fileSystem ): void {
		$this->snippetFileSystem = $fileSystem;
	}

	public function setSectionFileSystem( FileSystem $fileSystem ): void {
		$this->sectionFileSystem = $fileSystem;
	}

	/**
	 * Enable the compiled rendering path. Templates and named partials are
	 * compiled to native PHP closures in `$dir` (one `.php` file each); on
	 * subsequent runs OPcache serves them from shared memory. Artifact
	 * filenames embed a hash of the template content, so an edited source
	 * naturally recompiles — call {@see clearCompiledTemplates} only to sweep
	 * stale artifacts left behind by prior versions.
	 */
	public function setCompiledTemplateDir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new \RuntimeException( 'Unable to create compiled template directory: ' . $dir );
		}

		if ( ! is_writable( $dir ) ) {
			throw new \RuntimeException( 'Compiled template directory is not writable: ' . $dir );
		}

		$this->compiledTemplateDir = $dir;
	}

	public function compiledTemplateDir(): ?string {
		return $this->compiledTemplateDir;
	}

	/** Drop in-memory compiled closures and any `.php` artifacts in the cache dir. */
	public function clearCompiledTemplates(): void {
		$this->compiledTemplates = new \WeakMap();

		// The memoized `<schema>` sidecar answers are keyed by sidecar path, and
		// those paths are about to stop existing. Dropping them keeps a
		// long-lived worker that clears the cache from accumulating an entry per
		// source it ever compiled.
		Template::forgetSchemaFlags();

		if ( null === $this->compiledTemplateDir ) {
			return;
		}

		foreach ( glob( $this->compiledTemplateDir . '/*.{php,meta}', GLOB_BRACE ) ?: [] as $file ) {
			@unlink( $file );
		}
	}

	public function snippetFileSystem(): ?FileSystem {
		return $this->snippetFileSystem;
	}

	public function sectionFileSystem(): ?FileSystem {
		return $this->sectionFileSystem;
	}

	/**
	 * Parse + render a named template through a FileSystem, cached so repeated
	 * calls re-render the same parsed document. When a parent Context is given,
	 * the partial renders with the parent's scope visible (nested section
	 * renders); otherwise it gets a fresh scope.
	 *
	 * With a compiled template dir configured, the partial is compiled to a
	 * native PHP closure instead of interpreted.
	 *
	 * @param array<string, mixed> $data
	 * @param array{tag?: string, attrs?: array<string, mixed>|string}|null $wrapper
	 */
	public function renderPartial( string $name, FileSystem $fileSystem, array $data = [], ?Context $parent = null, ?array $wrapper = null ): string {
		if ( null !== $this->compiledTemplateDir ) {
			$compiled = $this->compiledTemplates[ $fileSystem ][ $name ] ?? null;

			if ( ! $compiled instanceof CompiledTemplate ) {
				$compiled = $this->compilePartial( $name, $fileSystem );

				// A WeakMap value cannot be modified in place, so the per-name
				// map is read, extended and written back whole.
				$byName                                  = $this->compiledTemplates[ $fileSystem ] ?? [];
				$byName[ $name ]                         = $compiled;
				$this->compiledTemplates[ $fileSystem ] = $byName;
			}

			return null !== $parent
				? $compiled->renderIn( $this, $data, $parent, false, $wrapper )
				: $compiled->render( $this, $data, false, $wrapper );
		}

		$template = $this->partials[ $fileSystem ][ $name ] ?? null;

		if ( ! $template instanceof Template ) {
			$template = Template::parse( $fileSystem->load( $name ), $this, $name );

			$byName                          = $this->partials[ $fileSystem ] ?? [];
			$byName[ $name ]                 = $template;
			$this->partials[ $fileSystem ] = $byName;
		}

		return null !== $parent ? $template->renderIn( $data, $parent, false, $wrapper ) : $template->render( $data, false, $wrapper );
	}

	private function compilePartial( string $name, FileSystem $fileSystem ): CompiledTemplate {
		$dir = $this->compiledTemplateDir;

		if ( null === $dir ) {
			throw new \LogicException( 'compilePartial requires a compiled template dir' );
		}

		$source  = $fileSystem->load( $name );
		$fileKey = md5( 'partial:' . Compiler::fingerprint() . ':' . $name . ':' . md5( $source ) );

		return CompiledTemplate::cached(
			$dir,
			$fileKey,
			$name,
			fn (): string => ( new Compiler() )->compile( ( new Parser() )->parse( $source ) )
		);
	}

	private function makeRenderGlobal(): callable {
		return function ( string $name, array $props = [] ): string {
			if ( null === $this->snippetFileSystem ) {
				throw new \RuntimeException( 'No snippet file system configured' );
			}

			return $this->renderPartial( $name, $this->snippetFileSystem, [ 'props' => $props ] );
		};
	}

	private function makeSectionGlobal(): callable {
		// Context-aware: `{% section %}` renders with the parent scope visible.
		return function ( Context $context, string $name ): string {
			if ( null === $this->sectionFileSystem ) {
				throw new \RuntimeException( 'No section file system configured' );
			}

			return $this->renderPartial( $name, $this->sectionFileSystem, [ 'section' => [ 'name' => $name ] ], $context );
		};
	}

	private static function wantsContext( callable $callable ): bool {
		$reflection = is_array( $callable )
			? new \ReflectionMethod( $callable[0], $callable[1] )
			: new \ReflectionFunction( \Closure::fromCallable( $callable ) );

		$parameters = $reflection->getParameters();

		if ( [] === $parameters ) {
			return false;
		}

		$type = $parameters[0]->getType() ?? null;

		return $type instanceof \ReflectionNamedType
			&& ! $type->isBuiltin()
			&& Context::class === $type->getName();
	}
}