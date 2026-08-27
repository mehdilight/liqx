<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node;
use Phpmystic\Liqx\Node\Document;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\Output;
use Phpmystic\Liqx\Node\Style;
use Phpmystic\Liqx\Node\Text;

/**
 * Walks the document AST and emits markup, evaluating expressions through the
 * Evaluator. Frontmatter declarations are evaluated into a scope first so the
 * render body can reference them.
 */
final class Renderer {

	private Evaluator $evaluator;

	public function __construct() {
		$this->evaluator = new Evaluator( $this );
	}

	public function render( Document $document, Context $ctx ): string {
		$out = '';

		$ctx->push();

		foreach ( $document->frontmatter as $declaration ) {
			try {
				if ( $declaration instanceof FrontmatterDestructure ) {
					$value = $this->evaluator->evaluate( $declaration->init, $ctx );

					foreach ( $declaration->bindings as $binding ) {
						if ( is_array( $value ) && array_key_exists( $binding['name'], $value ) ) {
							$ctx->set( $binding['name'], $value[ $binding['name'] ] );

							continue;
						}

						$ctx->set(
							$binding['name'],
							null !== $binding['default'] ? $this->evaluator->evaluate( $binding['default'], $ctx ) : null
						);
					}

					continue;
				}

				$ctx->set( $declaration->name, $this->evaluator->evaluate( $declaration->expr, $ctx ) );
			} catch ( LiqxException $e ) {
				$this->stampLine( $e, $declaration->line );

				throw $e;
			}
		}

		foreach ( $document->body as $node ) {
			$out .= $this->renderNode( $node, $ctx );
		}

		$ctx->pop();

		return $out;
	}

	public function renderNode( Node $node, Context $ctx ): string {
		if ( $node instanceof Text ) {
			return $node->value;
		}

		if ( $node instanceof Style ) {
			return $this->renderStyle( $node, $ctx );
		}

		if ( $node instanceof Output ) {
			try {
				return $this->renderValue( $this->evaluator->evaluate( $node->expr, $ctx ), $ctx );
			} catch ( LiqxException $e ) {
				$this->stampLine( $e, $node->line );

				throw $e;
			}
		}

		if ( $node instanceof Element ) {
			return $this->renderElement( $node, $ctx );
		}

		return '';
	}

	public function renderElement( Element $element, Context $ctx ): string {
		$attributes = '';

		try {
			foreach ( $element->attrs as $attr ) {
				if ( null === $attr['name'] ) {
					// `{...expr}` (spread) or raw attribute-string injection.
					$value = $this->evaluator->evaluate( $attr['value'], $ctx );

					if ( $attr['spread'] ) {
						if ( is_array( $value ) ) {
							foreach ( $value as $name => $v ) {
								if ( 'key' === $name ) {
									continue;
								}

								$attributes .= ' ' . $name . '="' . $this->stringify( $v ) . '"';
							}
						}

						continue;
					}

					$attributes .= $this->stringify( $value );

					continue;
				}

				if ( 'key' === $attr['name'] ) {
					continue;
				}

				$value = $attr['value'];

				if ( null === $value ) {
					$attributes .= ' ' . $attr['name'];

					continue;
				}

				$v = $this->evaluator->evaluate( $value, $ctx );

				if ( true === $v ) {
					$attributes .= ' ' . $attr['name'];

					continue;
				}

				if ( false === $v || null === $v ) {
					continue;
				}

				$attributes .= ' ' . $attr['name'] . '="' . $this->stringify( $v ) . '"';
			}

			if ( $element->selfClosing ) {
				return '<' . $element->tag . $attributes . ' />';
			}

			$children = '';

			foreach ( $element->children as $child ) {
				$children .= $this->renderNode( $child, $ctx );
			}

			return '<' . $element->tag . $attributes . '>' . $children . '</' . $element->tag . '>';
		} catch ( LiqxException $e ) {
			$this->stampLine( $e, $element->line );

			throw $e;
		}
	}

	public function renderValue( mixed $value, Context $ctx ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( is_array( $value ) ) {
			$out = '';
			foreach ( $value as $item ) {
				$out .= $this->renderValue( $item, $ctx );
			}

			return $out;
		}

		if ( is_object( $value ) && ! method_exists( $value, '__toString' ) ) {
			return '';
		}

		return (string) $value;
	}

	private function stampLine( LiqxException $e, int $line ): void {
		if ( null === $e->lineNumber ) {
			$e->lineNumber = $line;
		}
	}

	private function renderStyle( Style $style, Context $ctx ): string {
		return $this->interpolateStyle( $style->body, $ctx );
	}

	/**
	 * Interpolate `{…}` in a style body. A brace group whose content has no
	 * top-level `:` or `;` is an expression and is evaluated; otherwise it is
	 * CSS syntax (`{ background: … }`), kept verbatim but scanned recursively
	 * so nested `{expr}` interpolations still apply.
	 */
	private function interpolateStyle( string $body, Context $ctx ): string {
		$out = '';
		$i   = 0;
		$len = strlen( $body );

		while ( $i < $len ) {
			$start = strpos( $body, '{', $i );

			if ( false === $start ) {
				$out .= substr( $body, $i, $len - $i );

				break;
			}

			$out .= substr( $body, $i, $start - $i );

			[ $end, $inner ] = $this->matchingBrace( $body, $start );

			if ( $this->isExpression( $inner ) ) {
				$out .= $this->renderValue( $this->evaluator->evaluateString( $inner, $ctx ), $ctx );
			} else {
				$out .= '{' . $this->interpolateStyle( $inner, $ctx ) . '}';
			}

			$i = $end;
		}

		return $out;
	}

	/** @return array{0:int, 1:string} matching-brace index and inner content */
	private function matchingBrace( string $body, int $start ): array {
		$depth = 1;
		$j     = $start + 1;
		$len   = strlen( $body );

		while ( $j < $len && $depth > 0 ) {
			if ( '{' === $body[ $j ] ) {
				$depth++;
			} elseif ( '}' === $body[ $j ] ) {
				$depth--;
			}

			$j++;
		}

		return [ $j, substr( $body, $start + 1, $j - $start - 2 ) ];
	}

	/**
	 * A `{…}` in a `<style>` block is an interpolation only when its content is
	 * a plausible expression — i.e. it has no top-level `:` or `;`, which would
	 * mark it as a CSS declaration/block instead.
	 */
	private function isExpression( string $content ): bool {
		$content = trim( $content );

		if ( '' === $content ) {
			return false;
		}

		$depth = 0;

		for ( $i = 0, $len = strlen( $content ); $i < $len; $i++ ) {
			$char = $content[ $i ];

			if ( in_array( $char, [ '(', '[', '{' ], true ) ) {
				$depth++;
			} elseif ( in_array( $char, [ ')', ']', '}' ], true ) ) {
				$depth--;
			} elseif ( 0 === $depth && ( ':' === $char || ';' === $char ) ) {
				return false;
			}
		}

		return true;
	}

	private function stringify( mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( is_array( $value ) ) {
			return implode( ' ', array_map( fn ( $v ) => $this->stringify( $v ), $value ) );
		}

		return (string) $value;
	}
}
