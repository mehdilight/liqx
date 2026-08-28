<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

final class RenderTest extends TestCase {

	private Environment $env;

	protected function setUp(): void {
		$this->env = Environment::create();
	}

	/** @param array<string, mixed> $data */
	private function render( string $source, array $data = [], bool $strict = false ): string {
		return Template::parse( $source, $this->env )->render( $data, strict: $strict );
	}

	public function testInterpolation(): void {
		$this->assertSame( '<p>Ada</p>', $this->render( '<p>{name}</p>', [ 'name' => 'Ada' ] ) );
	}

	public function testPropertyLookup(): void {
		$this->assertSame(
			'<span>Widget</span>',
			$this->render( '<span>{product.title}</span>', [ 'product' => [ 'title' => 'Widget' ] ] )
		);
	}

	public function testNullSafePropertyReturnsEmptyOnMissingObject(): void {
		$this->assertSame( '<p></p>', $this->render( '<p>{product?.title}</p>', [] ) );
	}

	public function testNullSafePropertyResolvesWhenPresent(): void {
		$this->assertSame(
			'<p>Widget</p>',
			$this->render( '<p>{product?.title}</p>', [ 'product' => [ 'title' => 'Widget' ] ] )
		);
	}

	public function testNullSafeChainingShortCircuits(): void {
		// `a` is missing, so `?.b` short-circuits to null and `.c` is never read.
		// A plain `.b.c` on a missing `a` would throw in strict mode.
		$this->assertSame( '<p></p>', $this->render( '<p>{a?.b.c}</p>', [], strict: true ) );
	}

	public function testNullSafeOnExistingNullData(): void {
		$this->assertSame( '<p></p>', $this->render( '<p>{product?.title}</p>', [ 'product' => null ], strict: true ) );
	}

	public function testNullSafeComputedProperty(): void {
		$this->assertSame(
			'<p>Widget</p>',
			$this->render( '<p>{product?.[key]}</p>', [ 'product' => [ 'title' => 'Widget' ], 'key' => 'title' ] )
		);
		$this->assertSame( '<p></p>', $this->render( '<p>{product?.[key]}</p>', [ 'key' => 'title' ] ) );
	}

	public function testNullSafeInFrontmatter(): void {
		$source = "---\nconst title = product?.title ?? 'Fallback';\n---\n<h1>{title}</h1>";

		$this->assertSame( '<h1>Fallback</h1>', $this->render( $source, [] ) );
		$this->assertSame( '<h1>Widget</h1>', $this->render( $source, [ 'product' => [ 'title' => 'Widget' ] ] ) );
	}

	public function testCollectionPredicateMethodsInFrontmatter(): void {
		$source = "---\nconst items = [ { active: true }, { active: false } ];\nconst hasActive = items.some(i => i.active);\nconst allActive = items.every(i => i.active);\nconst none = [].some(i => i.active);\n---\n{hasActive}|{allActive}|{none}";

		// `false` interpolates as empty (Liquid semantics); `true` as "true".
		$this->assertSame( 'true||', $this->render( $source, [] ) );
	}

	public function testFrontmatterReturnExposesProps(): void {
		$source = "---\nconst title = block.title | default('Hello');\nconst count = 3;\nreturn { title: title, count: count, items: [ 1, 2 ] };\n---\n<h1>{props.title}</h1><span>{props.count}</span><b>{props.items.length}</b>";

		$this->assertSame(
			'<h1>Hello</h1><span>3</span><b>2</b>',
			$this->render( $source, [] )
		);
	}

	public function testFrontmatterReturnMustBeLast(): void {
		$source = "---\nreturn { a: 1 };\nconst x = 2;\n---\n<p>{x}</p>";

		$this->expectException( \Phpmystic\Liqx\SyntaxException::class );

		$this->render( $source, [] );
	}

	public function testFrontmatterReturnRejectsJsx(): void {
		$source = '---' . "\n" . 'return <p>nope</p>;' . "\n" . '---' . "\n" . '<span/>';

		$this->expectException( \Phpmystic\Liqx\SyntaxException::class );

		$this->render( $source, [] );
	}

	public function testCollectionPredicateMethodsOnData(): void {
		$this->assertSame(
			'<p>yes</p>',
			$this->render( '<p>{items.some(i => i.active) ? "yes" : "no"}</p>', [ 'items' => [ [ 'active' => true ], [ 'active' => false ] ] ] )
		);
		$this->assertSame(
			'<p>no</p>',
			$this->render( '<p>{items.every(i => i.active) ? "yes" : "no"}</p>', [ 'items' => [ [ 'active' => true ], [ 'active' => false ] ] ] )
		);
	}

