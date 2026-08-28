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

	/** @var array<string, Template> parsed named templates, keyed by fs+name */
	private array $partials = [];

	private ?string $compiledTemplateDir = null;

	/** @var array<string, CompiledTemplate> compiled named templates, keyed by fs+name */
	private array $compiledTemplates = [];

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
		$this->compiledTemplates = [];

		if ( null === $this->compiledTemplateDir ) {
			return;
		}

		foreach ( glob( $this->compiledTemplateDir . '/*.php' ) ?: [] as $file ) {
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
	 */
	public function renderPartial( string $name, FileSystem $fileSystem, array $data = [], ?Context $parent = null ): string {
		$key = spl_object_id( $fileSystem ) . ':' . $name;

		if ( null !== $this->compiledTemplateDir ) {
			$compiled = $this->compiledTemplates[ $key ] ??= $this->compilePartial( $name, $fileSystem, $key );

			return null !== $parent
				? $compiled->renderIn( $this, $data, $parent )
				: $compiled->render( $this, $data );
		}

		$template  = $this->partials[ $key ] ??= Template::parse( $fileSystem->load( $name ), $this, $name );

		return null !== $parent ? $template->renderIn( $data, $parent ) : $template->render( $data );
	}

	private function compilePartial( string $name, FileSystem $fileSystem, string $key ): CompiledTemplate {
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