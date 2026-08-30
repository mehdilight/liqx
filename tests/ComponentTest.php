<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

/**
 * Tests for first-class JSX component syntax (<PascalCase>), props passing,
 * default slot (props.children / <slot />), named slots (<template slot="name"> / <slot name="name">),
 * and slot fallback content.
 */
final class ComponentTest extends TestCase {

	private string $root;
	private Environment $env;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/liqx-components-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/snippets', 0777, true );

		$this->env = Environment::create();
		$this->env->setSnippetFileSystem( new LocalFileSystem( $this->root . '/snippets' ) );
	}

	protected function tearDown(): void {
		$this->removeDir( $this->root );
	}

	private function write( string $relative, string $content ): void {
		file_put_contents( $this->root . '/' . $relative, $content );
	}

	private function removeDir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->removeDir( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	/** @param array<string, mixed> $data */
	private function render( string $source, array $data = [] ): string {
		return Template::parse( $source, $this->env )->render( $data );
	}

	public function testSelfClosingComponentSnippet(): void {
		$this->write(
			'snippets/card.liqx',
			"<div class=\"card\">\n  <h3>{props.title}</h3>\n  <span>{props.price | money}</span>\n</div>"
		);

		$rendered = $this->render( '<main><Card title={featured.title} price={featured.price} /></main>', [
			'featured' => [ 'title' => 'Widget', 'price' => 1000 ],
		] );

		$this->assertSame(
			"<main><div class=\"card\">\n  <h3>Widget</h3>\n  <span>10.00 MAD</span>\n</div></main>",
			$rendered
		);
	}

	public function testComponentBooleanPropAndSpread(): void {
		$this->write(
			'snippets/button.liqx',
			'<button class={props.variant} disabled={props.disabled}>{props.label}</button>'
		);

		$rendered = $this->render( '<Button {...btnProps} disabled label="Save" />', [
			'btnProps' => [ 'variant' => 'primary', 'type' => 'submit' ],
		] );

		$this->assertSame( '<button class="primary" disabled>Save</button>', $rendered );
	}

	public function testComponentDefaultSlotViaChildrenProp(): void {
		$this->write(
			'snippets/modal.liqx',
			"<div class=\"modal\">\n  <h2>{props.title}</h2>\n  <div class=\"body\">{props.children}</div>\n</div>"
		);

		$rendered = $this->render(
			"<Modal title=\"Welcome\">\n  <p>Hello <b>{user.name}</b>!</p>\n</Modal>",
			[ 'user' => [ 'name' => 'Alice' ] ]
		);

		$this->assertSame(
			"<div class=\"modal\">\n  <h2>Welcome</h2>\n  <div class=\"body\">\n  <p>Hello <b>Alice</b>!</p>\n</div>\n</div>",
			$rendered
		);
	}

	public function testComponentSlotElement(): void {
		$this->write(
			'snippets/box.liqx',
			'<div class="box"><slot /></div>'
		);

		$rendered = $this->render( '<Box><span>Inside Box</span></Box>' );
		$this->assertSame( '<div class="box"><span>Inside Box</span></div>', $rendered );
	}

	public function testComponentNamedSlots(): void {
		$this->write(
			'snippets/card.liqx',
			"<div class=\"card\">\n  {props.slots?.header && <header>{props.slots.header}</header>}\n  <div class=\"content\">{props.children}</div>\n  {props.slots?.footer && <footer>{props.slots.footer}</footer>}\n</div>"
		);

		$template = "<Card title=\"Product\">\n  <template slot=\"header\">\n    <span class=\"badge\">New</span>\n  </template>\n  <p>Main product description</p>\n  <template slot=\"footer\">\n    <button>Buy Now</button>\n  </template>\n</Card>";

		$rendered = $this->render( $template );

		$expected = "<div class=\"card\">\n  <header>\n    <span class=\"badge\">New</span>\n  </header>\n  <div class=\"content\">\n  <p>Main product description</p>\n</div>\n  <footer>\n    <button>Buy Now</button>\n  </footer>\n</div>";

		$this->assertSame( $expected, $rendered );
	}

	public function testComponentNamedSlotElements(): void {
		$this->write(
			'snippets/layout.liqx',
			"<div class=\"layout\">\n  <slot name=\"header\" />\n  <main><slot /></main>\n  <slot name=\"footer\" />\n</div>"
		);

		$template = "<Layout>\n  <template slot=\"header\"><h1>Site Header</h1></template>\n  <p>Page Content</p>\n  <template slot=\"footer\"><p>Site Footer</p></template>\n</Layout>";

		$rendered = $this->render( $template );

		$expected = "<div class=\"layout\">\n  <h1>Site Header</h1>\n  <main>\n  <p>Page Content</p>\n</main>\n  <p>Site Footer</p>\n</div>";

		$this->assertSame( $expected, $rendered );
	}

	public function testComponentSlotFallbackContent(): void {
		$this->write(
			'snippets/panel.liqx',
			"<div class=\"panel\">\n  <slot name=\"header\"><h3>Default Title</h3></slot>\n  <slot><p>Default Content</p></slot>\n</div>"
		);

		// No slot content provided -> fallbacks should render
		$renderedFallback = $this->render( '<Panel />' );
		$this->assertSame( "<div class=\"panel\">\n  <h3>Default Title</h3>\n  <p>Default Content</p>\n</div>", $renderedFallback );

		// Custom content provided -> replaces fallbacks
		$renderedCustom = $this->render( "<Panel>\n  <template slot=\"header\"><h3>Custom</h3></template>\n  <p>Custom Body</p>\n</Panel>" );
		$this->assertSame( "<div class=\"panel\">\n  <h3>Custom</h3>\n  \n  <p>Custom Body</p>\n\n</div>", $renderedCustom );
	}

	public function testPascalCaseSnippetNameResolution(): void {
		// kebab-case snippet file
		$this->write( 'snippets/product-card.liqx', '<div>ProductCard-{props.id}</div>' );

		$rendered = $this->render( '<ProductCard id={42} />' );
		$this->assertSame( '<div>ProductCard-42</div>', $rendered );
	}

	public function testComponentInsideMapExpression(): void {
		$this->write( 'snippets/card.liqx', '<div key={props.id}>{props.name}</div>' );

		$source = '<ul>{items.map(item => <Card id={item.id} name={item.name} />)}</ul>';
		$rendered = $this->render( $source, [
			'items' => [
				[ 'id' => 1, 'name' => 'A' ],
				[ 'id' => 2, 'name' => 'B' ],
			],
		] );

		$this->assertSame( '<ul><div>A</div><div>B</div></ul>', $rendered );
	}

	public function testNestedComponents(): void {
		$this->write( 'snippets/container.liqx', '<div class="container">{props.children}</div>' );
		$this->write( 'snippets/badge.liqx', '<span class="badge">{props.text}</span>' );

		$rendered = $this->render( '<Container><Badge text="VIP" /></Container>' );
		$this->assertSame( '<div class="container"><span class="badge">VIP</span></div>', $rendered );
	}

	public function testSnakeCaseSnippetNameResolution(): void {
		$this->write( 'snippets/user_profile.liqx', '<div>User-{props.username}</div>' );

		$rendered = $this->render( '<UserProfile username="john" />' );
		$this->assertSame( '<div>User-john</div>', $rendered );
	}

	public function testMissingComponentThrowsFileSystemException(): void {
		$this->expectException( \Phpmystic\Liqx\FileSystemException::class );
		$this->render( '<NonExistentComponent />' );
	}

	public function testCompiledModeWithNamedSlotsAndFallbacks(): void {
		$compiledDir = $this->root . '/compiled';
		mkdir( $compiledDir, 0777, true );
		$this->env->setCompiledTemplateDir( $compiledDir );

		$this->write(
			'snippets/dialog.liqx',
			'<div class="dialog"><slot name="header"><h3>Alert</h3></slot><div class="content"><slot /></div></div>'
		);

		$renderedFallback = $this->render( '<Dialog><p>Body only</p></Dialog>' );
		$this->assertSame( '<div class="dialog"><h3>Alert</h3><div class="content"><p>Body only</p></div></div>', $renderedFallback );

		$renderedCustom = $this->render( '<Dialog><template slot="header"><h2>Custom Header</h2></template><p>Body with header</p></Dialog>' );
		$this->assertSame( '<div class="dialog"><h2>Custom Header</h2><div class="content"><p>Body with header</p></div></div>', $renderedCustom );
	}
}