	public function testAttributeExpressionValue(): void {
		$this->assertSame(
			'<img src="/a.jpg" />',
			$this->render( '<img src={url} />', [ 'url' => '/a.jpg' ] )
		);
	}

	public function testTernary(): void {
		$source = '<div>{settings.layout === "left" ? <span>L</span> : <span>R</span>}</div>';
		$this->assertSame(
			'<div><span>L</span></div>',
			$this->render( $source, [ 'settings' => [ 'layout' => 'left' ] ] )
		);
		$this->assertSame(
			'<div><span>R</span></div>',
			$this->render( $source, [ 'settings' => [ 'layout' => 'right' ] ] )
		);
	}

	public function testLogicalAnd(): void {
		$this->assertSame(
			'<p>New</p>',
			$this->render( '{show && <p>New</p>}', [ 'show' => true ] )
		);
		$this->assertSame(
			'',
			$this->render( '{show && <p>New</p>}', [ 'show' => false ] )
		);
	}

	public function testMapLoop(): void {
		$source = '<ul>{items.map(item => <li key={item.id}>{item.name}</li>)}</ul>';
		$this->assertSame(
			'<ul><li>a</li><li>b</li></ul>',
			$this->render( $source, [ 'items' => [ [ 'id' => 1, 'name' => 'a' ], [ 'id' => 2, 'name' => 'b' ] ] ] )
		);
	}

	public function testTraversableCollectionSupportsMap(): void {
		$items = new \ArrayIterator( [ [ 'id' => 1, 'name' => 'a' ], [ 'id' => 2, 'name' => 'b' ] ] );
		$this->assertSame(
			'<ul><li>a</li><li>b</li></ul>',
			$this->render( '<ul>{items.map(item => <li>{item.name}</li>)}</ul>', [ 'items' => $items ] )
		);
	}

	public function testTraversableCollectionSupportsFilter(): void {
		$items = new \ArrayIterator( [ [ 'n' => 1 ], [ 'n' => 2 ], [ 'n' => 3 ] ] );
		$this->assertSame(
			'2',
			$this->render( '{items.filter(i => i.n > 1).length}', [ 'items' => $items ] )
		);
	}

	public function testLazyCountableGivesLengthWithoutHydrating(): void {
		$lazy = new class( 7 ) extends \ArrayIterator {
			public function __construct( private readonly int $size ) {
				parent::__construct( [] );
			}

			public function count(): int {
				return $this->size;
			}
		};

		$this->assertSame( '7', $this->render( '{items.length}', [ 'items' => $lazy ] ) );
	}

	public function testPipeFilters(): void {
		$this->assertSame( 'HELLO', $this->render( '{text | upcase}', [ 'text' => 'hello' ] ) );
		$this->assertSame(
			'BB...',
			$this->render( '{text | replace("a", "b") | truncate(5) | upcase}', [ 'text' => 'aaaaaaa' ] )
		);
	}

	public function testCommentNotRendered(): void {
		$this->assertSame( '<p>ab</p>', $this->render( '<p>a{/* hidden */}b</p>' ) );
	}

	public function testEmptyCall(): void {
		$this->env->registerGlobal( 'appEmbeds', static fn (): string => 'appEmbeds()' );
		$this->assertSame( 'appEmbeds()', $this->render( '{appEmbeds()}' ) );
	}

	public function testCallWithTrailingComma(): void {
		$this->env->registerGlobal( 'f', static fn ( mixed $a ): string => 'ok' );
		$this->assertSame( 'ok', $this->render( "{f('x',)}" ) );
	}

	public function testMapOnUndefinedRendersEmpty(): void {
		$this->assertSame(
			'<ul></ul>',
			$this->render( '<ul>{maybe.lines.map(line => <li>{line.title}</li>)}</ul>' )
		);
	}

	public function testObjectLiteralWithReservedWordKey(): void {
		$this->env->registerGlobal( 'tag', static fn ( array $attrs = [] ): string => implode( ' ', array_keys( $attrs ) ) );
		$this->assertSame( 'class', $this->render( '{tag({ class: "x" })}' ) );
	}

	public function testChainedMethodCalls(): void {
		$this->assertSame(
			'<li>x</li><li>y</li>',
			$this->render( '{items.slice(0, 2).map(i => <li>{i.name}</li>)}', [ 'items' => [ [ 'name' => 'x' ], [ 'name' => 'y' ], [ 'name' => 'z' ] ] ] )
		);
	}

	public function testScriptInsideExpressionIsAElement(): void {
		// <script> in a { } expression is an ordinary element, not a verbatim block.
		$this->assertSame(
			'<div><script src="/a.js" defer></script></div>',
			$this->render( '{show && <div><script src={url} defer></script></div>}', [ 'show' => true, 'url' => '/a.js' ] )
		);
	}

