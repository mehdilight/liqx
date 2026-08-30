<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Expr\ArrowFunction;
use Phpmystic\Liqx\Expr\ArrayLit;
use Phpmystic\Liqx\Expr\Binary;
use Phpmystic\Liqx\Expr\BlockBody;
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
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterAssignment;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\FrontmatterFunction;
use Phpmystic\Liqx\Node\FrontmatterIf;
use Phpmystic\Liqx\Node\FrontmatterReturn;
use Phpmystic\Liqx\Node\FrontmatterSwitch;
use Phpmystic\Liqx\Node\Output;
use Phpmystic\Liqx\Node\Schema;
use Phpmystic\Liqx\Node\Script;
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

	/**
	 * Binary/logical operator precedence, lowest binds loosest. One
	 * precedence-climbing loop ({@see parseBinary}) replaces a seven-deep ladder
	 * of near-identical methods — same tree, far fewer call frames per
	 * expression.
	 */
	private const BINARY_PRECEDENCE = [
		'||'  => 1,
		'??'  => 2,
		'&&'  => 3,
		'=='  => 4,
		'!='  => 4,
		'===' => 4,
		'!==' => 4,
		'<'   => 5,
		'>'   => 5,
		'<='  => 5,
		'>='  => 5,
		'+'   => 6,
		'-'   => 6,
		'*'   => 7,
		'/'   => 7,
		'%'   => 7,
	];

	/** @var array<string, true> operators that build a {@see Logical} node. */
	private const LOGICAL_OPERATORS = [ '||' => true, '&&' => true, '??' => true ];

	public function __construct(
		private readonly TokenStream $stream,
	) {}

	// ---------------------------------------------------------------------
	// Expressions
	// ---------------------------------------------------------------------

	public function parse(): Expr {
		// An arrow starts only with `(` (param list) or a bare `identifier =>`.
		// Skip the speculative param-parse + backtrack for anything else.
		if ( $this->couldStartArrow() ) {
			$save   = $this->stream->position();
			$params = $this->tryArrowParams();

			if ( null !== $params ) {
				$this->stream->expect( TokenType::Arrow );

				return new ArrowFunction( $params, $this->parseArrowBody() );
			}

			$this->stream->seek( $save );
		}

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

	/** The arrow body: `{ const …; return …; }` or a plain expression. */
	private function parseArrowBody(): Expr {
		if ( null !== $this->stream->acceptValue( TokenType::Operator, '{' ) ) {
			return $this->parseBlockBody();
		}

		return $this->parse();
	}

	/**
	 * `{ const a = …; if (c) return …; return …; }` — statements run in the
	 * arrow's scope, the trailing `return` is its value.
	 */
	private function parseBlockBody(): BlockBody {
		$declarations = [];
		$return       = null;

		while ( null === $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
			$token = $this->stream->current();

			if ( null === $token ) {
				throw new SyntaxException( 'Unterminated block body' );
			}

			$stmt = $this->parseFrontmatterStatement();

			if ( $stmt instanceof FrontmatterReturn ) {
				$return = $stmt->expr;
				$declarations[] = $stmt;
				if ( null !== $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
					break;
				}
				continue;
			}

			$declarations[] = $stmt;
		}

		return new BlockBody( $declarations, $return );
	}

	/**
	 * Parse a frontmatter statement: `const/let/var`, `if`, `switch`, `function`, `return`, assignment, etc.
	 */
	public function parseFrontmatterStatement(): object {
		$token = $this->stream->current();
		if ( null === $token ) {
			throw new SyntaxException( 'Unexpected end of frontmatter' );
		}

		if ( TokenType::Keyword === $token->type ) {
			if ( in_array( $token->value, [ 'const', 'let', 'var' ], true ) ) {
				return $this->parseDeclaration();
			}

			if ( 'if' === $token->value ) {
				return $this->parseIfStatement();
			}

			if ( 'switch' === $token->value ) {
				return $this->parseSwitchStatement();
			}

			if ( 'function' === $token->value ) {
				return $this->parseFunctionStatement();
			}

			if ( 'return' === $token->value ) {
				$this->stream->next();
				$line = $token->line;
				$expr = null;

				if ( null === $this->stream->accept( TokenType::Semicolon ) ) {
					$next = $this->stream->current();
					if ( null !== $next && TokenType::Operator !== $next->type && '}' !== $next->value && TokenType::FrontmatterEnd !== $next->type ) {
						$expr = $this->parse();
					}
					$this->stream->accept( TokenType::Semicolon );
				}

				return new FrontmatterReturn( $expr, $line );
			}

			if ( 'break' === $token->value ) {
				$this->stream->next();
				$this->stream->accept( TokenType::Semicolon );

				return new FrontmatterReturn( null, $token->line );
			}
		}

		// Check for assignment: identifier = expr; or identifier += expr;
		if ( TokenType::Identifier === $token->type ) {
			$save = $this->stream->position();
			$name = $this->stream->next()->value;
			$next = $this->stream->current();

			if ( null !== $next && TokenType::Operator === $next->type && in_array( $next->value, [ '=', '+=', '-=', '*=', '/=', '%=' ], true ) ) {
				$op = $this->stream->next()->value;
				$expr = $this->parse();
				$this->stream->accept( TokenType::Semicolon );

				return new FrontmatterAssignment( $name, $op, $expr, $token->line );
			}

			$this->stream->seek( $save );
		}

		// Fallback: standalone expression
		$expr = $this->parse();
		$this->stream->accept( TokenType::Semicolon );

		return $expr;
	}

	public function parseIfStatement(): FrontmatterIf {
		$keyword = $this->stream->expect( TokenType::Keyword );
		$line = $keyword->line;
		$this->stream->expect( TokenType::OpenParen );
		$test = $this->parse();
		$this->stream->expect( TokenType::CloseParen );
		$then = $this->parseBlockOrStatement();

		$elseIfs = [];
		$else = [];

		while ( null !== $this->acceptKeyword( 'else' ) ) {
			if ( null !== $this->acceptKeyword( 'if' ) ) {
				$this->stream->expect( TokenType::OpenParen );
				$elseIfTest = $this->parse();
				$this->stream->expect( TokenType::CloseParen );
				$elseIfBody = $this->parseBlockOrStatement();
				$elseIfs[] = [ 'test' => $elseIfTest, 'body' => $elseIfBody ];
			} else {
				$else = $this->parseBlockOrStatement();
				break;
			}
		}

		return new FrontmatterIf( $test, $then, $elseIfs, $else, $line );
	}

	public function parseSwitchStatement(): FrontmatterSwitch {
		$keyword = $this->stream->expect( TokenType::Keyword );
		$line = $keyword->line;
		$this->stream->expect( TokenType::OpenParen );
		$discriminant = $this->parse();
		$this->stream->expect( TokenType::CloseParen );
		$this->stream->expectValue( TokenType::Operator, '{' );

		$cases = [];

		while ( null === $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
			if ( $this->stream->eof() ) {
				throw new SyntaxException( 'Unterminated switch statement', $line );
			}

			if ( null !== $this->acceptKeyword( 'case' ) ) {
				$caseTest = $this->parse();
				$this->stream->expect( TokenType::Colon );
				$caseBody = $this->parseCaseStatements();
				$cases[] = [ 'test' => $caseTest, 'body' => $caseBody ];
			} elseif ( null !== $this->acceptKeyword( 'default' ) ) {
				$this->stream->expect( TokenType::Colon );
				$defaultBody = $this->parseCaseStatements();
				$cases[] = [ 'test' => null, 'body' => $defaultBody ];
			} else {
				throw new SyntaxException( 'Expected `case` or `default` inside switch block', $this->stream->current()?->line );
			}
		}

		return new FrontmatterSwitch( $discriminant, $cases, $line );
	}

	private function parseCaseStatements(): array {
		$stmts = [];
		while ( ! $this->stream->eof() ) {
			$token = $this->stream->current();
			if ( null === $token ) {
				break;
			}

			if ( TokenType::Operator === $token->type && '}' === $token->value ) {
				break;
			}

			if ( TokenType::Keyword === $token->type && in_array( $token->value, [ 'case', 'default' ], true ) ) {
				break;
			}

			if ( TokenType::Keyword === $token->type && 'break' === $token->value ) {
				$this->stream->next();
				$this->stream->accept( TokenType::Semicolon );
				continue;
			}

			$stmts[] = $this->parseFrontmatterStatement();
		}

		return $stmts;
	}

	public function parseFunctionStatement(): FrontmatterFunction {
		$keyword = $this->stream->expect( TokenType::Keyword );
		$line = $keyword->line;
		$name = $this->stream->expect( TokenType::Identifier )->value;
		$this->stream->expect( TokenType::OpenParen );

		$params = [];
		while ( null === $this->stream->accept( TokenType::CloseParen ) ) {
			$paramName = $this->stream->expect( TokenType::Identifier )->value;
			$default = null;
			if ( null !== $this->stream->acceptValue( TokenType::Operator, '=' ) ) {
				$default = $this->parse();
			}
			$params[] = [ 'name' => $paramName, 'default' => $default ];
			if ( null !== $this->stream->accept( TokenType::Comma ) ) {
				continue;
			}
			$this->stream->expect( TokenType::CloseParen );
			break;
		}

		$body = $this->parseBlockOrStatement();

		return new FrontmatterFunction( $name, $params, $body, null, $line );
	}

	private function parseBlockOrStatement(): array {
		if ( null !== $this->stream->acceptValue( TokenType::Operator, '{' ) ) {
			$stmts = [];
			while ( null === $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
				if ( $this->stream->eof() ) {
					throw new SyntaxException( 'Unterminated block `{ ... }`' );
				}
				$stmts[] = $this->parseFrontmatterStatement();
			}
			return $stmts;
		}

		return [ $this->parseFrontmatterStatement() ];
	}

	private function acceptKeyword( string $name ): ?Token {
		$token = $this->stream->current();
		if ( null !== $token && TokenType::Keyword === $token->type && $token->value === $name ) {
			$this->stream->next();
			return $token;
		}
		return null;
	}

	/**
	 * A `const`/`let`/`var` declaration — shared by the frontmatter and arrow block
	 * bodies. Returns the single binding or the destructuring form.
	 */
	public function parseDeclaration(): Frontmatter|FrontmatterDestructure {
		$keyword = $this->stream->accept( TokenType::Keyword );

		if ( null === $keyword || ! in_array( $keyword->value, [ 'const', 'let', 'var' ], true ) ) {
			throw new SyntaxException( 'Expected `const`/`let`/`var` declaration', $this->stream->current()?->line );
		}

		$line = $keyword->line;

		if ( null !== $this->stream->acceptValue( TokenType::Operator, '{' ) ) {
			$bindings = [];

			while ( null === $this->stream->acceptValue( TokenType::Operator, '}' ) ) {
				$name    = $this->stream->expect( TokenType::Identifier )->value;
				$default = null;

				if ( null !== $this->stream->acceptValue( TokenType::Operator, '=' ) ) {
					$default = $this->parse();
				}

				$bindings[] = [ 'name' => $name, 'default' => $default ];

				if ( null !== $this->stream->accept( TokenType::Comma ) ) {
					continue;
				}

				$this->stream->expectValue( TokenType::Operator, '}' );

				break;
			}

			$this->stream->expectValue( TokenType::Operator, '=' );
			$init = $this->parse();
			$this->stream->accept( TokenType::Semicolon );

			return new FrontmatterDestructure( $bindings, $init, $line );
		}

		$name = $this->stream->expect( TokenType::Identifier )->value;

		if ( null !== $this->stream->acceptValue( TokenType::Operator, '=' ) ) {
			$expr = $this->parse();
		} else {
			$expr = new Literal( null );
		}

		$this->stream->accept( TokenType::Semicolon );

		return new Frontmatter( $name, $expr, $line );
	}

	private function parseConditional(): Expr {
		$test = $this->parseBinary( 1 );

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
		if ( $this->couldStartArrow() ) {
			$save   = $this->stream->position();
			$params = $this->tryArrowParams();

			if ( null !== $params ) {
				$this->stream->expect( TokenType::Arrow );

				return new ArrowFunction( $params, $this->parseArrowBody() );
			}

			$this->stream->seek( $save );
		}

		return $this->parseBinary( 1 );
	}

	/** Cheap gate before the speculative arrow-param parse + backtrack. */
	private function couldStartArrow(): bool {
		$token = $this->stream->current();

		if ( null === $token ) {
			return false;
		}

		if ( TokenType::OpenParen === $token->type ) {
			return true;
		}

		return TokenType::Identifier === $token->type
			&& TokenType::Arrow === $this->stream->peek()?->type;
	}

	/**
	 * Precedence-climbing binary/logical parse. `$minPrec` is the lowest
	 * precedence this call may consume; operators are left-associative, so the
	 * right operand recurses at `prec + 1`.
	 */
	private function parseBinary( int $minPrec ): Expr {
		$left = $this->parseUnary();

		while ( true ) {
			$token = $this->stream->current();

			if ( null === $token || TokenType::Operator !== $token->type ) {
				break;
			}

			$prec = self::BINARY_PRECEDENCE[ $token->value ] ?? 0;

			if ( $prec < $minPrec ) {
				break;
			}

			$this->stream->next();
			$right = $this->parseBinary( $prec + 1 );

			$left = isset( self::LOGICAL_OPERATORS[ $token->value ] )
				? new Logical( $token->value, $left, $right )
				: new Binary( $token->value, $left, $right );
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
					$expr = new Call( $member, $args );

					continue;
				}

				$expr = $member;

				continue;
			}

			if ( null !== $this->stream->accept( TokenType::NullSafe ) ) {
				if ( null !== $this->stream->accept( TokenType::OpenBracket ) ) {
					$index = $this->parse();
					$this->stream->expect( TokenType::CloseBracket );
					$expr = new Member( $expr, $index, computed: true, nullSafe: true );

					continue;
				}

				$token = $this->stream->accept( TokenType::Identifier ) ?? $this->stream->accept( TokenType::Keyword );
				if ( null === $token ) {
					throw new SyntaxException( 'Expected property name after `?.`', $this->stream->current()?->line );
				}

				$expr = new Member( $expr, $token->value, nullSafe: true );

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
			TokenType::TemplateString => $this->parseTemplateString(),
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

	private function parseTemplateString(): TemplateString {
		$raw   = $this->stream->next()->value;
		$parts = $this->parseTemplateParts( $raw );

		return new TemplateString( $raw, $parts );
	}

	/**
	 * @return list<string|Expr>
	 */
	private function parseTemplateParts( string $raw ): array {
		$content = substr( $raw, 1, -1 ); // strip surrounding backticks
		$parts   = [];
		$len     = strlen( $content );
		$i       = 0;

		while ( $i < $len ) {
			$start = strpos( $content, '${', $i );

			if ( false === $start ) {
				$parts[] = substr( $content, $i, $len - $i );

				break;
			}

			if ( $start > $i ) {
				$parts[] = substr( $content, $i, $start - $i );
			}

			$depth = 1;
			$j     = $start + 2;

			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $content[ $j ] ) {
					$depth++;
				} elseif ( '}' === $content[ $j ] ) {
					$depth--;
				}

				$j++;
			}

			$inner   = substr( $content, $start + 2, $j - $start - 3 );
			$stream  = ( new Lexer() )->tokenize( '{' . $inner . '}' );
			$stream->next(); // ExpressionStart
			$parts[] = ( new self( $stream ) )->parse();

			$i = $j;
		}

		return $parts;
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
			$keyToken = $this->stream->accept( TokenType::Identifier )
				?? $this->stream->accept( TokenType::String )
				?? $this->stream->accept( TokenType::Keyword );

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

	/**
	 * @return list<Expr>
	 */
	private function parseArguments(): array {
		$args = [];

		while ( true ) {
			$token = $this->stream->current();

			if ( null === $token || TokenType::CloseParen === $token->type ) {
				// Empty `()` or a trailing comma — the CloseParen is the caller's.
				return $args;
			}

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

	public function parseElement(): Node {
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

				// <style>/<script> inside an expression still have a verbatim
				// body token captured by the lexer, not JSX children.
				$verbatim = $this->peekVerbatimBody( $tag );

				if ( null !== $verbatim ) {
					return $verbatim;
				}

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
			&& in_array( $peek->value, [ 'style', 'script', 'schema' ], true ) ) {
			$open  = $this->stream->next(); // OpenTag
			$name  = $this->stream->next()->value;
			$attrs = [];

			// Verbatim blocks can carry attributes (`<script src=... defer>`).
			while ( null === $this->stream->accept( TokenType::TagEnd ) ) {
				$token = $this->stream->current();

				if ( null === $token ) {
					throw SyntaxException::tagNeverClosed( $name, $peek->line );
				}

				if ( TokenType::SelfClose === $token->type ) {
					$this->stream->next();

					return new Element( $name, $attrs, [], selfClosing: true, line: $open->line );
				}

				if ( TokenType::Identifier === $token->type ) {
					$attrName = $this->stream->next()->value;
					$value    = null;

					if ( null !== $this->stream->accept( TokenType::AttrEquals ) ) {
						$attrToken = $this->stream->current();

						if ( null !== $attrToken && TokenType::AttrString === $attrToken->type ) {
							$value = new Literal( $this->stream->next()->value );
						} elseif ( null !== $attrToken && TokenType::ExpressionStart === $attrToken->type ) {
							$this->stream->next();
							$value = $this->parse();
							$this->stream->expect( TokenType::ExpressionEnd );
						}
					}

					$attrs[] = [ 'name' => $attrName, 'value' => $value, 'spread' => false ];

					continue;
				}

				throw new SyntaxException( 'Unexpected token in element tag', $token->line );
			}

			// A verbatim body token means the lexer captured a <style>/<script>/
			// <schema> block; otherwise this is a regular element.
			$verbatim = $this->peekVerbatimBody( $name, $attrs, $open->line );

			if ( null !== $verbatim ) {
				return $verbatim;
			}

			$body = $this->stream->current();

			if ( null !== $body && TokenType::Schema === $body->type ) {
				$this->stream->next();

				return new Schema( $body->value );
			}

			return new Element( $name, $attrs, $this->parseChildren( $name ), selfClosing: false, line: $open->line );
		}

		return $this->parseElement();
	}

	/**
	 * If the next token is a verbatim body (Style/Script), consume it and
	 * return the node; used by both document and expression element parsing.
	 *
	 * @param list<array{name:string|null, value:Expr|null, spread:bool}> $attrs
	 */
	private function peekVerbatimBody( string $tag, array $attrs = [], int $line = 0 ): ?Node {
		if ( ! in_array( $tag, [ 'style', 'script' ], true ) ) {
			return null;
		}

		$token = $this->stream->current();

		if ( null === $token || ! in_array( $token->type, [ TokenType::Style, TokenType::Script ], true ) ) {
			return null;
		}

		$this->stream->next();
		$parts = $this->parseVerbatimParts( $token->value );

		return 'style' === $tag
			? new Style( $token->value, $attrs, $parts )
			: new Script( $token->value, $attrs, $parts );
	}

	/**
	 * @return list<string|Expr>
	 */
	private function parseVerbatimParts( string $body ): array {
		$parts = [];
		$i     = 0;
		$len   = strlen( $body );

		while ( $i < $len ) {
			$start = strpos( $body, '{', $i );

			if ( false === $start ) {
				$parts[] = substr( $body, $i, $len - $i );

				break;
			}

			if ( $start > $i ) {
				$parts[] = substr( $body, $i, $start - $i );
			}

			[ $end, $inner ] = $this->matchingBrace( $body, $start );

			if ( $this->isVerbatimExpression( $inner ) ) {
				$stream  = ( new Lexer() )->tokenize( '{' . $inner . '}' );
				$stream->next(); // ExpressionStart
				$parts[] = ( new self( $stream ) )->parse();
			} else {
				$nestedParts = $this->parseVerbatimParts( $inner );
				$parts[]     = '{';
				foreach ( $nestedParts as $np ) {
					$parts[] = $np;
				}
				$parts[] = '}';
			}

			$i = $end;
		}

		return $parts;
	}

	/** @return array{0:int, 1:string} */
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

	private function isVerbatimExpression( string $content ): bool {
		$content = trim( $content );

		if ( '' === $content ) {
			return false;
		}

		$depth = 0;
		$quote = null;

		for ( $i = 0, $len = strlen( $content ); $i < $len; $i++ ) {
			$char = $content[ $i ];

			if ( null !== $quote ) {
				if ( '\\' === $char ) {
					$i++;
				} elseif ( $char === $quote ) {
					$quote = null;
				}

				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;

				continue;
			}

			if ( in_array( $char, [ '(', '[', '{' ], true ) ) {
				$depth++;

				continue;
			}

			if ( in_array( $char, [ ')', ']', '}' ], true ) ) {
				$depth--;

				continue;
			}

			if ( 0 === $depth && ( ':' === $char || ';' === $char ) ) {
				return false;
			}
		}

		return true;
	}
}
