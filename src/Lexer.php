<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Hand-written, mode-stack scanner for the Liqx language.
 *
 * A `.liqx` document is one linear byte stream, so a single Lexer walks it
 * and switches modes as it crosses boundaries, producing a flat token list:
 *
 *   - Content: plain HTML text, `<tag>`/`</tag>` markers, and `{ }` expression
 *     delimiters. `<style>`/`<schema>` open blocks captured verbatim.
 *   - Tag: attribute names, `=`, quoted values, `{ }` expression values.
 *   - Js: a real JS expression. A `<` at an operand position followed by a
 *     letter starts an inline JSX element (the classic JSX `<` ambiguity is
 *     resolved by expectation — after an operand `<` is comparison, after an
 *     operator it opens a JSX element).
 *   - Frontmatter: a restricted JS statement block between `---` fences.
 *
 * The mode + tag stacks let JSX elements embedded inside `{ }` expressions
 * return the scanner to the expression context when they close.
 */
final class Lexer {

	/** @var list<string> reserved words that get their own token kind. */
	private const KEYWORDS = [
		'const', 'let', 'var', 'return', 'function', 'new', 'typeof',
		'this', 'if', 'else', 'for', 'while', 'do', 'switch', 'case',
		'break', 'continue', 'throw', 'try', 'catch', 'finally',
		'void', 'delete', 'of', 'in', 'instanceof', 'import', 'export',
		'default', 'class', 'extends', 'super', 'async', 'await',
		'yield', 'static',
	];

	private const TWO_CHAR_OPERATORS = [
		'&&', '||', '??', '==', '!=', '===', '!==', '<=', '>=',
		'**', '+=', '-=', '*=', '/=', '%=', '<<', '>>',
	];