	public function testLiteralBraces(): void {
		$this->assertSame( '<p>{{ not interpolated }}</p>', $this->render( "<p>{'{{ not interpolated }}'}</p>" ) );
	}

	public function testFrontmatterConst(): void {
		$source = "---\nconst x = 5;\n---\n<p>{x}</p>";
		$this->assertSame( "<p>5</p>", $this->render( $source ) );
	}

	public function testFrontmatterDerivedValue(): void {
		$source = "---\nconst greeting = `Hi \${name}`;\n---\n<p>{greeting}</p>";
		$this->assertSame( "<p>Hi Ada</p>", $this->render( $source, [ 'name' => 'Ada' ] ) );
	}

	public function testFrontmatterPipeAssignment(): void {
		$source = "---\nconst heading = title | upcase;\n---\n<h1>{heading}</h1>";
		$this->assertSame( "<h1>HELLO</h1>", $this->render( $source, [ 'title' => 'hello' ] ) );
	}

	public function testStyleBlockRendersWithInterpolation(): void {
		$source = '<style>.hero--{section.id} { background: {settings.bg_color}; }</style>';
		$this->assertSame(
			'<style>.hero--hero-1 { background: #fff; }</style>',
			$this->render( $source, [ 'section' => [ 'id' => 'hero-1' ], 'settings' => [ 'bg_color' => '#fff' ] ] )
		);
	}

	public function testStyleBlocksRenderInPlaceAndDoNotCollapse(): void {
		$source = '<style>.a{color:red}</style><p>x</p><style>.b{color:blue}</style>';
		$this->assertSame( '<style>.a{color:red}</style><p>x</p><style>.b{color:blue}</style>', $this->render( $source ) );
	}

	public function testScriptBlockInterpolatesButKeepsJsObjectsLiteral(): void {
		$source = '<script>window.routes = { cart: \'x\' }; var url = \'{routes.cart_url}\';</script>';
		$this->assertSame(
			"<script>window.routes = { cart: 'x' }; var url = '/cart';</script>",
			$this->render( $source, [ 'routes' => [ 'cart_url' => '/cart' ] ] )
		);
	}

	public function testScriptBlockKeepsJsObjectWithSemicolonsLiteral(): void {
		$source = '<script>if (a) { b(); }</script>';
		$this->assertSame( '<script>if (a) { b(); }</script>', $this->render( $source ) );
	}

	public function testScriptBlockWithAttributes(): void {
		$source = '<script src={url} defer>window.x = 1;</script>';
		$this->assertSame( '<script src="/a.js" defer>window.x = 1;</script>', $this->render( $source, [ 'url' => '/a.js' ] ) );
	}

	public function testStyleInterpolationAllowsColonsInsideStrings(): void {
		$source = '<style>.a { --x: {cond && `--hero-background-image: url(${url});`}; }</style>';
		$this->assertSame(
			'<style>.a { --x: --hero-background-image: url(/u.jpg);; }</style>',
			$this->render( $source, [ 'cond' => true, 'url' => '/u.jpg' ] )
		);
	}

	public function testDocumentExposesLastStyleBlock(): void {
		$template = Template::parse( '<style>.a{}</style><style>.b{}</style>' );
		$this->assertSame( '.b{}', $template->document()->style?->body );
	}

	public function testSchemaBlockCaptured(): void {
		$template = Template::parse( "<schema>\n{\"name\":\"Hero\",\"max_blocks\":8}\n</schema>" );
		$this->assertSame( [ 'name' => 'Hero', 'max_blocks' => 8 ], $template->schema() );
	}

	public function testSchemaAbsentReturnsNull(): void {
		$this->assertNull( Template::parse( '<p>hi</p>' )->schema() );
	}

	public function testSpreadAttributes(): void {
		$source = '<a href="/x" {...attrs}>Go</a>';
		$this->assertSame(
			'<a href="/x" class="btn" data-id="3">Go</a>',
			$this->render( $source, [ 'attrs' => [ 'class' => 'btn', 'data-id' => 3 ] ] )
		);
	}

	public function testSnippetProps(): void {
		$source = "---\nconst { product, size = 'medium' } = props;\n---\n<div class={size}>{product.title}</div>";
		$this->assertSame(
			'<div class="large">Widget</div>',
			$this->render( $source, [ 'props' => [ 'product' => [ 'title' => 'Widget' ], 'size' => 'large' ] ] )
		);
	}

