<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Lexer;
use Phpmystic\Liqx\Token;
use Phpmystic\Liqx\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase {

	/** @return list<Token> */
	private function lex( string $source ): array {
		return ( new Lexer() )->tokenize( $source )->tokens();
	}

	/**
	 * @param list<Token> $tokens
	 * @return list<string>
	 */
	private function types( array $tokens ): array {
		return array_map( static fn ( Token $t ) => $t->type->value, $tokens );
	}

	public function testPlainText(): void {
		$this->assertSame( [ 'text', 'eof' ], $this->types( $this->lex( 'Hello world' ) ) );
	}

	public function testElementWithAttributes(): void {
		$tokens = $this->lex( '<section id="main">hi</section>' );

		$this->assertSame(
			[ 'open_tag', 'identifier', 'identifier', 'attr_equals', 'attr_string', 'tag_end', 'text', 'close_tag', 'identifier', 'tag_end', 'eof' ],
			$this->types( $tokens )
		);
		$this->assertSame( 'section', $tokens[1]->value );
		$this->assertSame( 'id', $tokens[2]->value );
		$this->assertSame( 'main', $tokens[4]->value );
	}

	public function testSelfClosingElement(): void {
		$this->assertSame(
			[ 'open_tag', 'identifier', 'self_close', 'eof' ],
			$this->types( $this->lex( '<img/>' ) )
		);
	}

	public function testExpressionDelimiters(): void {
		$this->assertSame(
			[ 'expression_start', 'identifier', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{name}' ) )
		);
	}

	public function testLessThanInTextIsNotATag(): void {
		$this->assertSame(
			[ 'text', 'eof' ],
			$this->types( $this->lex( '1 < 2' ) )
		);
	}

	public function testJsxElementInsideExpression(): void {
		$this->assertSame(
			[ 'expression_start', 'identifier', 'operator', 'open_tag', 'identifier', 'tag_end', 'text', 'close_tag', 'identifier', 'tag_end', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{x && <span>hi</span>}' ) )
		);
	}

	public function testJsxSelfCloseInsideExpressionReturnsToJs(): void {
		$this->assertSame(
			[ 'expression_start', 'open_tag', 'identifier', 'self_close', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{<img/>}' ) )
		);
	}

	public function testComparisonOperatorInsideExpression(): void {
		// `a < b` must lex as an operator, not a JSX tag.
		$this->assertSame(
			[ 'expression_start', 'identifier', 'operator', 'identifier', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{a < b}' ) )
		);
	}

	public function testAttributeExpressionValue(): void {
		$this->assertSame(
			[ 'open_tag', 'identifier', 'identifier', 'attr_equals', 'expression_start', 'identifier', 'expression_end', 'self_close', 'eof' ],
			$this->types( $this->lex( '<img src={url} />' ) )
		);
	}

	public function testFrontmatter(): void {
		$tokens = $this->lex( "---\nconst x = 5;\n---\n<p>{x}</p>" );

		$this->assertSame(
			[ 'frontmatter_start', 'keyword', 'identifier', 'operator', 'number', 'semicolon', 'frontmatter_end', 'open_tag', 'identifier', 'tag_end', 'expression_start', 'identifier', 'expression_end', 'close_tag', 'identifier', 'tag_end', 'eof' ],
			$this->types( $tokens )
		);
	}

	public function testPipeTokensInsideExpression(): void {
		$this->assertSame(
			[ 'expression_start', 'identifier', 'pipe', 'identifier', 'open_paren', 'number', 'close_paren', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{title | truncate(20)}' ) )
		);
	}

	public function testStyleBlockCapturedVerbatim(): void {
		$tokens = $this->lex( '<style>.a { color: red; }</style>' );

		$this->assertSame( [ 'open_tag', 'identifier', 'tag_end', 'style', 'eof' ], $this->types( $tokens ) );
		$this->assertSame( '.a { color: red; }', $tokens[3]->value );
	}

	public function testSchemaBlockCapturedVerbatim(): void {
		$tokens = $this->lex( "<schema>\n{\"name\":\"Hero\"}\n</schema>" );

		$this->assertSame( [ 'open_tag', 'identifier', 'tag_end', 'schema', 'eof' ], $this->types( $tokens ) );
		$this->assertSame( "\n{\"name\":\"Hero\"}\n", $tokens[3]->value );
	}

	public function testJsxCommentProducesEmptyExpression(): void {
		$this->assertSame(
			[ 'expression_start', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{/* comment */}' ) )
		);
	}

	public function testTemplateLiteral(): void {
		$this->assertSame(
			[ 'expression_start', 'template_string', 'expression_end', 'eof' ],
			$this->types( $this->lex( '{`Hi ${name}`}' ) )
		);
	}

	public function testFrontmatterWithPipeAndTemplate(): void {
		$source = "---\n"
			. 'const heading = title | upcase;' . "\n"
			. 'const greeting = `Hi ${name}`;' . "\n"
			. "---\n<p>{greeting}</p>";
		$tokens = $this->lex( $source );
		$this->assertContains( 'frontmatter_start', $this->types( $tokens ) );
		$this->assertContains( 'pipe', $this->types( $tokens ) );
		$this->assertContains( 'template_string', $this->types( $tokens ) );
		$this->assertContains( 'frontmatter_end', $this->types( $tokens ) );
	}

	public function testVoidElementWithoutSlashSelfCloses(): void {
		$this->assertSame(
			[ 'open_tag', 'identifier', 'identifier', 'attr_equals', 'attr_string', 'self_close', 'eof' ],
			$this->types( $this->lex( '<input type="text">' ) )
		);
	}

	public function testVoidElementDoesNotUnbalanceSiblings(): void {
		$this->assertSame(
			[ 'open_tag', 'identifier', 'tag_end', 'open_tag', 'identifier', 'self_close', 'text', 'close_tag', 'identifier', 'tag_end', 'eof' ],
			$this->types( $this->lex( '<form><input>hi</form>' ) )
		);
	}

	public function testUnclosedTagThrows(): void {
		$this->expectException( \Phpmystic\Liqx\SyntaxException::class );
		$this->lex( '<div>' );
	}

	public function testUnclosedFrontmatterThrows(): void {
		$this->expectException( \Phpmystic\Liqx\SyntaxException::class );
		$this->lex( "---\nconst x = 1;\n" );
	}
}
