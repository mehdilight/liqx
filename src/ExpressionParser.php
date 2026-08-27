<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Expr\ArrowFunction;
use Phpmystic\Liqx\Expr\ArrayLit;
use Phpmystic\Liqx\Expr\Binary;
use Phpmystic\Liqx\Expr\Call;
use Phpmystic\Liqx\Expr\Conditional;
use Phpmystic\Liqx\Expr\Filter;
use Phpmystic\Liqx\Expr\Filtered;
use Phpmystic\Liqx\Expr\Identifier;
use Phpmystic\Liqx\Expr\Literal;
use Phpmystic\Liqx\Expr\Logical;
use Phpmystic\Liqx\Expr\Member;
use Phpmystic\Liqx\Expr\ObjectLit;
use Phpmystic\Liqx\Expr\TemplateString;
use Phpmystic\Liqx\Expr\Unary;
use Phpmystic\Liqx\Node;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Output;
use Phpmystic\Liqx\Node\Schema;
use Phpmystic\Liqx\Node\Style;
use Phpmystic\Liqx\Node\Text;

/**
 * Recursive-descent parser over the shared token stream.
 *
 * Produces expression values (`Expr`) for `{ }` interpolations and the
 * frontmatter block, and document nodes (`Node`) for the render body. JSX
 * elements are both: an element is a document node and, when it appears
 * inside an expression, an expression atom that evaluates to its rendered
 * string.
 *
 * JS precedence (highest first): member/call → unary → `* / %` →
 * `+ -` → relational → equality → `&&` → `??` → `||` → conditional →
 * pipe pipeline.
 */
final class ExpressionParser {

	private const LITERALS = [
		'true'      => true,
		'false'     => false,
		'null'      => null,
		'undefined' => null,
	];

	public function __construct(
		private readonly TokenStream $stream,
	) {}

	// ---------------------------------------------------------------------
	// Expressions
	// ---------------------------------------------------------------------

	public function parse(): Expr {
		$save = $this->stream->position();
		$params = $this->tryArrowParams();

		if ( null !== $params ) {
			$this->stream->expect( TokenType::Arrow );
			$body = $this->parse();

			return new ArrowFunction( $params, $body );
		}

		$this->stream->seek( $save );

		$value = $this->parseConditional();

		$filters = [];
		while ( null !== $this->stream->accept( TokenType::Pipe ) ) {
			$token = $this->stream->accept( TokenType::Identifier ) ?? $this->stream->accept( TokenType::Keyword );
			if ( null === $token ) {
				throw new SyntaxException( 'Expected filter name after `|`', $this->stream->current()?->line );
			}

			$args = [];
			if ( null !== $this->stream->accept( TokenType::OpenParen ) ) {
				$args = $this->parseArguments();
				$this->stream->expect( TokenType::CloseParen );
			}

			$filters[] = new Filter( $token->value, $args );
		}

		return [] === $filters ? $value : new Filtered( $value, $filters );
	}

	private function parseConditional(): Expr {
		$test = $this->parseLogicalOr();

		if ( null !== $this->stream->acceptValue( TokenType::Operator, '?' ) ) {
			$consequent = $this->parseAssignmentOrArrow();
			$this->stream->expect( TokenType::Colon );
			$alternate = $this->parseConditional();

			return new Conditional( $test, $consequent, $alternate );
		}

		return $test;
	}

	/** The middle of a ternary (or a nested arrow) — arrows need no parens. */
	private function parseAssignmentOrArrow(): Expr {
		$save = $this->stream->position();
		$params = $this->tryArrowParams();

		if ( null !== $params ) {
			$this->stream->expect( TokenType::Arrow );
			$body = $this->parse();

			return new ArrowFunction( $params, $body );
		}

		$this->stream->seek( $save );

		return $this->parseLogicalOr();
	}

	private function parseLogicalOr(): Expr {
		$left = $this->parseNullish();

		while ( null !== $this->stream->acceptValue( TokenType::Operator, '||' ) ) {
			$left = new Logical( '||', $left, $this->parseNullish() );
		}

		return $left;
	}

	private function parseNullish(): Expr {
		$left = $this->parseLogicalAnd();

		while ( null !== $this->stream->acceptValue( TokenType::Operator, '??' ) ) {
			$left = new Logical( '??', $left, $this->parseLogicalAnd() );
		}

		return $left;
	}

