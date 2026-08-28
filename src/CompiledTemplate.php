<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * A Document compiled to a native PHP closure. The generated source lives in
 * a `*.php` file on disk (see {@see self::cached}) so PHP's OPcache compiles
 * it to bytecode once and serves subsequent renders from shared memory.
 *
 * Rendering still flows through the {@see Evaluator} for value semantics
 * (filters, methods, loose comparisons) — only the AST-walking structure is
 * compiled away.
 */
final class CompiledTemplate {

	/**
	 * Shared, stateless value-semantics helper. The generated closure only ever
	 * calls pure methods on it (filters, property lookup, stringify), so one
	 * instance serves every render in the process.
	 */
	private static ?Evaluator $evaluator = null;

	private function __construct(
		private readonly \Closure $render,
		private string $name = '',
	) {}

	private static function evaluator(): Evaluator {
		return self::$evaluator ??= new Evaluator( new Renderer() );
	}

	/** Compile a PHP source string and load it (used when no cache dir is set). */
	public static function fromSource( string $phpSource, string $name = '' ): self {
		$file = tempnam( sys_get_temp_dir(), 'liqx' );

		if ( false === $file ) {
			throw new \RuntimeException( 'Unable to create a temporary file for the compiled template' );
		}

		file_put_contents( $file, $phpSource );

		try {
			return new self( self::requireClosure( $file ), $name );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Load a compiled template from a cache directory, compiling on the first
	 * request and writing the `.php` file for OPcache to pick up.
	 *
	 * `$fileKey` names the artifact; deriving it from the template name and
	 * source content is how hosts get free recompilation on change.
	 */
	public static function cached( string $dir, string $fileKey, string $name, \Closure $compile ): self {
		$path = $dir . '/' . $fileKey . '.php';

		if ( ! is_file( $path ) ) {
			file_put_contents( $path, $compile(), LOCK_EX );
		}

		return new self( self::requireClosure( $path ), $name );
	}

	private static function requireClosure( string $path ): \Closure {
		$result = require $path;

		if ( ! $result instanceof \Closure ) {
			throw new \RuntimeException( 'Compiled template did not return a closure: ' . $path );
		}

		return $result;
	}

	/** @param array<string, mixed> $data */
	public function render( Environment $environment, array $data = [], bool $strict = false ): string {
		return $this->renderContext( new Context( $environment, $strict, $data ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function renderIn( Environment $environment, array $data, Context $parent, bool $strict = false ): string {
		return $this->renderContext(
			Context::inherit( $environment, $strict || $parent->strict, $parent, $data )
		);
	}

	/** Render into a caller-built context. */
	public function renderContext( Context $context ): string {
		try {
			return ( $this->render )( $context, self::evaluator() );
		} catch ( LiqxException $e ) {
			if ( null === $e->templateName && '' !== $this->name ) {
				$e->templateName = $this->name;
			}

			throw $e;
		}
	}
}