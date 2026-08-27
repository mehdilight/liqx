<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use Phpmystic\Liqx\UndefinedVariableException;
use Phpmystic\Liqx\UnknownFilterException;
use PHPUnit\Framework\TestCase;

/**
 * Error-reporting readiness for hosts like Sworen that render by name and
 * show a dev overlay: runtime failures must carry the template name and the
 * source line, and a partial must name itself.
 */
final class ErrorReportingTest extends TestCase {

	private string $root;

	private Environment $env;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/liqx-err-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/snippets', 0777, true );

		$this->env = Environment::create();
		$this->env->setSnippetFileSystem( new LocalFileSystem( $this->root . '/snippets' ) );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/snippets/*' ) ?: [] as $file ) {
			unlink( $file );
		}

		@rmdir( $this->root . '/snippets' );
		@rmdir( $this->root );
	}

	public function testRuntimeErrorCarriesTemplateNameAndLine(): void {
		$template = Template::parse( '<p>{missing}</p>', $this->env, 'templates/index' );

		try {
			$template->render( [], strict: true );
			$this->fail( 'Expected UndefinedVariableException' );
		} catch ( UndefinedVariableException $e ) {
			$this->assertSame( 'templates/index', $e->templateName );
			$this->assertSame( 1, $e->lineNumber );
		}
	}

	public function testErrorInsideNestedElementCarriesLine(): void {
		$template = Template::parse( "<div>\n  <p>{missing}</p>\n</div>", $this->env, 'layout/theme' );

		try {
			$template->render( [], strict: true );
			$this->fail( 'Expected UndefinedVariableException' );
		} catch ( UndefinedVariableException $e ) {
			$this->assertSame( 'layout/theme', $e->templateName );
			$this->assertSame( 2, $e->lineNumber );
		}
	}

	public function testFrontmatterErrorCarriesLine(): void {
		$template = Template::parse( "---\nconst x = missing;\n---\n<p>{x}</p>", $this->env, 'sections/hero' );

		try {
			$template->render( [], strict: true );
			$this->fail( 'Expected UndefinedVariableException' );
		} catch ( UndefinedVariableException $e ) {
			$this->assertSame( 'sections/hero', $e->templateName );
			$this->assertSame( 2, $e->lineNumber );
		}
	}

	public function testPartialErrorNamesThePartial(): void {
		file_put_contents( $this->root . '/snippets/card.liqx', '<p>{text | no_such_filter}</p>' );

		try {
			Template::parse( '{render("card", { text: "hi" })}', $this->env )->render();
			$this->fail( 'Expected UnknownFilterException' );
		} catch ( UnknownFilterException $e ) {
			$this->assertSame( 'card', $e->templateName );
			$this->assertSame( 1, $e->lineNumber );
		}
	}

	public function testSyntaxErrorCarriesTemplateNameAtParse(): void {
		try {
			Template::parse( '<div>', $this->env, 'sections/hero' );
			$this->fail( 'Expected SyntaxException' );
		} catch ( \Phpmystic\Liqx\SyntaxException $e ) {
			$this->assertSame( 'sections/hero', $e->templateName );
		}
	}
}