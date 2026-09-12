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
use Phpmystic\Liqx\Node\TemplateBlock;
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

	/**
	 * @param array{tag?: string, attrs?: array<string, mixed>|string}|null $wrapper
	 */
	public function render( Document $document, Context $ctx, ?array $wrapper = null ): string {
		$ctx->push();

		if ( [] !== $document->namedTemplates ) {
			$ctx->set( '__named_templates__', $document->namedTemplates );
		}

		$this->evaluateFrontmatter( $document, $ctx );

		if ( $ctx->get( '__early_return__' ) ) {
			$ctx->pop();

			return '';
		}

		$hasTemplateBlock = false;

		foreach ( $document->body as $node ) {
			if ( $node instanceof TemplateBlock ) {
				$hasTemplateBlock = true;

				break;
			}
		}

		$out = '';

		if ( ! $hasTemplateBlock && null !== $wrapper && ! empty( $wrapper['tag'] ) && 'none' !== $wrapper['tag'] ) {
			$tag   = $wrapper['tag'];
			$attrs = $this->evaluator->formatWrapperAttrs( $wrapper['attrs'] ?? null );
			$out  .= '<' . $tag . $attrs . '>';

			foreach ( $document->body as $node ) {
				$out .= $this->renderNode( $node, $ctx, $wrapper );
			}

			$out .= '</' . $tag . '>';
		} else {
			foreach ( $document->body as $node ) {
				$out .= $this->renderNode( $node, $ctx, $wrapper );
			}
		}

		$ctx->pop();

		return $out;
	}

	/**
	 * Evaluate the document's frontmatter declarations (and a final `return` as
	 * `props`) into the context's current scope. Returns the evaluated
	 * const-name → value map — also the dev/inspection surface for hosts that
	 * want a debug endpoint showing what a template derived from its data.
	 *
	 * @return array<string, mixed>
	 */
	public function evaluateFrontmatter( Document $document, Context $ctx ): array {
		$values = [];

		foreach ( $document->frontmatter as $stmt ) {
			try {
				if ( $stmt instanceof Node\FrontmatterDestructure ) {
					$value = $this->evaluator->evaluate( $stmt->init, $ctx );

					foreach ( $stmt->bindings as $binding ) {
						[ 'found' => $found, 'value' => $resolved ] = $this->evaluator->lookupProperty( $value, $binding['name'] );

						if ( $found ) {
							$ctx->set( $binding['name'], $resolved );
							$values[ $binding['name'] ] = $resolved;

							continue;
						}

						$computed                   = null !== $binding['default'] ? $this->evaluator->evaluate( $binding['default'], $ctx ) : null;
						$ctx->set( $binding['name'], $computed );
						$values[ $binding['name'] ] = $computed;
					}

					continue;
				}

				if ( $stmt instanceof Node\Frontmatter ) {
					$computed                   = $this->evaluator->evaluate( $stmt->expr, $ctx );
					$ctx->set( $stmt->name, $computed );
					$values[ $stmt->name ] = $computed;

					continue;
				}

				$res = $this->evaluator->evaluateStatement( $stmt, $ctx );

				if ( $res instanceof ReturnSignal ) {
					if ( null !== $document->frontmatterReturn && $stmt instanceof Node\FrontmatterReturn && $stmt->expr === $document->frontmatterReturn ) {
						// This is the top-level props return declaration.
						continue;
					}

					$ctx->set( '__early_return__' , true );
					if ( null !== $res->value ) {
						$ctx->set( 'props', $res->value );
					}
					break;
				}
			} catch ( LiqxException $e ) {
				if ( isset( $stmt->line ) ) {
					$this->stampLine( $e, $stmt->line );
				}

				throw $e;
			}
		}

		// A final `return { … };` becomes the body's `props`.
		if ( null !== $document->frontmatterReturn && ! $ctx->get( '__early_return__' ) ) {
			$props                      = $this->evaluator->evaluate( $document->frontmatterReturn, $ctx );
			$values['props']            = $props;
			$ctx->set( 'props', $props );
		}

		return $values;
	}

	/**
	 * @param array{tag?: string, attrs?: array<string, mixed>|string}|null $wrapper
	 */
	public function renderNode( Node $node, Context $ctx, ?array $wrapper = null ): string {
		if ( $node instanceof TemplateBlock ) {
			$inner = '';

			foreach ( $node->children as $child ) {
				$inner .= $this->renderNode( $child, $ctx, $wrapper );
			}

			if ( null !== $wrapper && ! empty( $wrapper['tag'] ) && 'none' !== $wrapper['tag'] ) {
				$tag   = $wrapper['tag'];
				$attrs = $this->evaluator->formatWrapperAttrs( $wrapper['attrs'] ?? null );

				return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
			}

			return $inner;
		}

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
		try {
			if ( '' !== $element->tag ) {
				$tagLower = strtolower( $element->tag );
				if ( 'if' === $tagLower ) {
					return $this->renderIfElement( $element, $ctx );
				}
				if ( 'show' === $tagLower ) {
					return $this->renderShowElement( $element, $ctx );
				}
				if ( 'switch' === $tagLower ) {
					return $this->renderSwitchElement( $element, $ctx );
				}
				if ( ctype_upper( $element->tag[0] ) ) {
					return $this->renderComponent( $element, $ctx );
				}
			}

			if ( 'slot' === strtolower( $element->tag ) ) {
				return $this->renderSlotElement( $element, $ctx );
			}

			$attributes = '';
			$classBase = null;
			$classModifiers = [];
			$hasModifiers = false;

			foreach ( $element->attrs as $attr ) {
				$name = $attr['name'];
				if ( null !== $name && str_starts_with( $name, 'class:' ) ) {
					$hasModifiers = true;
					$modifier = substr( $name, 6 );
					$val = null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
					$classModifiers[ $modifier ] = $val;
				} elseif ( 'class' === $name ) {
					$classBase = null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
				}
			}

			$classHandled = false;

			foreach ( $element->attrs as $attr ) {
				$name = $attr['name'];

				if ( null !== $name && ( 'class' === $name || str_starts_with( $name, 'class:' ) ) ) {
					if ( $classHandled ) {
						continue;
					}
					$classHandled = true;

					if ( $hasModifiers ) {
						$resolvedClass = $this->evaluator->resolveClass( $classBase, $classModifiers );
						if ( null !== $resolvedClass ) {
							$attributes .= ' class="' . htmlspecialchars( $resolvedClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
						}
						continue;
					}

					if ( null === $attr['value'] ) {
						$attributes .= ' class';
						continue;
					}
					$v = $classBase;
					if ( true === $v ) {
						$attributes .= ' class';
						continue;
					}
					if ( false === $v || null === $v ) {
						continue;
					}
					$attributes .= ' class="' . $this->stringify( $v ) . '"';
					continue;
				}

				if ( null === $name ) {
					// `{...expr}` (spread) or raw attribute-string injection.
					$value = $this->evaluator->evaluate( $attr['value'], $ctx );

					if ( $attr['spread'] ) {
						if ( is_array( $value ) ) {
							foreach ( $value as $n => $v ) {
								if ( 'key' === $n ) {
									continue;
								}

								$attributes .= ' ' . $n . '="' . $this->stringify( $v ) . '"';
							}
						}

						continue;
					}

					$attributes .= $this->stringify( $value );

					continue;
				}

				if ( 'key' === $name ) {
					continue;
				}

				$value = $attr['value'];

				if ( null === $value ) {
					$attributes .= ' ' . $name;

					continue;
				}

				$v = $this->evaluator->evaluate( $value, $ctx );

				if ( true === $v ) {
					$attributes .= ' ' . $name;

					continue;
				}

				if ( false === $v || null === $v ) {
					continue;
				}

				$attributes .= ' ' . $name . '="' . $this->stringify( $v ) . '"';
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

	public function renderComponent( Element $element, Context $ctx ): string {
		$props = [];
		$classBase = null;
		$hasClassBase = false;
		$classModifiers = [];

		foreach ( $element->attrs as $attr ) {
			if ( null === $attr['name'] ) {
				if ( $attr['spread'] ) {
					$value = $this->evaluator->evaluate( $attr['value'], $ctx );
					$props = $this->evaluator->mergeProps( $props, $value );
				}
				continue;
			}

			if ( 'key' === $attr['name'] ) {
				continue;
			}

			if ( str_starts_with( $attr['name'], 'class:' ) ) {
				$modifier = substr( $attr['name'], 6 );
				$classModifiers[ $modifier ] = null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
				continue;
			}

			if ( 'class' === $attr['name'] ) {
				$hasClassBase = true;
				$classBase = null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
				continue;
			}

			$props[ $attr['name'] ] = null === $attr['value']
				? true
				: $this->evaluator->evaluate( $attr['value'], $ctx );
		}

		if ( $hasClassBase || [] !== $classModifiers ) {
			if ( ! $hasClassBase && isset( $props['class'] ) ) {
				$classBase = $props['class'];
			}
			$resolvedClass = $this->evaluator->resolveClass( $classBase, $classModifiers );
			if ( null !== $resolvedClass ) {
				$props['class'] = $resolvedClass;
			}
		}

		$slots           = [];
		$childrenNodes   = [];
		$defaultChildren = '';

		if ( ! $element->selfClosing ) {
			foreach ( $element->children as $child ) {
				if ( $child instanceof Element && 'template' === strtolower( $child->tag ) ) {
					$slotName = null;
					foreach ( $child->attrs as $a ) {
						if ( 'slot' === $a['name'] ) {
							if ( null === $a['value'] ) {
								$slotName = 'default';
							} else {
								$slotName = (string) $this->evaluator->evaluate( $a['value'], $ctx );
							}
							break;
						}
					}

					if ( null !== $slotName ) {
						if ( [] !== $childrenNodes ) {
							$lastIdx = count( $childrenNodes ) - 1;
							if ( $childrenNodes[ $lastIdx ] instanceof Text && '' === trim( $childrenNodes[ $lastIdx ]->value ) ) {
								array_pop( $childrenNodes );
							}
						}

						$slotContent = '';
						foreach ( $child->children as $slotChild ) {
							$slotContent .= $this->renderNode( $slotChild, $ctx );
						}
						$slots[ $slotName ] = $slotContent;
						continue;
					}
				}

				$childrenNodes[] = $child;
			}
		}

		$defaultChildren = '';
		foreach ( $childrenNodes as $cNode ) {
			$defaultChildren .= $this->renderNode( $cNode, $ctx );
		}

		$props['children'] = $element->selfClosing ? null : $defaultChildren;
		$props['slots']    = $slots;

		$namedTemplates = $ctx->get( '__named_templates__' );
		if ( is_array( $namedTemplates ) ) {
			foreach ( $namedTemplates as $tName => $tBlock ) {
				if ( 0 === strcasecmp( $tName, $element->tag ) && $tBlock instanceof TemplateBlock ) {
					$ctx->push( $props );
					$ctx->set( 'props', $props );
					try {
						$out = '';
						foreach ( $tBlock->children as $child ) {
							$out .= $this->renderNode( $child, $ctx );
						}

						return $out;
					} finally {
						$ctx->pop();
					}
				}
			}
		}

		return $ctx->environment->renderSnippet( $element->tag, $props );
	}

	public function renderSlotElement( Element $element, Context $ctx ): string {
		$slotName = null;

		foreach ( $element->attrs as $attr ) {
			if ( 'name' === $attr['name'] ) {
				$slotName = null === $attr['value'] ? '' : (string) $this->evaluator->evaluate( $attr['value'], $ctx );
				break;
			}
		}

		$fallback = '';
		foreach ( $element->children as $child ) {
			$fallback .= $this->renderNode( $child, $ctx );
		}

		return $this->evaluator->renderSlot( $ctx, $slotName, $fallback );
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

	public function renderIfElement( Element $element, Context $ctx ): string {
		$cond = $this->evalConditionAttr( $element, $ctx );
		if ( $this->evaluator->truthy( $cond ) ) {
			$out = '';
			foreach ( $element->children as $child ) {
				if ( $child instanceof Element ) {
					$childTag = strtolower( $child->tag );
					if ( 'elseif' === $childTag || 'else' === $childTag ) {
						break;
					}
				}
				$out .= $this->renderNode( $child, $ctx );
			}
			return $out;
		}

		// Main If was falsy, search ElseIf and Else branches
		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$childTag = strtolower( $child->tag );
				if ( 'elseif' === $childTag ) {
					$elseIfCond = $this->evalConditionAttr( $child, $ctx );
					if ( $this->evaluator->truthy( $elseIfCond ) ) {
						$out = '';
						foreach ( $child->children as $c ) {
							$out .= $this->renderNode( $c, $ctx );
						}
						return $out;
					}
				} elseif ( 'else' === $childTag ) {
					$out = '';
					foreach ( $child->children as $c ) {
						$out .= $this->renderNode( $c, $ctx );
					}
					return $out;
				}
			}
		}

		return '';
	}

	public function renderShowElement( Element $element, Context $ctx ): string {
		$when = $this->evalConditionAttr( $element, $ctx, [ 'when', 'condition', 'cond', 'is' ] );
		$fallbackAttrExpr = null;
		foreach ( $element->attrs as $attr ) {
			if ( 'fallback' === $attr['name'] ) {
				$fallbackAttrExpr = $attr['value'];
				break;
			}
		}

		$mainChildren = [];
		$fallbackSlotChildren = null;

		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$tagLower = strtolower( $child->tag );
				if ( 'template' === $tagLower ) {
					$isFallbackSlot = false;
					foreach ( $child->attrs as $a ) {
						if ( 'slot' === $a['name'] && 'fallback' === ( null === $a['value'] ? '' : (string) $this->evaluator->evaluate( $a['value'], $ctx ) ) ) {
							$isFallbackSlot = true;
							break;
						}
					}
					if ( $isFallbackSlot ) {
						$fallbackSlotChildren = $child->children;
						continue;
					}
				} elseif ( 'fallback' === $tagLower ) {
					$fallbackSlotChildren = $child->children;
					continue;
				}
			}
			$mainChildren[] = $child;
		}

		if ( $this->evaluator->truthy( $when ) ) {
			$out = '';
			foreach ( $mainChildren as $c ) {
				$out .= $this->renderNode( $c, $ctx );
			}
			return $out;
		}

		if ( null !== $fallbackSlotChildren ) {
			$out = '';
			foreach ( $fallbackSlotChildren as $c ) {
				$out .= $this->renderNode( $c, $ctx );
			}
			return $out;
		}

		if ( null !== $fallbackAttrExpr ) {
			return $this->renderValue( $this->evaluator->evaluate( $fallbackAttrExpr, $ctx ), $ctx );
		}

		return '';
	}

	public function renderSwitchElement( Element $element, Context $ctx ): string {
		$hasTarget = false;
		$targetVal = null;
		foreach ( $element->attrs as $attr ) {
			if ( 'value' === $attr['name'] || 'val' === $attr['name'] ) {
				$hasTarget = true;
				$targetVal = null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
				break;
			}
		}

		$defaultChildren = null;

		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$tagLower = strtolower( $child->tag );
				if ( 'match' === $tagLower ) {
					$isDefault = false;
					$matchVal = null;
					$hasMatchVal = false;
					foreach ( $child->attrs as $a ) {
						if ( 'default' === $a['name'] ) {
							$isDefault = true;
							break;
						}
						if ( 'when' === $a['name'] || 'value' === $a['name'] || 'val' === $a['name'] || 'is' === $a['name'] ) {
							$hasMatchVal = true;
							$matchVal = null === $a['value'] ? true : $this->evaluator->evaluate( $a['value'], $ctx );
							break;
						}
					}

					if ( $isDefault ) {
						$defaultChildren = $child->children;
						continue;
					}

					if ( $hasTarget ) {
						if ( $hasMatchVal && $this->evaluator->looseEqual( $targetVal, $matchVal ) ) {
							$out = '';
							foreach ( $child->children as $c ) {
								$out .= $this->renderNode( $c, $ctx );
							}
							return $out;
						}
					} else {
						if ( $hasMatchVal && $this->evaluator->truthy( $matchVal ) ) {
							$out = '';
							foreach ( $child->children as $c ) {
								$out .= $this->renderNode( $c, $ctx );
							}
							return $out;
						}
					}
				} elseif ( 'default' === $tagLower ) {
					$defaultChildren = $child->children;
				}
			}
		}

		if ( null !== $defaultChildren ) {
			$out = '';
			foreach ( $defaultChildren as $c ) {
				$out .= $this->renderNode( $c, $ctx );
			}
			return $out;
		}

		return '';
	}

	/** @param list<string> $candidates */
	private function evalConditionAttr( Element $element, Context $ctx, array $candidates = [ 'condition', 'cond', 'when', 'is' ] ): mixed {
		foreach ( $element->attrs as $attr ) {
			if ( null !== $attr['name'] && in_array( strtolower( $attr['name'] ), $candidates, true ) ) {
				return null === $attr['value'] ? true : $this->evaluator->evaluate( $attr['value'], $ctx );
			}
		}
		if ( isset( $element->attrs[0] ) && null === $element->attrs[0]['name'] && ! $element->attrs[0]['spread'] ) {
			return $this->evaluator->evaluate( $element->attrs[0]['value'], $ctx );
		}
		return false;
	}
}