	private function parseLogicalAnd(): Expr {
		$left = $this->parseEquality();

		while ( null !== $this->stream->acceptValue( TokenType::Operator, '&&' ) ) {
			$left = new Logical( '&&', $left, $this->parseEquality() );
		}

		return $left;
	}

	private function parseEquality(): Expr {
		$left = $this->parseRelational();

		while ( true ) {
			$token = $this->stream->current();
			if ( null === $token || TokenType::Operator !== $token->type || ! in_array( $token->value, [ '==', '!=', '===', '!==' ], true ) ) {
				break;
			}
			$this->stream->next();
			$left = new Binary( $token->value, $left, $this->parseRelational() );
		}

		return $left;
	}

	private function parseRelational(): Expr {
		$left = $this->parseAdditive();

		while ( true ) {
			$token = $this->stream->current();
			if ( null === $token || TokenType::Operator !== $token->type || ! in_array( $token->value, [ '<', '>', '<=', '>=' ], true ) ) {
				break;
			}
			$this->stream->next();
			$left = new Binary( $token->value, $left, $this->parseAdditive() );
		}

		return $left;
	}

	private function parseAdditive(): Expr {
		$left = $this->parseMultiplicative();

		while ( true ) {
			$token = $this->stream->current();
			if ( null === $token || TokenType::Operator !== $token->type || ! in_array( $token->value, [ '+', '-' ], true ) ) {
				break;
			}
			$this->stream->next();
			$left = new Binary( $token->value, $left, $this->parseMultiplicative() );
		}

		return $left;
	}

	private function parseMultiplicative(): Expr {
		$left = $this->parseUnary();

		while ( true ) {
			$token = $this->stream->current();
			if ( null === $token || TokenType::Operator !== $token->type || ! in_array( $token->value, [ '*', '/', '%' ], true ) ) {
				break;
			}
			$this->stream->next();
			$left = new Binary( $token->value, $left, $this->parseUnary() );
		}

		return $left;
	}

	private function parseUnary(): Expr {
		$token = $this->stream->current();

		if ( null !== $token && TokenType::Operator === $token->type && in_array( $token->value, [ '!', '-', '+' ], true ) ) {
			$this->stream->next();

			return new Unary( $token->value, $this->parseUnary() );
		}

		return $this->parsePostfix();
	}

	private function parsePostfix(): Expr {
		$expr = $this->parsePrimary();

		while ( true ) {
			if ( null !== $this->stream->accept( TokenType::Dot ) ) {
				$token = $this->stream->accept( TokenType::Identifier ) ?? $this->stream->accept( TokenType::Keyword );
				if ( null === $token ) {
					throw new SyntaxException( 'Expected property name after `.`', $this->stream->current()?->line );
				}

				$member = new Member( $expr, $token->value );

				if ( null !== $this->stream->accept( TokenType::OpenParen ) ) {
					$args = $this->parseArguments();
					$this->stream->expect( TokenType::CloseParen );

					return new Call( $member, $args );
				}

				$expr = $member;

				continue;
			}

			if ( null !== $this->stream->accept( TokenType::OpenBracket ) ) {
				$index = $this->parse();
				$this->stream->expect( TokenType::CloseBracket );
				$expr = new Member( $expr, $index, computed: true );

				continue;
			}

			if ( null !== $this->stream->accept( TokenType::OpenParen ) ) {
				$args = $this->parseArguments();
				$this->stream->expect( TokenType::CloseParen );
				$expr = new Call( $expr, $args );

				continue;
			}

			break;
		}

		return $expr;
	}

	private function parsePrimary(): Expr {
		$token = $this->stream->current();

		if ( null === $token ) {
			throw new SyntaxException( 'Unexpected end of expression' );
		}

		return match ( $token->type ) {
			TokenType::String         => new Literal( $this->stream->next()->value ),
			TokenType::Number         => new Literal( $this->coerceNumber( $this->stream->next()->value ) ),
			TokenType::TemplateString => new TemplateString( $this->stream->next()->value ),
			TokenType::Identifier     => $this->parseIdentifier(),
			TokenType::OpenParen      => $this->parseParenthesized(),
			TokenType::OpenBracket    => $this->parseArray(),
			TokenType::Operator       => $this->parseOperatorPrimary(),
			TokenType::OpenTag        => $this->parseElement(),
			default                   => throw new SyntaxException( 'Invalid expression start: ' . $token->value, $token->line ),
		};
	}

