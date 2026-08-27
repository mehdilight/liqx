<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node\Document;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\Schema;
use Phpmystic\Liqx\Node\Style;

/**
 * Document-level parser. Reads the flat token stream produced by the Lexer
 * and builds a Document AST: an optional frontmatter block (restricted JS
 * declarations) followed by the render body, plus optional `<style>` and
 * `<schema>` blocks.
 */
final class Parser {

	private TokenStream $stream;

	private ExpressionParser $expr;

	private SandboxValidator $sandbox;

	public function parse( string $source ): Document {
		$stream = ( new Lexer() )->tokenize( $source );

		$this->stream = $stream;
		$this->expr   = new ExpressionParser( $stream );
		$this->sandbox = new SandboxValidator();

		$frontmatter = $this->parseFrontmatter();

		$body   = [];
		$style  = null;
		$schema = null;

		while ( ! $this->stream->eof() ) {
			$node = $this->expr->parseBodyNode();

			if ( $node instanceof Schema ) {
				$schema = $node;

				continue;
			}

			if ( $node instanceof Style ) {
				// Styles render inline where they appear; the last one is also
				// kept for hosts that want to collect a document's scoped CSS.
				$style = $node;
			}

			if ( null !== $node ) {
				$body[] = $node;
			}
		}

		return new Document( $frontmatter, $body, $style, $schema );
	}

	/** @return list<Frontmatter|FrontmatterDestructure> */
	private function parseFrontmatter(): array {
		if ( null === $this->stream->accept( TokenType::FrontmatterStart ) ) {
			return [];
		}

		$declarations = [];

		while ( null === $this->stream->accept( TokenType::FrontmatterEnd ) ) {
			$declaration = $this->expr->parseDeclaration();
			$this->sandbox->validateDeclaration( $declaration );
			$declarations[] = $declaration;
		}

		return $declarations;
	}
}
