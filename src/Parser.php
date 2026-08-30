<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node\Document;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\Schema;
use Phpmystic\Liqx\Node\Style;
use Phpmystic\Liqx\Node\TemplateBlock;

/**
 * Document-level parser. Reads the flat token stream produced by the Lexer
 * and builds a Document AST: an optional frontmatter block (restricted JS
 * declarations, plus an optional final `return <expr>;` that becomes the
 * body's `props`) followed by the render body, plus optional `<style>` and
 * `<schema>` blocks.
 */
	final class Parser {

	private TokenStream $stream;

	private ExpressionParser $expr;

	private SandboxValidator $sandbox;

	/** The frontmatter `return <expr>;`, if the document ends its frontmatter with one. */
	private ?Expr $frontmatterReturn = null;

	public function parse( string $source ): Document {
		$stream = ( new Lexer() )->tokenize( $source );

		$this->stream = $stream;
		$this->expr   = new ExpressionParser( $stream );
		$this->sandbox = new SandboxValidator();
		$this->frontmatterReturn = null;

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

			if ( $node instanceof Element && 'template' === strtolower( $node->tag ) ) {
				$templateChildren = [];

				foreach ( $node->children as $child ) {
					if ( $child instanceof Schema ) {
						$schema = $child;

						continue;
					}

					if ( $child instanceof Style ) {
						$style = $child;
					}

					$templateChildren[] = $child;
				}

				$body[] = new TemplateBlock( $templateChildren, $node->attrs, $node->line );

				continue;
			}

			if ( null !== $node ) {
				$body[] = $node;
			}
		}

		return new Document( $frontmatter, $body, $style, $schema, $this->frontmatterReturn );
	}

	/** @return list<object> */
	private function parseFrontmatter(): array {
		if ( null === $this->stream->accept( TokenType::FrontmatterStart ) ) {
			return [];
		}

		$declarations = [];

		while ( null === $this->stream->accept( TokenType::FrontmatterEnd ) ) {
			$keyword = $this->stream->current();

			if ( null !== $keyword && TokenType::Keyword === $keyword->type && 'return' === $keyword->value ) {
				$this->stream->next();

				$tokenAfterReturn = $this->stream->current();
				if ( null === $tokenAfterReturn || TokenType::Semicolon === $tokenAfterReturn->type ) {
					$this->stream->accept( TokenType::Semicolon );
					$declarations[] = new Node\FrontmatterReturn( null, $keyword->line );
					continue;
				}

				$return = $this->expr->parse();
				$this->sandbox->validateExported( $return, $keyword->line );
				$this->stream->accept( TokenType::Semicolon );

				// A `return` must be the final frontmatter declaration, so the
				// very next token must be the `---` that ends the block.
				if ( TokenType::FrontmatterEnd !== $this->stream->current()?->type ) {
					throw new SyntaxException( 'A `return` statement must be the last frontmatter declaration', $keyword->line );
				}

				$this->frontmatterReturn = $return;
				$this->stream->next();

				break;
			}

			$stmt = $this->expr->parseFrontmatterStatement();
			$this->sandbox->validateStatement( $stmt );
			$declarations[] = $stmt;
		}

		return $declarations;
	}
}
