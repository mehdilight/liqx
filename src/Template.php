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

	private function __construct(
		private readonly Document $document,
		private readonly Environment $environment,
		private string $name = '',
	) {}

	public static function parse( string $source, ?Environment $environment = null, string $name = '' ): self {
		$environment ??= Environment::default();

		try {
			$document = ( new Parser() )->parse( $source );
		} catch ( LiqxException $e ) {
			if ( null === $e->templateName && '' !== $name ) {
				$e->templateName = $name;
			}

			throw $e;
		}

		return new self( $document, $environment, $name );
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

	private function renderContext( Context $context ): string {
		try {
			return ( new Renderer() )->render( $this->document, $context );
		} catch ( LiqxException $e ) {
			if ( null === $e->templateName && '' !== $this->name ) {
				$e->templateName = $this->name;
			}

			throw $e;
		}
	}

	public function document(): Document {
		return $this->document;
	}

	/** The decoded `<schema>` JSON, or null if the template has none. */
	/** @return array<string, mixed>|null */
	public function schema(): ?array {
		if ( null === $this->document->schema ) {
			return null;
		}

		$decoded = json_decode( $this->document->schema->json, true );

		if ( ! is_array( $decoded ) ) {
			throw new LiqxException( 'Invalid schema JSON' );
		}

		return $decoded;
	}
}
