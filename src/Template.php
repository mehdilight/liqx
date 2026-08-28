<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node\Document;

/**
 * A parsed `.liqx` document. Parse once, render many times. A host that loads
 * by name (e.g. `sections/hero`) passes it at parse time so render errors can
 * name their source.
 */
final class Template {

	private ?CompiledTemplate $compiled = null;

	private function __construct(
		private Environment $environment,
		private string $source,
		private string $name = '',
		private ?Document $document = null,
	) {}

	public static function parse( string $source, ?Environment $environment = null, string $name = '' ): self {
		$environment ??= Environment::default();

		if ( null !== $environment->compiledTemplateDir() ) {
			$template = new self( $environment, $source, $name );

			// Fail fast: a cache hit (a valid artifact on disk) proves the source
			// compiled and validated once, so parsing can be skipped entirely. On
			// a miss we parse now, so a syntax error surfaces at `parse()` time
			// — not on first render — and the parsed tree feeds the compiler.
			if ( ! $template->hasCachedArtifact() ) {
				$template->document();
			}

			return $template;
		}

		$document = self::parseDocument( $source, $name );

		return new self( $environment, $source, $name, $document );
	}

	/**
	 * Whether a compiled artifact already exists for this template. The artifact
	 * path matches {@see self::compiled()}, so a positive answer means rendering
	 * will hit the cache and never touch the parser.
	 */
	private function hasCachedArtifact(): bool {
		$dir = $this->environment->compiledTemplateDir();

		if ( null === $dir ) {
			return false;
		}

		return is_file( $dir . '/' . $this->artifactKey() . '.php' );
	}

	private function artifactKey(): string {
		return md5( 'template:' . Compiler::fingerprint() . ':' . $this->name . ':' . md5( $this->source ) );
	}

	private static function parseDocument( string $source, string $name ): Document {
		try {
			return ( new Parser() )->parse( $source );
		} catch ( LiqxException $e ) {
			if ( null === $e->templateName && '' !== $name ) {
				$e->templateName = $name;
			}

			throw $e;
		}
	}

	public function name(): string {
		return $this->name;
	}

	/** A copy with a name — hosts that load by name call this after caching. */
	public function withName( string $name ): self {
		$clone       = clone $this;
		$clone->name = $name;

		return $clone;
	}

	/** @param array<string, mixed> $data */
	public function render( array $data = [], bool $strict = false ): string {
		$context = new Context( $this->environment, $strict, $data );

		return $this->renderContext( $context );
	}

	/**
	 * Render with a parent context's scope visible (plus `$data`) — nested
	 * section renders share the page scope.
	 *
	 * @param array<string, mixed> $data
	 */
	public function renderIn( array $data, Context $parent, bool $strict = false ): string {
		$context = Context::inherit( $this->environment, $strict || $parent->strict, $parent, $data );

		return $this->renderContext( $context );
	}

	/**
	 * Render into a caller-built context — used by hosts that stage extra
	 * scope (e.g. block children) on the context before rendering.
	 */
	public function renderContext( Context $context ): string {
		$document = $this->document();

		if ( null !== $document->schema ) {
			$this->validateFrontmatterTypes( $document, $context );
		}

		if ( null !== $this->environment->compiledTemplateDir() ) {
			return $this->compiled()->renderContext( $context );
		}

		try {
			return ( new Renderer() )->render( $document, $context );
		} catch ( LiqxException $e ) {
			if ( null === $e->templateName && '' !== $this->name ) {
				$e->templateName = $this->name;
			}

			throw $e;
		}
	}

	/**
	 * Evaluate the template's frontmatter and return the const-name → value map.
	 * When the template declares a `<schema>`, the resulting `props` are type-
	 * checked against it, throwing a typed early failure on a mismatch.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function frontmatter( array $data = [], bool $strict = false ): array {
		$document = $this->document();
		$context  = new Context( $this->environment, $strict, $data );

		$values = ( new Renderer() )->evaluateFrontmatter( $document, $context );

		if ( null !== $document->schema ) {
			( new SchemaValidator() )->validate( $document, $values );
		}

		return $values;
	}

	private function validateFrontmatterTypes( Document $document, Context $context ): void {
		$values = ( new Renderer() )->evaluateFrontmatter( $document, $context );

		( new SchemaValidator() )->validate( $document, $values );
	}

	/**
	 * The compiled closure for this template, cached on the instance. The
	 * artifact filename embeds the template name and source hash, so an edited
	 * source compiles to a different file rather than a stale one.
	 */
	private function compiled(): CompiledTemplate {
		if ( null !== $this->compiled ) {
			return $this->compiled;
		}

		$dir = $this->environment->compiledTemplateDir();

		if ( null === $dir ) {
			return $this->compiled = CompiledTemplate::fromSource(
				( new Compiler() )->compile( $this->document() ),
				$this->name
			);
		}

		$fileKey = $this->artifactKey();

		return $this->compiled = CompiledTemplate::cached(
			$dir,
			$fileKey,
			$this->name,
			fn (): string => ( new Compiler() )->compile( $this->document() )
		);
	}

	/**
	 * The parsed document, parsing lazily on first need (subsequent calls return
	 * the cached tree). In compiled-dir mode a cache hit never reaches this.
	 */
	public function document(): Document {
		if ( null === $this->document ) {
			$this->document = self::parseDocument( $this->source, $this->name );
		}

		return $this->document;
	}

	/** The decoded `<schema>` JSON, or null if the template has none. */
	/** @return array<string, mixed>|null */
	public function schema(): ?array {
		if ( null === $this->document()->schema ) {
			return null;
		}

		$decoded = json_decode( $this->document()->schema->json, true );

		if ( ! is_array( $decoded ) ) {
			throw new LiqxException( 'Invalid schema JSON' );
		}

		return $decoded;
	}
}
