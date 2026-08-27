<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Compiler;
use Phpmystic\Liqx\CompiledTemplate;
use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use Phpmystic\Liqx\UnknownFilterException;
use PHPUnit\Framework\TestCase;

/**
 * The compiled rendering path. A template is lowered to a native PHP closure
 * (saved to a `.php` cache file for OPcache). Everything that flows through
 * the interpreter must render identically through the compiled path.
 */
final class CompiledTemplateTest extends TestCase {

	private string $cacheDir;

	private Environment $env;

	protected function setUp(): void {
		$this->cacheDir = sys_get_temp_dir() . '/liqx-cc-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->cacheDir, 0777, true );

		$this->env = Environment::create();
		$this->env->setCompiledTemplateDir( $this->cacheDir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->cacheDir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}

		@rmdir( $this->cacheDir );
	}

	/** @param array<string, mixed> $data */
	private function render( string $source, array $data = [], bool $strict = false ): string {
		return Template::parse( $source, $this->env )->render( $data, strict: $strict );
	}

	/** @param array<string, mixed> $data */
	private function assertParity( string $source, array $data = [], bool $strict = false ): void {
		$interpreter = Template::parse( $source, Environment::create() )->render( $data, strict: $strict );
		$compiled    = $this->render( $source, $data, $strict );

		$this->assertSame( $interpreter, $compiled, 'compiled output diverged from the interpreter for: ' . $source );
	}

	public function testDataProviderOfRepresentativeTemplates(): void {
		$data = [
			'name'     => 'Ada',
			'product'  => [ 'title' => 'Widget', 'price' => 1000, 'available' => true, 'variants' => [ 1, 2 ] ],
			'settings' => [ 'layout' => 'right', 'bg_color' => '#fff' ],
			'show'     => true,
			'items'    => [ [ 'id' => 1, 'name' => 'a', 'n' => 2 ], [ 'id' => 2, 'name' => 'b', 'n' => 5 ] ],
			'url'      => '/a.jpg',
			'attrs'    => [ 'class' => 'btn', 'data-id' => 3 ],
			'title'    => 'hello',
			'text'     => 'hello',
			'name2'    => 'x',
		];

		$templates = [
			'<p>{name}</p>',
			'<span>{product.title}</span>',
			'<img src={url} />',
			'<div>{settings.layout === "left" ? <span>L</span> : <span>R</span>}</div>',
			'{show && <p>New</p>}',
			'{items.map(item => <li key={item.id}>{item.name | upcase}</li>)}',
			'{items.filter(i => i.n > 1).length}',
			'{text | upcase}',
			'{items.slice(0, 1).map(i => <li>{i.name}</li>)}',
			'{show && <div><script src={url} defer></script></div>}',
			'<ul>{maybe.lines.map(line => <li>{line.title}</li>)}</ul>',
			'---' . "\n" . "const greeting = `Hi \${name}`;" . "\n" . '---' . "\n" . '<p>{greeting}</p>',
			'---' . "\n" . 'const heading = title | upcase;' . "\n" . '---' . "\n" . '<h1>{heading}</h1>',
			'<style>.hero--{product.id} { background: {settings.bg_color}; }</style>',
			'<style>.a{color:red}</style><p>x</p><style>.b{color:blue}</style>',
			"<script>window.routes = { cart: 'x' }; var url = '{title}';</script>",
			'<script>if (a) { b(); }</script>',
			'<script src={url} defer>window.x = 1;</script>',
			'<a href="/x" {...attrs}>Go</a>',
			"---\nconst { product, size = 'medium' } = props;\n---\n<div class={size}>{product.title}</div>",
			'{items.map(x => { const d = x.id * 2; return d + 1; })}',
			'{items.map(p => { const { name } = p; return <em>{name}</em>; })}',
			'{items[0].n % 2 === 0 ? `even` : `odd`}',
			'{missing ?? "fallback"}',
			'{a && b || c}',
			'<p>{`${name} has ${items.length} items`}</p>',
			'{product.available && product.variants.length > 0 ? "in" : "out"}',
			'{items.map((item, i) => <div key={i} class={`c--${i % 2 === 0 ? "e" : "o"}`}>{item.name}</div>)}',
			'<textarea name="content" rows="4" />',
			'<div class="divider" />',
		];

		foreach ( $templates as $source ) {
			$this->assertParity( $source, $data );
		}
	}

	public function testStrictModeThrowsWithTemplateName(): void {
		$template = Template::parse( '<p>{missing}</p>', $this->env, 'templates/index' );

		$this->expectException( \Phpmystic\Liqx\UndefinedVariableException::class );
		$template->render( [], strict: true );
	}

	public function testStrictModeUndefinedInFrontmatter(): void {
		$template = Template::parse( "---\nconst x = missing;\n---\n<p>{x}</p>", $this->env, 'sections/hero' );

		$this->expectException( \Phpmystic\Liqx\UndefinedVariableException::class );

		try {
			$template->render( [], strict: true );
		} catch ( \Phpmystic\Liqx\UndefinedVariableException $e ) {
			$this->assertSame( 'sections/hero', $e->templateName );
			throw $e;
		}
	}

	public function testPartialsAndSectionsRenderViaCompiledPath(): void {
		$root = sys_get_temp_dir() . '/liqx-ccfs-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/sections', 0777, true );
		mkdir( $root . '/snippets', 0777, true );

		$this->env->setSnippetFileSystem( new LocalFileSystem( $root . '/snippets' ) );
		$this->env->setSectionFileSystem( new LocalFileSystem( $root . '/sections' ) );

