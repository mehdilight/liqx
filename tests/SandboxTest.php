<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\SyntaxException;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

/**
 * PRD §5 — the frontmatter block is a restricted, sandboxed subset, validated
 * at parse time: no eval/global access, no arbitrary function definitions,
 * no markup — data plus restricted logic only.
 */
final class SandboxTest extends TestCase {

	private Environment $env;

	protected function setUp(): void {
		$this->env = Environment::create();
	}

	private function parseFrontmatter( string $body ): void {
		Template::parse( "---\n" . $body . "\n---\n<p>x</p>", $this->env );
	}

	public function testAllowedConstDeclarations(): void {
		$this->parseFrontmatter( 'const x = 5;' );
		$this->parseFrontmatter( 'const a = true && b || c ?? d;' );
		$this->parseFrontmatter( 'const b = x > 1 ? y : z;' );
		$this->parseFrontmatter( 'const c = a + b * c / d;' );
		$this->addToAssertionCount( 4 );
	}

	public function testAllowedTemplateLiteral(): void {
		$this->parseFrontmatter( 'const greeting = `Hi ${name}`;' );
		$this->addToAssertionCount( 1 );
	}

	public function testAllowedDestructuringWithDefaults(): void {
		$this->parseFrontmatter( "const { product, size = 'medium' } = props;" );
		$this->addToAssertionCount( 1 );
	}

	public function testAllowedCollectionCallbacks(): void {
		$this->parseFrontmatter( "const featured = products.filter(p => p.tags.includes('featured'));" );
		$this->parseFrontmatter( 'const names = list.map(item => item.title | upcase);' );
		$this->parseFrontmatter( 'const first = list.find(item => item.active);' );
		$this->addToAssertionCount( 3 );
	}

	public function testAllowedPipeChain(): void {
		$this->parseFrontmatter( 'const heading = settings.heading | truncate(40) | upcase;' );
		$this->addToAssertionCount( 1 );
	}

	public function testRejectsEval(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'eval' );
		$this->parseFrontmatter( "const x = eval('code');" );
	}

	public function testRejectsGlobalAccess(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'window' );
		$this->parseFrontmatter( 'const x = window.location;' );
	}

	public function testRejectsFunctionConstructor(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'constructor' );
		$this->parseFrontmatter( 'const x = obj.constructor;' );
	}

	public function testRejectsArbitraryArrowFunction(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'Arrow functions' );
		$this->parseFrontmatter( 'const double = x => x * 2;' );
	}

	public function testRejectsJsxInFrontmatter(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'frontmatter' );
		$this->parseFrontmatter( 'const badge = show && <span>hi</span>;' );
	}

	public function testRejectsForbiddenViaPipeArgument(): void {
		$this->expectException( SyntaxException::class );
		$this->expectExceptionMessage( 'eval' );
		$this->parseFrontmatter( 'const x = value | replace(eval, "");' );
	}

	public function testErrorCarriesFrontmatterLine(): void {
		try {
			$this->parseFrontmatter( "const ok = 1;\nconst x = window.top;" );
			$this->fail( 'Expected SyntaxException' );
		} catch ( SyntaxException $e ) {
			$this->assertSame( 3, $e->lineNumber );
		}
	}
}