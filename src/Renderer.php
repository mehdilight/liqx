<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node;
use Phpmystic\Liqx\Node\Document;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\Output;
use Phpmystic\Liqx\Node\Script;
use Phpmystic\Liqx\Node\Style;
use Phpmystic\Liqx\Node\Text;

/**
 * Walks the document AST and emits markup, evaluating expressions through the
 * Evaluator. Frontmatter declarations are evaluated into a scope first so the
 * render body can reference them.
 */
final class Renderer {

	/**
	 * HTML void elements that must not have closing tags.
	 * All other elements written in self-closing JSX style will emit <tag></tag>.
	 *
	 * @var array<string, bool>
	 */
	private const VOID_ELEMENTS = [
		'area'    => true,
		'base'    => true,
		'br'      => true,
		'col'     => true,
		'embed'   => true,
		'hr'      => true,
		'img'     => true,
		'input'   => true,
		'link'    => true,
		'meta'    => true,
		'param'   => true,
		'source'  => true,
		'track'   => true,
		'wbr'     => true,
	];

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
						[ 'found' => $found, 'value' => $resolved ] = $this->evaluator->lookupProperty( $value, $binding['name'] );

						if ( $found ) {
							$ctx->set( $binding['name'], $resolved );

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

		// A final `return { … };` becomes the body's `props`.
		if ( null !== $document->frontmatterReturn ) {
			$ctx->set( 'props', $this->evaluator->evaluate( $document->frontmatterReturn, $ctx ) );
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

		if ( $node instanceof Element ) {
			return $this->renderElement( $node, $ctx );
		}

		if ( $node instanceof Output ) {
			try {
				return $this->renderValue( $this->evaluator->evaluate( $node->expr, $ctx ), $ctx );
			} catch ( LiqxException $e ) {
				$this->stampLine( $e, $node->line );

				throw $e;
			}
		}

		if ( $node instanceof Style ) {
			return $this->renderStyle( $node, $ctx );
		}

		if ( $node instanceof Script ) {
			return $this->renderScript( $node, $ctx );
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
				if ( isset( self::VOID_ELEMENTS[ strtolower( $element->tag ) ] ) ) {
					return '<' . $element->tag . $attributes . ' />';
				}

				return '<' . $element->tag . $attributes . '></' . $element->tag . '>';
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

	public function renderStyle( Style $node, Context $ctx ): string {
		return '<style>' . $this->renderVerbatimParts( $node->parts, $node->body, $ctx ) . '</style>';
	}

	public function renderScript( Script $node, Context $ctx ): string {
		return '<script' . $this->renderAttrs( $node->attrs, $ctx ) . '>' . $this->renderVerbatimParts( $node->parts, $node->body, $ctx ) . '</script>';
	}

	/**
	 * @param list<array{name:string|null, value:Expr|null, spread:bool}> $attrs
	 */
	private function renderAttrs( array $attrs, Context $ctx ): string {
		$out = '';

		foreach ( $attrs as $attr ) {
			if ( null === $attr['value'] ) {
				$out .= ' ' . $attr['name'];

				continue;
			}

			$value = $this->evaluator->evaluate( $attr['value'], $ctx );

			if ( true === $value ) {
				$out .= ' ' . $attr['name'];

				continue;
			}

			if ( false === $value || null === $value ) {
				continue;
			}

			$out .= ' ' . $attr['name'] . '="' . $this->stringify( $value ) . '"';
		}

		return $out;
	}

	/**
	 * @param list<string|Expr> $parts
	 */
	private function renderVerbatimParts( array $parts, string $fallbackBody, Context $ctx ): string {
		if ( [] === $parts ) {
			return $fallbackBody;
		}

		$out = '';

		foreach ( $parts as $part ) {
			if ( is_string( $part ) ) {
				$out .= $part;
			} else {
				$out .= $this->renderValue( $this->evaluator->evaluate( $part, $ctx ), $ctx );
			}
		}

		return $out;
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
