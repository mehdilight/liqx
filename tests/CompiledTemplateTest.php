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

	public function testNestedArrowsCaptureEnclosingClosureVariables(): void {
		// `filter` (and `i`) are bound by the outer map; the inner map references
		// them across the closure boundary. The compiled closure must capture
		// them in its `use (...)`, or they resolve to undefined/null at runtime.
		$data = [ 'groups' => [
			[ 'label' => 'A', 'items' => [ [ 'name' => 'a1' ], [ 'name' => 'a2' ] ] ],
			[ 'label' => 'B', 'items' => [ [ 'name' => 'b1' ] ] ],
		] ];

		$source = '{groups.map((group, gi) => <section data-i={gi}>{group.items.map(item => <span>{item.name}-{group.label}-{gi}</span>)}</section>)}';

		$this->assertParity( $source, $data );

		// Triple nesting: inmost arrow captures from two enclosing scopes.
		$deep = '{groups.map(g => <div>{g.items.map(i => <p>{i.name.length > 0 ? g.items.map(j => j.name) : ""}</p>)}</div>)}';
		$this->assertParity( $deep, $data );

		// A block-body inner arrow whose locals and constants reference an
		// enclosing arrow's parameter (capture into a block-body declaration).
		$blockBody = '{groups.map(g => <ul>{g.items.map(x => { const tag = g.label + "-" + x.name; return <li>{tag}</li>; })}</ul>)}';
		$this->assertParity( $blockBody, $data );

		// Shadowing: the inner arrow rebinds the same name — must NOT capture the
		// outer one (it is its own param), while a distinct outer var is captured.
		$shadow = '{groups.map(g => <div>{g.items.map(g => <i>{g.name}</i>)}</div>)}';
		$this->assertParity( $shadow, $data );

		// Capture from two distinct enclosing scopes at once plus the outer var
		// used as a filter argument inside the inmost arrow.
		$filters = '{groups.map((g, idx) => <p>{g.items.map(x => `${x.name}-${g.label}-${idx}`)}</p>)}';
		$this->assertParity( $filters, $data );
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

		// The key is content-addressed on the compiler fingerprint + source, and
		// still carries the explicit VERSION for human-bumped upgrades.
		$expected = md5( 'template:' . Compiler::fingerprint() . ':' . '' . ':' . md5( $source ) );
		$this->assertFileExists( $this->cacheDir . '/' . $expected . '.php' );

		// An artifact written by an older emitter must not be served — the key
		// change forces a recompile.
		$stale = md5( 'template:stale:' . '' . ':' . md5( $source ) );
		$this->assertFileDoesNotExist( $this->cacheDir . '/' . $stale . '.php' );
	}

	public function testCacheKeyIncludesCompilerFingerprintSoEmitterChangesRecompile(): void {
		$source = '<p>{name}</p>';
		$this->render( $source, [ 'name' => 'Ada' ] );

		$fingerprint = Compiler::fingerprint();
		$this->assertNotSame( '', $fingerprint, 'fingerprint must not be empty' );
		$this->assertSame( $fingerprint, Compiler::fingerprint(), 'fingerprint must be stable within a run' );

		$expected = md5( 'template:' . $fingerprint . ':' . '' . ':' . md5( $source ) );
		$this->assertFileExists( $this->cacheDir . '/' . $expected . '.php' );

		// An artifact written under an older emitter fingerprint must not be served.
		$stale = md5( 'template:stale-fingerprint:' . '' . ':' . md5( $source ) );
		$this->assertFileDoesNotExist( $this->cacheDir . '/' . $stale . '.php' );
	}

	public function testCompiledModeFailsFastAtParseTime(): void {
		// In compiled mode a syntax error must surface the moment the template is
		// parsed — not be deferred to first render.
		try {
			Template::parse( '<p>{unclosed', $this->env, 'sections/broken' );
			$this->fail( 'Expected a LiqxException at parse() time' );
		} catch ( \Phpmystic\Liqx\LiqxException $e ) {
			$this->assertSame( 'sections/broken', $e->templateName );
		}
	}

	public function testCompiledModeGuardsAgainstPathologicalNesting(): void {
		// Adversarial / machine-generated input far deeper than the compiler
		// cap must fail fast with a clear error instead of exhausting the stack.
		$source = '{' . str_repeat( '!', 5000 ) . 'true}';

		$this->expectException( \Phpmystic\Liqx\LiqxException::class );

		$this->render( $source, [] );
	}

	public function testNullSafeNavigationParity(): void {
		$data = [ 'product' => [ 'title' => 'Widget' ] ];

		$this->assertParity( '<p>{product?.title}</p>', $data );
		$this->assertParity( '<p>{product?.title}</p>', [] );
		$this->assertParity( '<p>{missing?.title}</p>', $data, strict: true );
		$this->assertParity( '<p>{missing?.a.b.c}</p>', $data, strict: true );
		$this->assertParity( '<p>{product?.["title"]}</p>', $data, strict: true );
		$this->assertParity( '<p>{a?.b ?? "default"}</p>', [ 'a' => [ 'b' => null ] ], strict: true );
	}

	public function testStaticFrontmatterConstsAreFoldedAtCompileTime(): void {
		$source = "---\nconst base = 100;\nconst tax = base * 2;\n---\n<p>{tax}</p>";

		$doc = ( new \Phpmystic\Liqx\Parser() )->parse( $source );
		$php = ( new \Phpmystic\Liqx\Compiler() )->compile( $doc );

		// A chain of static consts is resolved to a single literal at compile
		// time — the runtime must not recompute `base * 2` on every render.
		$this->assertStringContainsString( "set( 'tax', 200 );", $php );
		$this->assertStringNotContainsString( ' * 2', $php );

		// Object/array literal trees fold to PHP literals (no per-invocation eval).
		$obj   = "---\nconst links = [ { url: '/a', label: 'A' }, { url: '/b', label: 'B' } ];\n---\n{links.length}";
		$objDoc = ( new \Phpmystic\Liqx\Parser() )->parse( $obj );
		$objPhp = ( new \Phpmystic\Liqx\Compiler() )->compile( $objDoc );
		$this->assertStringNotContainsString( " '[", $objPhp );

		// Parity must hold: folded output is observably identical.
		$this->assertParity( $source, [] );
		$this->assertParity( $obj, [] );
	}

	public function testCollectionPredicateMethodsParity(): void {
		$data = [ 'items' => [ [ 'active' => true ], [ 'active' => false ], [ 'active' => true ] ] ];

		$this->assertParity( '<p>{items.some(i => i.active)}</p>', $data );
		$this->assertParity( '<p>{items.every(i => i.active)}</p>', $data );
		$this->assertParity( '<p>{"a".split(",").length}</p>', [] );
	}

	public function testFrontmatterReturnPropsParity(): void {
		$source = "---\nconst title = block.title | default('Hello');\nconst count = 3;\nreturn { title: title, count: count };\n---\n<h1>{props.title}</h1><span>{props.count}</span>";

		$this->assertParity( $source, [ 'block' => [ 'title' => 'Hi' ] ] );
		$this->assertParity( $source, [] );
	}

	public function testRootReferenceParity(): void {
		$source = '<h1>{root.block.title}</h1><span>{root.section}</span>';

		$this->assertParity( $source, [ 'block' => [ 'title' => 'Hi' ], 'section' => 'x' ] );
	}

	public function testDynamicComputedAccessInFrontmatterParity(): void {
		$source = "---\nconst key = 'title';\nconst t = product[key];\nconst first = items[0];\n---\n<h1>{t}</h1><span>{first}</span>";

		$this->assertParity( $source, [ 'product' => [ 'title' => 'Widget' ], 'items' => [ 'A', 'B' ] ] );
	}

	public function testNowDateFilterParity(): void {
		// `now()` itself is non-deterministic; pin the comparison to the current
		// year, which both the interpreter and compiled path must agree on.
		$source = '<span>{now() | date(\'%Y\')}</span>';

		$interpreter = Template::parse( $source, Environment::create() )->render();
		$compiled    = $this->render( $source );

		$this->assertSame( $interpreter, $compiled );
		$this->assertStringContainsString( (string) date( 'Y' ), $compiled );
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

	public function testTemplateTagParity(): void {
		$this->assertParity( '<template><p>Hello {name}</p></template>', [ 'name' => 'Ada' ] );
		$this->assertParity( '<template />' );
		$this->assertParity( '<template><p>First</p><p>Second</p></template>' );
		$this->assertParity( '<template><div><template id="row-tpl"><span>Nested</span></template></div></template>' );
		$this->assertParity( '<div>{show && <template id="js-tpl"><span>A</span><span>B</span></template>}</div>', [ 'show' => true ] );

		$source = <<<'LIQX'
---
const title = 'Welcome';
---
<template>
  <h1>{title}</h1>
  <template id="row-tpl"><tr><td>Row</td></tr></template>
</template>
<style>
  h1 { color: red; }
</style>
<schema>
{ "name": "Hero" }
</schema>
LIQX;
		$this->assertParity( $source, [] );

		$interpreterWrapper = Template::parse( $source, Environment::create() )->render( [], wrapper: [
			'tag'   => 'section',
			'attrs' => [ 'id' => 'hero-1', 'class' => 'hero-sec' ],
		] );
		$compiledWrapper    = Template::parse( $source, $this->env )->render( [], wrapper: [
			'tag'   => 'section',
			'attrs' => [ 'id' => 'hero-1', 'class' => 'hero-sec' ],
		] );
		$this->assertSame( $interpreterWrapper, $compiledWrapper );

		// Parity on non-template document with wrapper
		$plain = '<h2>{title}</h2>';
		$iPlain = Template::parse( $plain, Environment::create() )->render( [ 'title' => 'T' ], wrapper: [ 'tag' => 'div', 'attrs' => ' class="wrap"' ] );
		$cPlain = Template::parse( $plain, $this->env )->render( [ 'title' => 'T' ], wrapper: [ 'tag' => 'div', 'attrs' => ' class="wrap"' ] );
		$this->assertSame( $iPlain, $cPlain );
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