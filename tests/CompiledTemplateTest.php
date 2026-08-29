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

	/** The generated PHP for a source — for asserting on the emitted shape. */
	private function compile( string $source ): string {
		return ( new Compiler() )->compile( ( new \Phpmystic\Liqx\Parser() )->parse( $source ) );
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

	/**
	 * A template literal's literal segments are known at compile time, so they
	 * belong in the generated source as native concatenation. Routing them
	 * through `Evaluator::templateString()` allocates a parts array and loops it
	 * on every render for text the compiler already had.
	 */
	public function testTemplateStringsCompileToNativeConcatenation(): void {
		$php = $this->compile( '<p>{`Hi ${name}, you have ${count} items`}</p>' );

		$this->assertStringNotContainsString( 'templateString', $php, 'template literal still dispatches to the templateString helper' );

		// The literal segments survive as PHP string literals.
		$this->assertStringContainsString( "'Hi '", $php );
		$this->assertStringContainsString( "', you have '", $php );
		$this->assertStringContainsString( "' items'", $php );

		// A literal-only template string needs no runtime work at all.
		$this->assertStringNotContainsString( 'templateString', $this->compile( '<p>{`plain`}</p>' ) );
	}

	/**
	 * Template-literal interpolation stringifies through `renderValue`, so the
	 * inlined form must agree with the interpreter for every value shape —
	 * including the ones with surprising rules (`false`/null vanish, `true`
	 * prints, arrays concatenate their items, objects without `__toString`
	 * vanish).
	 */
	public function testInlinedTemplateStringMatchesInterpreterForEveryValueShape(): void {
		$source = '<p>{`[${v}]`}</p>';

		foreach ( [ null, false, true, 0, 0.0, '', '0', 7, 1.5, 'x', [ 1, 2 ], [ 'a' => 'b' ], [ [ 1 ], [ 2 ] ] ] as $value ) {
			$this->assertParity( $source, [ 'v' => $value ] );
		}

		$this->assertParity( $source, [ 'v' => new \stdClass() ] );
		$this->assertParity( '<p>{`${a}${b}${c}`}</p>', [ 'a' => 'x', 'b' => null, 'c' => 3 ] );
		$this->assertParity( '<p>{`${n | upcase} ${m}`}</p>', [ 'n' => 'ada', 'm' => 2 ] );

		// Nested interpolation and an interpolation holding an element.
		$this->assertParity( '<p>{`${`${deep}`}`}</p>', [ 'deep' => 'd' ] );
		$this->assertParity( '<div>{items.map(i => `${i.a}-${i.b}`)}</div>', [ 'items' => [ [ 'a' => 1, 'b' => 2 ], [ 'a' => 3, 'b' => 4 ] ] ] );
	}

	/**
	 * Now that a template literal compiles to concatenation, its result is
	 * statically known to be a string — so wrapping it in `renderValue()` (which
	 * exists to stringify unknown values) is dead work, as is routing it through
	 * `attribute()` (whose job is the `true`/`false`/null attribute rules that a
	 * string can never trigger).
	 */
	public function testKnownStringExpressionsSkipRedundantStringifyWrappers(): void {
		// Output position: `{`…`}` needs no renderValue.
		$output = $this->compile( '<p>{`Hi ${name}`}</p>' );
		$this->assertStringNotContainsString( 'renderValue( ( \'Hi \'', $output, 'a template literal is still re-stringified in output position' );

		// Attribute position: a template literal value needs no attribute().
		$attr = $this->compile( '<div class={`a-${n}`}></div>' );
		$this->assertStringNotContainsString( '$eval->attribute( \'class\'', $attr, 'a template-literal attribute still goes through attribute()' );
		$this->assertStringContainsString( ' class="', $attr, 'the attribute name/quotes were not baked into the artifact' );

		// A string literal attribute is fully static.
		$this->assertStringNotContainsString( '$eval->attribute(', $this->compile( '<div class="static"></div>' ) );

		// A value of unknown type must keep both helpers — they carry the rules.
		$dynamic = $this->compile( '<div class={flag}>{value}</div>' );
		$this->assertStringContainsString( '$eval->attribute(', $dynamic );
		$this->assertStringContainsString( 'renderValue(', $dynamic );
	}

	public function testAttributeAndOutputParityForKnownStringValues(): void {
		// The attribute rules only differ for non-strings, so a string-valued
		// attribute must render identically — including empty and "0".
		foreach ( [ '', '0', 'x', 'a b' ] as $value ) {
			$this->assertParity( '<div class={`${v}`}></div>', [ 'v' => $value ] );
			$this->assertParity( '<p>{`${v}`}</p>', [ 'v' => $value ] );
		}

		// A template literal that interpolates a non-string still stringifies it
		// the same way before the concatenation happens.
		foreach ( [ null, false, true, 0, [ 1, 2 ] ] as $value ) {
			$this->assertParity( '<div class={`v-${v}`}></div>', [ 'v' => $value ] );
		}

		// An element in attribute/output position is also a known string.
		$this->assertParity( '<div>{show && <span>x</span>}</div>', [ 'show' => true ] );
		$this->assertParity( '<div title={`t`}><em>{`e`}</em></div>' );
	}

	/**
	 * `product.title` has a compile-time-known key, and the overwhelmingly
	 * common receiver is a plain array. Emitting the array read inline with the
	 * helper as fallback keeps Drop / ArrayAccess / `length` / object semantics
	 * intact while skipping a method call per access on the hot path.
	 */
	public function testLiteralKeyMemberAccessCompilesToInlineArrayRead(): void {
		$php = $this->compile( '<p>{product.title}</p>' );

		$this->assertMatchesRegularExpression(
			'/is_array\(.*\)\s*\?\s*\(.*\[\s*\'title\'\s*\]/s',
			$php,
			'literal-key member access did not emit an inline array read'
		);

		// `length` is not a plain array key (it means count()), and a computed
		// key is not known at compile time — both must stay on the helper.
		$this->assertStringNotContainsString( "[ 'length' ]", $this->compile( '<p>{items.length}</p>' ) );
		$this->assertStringContainsString( 'getProperty', $this->compile( '<p>{items[key]}</p>' ) );
	}

	/**
	 * The inline array read is only valid where it agrees with
	 * `Evaluator::getProperty()`, so every receiver kind must still match the
	 * interpreter exactly.
	 */
	public function testInlinedMemberAccessMatchesInterpreterForEveryReceiver(): void {
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => [ 'title' => 'Widget' ] ] );
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => [] ] );
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => [ 'title' => null ] ] );
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => null ] );
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => 'a string' ] );
		$this->assertParity( '<p>{o.title}</p>', [ 'o' => 42 ] );
		$this->assertParity( '<p>{o.length}</p>', [ 'o' => [ 1, 2, 3 ] ] );
		$this->assertParity( '<p>{o.length}</p>', [ 'o' => 'abcd' ] );

		// A Drop resolves members through beforeMethod, never array access.
		$interpreter = Template::parse( '<p>{o.title}</p>', Environment::create() )->render( [ 'o' => $this->drop( [ 'title' => 'Widget' ] ) ] );
		$this->assertSame( $interpreter, $this->render( '<p>{o.title}</p>', [ 'o' => $this->drop( [ 'title' => 'Widget' ] ) ] ) );

		// A plain object exposes public properties.
		$plain        = new \stdClass();
		$plain->title = 'Obj';
		$this->assertSame(
			Template::parse( '<p>{o.title}</p>', Environment::create() )->render( [ 'o' => $plain ] ),
			$this->render( '<p>{o.title}</p>', [ 'o' => $plain ] )
		);

		// ArrayAccess resolves through offsetExists/offsetGet.
		$arrayAccess = new class() implements \ArrayAccess {
			/** @var array<string, mixed> */
			private array $data = [ 'title' => 'AA' ];

			public function offsetExists( mixed $offset ): bool {
				return isset( $this->data[ $offset ] );
			}

			public function offsetGet( mixed $offset ): mixed {
				return $this->data[ $offset ] ?? null;
			}

			public function offsetSet( mixed $offset, mixed $value ): void {}

			public function offsetUnset( mixed $offset ): void {}
		};
		$this->assertSame(
			Template::parse( '<p>{o.title}</p>', Environment::create() )->render( [ 'o' => $arrayAccess ] ),
			$this->render( '<p>{o.title}</p>', [ 'o' => $arrayAccess ] )
		);
	}

	/**
	 * A filter pipeline is known at compile time, so it should not be rebuilt as
	 * a `[[name, args], …]` array and walked by a loop on every evaluation. Each
	 * filter becomes one direct call instead.
	 *
	 * Note the deliberate limit: the *callable* is *not* resolved ahead of the
	 * value. The interpreter evaluates the value first and only then looks the
	 * filter up, so resolving earlier would change which error surfaces when
	 * both the value and the filter name are bad. See
	 * {@see testFilterErrorOrderingMatchesInterpreter}.
	 */
	public function testFilterPipelineCompilesToDirectCallsNotAPipelineArray(): void {
		$php = $this->compile( '<div>{items.map(i => <span>{i.price | money}</span>)}</div>' );

		$this->assertStringContainsString( '$eval->applyFilter(', $php, 'filter is not applied through a direct call' );
		$this->assertStringNotContainsString( '$eval->filtered(', $php, 'filter still goes through the pipeline-array walker' );
		$this->assertStringNotContainsString( "[ [ 'money'", $php, 'pipeline array literal is still emitted' );

		// A multi-filter pipeline nests the calls, innermost filter first.
		$chained = $this->compile( '<p>{text | upcase | truncate(5)}</p>' );
		$this->assertStringContainsString( "'upcase'", $chained );
		$this->assertStringContainsString( "'truncate'", $chained );
		$this->assertLessThan(
			strpos( $chained, "'truncate'" ),
			strpos( $chained, "'upcase'" ),
			'the first filter in the pipeline must be applied first (innermost call)'
		);
	}

	public function testFilterPipelineParity(): void {
		$data = [
			'items' => [ [ 'price' => 1000, 'name' => 'ada' ], [ 'price' => 250, 'name' => 'bob' ] ],
			'text'  => 'hello world',
			'n'     => 3,
		];

		$this->assertParity( '<div>{items.map(i => <span>{i.price | money}</span>)}</div>', $data );
		$this->assertParity( '<p>{text | upcase | truncate(5)}</p>', $data );
		$this->assertParity( '<p>{n | plus(2) | times(3)}</p>', $data );
		$this->assertParity( '<div>{items.map(i => i.name | upcase | append("!"))}</div>', $data );
		$this->assertParity( '<p>{missing | default("fallback")}</p>', $data );

		// A filter argument that depends on the loop variable must still be
		// evaluated per item.
		$this->assertParity( '<div>{items.map(i => i.name | append(i.price))}</div>', $data );
	}

	/**
	 * The interpreter evaluates a filter's *value* before looking the filter up,
	 * so when both are broken the value's error is the one that surfaces. The
	 * compiled path must report the same error — this is what rules out
	 * resolving filter callables ahead of the value (e.g. hoisting them to the
	 * top of the render), since PHP resolves a callee before its arguments.
	 */
	public function testFilterErrorOrderingMatchesInterpreter(): void {
		// Two unknown filters: the *first* in the pipeline must be reported.
		try {
			$this->render( '<p>{text | inner_missing | outer_missing}</p>', [ 'text' => 'hi' ] );
			$this->fail( 'Expected UnknownFilterException' );
		} catch ( UnknownFilterException $e ) {
			$this->assertStringContainsString( 'inner_missing', $e->getMessage() );
		}

		// A bad value plus an unknown filter: the value's error wins.
		$this->expectException( \Phpmystic\Liqx\UndefinedVariableException::class );
		$this->render( '<p>{nope | no_such_filter}</p>', [], strict: true );
	}

	/**
	 * An unknown filter must still raise at render time, and only when the
	 * expression that uses it is actually evaluated — a never-taken branch must
	 * not become an eager failure.
	 */
	public function testUnknownFilterStillThrowsOnlyWhenEvaluated(): void {
		$this->expectException( UnknownFilterException::class );
		$this->render( '<p>{text | no_such_filter}</p>', [ 'text' => 'hi' ] );
	}

	public function testUnknownFilterInUntakenBranchDoesNotThrow(): void {
		$source = '<p>{show ? (text | no_such_filter) : "safe"}</p>';
		$data   = [ 'show' => false, 'text' => 'hi' ];

		$this->assertSame( '<p>safe</p>', Template::parse( $source, Environment::create() )->render( $data ) );
		$this->assertParity( $source, $data );
	}

	/**
	 * The optional wrapper only adds an opening and a closing tag around the
	 * body, yet the emitter wrote the *entire* body twice — once for the
	 * wrapped branch and once for the unwrapped one. That doubles artifact
	 * bytes, OPcache shared memory, and first-request compile time for a branch
	 * on a value that is known before any output is produced.
	 */
	public function testWrapperDoesNotDuplicateTheCompiledBody(): void {
		$php = $this->compile( '<div class="products">{items.map(i => <span>{i.name}</span>)}</div>' );

		$this->assertSame( 1, substr_count( $php, "'products'" ), 'the body was emitted more than once' );
		$this->assertSame( 1, substr_count( $php, "'map'" ), 'the body was emitted more than once' );

		// The wrapper tag itself is still conditional.
		$this->assertStringContainsString( 'formatWrapperAttrs', $php );

		// Same for a <template> block, which carries its own wrapper branch.
		// (`$ctx->get( 'title' )` is the non-strict half of the identifier pair,
		// so it appears exactly once per emission of the body.)
		$tpl = $this->compile( '<template><p>{title}</p></template>' );
		$this->assertSame( 1, substr_count( $tpl, "\$ctx->get( 'title' )" ), 'the template-block body was emitted more than once' );
	}

	public function testWrapperParityAcrossEveryWrapperShape(): void {
		$plain    = '<h2>{title}</h2><p>tail</p>';
		$withTpl  = '<template><h2>{title}</h2></template>';
		$data     = [ 'title' => 'T' ];
		$wrappers = [
			null,
			[ 'tag' => 'section' ],
			[ 'tag' => 'section', 'attrs' => ' class="wrap"' ],
			[ 'tag' => 'section', 'attrs' => [ 'id' => 'a', 'class' => 'b' ] ],
			[ 'tag' => 'none' ],
			[ 'tag' => '' ],
			[ 'attrs' => [ 'id' => 'no-tag' ] ],
		];

		foreach ( [ $plain, $withTpl ] as $source ) {
			foreach ( $wrappers as $wrapper ) {
				$interpreter = Template::parse( $source, Environment::create() )->render( $data, wrapper: $wrapper );
				$compiled    = Template::parse( $source, $this->env )->render( $data, wrapper: $wrapper );

				$this->assertSame(
					$interpreter,
					$compiled,
					'wrapper divergence for ' . $source . ' with ' . var_export( $wrapper, true )
				);
			}
		}
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

	/**
	 * The `<schema>` sidecar answers one question — "does this template need
	 * per-render schema validation?" — whose answer cannot change while the
	 * artifact key (a content hash of the source) stays the same. Reading it
	 * from disk on every render costs an uncached open+read syscall per render,
	 * so the answer must be memoized.
	 *
	 * Observable proof: prime the cache, delete the sidecar, then render again.
	 * A render that still consults disk cannot find the sidecar, falls back to
	 * parsing the document, and writes the sidecar back — so a sidecar that
	 * stays absent proves no per-render read happened.
	 */
	public function testWarmCacheDoesNotReadSchemaSidecarOnEveryRender(): void {
		$source = '<p>{name}</p>';

		// Cold: parses, compiles, writes the artifact and the sidecar.
		$this->assertSame( '<p>Ada</p>', Template::parse( $source, $this->env )->render( [ 'name' => 'Ada' ] ) );

		$sidecars = glob( $this->cacheDir . '/*.meta' ) ?: [];
		$this->assertCount( 1, $sidecars, 'the cold render should write exactly one sidecar' );
		$sidecar = $sidecars[0];

		// Warm: the artifact exists, so this instance never parses and must
		// answer the schema question from the sidecar (once) instead.
		$warm = Template::parse( $source, $this->env );
		$this->assertSame( '<p>Bob</p>', $warm->render( [ 'name' => 'Bob' ] ) );

		unlink( $sidecar );

		// Second render on the warm instance: the answer is already known.
		$this->assertSame( '<p>Cy</p>', $warm->render( [ 'name' => 'Cy' ] ) );
		$this->assertFileDoesNotExist( $sidecar, 'a repeat render re-read the sidecar instead of memoizing it' );

		// A fresh Template for the same source (a later request in the same
		// process) must reuse the process-wide answer, not go back to disk.
		$again = Template::parse( $source, $this->env );
		$this->assertSame( '<p>Di</p>', $again->render( [ 'name' => 'Di' ] ) );
		$this->assertFileDoesNotExist( $sidecar, 'a second Template re-read the sidecar instead of memoizing it' );
	}

	/**
	 * Memoizing the sidecar answer must not memoize the *validation*: a
	 * template that declares a `<schema>` still type-checks its props on every
	 * render, so bad data on a later render fails just as loudly as on the first.
	 */
	public function testSchemaValidationStillRunsOnEveryRenderWithWarmCache(): void {
		$source = "---\nconst n = value;\nreturn { count: n };\n---\n<p>{props.count}</p><schema>\n{ \"props\": { \"count\": \"int\" } }\n</schema>";

		$template = Template::parse( $source, $this->env );

		$this->assertSame( '<p>5</p>', $template->render( [ 'value' => 5 ] ) );
		$this->assertSame( '<p>7</p>', $template->render( [ 'value' => 7 ] ) );

		// The same warm template, now given data that violates the schema.
		$this->expectException( \Phpmystic\Liqx\LiqxException::class );
		$this->expectExceptionMessage( 'Schema type mismatch' );
		$template->render( [ 'value' => 'abc' ] );
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