	/** HTML void elements — `<input>`/`<img>`/`<br>` close themselves. */
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
		'link', 'meta', 'param', 'source', 'track', 'wbr',
	];

	private string $source = '';
	private int $cursor  = 0;
	private int $length  = 0;
	private int $line    = 1;

	/** @var list<Token> */
	private array $tokens = [];

	private LexerMode $mode = LexerMode::Content;

	/** @var list<LexerMode> modes awaiting return from a `{ }` expression. */
	private array $modeStack = [];

	/** @var list<array{name:string, fromJs:bool}> open element stack. */
	private array $tagStack = [];

	/** Nesting of `{ }` object/block braces within a Js expression. */
	private int $jsBraceDepth = 0;

	/** Outer brace depths awaiting restore when a `{…}` expression closes. */
	private array $jsBraceDepthStack = [];

	/** In Js mode: is the next token an operand (vs. postfix)? */
	private bool $expectOperand = true;

	/** Mode the scanner was in before entering the current <tag>. */
	private LexerMode $tagReturnMode = LexerMode::Content;

	/** Name of the element whose tag is being scanned. */
	private string $currentTagName = '';

	public function tokenize( string $source ): TokenStream {
		$this->source  = str_replace( [ "\r\n", "\r" ], "\n", $source );
		$this->length  = strlen( $this->source );
		$this->cursor  = 0;
		$this->line    = 1;
		$this->tokens  = [];
		$this->mode    = LexerMode::Content;
		$this->modeStack = [];
		$this->tagStack  = [];
		$this->jsBraceDepth = 0;
		$this->jsBraceDepthStack = [];
		$this->expectOperand = true;

		$this->lexFrontmatterOpen();

		while ( $this->cursor < $this->length ) {
			switch ( $this->mode ) {
				case LexerMode::Content:
					$this->lexContent();
					break;
				case LexerMode::Tag:
					$this->lexTag();
					break;
				case LexerMode::TagClose:
					$this->lexCloseTag();
					break;
				case LexerMode::Js:
					$this->lexJs();
					break;
				case LexerMode::Frontmatter:
					$this->lexFrontmatter();
					break;
				case LexerMode::Style:
					$this->lexVerbatimBlock( 'style', TokenType::Style, LexerMode::Style );
					break;
				case LexerMode::Script:
					$this->lexVerbatimBlock( 'script', TokenType::Script, LexerMode::Script );
					break;
				case LexerMode::Schema:
					$this->lexVerbatimBlock( 'schema', TokenType::Schema, LexerMode::Schema );
					break;
			}
		}

		if ( LexerMode::Frontmatter === $this->mode ) {
			throw SyntaxException::unterminated( 'frontmatter block', $this->line );
		}

		if ( LexerMode::Style === $this->mode ) {
			throw SyntaxException::tagNeverClosed( 'style', $this->line );
		}

		if ( LexerMode::Script === $this->mode ) {
			throw SyntaxException::tagNeverClosed( 'script', $this->line );
		}

		if ( LexerMode::Schema === $this->mode ) {
			throw SyntaxException::tagNeverClosed( 'schema', $this->line );
		}

		if ( [] !== $this->tagStack ) {
			throw SyntaxException::tagNeverClosed( $this->tagStack[0]['name'], $this->line );
		}

		$this->push( TokenType::EOF, '', $this->line );

		return new TokenStream( $this->tokens );
	}

	// ---------------------------------------------------------------------
	// Frontmatter entry
	// ---------------------------------------------------------------------

	/**
	 * An optional `---` fence at the very top of the document starts the
	 * frontmatter block. The block's statements are lexed as JS; a line that
	 * is exactly `---` ends it.
	 */
	private function lexFrontmatterOpen(): void {
		if ( $this->cursor >= $this->length ) {
			return;
		}

		// Allow blank lines / leading whitespace before the fence.
		$first = $this->readLine( $this->cursor );

		if ( '---' !== trim( $first ) ) {
			return;
		}

		$this->push( TokenType::FrontmatterStart, '---', $this->line );
		$this->cursor += strlen( $first );

		if ( $this->cursor < $this->length && "\n" === $this->source[ $this->cursor ] ) {
			$this->cursor++;
			$this->line++;
		}

		$this->mode = LexerMode::Frontmatter;
	}

	// ---------------------------------------------------------------------
	// Content mode
	// ---------------------------------------------------------------------

	private function lexContent(): void {
		$start     = $this->cursor;
		$startLine = $this->line;

		while ( $this->cursor < $this->length ) {
			$char = $this->source[ $this->cursor ];

			if ( '{' === $char ) {
				break;
			}

			if ( '<' === $char ) {
				$next = $this->source[ $this->cursor + 1 ] ?? '';
				if ( '/' === $next || $this->isIdentifierStart( $next ) ) {
					break;
				}
			}

			if ( "\n" === $char ) {
				$this->line++;
			}

			$this->cursor++;
		}

		$text = substr( $this->source, $start, $this->cursor - $start );

		if ( '' !== $text ) {
			$this->push( TokenType::Text, $text, $startLine );
		}

		if ( $this->cursor >= $this->length ) {
			return;
		}

		$char = $this->source[ $this->cursor ];

		if ( '{' === $char ) {
			$this->push( TokenType::ExpressionStart, '{', $this->line );
			$this->cursor++;
			$this->modeStack[] = LexerMode::Content;
			$this->jsBraceDepthStack[] = $this->jsBraceDepth;
			$this->jsBraceDepth = 0;
			$this->mode        = LexerMode::Js;
			$this->expectOperand = true;

			return;
		}

		if ( '/' === $this->source[ $this->cursor + 1 ] ) {
			$this->push( TokenType::CloseTag, '</', $this->line );
			$this->cursor += 2;
			$this->mode = LexerMode::TagClose;

			return;
		}

		$this->push( TokenType::OpenTag, '<', $this->line );
		$this->cursor++;
		$this->tagReturnMode = LexerMode::Content;
		$this->mode          = LexerMode::Tag;
	}

	// ---------------------------------------------------------------------
	// Tag mode — inside an opening element tag
	// ---------------------------------------------------------------------

	private function lexTag(): void {
		$this->skipWhitespace();

		if ( $this->cursor >= $this->length ) {
			throw SyntaxException::unterminated( 'element tag', $this->line );
		}

		$char = $this->source[ $this->cursor ];

		if ( '>' === $char ) {
			$isVoid = in_array( $this->currentTagName, self::VOID_ELEMENTS, true );

			// A void element closes itself even without `/>` — `<input>` has no
			// children, so nothing goes on the tag stack and nothing waits to
			// be closed.
			if ( $isVoid ) {
				$this->push( TokenType::SelfClose, '/>', $this->line );
				$this->cursor++;
				$this->currentTagName = '';
				$this->mode           = $this->tagReturnMode;

				return;
			}

			$this->push( TokenType::TagEnd, '>', $this->line );
			$this->cursor++;
			$this->openTagCompleted();

			return;
		}

		if ( '/' === $char && '>' === ( $this->source[ $this->cursor + 1 ] ?? '' ) ) {
			$this->push( TokenType::SelfClose, '/>', $this->line );
			$this->cursor += 2;
			$this->currentTagName = '';
			$this->mode           = $this->tagReturnMode;

			return;
		}

		if ( '{' === $char ) {
			$this->push( TokenType::ExpressionStart, '{', $this->line );
			$this->cursor++;
			$this->modeStack[] = LexerMode::Tag;
			$this->jsBraceDepthStack[] = $this->jsBraceDepth;
			$this->jsBraceDepth = 0;
			$this->mode        = LexerMode::Js;
			$this->expectOperand = true;

			return;
		}

		if ( '=' === $char ) {
			$this->push( TokenType::AttrEquals, '=', $this->line );
			$this->cursor++;

			return;
		}

		if ( '"' === $char || "'" === $char ) {
			$this->push( TokenType::AttrString, $this->scanString(), $this->line );

			return;
		}

		if ( $this->isIdentifierStart( $char ) ) {
			$value = $this->scanIdentifier( allowDash: true );
			$this->push( TokenType::Identifier, $value, $this->line );

			if ( '' === $this->currentTagName ) {
				$this->currentTagName = $value;
			}

			return;
		}

		throw SyntaxException::unexpectedCharacter( $char, $this->line );
	}

	private function openTagCompleted(): void {
		$name = $this->currentTagName;
		$this->currentTagName = '';

		// Verbatim block capture for <style> / <script> / <schema>.
		if ( 'style' === $name || 'script' === $name || 'schema' === $name ) {
			$this->mode = match ( $name ) {
				'style'  => LexerMode::Style,
				'script' => LexerMode::Script,
				default  => LexerMode::Schema,
			};

			return;
		}

		$this->tagStack[] = [
			'name'   => $name,
			'fromJs' => LexerMode::Js === $this->tagReturnMode,
		];
		$this->mode = LexerMode::Content;
	}

	// ---------------------------------------------------------------------
	// Closing tag mode — `</name>`
	// ---------------------------------------------------------------------

	private function lexCloseTag(): void {
		$this->skipWhitespace();

		if ( $this->cursor >= $this->length ) {
			throw SyntaxException::unterminated( 'closing tag', $this->line );
		}

		if ( $this->isIdentifierStart( $this->source[ $this->cursor ] ) ) {
			$this->push( TokenType::Identifier, $this->scanIdentifier( allowDash: true ), $this->line );

			return;
		}

		if ( '>' === $this->source[ $this->cursor ] ) {
			$this->push( TokenType::TagEnd, '>', $this->line );
			$this->cursor++;

			$entry = array_pop( $this->tagStack );

			if ( null === $entry ) {
				throw SyntaxException::unexpectedCharacter( '</>', $this->line );
			}

			$this->mode = $entry['fromJs'] ? LexerMode::Js : LexerMode::Content;

			return;
		}

		throw SyntaxException::unexpectedCharacter( $this->source[ $this->cursor ], $this->line );
	}

	// ---------------------------------------------------------------------
	// Js mode — a real JS expression, possibly containing inline JSX
	// ---------------------------------------------------------------------

	private function lexJs(): void {
		$this->skipWhitespace();

		if ( $this->cursor >= $this->length ) {
			throw SyntaxException::unterminated( 'expression', $this->line );
		}

		$char = $this->source[ $this->cursor ];

		// Closing brace of the `{ }` expression.
		if ( '}' === $char ) {
			if ( $this->jsBraceDepth > 0 ) {
				$this->jsBraceDepth--;
				$this->push( TokenType::Operator, '}', $this->line );
				$this->cursor++;
				$this->expectOperand = false;

				return;
			}

			$this->push( TokenType::ExpressionEnd, '}', $this->line );
			$this->cursor++;
			$this->jsBraceDepth = array_pop( $this->jsBraceDepthStack ) ?? 0;
			$this->mode        = array_pop( $this->modeStack ) ?? LexerMode::Content;

			return;
		}

		// Inline JSX element start: `<` at an operand position + letter.
		if ( '<' === $char && $this->expectOperand && $this->isIdentifierStart( $this->source[ $this->cursor + 1 ] ?? '' ) ) {
			$this->push( TokenType::OpenTag, '<', $this->line );
			$this->cursor++;
			$this->tagReturnMode = LexerMode::Js;
			$this->mode          = LexerMode::Tag;

			return;
		}

		$this->lexJsToken();
	}

	/**
	 * Lex a single token in a JS context (shared by `{ }` expressions and the
	 * frontmatter block).
	 */
	private function lexJsToken(): void {
		$this->skipWhitespace();

		$char = $this->source[ $this->cursor ];
		$next = $this->source[ $this->cursor + 1 ] ?? '';

		// JSX elements don't belong in the frontmatter — the sandbox keeps
		// markup in the render body. Only reached via the frontmatter path,
		// since Js-mode expressions handle `<` before reaching here.
		if ( '<' === $char && $this->expectOperand && $this->isIdentifierStart( $next ) ) {
			throw new SyntaxException( 'JSX elements are not allowed in frontmatter', $this->line );
		}

		// Line comment — vanishes.
		if ( '/' === $char && '/' === $next ) {
			$this->skipLineComment();

			return;
		}

		// Block comment — vanishes (covers `{/* ... */}`).
		if ( '/' === $char && '*' === $next ) {
			$this->skipBlockComment();

			return;
		}

		if ( "'" === $char || '"' === $char ) {
			$this->push( TokenType::String, $this->scanString(), $this->line );
			$this->expectOperand = false;

			return;
		}

		if ( '`' === $char ) {
			$this->push( TokenType::TemplateString, $this->scanTemplate(), $this->line );
			$this->expectOperand = false;

			return;
		}

		if ( $this->isDigit( $char ) || ( '.' === $char && $this->isDigit( $next ) ) ) {
			$this->push( TokenType::Number, $this->scanNumber(), $this->line );
			$this->expectOperand = false;

			return;
		}

		if ( $this->isIdentifierStart( $char ) ) {
			$value = $this->scanIdentifier( allowDash: false );
			$this->push(
				in_array( $value, self::KEYWORDS, true ) ? TokenType::Keyword : TokenType::Identifier,
				$value,
				$this->line
			);
			$this->expectOperand = $this->expectsOperandAfterKeyword( $value );

			return;
		}

		// Spread `...`.
		if ( '.' === $char && '.' === $next && '.' === ( $this->source[ $this->cursor + 2 ] ?? '' ) ) {
			$this->push( TokenType::Operator, '...', $this->line );
			$this->cursor += 3;
			$this->expectOperand = true;

			return;
		}

		// Arrow `=>`.
		if ( '=' === $char && '>' === $next ) {
			$this->push( TokenType::Arrow, '=>', $this->line );
			$this->cursor += 2;
			$this->expectOperand = true;

			return;
		}

		$two = substr( $this->source, $this->cursor, 2 );
		$three = substr( $this->source, $this->cursor, 3 );

		// Three-char operators must win over the two-char prefix (`===` not `==` + `=`).
		if ( '===' === $three || '!==' === $three ) {
			$this->push( TokenType::Operator, $three, $this->line );
			$this->cursor += 3;
			$this->expectOperand = true;

			return;
		}

		if ( in_array( $two, self::TWO_CHAR_OPERATORS, true ) ) {
			$this->push( TokenType::Operator, $two, $this->line );
			$this->cursor += 2;
			$this->expectOperand = true;

			return;
		}

		$single = match ( $char ) {
			'(' => TokenType::OpenParen,
			')' => TokenType::CloseParen,
			'[' => TokenType::OpenBracket,
			']' => TokenType::CloseBracket,
			',' => TokenType::Comma,
			':' => TokenType::Colon,
			';' => TokenType::Semicolon,
			'.' => TokenType::Dot,
			'|' => TokenType::Pipe,
			default => null,
		};

		if ( null !== $single ) {
			$this->push( $single, $char, $this->line );
			$this->cursor++;

			$this->expectOperand = match ( $char ) {
				')', ']', '.' => false,
				default       => true,
			};

			return;
		}

		// A `{` in Js context opens an object/block literal.
		if ( '{' === $char ) {
			$this->jsBraceDepth++;
			$this->push( TokenType::Operator, '{', $this->line );
			$this->cursor++;
			$this->expectOperand = true;

			return;
		}

		// A `}` not already consumed by the Js-mode loop (i.e. in the
		// frontmatter block) closes an object/block literal.
		if ( '}' === $char && $this->jsBraceDepth > 0 ) {
			$this->jsBraceDepth--;
			$this->push( TokenType::Operator, '}', $this->line );
			$this->cursor++;
			$this->expectOperand = false;

			return;
		}

		// Remaining operators and punctuation.
		$this->push( TokenType::Operator, $char, $this->line );
		$this->cursor++;
		$this->expectOperand = true;
	}

	// ---------------------------------------------------------------------
	// Frontmatter mode
	// ---------------------------------------------------------------------

	private function lexFrontmatter(): void {
		$this->skipWhitespace();

		if ( $this->cursor >= $this->length ) {
			throw SyntaxException::unterminated( 'frontmatter block', $this->line );
		}

		// A line whose trimmed content is exactly `---` terminates the block.
		$rest = $this->readLine( $this->cursor );

		if ( '---' === trim( $rest ) ) {
			$this->cursor += strlen( $rest );

			// Consume the newline that follows the fence (Astro-style).
			if ( $this->cursor < $this->length && "\n" === $this->source[ $this->cursor ] ) {
				$this->cursor++;
				$this->line++;
			}

			$this->push( TokenType::FrontmatterEnd, '---', $this->line );
			$this->mode = LexerMode::Content;

			return;
		}

		$this->lexJsToken();
	}

	// ---------------------------------------------------------------------
	// Verbatim block capture — <style> / <schema>
	// ---------------------------------------------------------------------

	private function lexVerbatimBlock( string $tagName, TokenType $type, LexerMode $mode ): void {
		$startLine = $this->line;

		$pattern = '/<\/' . $tagName . '\s*>/';
		if ( 1 !== preg_match( $pattern, $this->source, $m, PREG_OFFSET_CAPTURE, $this->cursor ) ) {
			throw SyntaxException::tagNeverClosed( $tagName, $startLine );
		}

		$body = substr( $this->source, $this->cursor, $m[0][1] - $this->cursor );

		if ( '' !== $body ) {
			$this->push( $type, $body, $startLine );
		}

		$this->line += substr_count( $body, "\n" );
		$this->cursor = $m[0][1] + strlen( $m[0][0] );
		$this->mode   = LexerMode::Content;
	}

	// ---------------------------------------------------------------------
	// Entry points for mode detection — called by Parser / callers
	// ---------------------------------------------------------------------

	/** @return list<Token> */
	public function frontmatterTokens( string $body ): array {
		// Re-lex a captured frontmatter body as a standalone JS stream.
		$tokens   = [];
		$original = [ $this->source, $this->cursor, $this->length, $this->line, $this->mode, $this->tokens ];
		$this->source  = str_replace( [ "\r\n", "\r" ], "\n", $body );
		$this->length  = strlen( $this->source );
		$this->cursor  = 0;
		$this->line    = 1;
		$this->mode    = LexerMode::Frontmatter;
		$this->tokens  = [];
		$this->jsBraceDepth = 0;

		while ( $this->cursor < $this->length ) {
			$this->lexFrontmatter();
		}

		$tokens       = $this->tokens;
		[ $this->source, $this->cursor, $this->length, $this->line, $this->mode, $this->tokens ] = $original;

		return $tokens;
	}

	// ---------------------------------------------------------------------
	// Scanners
	// ---------------------------------------------------------------------

	private function scanString(): string {
		$quote = $this->source[ $this->cursor++ ];
		$out   = '';

		while ( $this->cursor < $this->length ) {
			$char = $this->source[ $this->cursor ];

			if ( $quote === $char ) {
				$this->cursor++;

				return $out;
			}

			if ( '\\' === $char ) {
				$this->cursor++;
				$escaped = $this->source[ $this->cursor ] ?? '';
				$out .= match ( $escaped ) {
					'n'   => "\n",
					't'   => "\t",
					'r'   => "\r",
					'\\'  => '\\',
					"'"   => "'",
					'"'   => '"',
					'`'   => '`',
					default => $escaped,
				};
				$this->cursor++;

				continue;
			}

			if ( "\n" === $char ) {
				$this->line++;
			}

			$out .= $char;
			$this->cursor++;
		}

		throw SyntaxException::unterminated( 'string', $this->line );
	}

	private function scanTemplate(): string {
		$start = $this->cursor;
		$this->cursor++; // opening backtick
		$depth = 0;

		while ( $this->cursor < $this->length ) {
			$char = $this->source[ $this->cursor ];

			if ( '\\' === $char ) {
				$this->cursor += 2;

				continue;
			}

			if ( '`' === $char ) {
				if ( 0 === $depth ) {
					$this->cursor++;

					return substr( $this->source, $start, $this->cursor - $start );
				}

				$this->cursor++;

				continue;
			}

			if ( '$' === $char && '{' === ( $this->source[ $this->cursor + 1 ] ?? '' ) ) {
				$depth++;
				$this->cursor += 2;

				continue;
			}

			if ( '}' === $char && $depth > 0 ) {
				$depth--;
				$this->cursor++;

				continue;
			}

			if ( "\n" === $char ) {
				$this->line++;
			}

			$this->cursor++;
		}

		throw SyntaxException::unterminated( 'template literal', $this->line );
	}

	private function scanNumber(): string {
		$start = $this->cursor;

		while ( $this->cursor < $this->length && $this->isDigit( $this->source[ $this->cursor ] ) ) {
			$this->cursor++;
		}

		if ( '.' === ( $this->source[ $this->cursor ] ?? '' ) && $this->isDigit( $this->source[ $this->cursor + 1 ] ?? '' ) ) {
			$this->cursor++;
			while ( $this->cursor < $this->length && $this->isDigit( $this->source[ $this->cursor ] ) ) {
				$this->cursor++;
			}
		}

		return substr( $this->source, $start, $this->cursor - $start );
	}

	private function scanIdentifier( bool $allowDash ): string {
		$start = $this->cursor;

		while ( $this->cursor < $this->length ) {
			$char = $this->source[ $this->cursor ];

			if ( $this->isIdentifierStart( $char ) || ctype_digit( $char ) || '$' === $char || ( $allowDash && '-' === $char ) ) {
				$this->cursor++;

				continue;
			}

			break;
		}

		return substr( $this->source, $start, $this->cursor - $start );
	}

	private function skipLineComment(): void {
		while ( $this->cursor < $this->length && "\n" !== $this->source[ $this->cursor ] ) {
			$this->cursor++;
		}
	}

	private function skipBlockComment(): void {
		$this->cursor += 2;

		while ( $this->cursor < $this->length ) {
			if ( '*' === $this->source[ $this->cursor ] && '/' === ( $this->source[ $this->cursor + 1 ] ?? '' ) ) {
				$this->cursor += 2;

				return;
			}

			if ( "\n" === $this->source[ $this->cursor ] ) {
				$this->line++;
			}

			$this->cursor++;
		}
	}

	private function skipWhitespace(): void {
		while ( $this->cursor < $this->length ) {
			$char = $this->source[ $this->cursor ];

			if ( ' ' === $char || "\t" === $char || "\n" === $char ) {
				if ( "\n" === $char ) {
					$this->line++;
				}

				$this->cursor++;

				continue;
			}

			break;
		}
	}

	private function readLine( int $offset ): string {
		$end = strpos( $this->source, "\n", $offset );

		return substr( $this->source, $offset, false === $end ? $this->length - $offset : $end - $offset );
	}

	private function expectsOperandAfterKeyword( string $keyword ): bool {
		return in_array( $keyword, [
			'return', 'typeof', 'new', 'throw', 'void', 'delete',
			'in', 'instanceof', 'yield', 'await', 'case',
		], true );
	}

	private function push( TokenType $type, string $value, int $line ): void {
		if ( TokenType::Text === $type && '' === $value ) {
			return;
		}

		$this->tokens[] = new Token( $type, $value, $line );
	}

	private function isDigit( string $char ): bool {
		return '' !== $char && $char >= '0' && $char <= '9';
	}

	private function isIdentifierStart( string $char ): bool {
		return '' !== $char && ( ctype_alpha( $char ) || '_' === $char );
	}
}