	private function parseIdentifier(): Expr {
		$token = $this->stream->next();

		if ( array_key_exists( $token->value, self::LITERALS ) ) {
			return new Literal( self::LITERALS[ $token->value ] );
		}

		return new Identifier( $token->value );
	}

	private function parseParenthesized(): Expr {
		$this->stream->next(); // OpenParen

		// Empty parens `()` can't be a plain expression.
		$inner = $this->parse();
		$this->stream->expect( TokenType::CloseParen );

		return $inner;
	}

	private function parseArray(): Expr {
		$this->stream->next(); // OpenBracket
		$elements = [];

		while ( null === $this->stream->accept( TokenType::CloseBracket ) ) {
			$elements[] = $this->parse();

			if ( null !== $this->stream->accept( TokenType::Comma ) ) {
				continue;
			}

			$this->stream->expect( TokenType::CloseBracket );

			break;
		}

		return new ArrayLit( $elements );
	}

	private function parseOperatorPrimary(): Expr {
		if ( null !== $this->stream->acceptValue( TokenType::Operator, '{' ) ) {
			return $this->parseObject();
		}

		throw new SyntaxException( 'Invalid expression start', $this->stream->current()?->line );
	}

	private function parseObject(): Expr {
		$properties = [];

		while ( null === $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
			$keyToken = $this->stream->accept( TokenType::Identifier ) ?? $this->stream->accept( TokenType::String );
			if ( null === $keyToken ) {
				throw new SyntaxException( 'Expected object key', $this->stream->current()?->line );
			}

			$this->stream->expect( TokenType::Colon );
			$properties[] = [ $keyToken->value, $this->parse() ];

			if ( null !== $this->stream->accept( TokenType::Comma ) ) {
				continue;
			}

			$this->stream->expectValue( TokenType::Operator, '}' );

			break;
		}

		return new ObjectLit( $properties );
	}

	/** @return list<string>|null */
	private function tryArrowParams(): ?array {
		$save = $this->stream->position();

		if ( null !== $this->stream->accept( TokenType::OpenParen ) ) {
			$params = [];

			if ( null !== $this->stream->accept( TokenType::CloseParen ) ) {
				if ( TokenType::Arrow === $this->stream->current()?->type ) {
					return $params;
				}

				$this->stream->seek( $save );

				return null;
			}

			while ( true ) {
				$token = $this->stream->current();

				if ( null === $token || TokenType::Identifier !== $token->type ) {
					$this->stream->seek( $save );

					return null;
				}

				$params[] = $this->stream->next()->value;

				if ( null !== $this->stream->accept( TokenType::Comma ) ) {
					continue;
				}

				if ( null !== $this->stream->accept( TokenType::CloseParen ) ) {
					break;
				}

				$this->stream->seek( $save );

				return null;
			}

			if ( TokenType::Arrow === $this->stream->current()?->type ) {
				return $params;
			}

			$this->stream->seek( $save );

			return null;
		}

		$token = $this->stream->current();

		if ( null !== $token && TokenType::Identifier === $token->type && TokenType::Arrow === $this->stream->peek()?->type ) {
			return [ $this->stream->next()->value ];
		}

		return null;
	}

	/** @return list<Expr> */
	private function parseArguments(): array {
		$args = [];

		while ( null === $this->stream->accept( TokenType::CloseParen ) ) {
			$args[] = $this->parse();

			if ( null !== $this->stream->accept( TokenType::Comma ) ) {
				continue;
			}

			break;
		}

		return $args;
	}

	private function coerceNumber( string $value ): int|float {
		return str_contains( $value, '.' ) ? (float) $value : (int) $value;
	}

	// ---------------------------------------------------------------------
	// Document body nodes (shared with the document Parser)
	// ---------------------------------------------------------------------

