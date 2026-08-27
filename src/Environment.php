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
	 * @param array<string, mixed> $data
	 */
	public function renderPartial( string $name, FileSystem $fileSystem, array $data = [], ?Context $parent = null ): string {
		$key      = spl_object_id( $fileSystem ) . ':' . $name;
		$template = $this->partials[ $key ] ??= Template::parse( $fileSystem->load( $name ), $this, $name );

		return null !== $parent ? $template->renderIn( $data, $parent ) : $template->render( $data );
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

		$type = $reflection->getParameters()[0]->getType() ?? null;

		return $type instanceof \ReflectionNamedType
			&& ! $type->isBuiltin()
			&& Context::class === $type->getName();
	}
}