	public function testFrontmatterDestructuringFromSection(): void {
		$source = "---\nconst { settings, blocks } = section;\n---\n<p>{settings.heading}</p>";
		$this->assertSame(
			'<p>Sale</p>',
			$this->render( $source, [ 'section' => [ 'settings' => [ 'heading' => 'Sale' ], 'blocks' => [] ] ] )
		);
	}

	public function testFullWorkedExample(): void {
		$source = <<<'LQX'
---
const { settings, blocks } = section;
const featured = products.filter(p => p.tags.includes('featured'));
const heading = settings.heading | truncate(40) | upcase;
---
<section>
  <h2>{heading}</h2>
  {featured.map((product, i) => (
    <div class={i % 2 === 0 ? 'row-even' : 'row-odd'} key={product.id}>
      <img src={product.image | img_url('300x')} alt={product.title} />
      <span>{product.price | money}</span>
    </div>
  ))}
  {featured.length === 0 && <p>No featured products.</p>}
  {blocks.map(block => (
    block.type === 'text' ? (
      <p {...block.attrs}>{block.settings.text}</p>
    ) : block.type === 'button' ? (
      <a href={block.settings.url} {...block.attrs}>{block.settings.label}</a>
    ) : null
  ))}
</section>

<style>
  .hero--{section.id} { background: {settings.bg_color}; }
</style>

<schema>
{"name":"Hero"}
</schema>
LQX;

		$data = [
			'section' => [
				'id'       => 'hero-1',
				'settings' => [ 'heading' => 'Welcome', 'bg_color' => '#fff' ],
				'blocks'   => [
					[ 'type' => 'text', 'attrs' => [ 'class' => 't' ], 'settings' => [ 'text' => 'Hello' ] ],
					[ 'type' => 'button', 'attrs' => [ 'class' => 'b' ], 'settings' => [ 'url' => '/x', 'label' => 'Go' ] ],
				],
			],
			'products' => [
				[ 'id' => 1, 'title' => 'Alpha', 'image' => '/alpha.jpg', 'price' => 1000, 'tags' => [ 'featured' ] ],
				[ 'id' => 2, 'title' => 'Beta', 'image' => '/beta.jpg', 'price' => 2000, 'tags' => [ 'sale' ] ],
			],
		];

		// Domain filter — hosts provide these, not the library.
		$this->env->registerFilter( 'img_url', static fn ( mixed $url, mixed $size = '' ) => $url );

		$rendered = $this->render( $source, $data );

		$this->assertStringContainsString( '<h2>WELCOME</h2>', $rendered );
		$this->assertStringContainsString( '<img src="/alpha.jpg" alt="Alpha" />', $rendered );
		$this->assertStringContainsString( '<span>10.00 MAD</span>', $rendered );
		$this->assertStringContainsString( '<div class="row-even">', $rendered );
		$this->assertStringNotContainsString( 'No featured products', $rendered );
		$this->assertStringContainsString( '<p class="t">Hello</p>', $rendered );
		$this->assertStringContainsString( '<a href="/x" class="b">Go</a>', $rendered );
		$this->assertStringContainsString( '.hero--hero-1 { background: #fff; }', $rendered );
	}

	public function testStrictModeThrowsOnUndefinedVariable(): void {
		$this->expectException( \Phpmystic\Liqx\UndefinedVariableException::class );
		$this->render( '<p>{missing}</p>', [], strict: true );
	}

	public function testLenientModeRendersUndefinedEmpty(): void {
		$this->assertSame( '<p></p>', $this->render( '<p>{missing}</p>', [] ) );
	}

	public function testSelfClosingVoidAndNonVoidElements(): void {
		$this->assertSame( '<img src="/test.jpg" />', $this->render( '<img src="/test.jpg" />' ) );
		$this->assertSame( '<input type="text" name="foo" />', $this->render( '<input type="text" name="foo" />' ) );
		$this->assertSame( '<textarea name="content" rows="4"></textarea>', $this->render( '<textarea name="content" rows="4" />' ) );
		$this->assertSame( '<div class="divider"></div>', $this->render( '<div class="divider" />' ) );
		$this->assertSame( '<span></span>', $this->render( '<span />' ) );
	}

	public function testTemplateStringAndVerbatimInterpolation(): void {
		$source = '<div><p>{`${name} has ${items.length} items`}</p><style>.box { color: {theme.color}; padding: 10px; }</style></div>';
		$rendered = $this->render( $source, [
			'name' => 'Alice',
			'items' => [ 1, 2, 3 ],
			'theme' => [ 'color' => '#f00' ],
		] );

		$this->assertStringContainsString( '<p>Alice has 3 items</p>', $rendered );
		$this->assertStringContainsString( '<style>.box { color: #f00; padding: 10px; }</style>', $rendered );
	}
}