	/** @return list<Node> */
	public function parseChildren( string $tag ): array {
		$children = [];

		while ( true ) {
			$token = $this->stream->current();

			if ( null === $token ) {
				throw SyntaxException::tagNeverClosed( $tag, 0 );
			}

			if ( TokenType::CloseTag === $token->type ) {
				$this->stream->next();
				$name = $this->stream->expect( TokenType::Identifier )->value;
				$this->stream->expect( TokenType::TagEnd );

				if ( $name !== $tag ) {
					throw SyntaxException::mismatchedCloseTag( $name, $token->line );
				}

				return $children;
			}

			$node = $this->parseBodyNode();

			if ( null !== $node ) {
				$children[] = $node;
			}
		}
	}

	public function parseElement(): Element {
		$open  = $this->stream->expect( TokenType::OpenTag );
		$tag   = $this->stream->expect( TokenType::Identifier )->value;
		$line  = $open->line;

		$attrs = [];

		while ( true ) {
			$token = $this->stream->current();

			if ( null === $token ) {
				throw SyntaxException::tagNeverClosed( $tag, $line );
			}

			if ( TokenType::TagEnd === $token->type ) {
				$this->stream->next();

				return new Element( $tag, $attrs, $this->parseChildren( $tag ), selfClosing: false, line: $line );
			}

			if ( TokenType::SelfClose === $token->type ) {
				$this->stream->next();

				return new Element( $tag, $attrs, [], selfClosing: true, line: $line );
			}

			if ( TokenType::ExpressionStart === $token->type ) {
				// `{...expr}` spreads a map; `{expr}` injects a raw attribute
				// string (Liquid's `{{ section.lithos_attributes }}` pattern).
				$this->stream->next();
				$isSpread = null !== $this->stream->acceptValue( TokenType::Operator, '...' );
				$value    = $this->parse();
				$this->stream->expect( TokenType::ExpressionEnd );
				$attrs[] = [ 'name' => null, 'value' => $value, 'spread' => $isSpread ];

				continue;
			}

			if ( TokenType::Identifier === $token->type ) {
				$name  = $this->stream->next()->value;
				$value = null;

				if ( null !== $this->stream->accept( TokenType::AttrEquals ) ) {
					$attrToken = $this->stream->current();

					if ( null !== $attrToken && TokenType::AttrString === $attrToken->type ) {
						$value = new Literal( $this->stream->next()->value );
					} elseif ( null !== $attrToken && TokenType::ExpressionStart === $attrToken->type ) {
						$this->stream->next();
						$value = $this->parse();
						$this->stream->expect( TokenType::ExpressionEnd );
					} else {
						$value = new Literal( true );
					}
				}

				$attrs[] = [ 'name' => $name, 'value' => $value, 'spread' => false ];

				continue;
			}

			throw new SyntaxException( 'Unexpected token in element tag', $token->line );
		}
	}

	public function parseBodyNode(): ?Node {
		$token = $this->stream->current();

		if ( null === $token ) {
			return null;
		}

		return match ( $token->type ) {
			TokenType::Text => new Text( $this->stream->next()->value ),
			TokenType::OpenTag => $this->parseBlockOrElement(),
			TokenType::ExpressionStart => $this->parseOutput(),
			default => null,
		};
	}

	private function parseOutput(): ?Node {
		$start = $this->stream->expect( TokenType::ExpressionStart );

		if ( null !== $this->stream->accept( TokenType::ExpressionEnd ) ) {
			// Empty expression — e.g. `{/* comment */}`.
			return null;
		}

		$expr = $this->parse();
		$this->stream->expect( TokenType::ExpressionEnd );

		return new Output( $expr, $start->line );
	}

	private function parseBlockOrElement(): Node {
		$peek = $this->stream->peek();

		if ( null !== $peek && TokenType::Identifier === $peek->type
			&& in_array( $peek->value, [ 'style', 'schema' ], true ) ) {
			$this->stream->next(); // OpenTag
			$name = $this->stream->next()->value;
			$this->stream->expect( TokenType::TagEnd );

			$block = $this->stream->next();

			if ( null === $block ) {
				throw SyntaxException::tagNeverClosed( $name, $peek->line );
			}

			return 'style' === $name
				? new Style( $block->value )
				: new Schema( $block->value );
		}

		return $this->parseElement();
	}
}