		file_put_contents( $root . '/snippets/card.liqx', "---\nconst { product } = props;\n---\n<div class=\"card\">{product.title}</div>" );
		file_put_contents( $root . '/sections/header.liqx', '<h1>{shop.name}</h1><span>{section.name}</span>' );

		$this->assertSame(
			'<div class="card">Widget</div>',
			$this->render( '{render("card", { product: { title: "Widget" } })}' )
		);

		$this->assertSame(
			'<h1>Cein</h1><span>header</span>',
			$this->render( '{section("header")}', [ 'shop' => [ 'name' => 'Cein' ] ] )
		);

		foreach ( glob( $root . '/*' ) ?: [] as $entry ) {
			is_dir( $entry ) ? $this->removeDir( $entry ) : unlink( $entry );
		}

		@rmdir( $root );
	}

	public function testUnknownFilterInPartialNamesThePartial(): void {
		$root = sys_get_temp_dir() . '/liqx-ccfs-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/snippets', 0777, true );

		$this->env->setSnippetFileSystem( new LocalFileSystem( $root . '/snippets' ) );
		file_put_contents( $root . '/snippets/card.liqx', '<p>{text | no_such_filter}</p>' );

		try {
			$this->render( '{render("card", { text: "hi" })}' );
			$this->fail( 'Expected UnknownFilterException' );
		} catch ( UnknownFilterException $e ) {
			$this->assertSame( 'card', $e->templateName );
		} finally {
			unlink( $root . '/snippets/card.liqx' );
			@rmdir( $root . '/snippets' );
			@rmdir( $root );
		}
	}

	public function testCachedArtifactIsContentAddressed(): void {
		$sourceA = '<p>{name}</p>';
		$sourceB = '<p>{title}</p>';

		$templateA = Template::parse( $sourceA, $this->env, 'theme/home' );
		$templateB = Template::parse( $sourceB, $this->env, 'theme/home' );

		// Same name, different source → distinct artifacts…
		$this->assertSame( '<p>Ada</p>', $templateA->render( [ 'name' => 'Ada', 'title' => 'T' ] ) );
		$this->assertSame( '<p>T</p>', $templateB->render( [ 'name' => 'Ada', 'title' => 'T' ] ) );

		$artifacts = glob( $this->cacheDir . '/*.php' ) ?: [];
		$this->assertCount( 2, $artifacts );

		// …and the original still re-renders from its own artifact.
		$this->assertSame( '<p>Ada</p>', $templateA->render( [ 'name' => 'Ada', 'title' => 'T' ] ) );
	}

	public function testCacheKeyEmbedsCompilerVersionSoUpgradesRecompile(): void {
		$source = '<p>{name}</p>';
		$this->render( $source, [ 'name' => 'Ada' ] );

		$expected = md5( 'template:' . Compiler::VERSION . ':' . '' . ':' . md5( $source ) );
		$this->assertFileExists( $this->cacheDir . '/' . $expected . '.php' );

		// An artifact written by an older emitter (VERSION - 1) must not be
		// served — the key change forces a recompile.
		$stale = md5( 'template:' . ( Compiler::VERSION - 1 ) . ':' . '' . ':' . md5( $source ) );
		$this->assertFileDoesNotExist( $this->cacheDir . '/' . $stale . '.php' );
	}

	public function testClearCompiledTemplatesRemovesArtifacts(): void {
		$this->render( '<p>{name}</p>', [ 'name' => 'Ada' ] );
		$this->assertNotEmpty( glob( $this->cacheDir . '/*.php' ) ?: [] );

		$this->env->clearCompiledTemplates();
		$this->assertSame( [], glob( $this->cacheDir . '/*.php' ) ?: [] );

		// Recompiles on demand afterwards.
		$this->assertSame( '<p>Ada</p>', $this->render( '<p>{name}</p>', [ 'name' => 'Ada' ] ) );
	}

	public function testCompiledTemplateFromSource(): void {
		$document  = Template::parse( '<b>{greeting}</b>' )->document();
		$phpSource = ( new Compiler() )->compile( $document );
		$compiled  = CompiledTemplate::fromSource( $phpSource );

		$this->assertSame( '<b>Hello</b>', $compiled->render( $this->env, [ 'greeting' => 'Hello' ] ) );
	}

	public function testSetCompiledTemplateDirCreatesMissingDirectory(): void {
		$dir = sys_get_temp_dir() . '/liqx-cd-' . bin2hex( random_bytes( 4 ) ) . '/nested';

		$env = Environment::create();
		$env->setCompiledTemplateDir( $dir );

		$this->assertTrue( is_dir( $dir ) );
		$this->assertSame( '<p>ok</p>', Template::parse( '<p>ok</p>', $env )->render() );

		$this->removeDir( $dir );
	}

	/** @return void */
	public function testObjectOpsAndFiltersParity(): void {
		$interpreter = Template::parse( '<h3>{product.title}</h3>', Environment::create() )->render( [ 'product' => $this->drop( [ 'title' => 'Widget' ] ) ] );
		$compiled    = $this->render( '<h3>{product.title}</h3>', [ 'product' => $this->drop( [ 'title' => 'Widget' ] ) ] );

		$this->assertSame( $interpreter, $compiled );
	}

	/** @param array<string, mixed> $data */
	private function drop( array $data ): object {
		return new class( $data ) {
			/** @param array<string, mixed> $data */
			public function __construct( private array $data ) {}

			public function beforeMethod( string $method ): mixed {
				return $this->data[ $method ] ?? null;
			}
		};
	}

	private function removeDir( string $dir ): void {
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}

		@rmdir( $dir );
	}
}