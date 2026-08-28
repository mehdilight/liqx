<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Context;
use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

/**
 * Section/snippet composition and the host-facing extension points: custom
 * globals (the "tags" of Liqx), custom filters, and the FileSystem hook for
 * named partials.
 */
final class CompositionTest extends TestCase {

	private string $root;

	private Environment $env;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/liqx-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/sections', 0777, true );
		mkdir( $this->root . '/snippets', 0777, true );

		$this->env = Environment::create();
		$this->env->setSnippetFileSystem( new LocalFileSystem( $this->root . '/snippets' ) );
		$this->env->setSectionFileSystem( new LocalFileSystem( $this->root . '/sections' ) );
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

	public function testRenderSnippetWithProps(): void {
		$this->write(
			'snippets/card.liqx',
			"---\nconst { product } = props;\n---\n<div class=\"card\">\n  <h3>{product.title}</h3>\n  <span>{product.price | money}</span>\n</div>"
		);

		$rendered = $this->render( '<main>{render("card", { product: featured })}</main>', [
			'featured' => [ 'title' => 'Widget', 'price' => 1000 ],
		] );

		$this->assertSame(
			'<main><div class="card">' . "\n  " . '<h3>Widget</h3>' . "\n  " . '<span>10.00 MAD</span>' . "\n" . '</div></main>',
			$rendered
		);
	}

	public function testRenderSnippetWithDefaultProps(): void {
		$this->write( 'snippets/badge.liqx', "<span class=\"badge\">{props.label}</span>" );

		$this->assertSame( '<span class="badge">New</span>', $this->render( '{render("badge", { label: "New" })}' ) );
	}

	public function testSectionBuiltInGlobal(): void {
		$this->write( 'sections/header.liqx', "<h1>{section.name}</h1>" );

		$this->assertSame( '<h1>header</h1>', $this->render( '{section("header")}' ) );
	}

	public function testSectionGlobalIsOverridableByHost(): void {
		$this->write( 'sections/header.liqx', "---\nconst { settings } = section;\n---\n<h1>{settings.heading}</h1>" );

		// The host resolves the merchant's saved settings for this instance.
		$this->env->registerGlobal( 'section', function ( string $name ) {
			return $this->env->renderPartial( $name, new LocalFileSystem( $this->root . '/sections' ), [
				'section' => [ 'settings' => [ 'heading' => 'Sale' ] ],
			] );
		} );

		$this->assertSame( '<h1>Sale</h1>', $this->render( '{section("header")}' ) );
	}

	public function testSectionRenderingNestedSnippet(): void {
		$this->write( 'snippets/card.liqx', "<div class=\"card\">{props.product.title}</div>" );
		$this->write( 'sections/hero.liqx', "<section>{render('card', { product: { title: 'Alpha' } })}</section>" );

		$this->assertSame( '<section><div class="card">Alpha</div></section>', $this->render( '{section("hero")}' ) );
	}

	public function testSectionGlobalSharesParentScope(): void {
		$this->write( 'sections/header.liqx', '<h1>{shop.name}</h1><span>{section.name}</span>' );

		$this->assertSame(
			'<h1>Cein</h1><span>header</span>',
			$this->render( '{section("header")}', [ 'shop' => [ 'name' => 'Cein' ] ] )
		);
	}

	public function testRenderSnippetStaysIsolated(): void {
		$this->write( 'snippets/card.liqx', '<p>{props.label}</p>' );

		// A parent variable must NOT leak into an explicit-props snippet.
		$this->assertSame( '<p>explicit</p>', $this->render( '{render("card", { label: "explicit" })}', [ 'label' => 'leaked' ] ) );
	}

	public function testCustomGlobalIsLikeALiquidTag(): void {
		$this->env->registerGlobal( 'badge', static fn ( string $text ) => '<span class="badge">' . $text . '</span>' );

		$this->assertSame( '<span class="badge">New</span>', $this->render( '{badge("New")}' ) );
	}

	public function testCustomGlobalReceivingContext(): void {
		$this->env->registerGlobal( 'site', static fn ( Context $ctx, string $key ) => $ctx->lookup( $key )['value'] );

		$this->assertSame( 'My Shop', $this->render( '{site("name")}', [ 'name' => 'My Shop' ] ) );
	}

	public function testCustomFilter(): void {
		$this->env->registerFilter( 'discount', static fn ( mixed $price, mixed $percent ) => (int) $price * ( 1 - (int) $percent / 100 ) );

		$this->assertSame( '80', $this->render( '{price | discount(20)}', [ 'price' => 100 ] ) );
	}

	public function testBuiltInGlobalCanBeOverridden(): void {
		$this->write( 'snippets/card.liqx', '<div>original</div>' );

		$this->env->registerGlobal( 'render', static fn ( string $name, array $props = [] ) => 'CUSTOM(' . $name . ')' );

		$this->assertSame( 'CUSTOM(card)', $this->render( '{render("card")}' ) );
	}

	public function testSectionWithoutFileSystemThrows(): void {
		$env = Environment::create();

		$this->expectException( \RuntimeException::class );
		Template::parse( '{section("header")}', $env )->render();
	}

	public function testSnippetWithoutFileSystemThrows(): void {
		$env = Environment::create();

		$this->expectException( \RuntimeException::class );
		Template::parse( '{render("card")}', $env )->render();
	}

	public function testDropAnswersLookups(): void {
		$product = new class( [ 'title' => 'Widget', 'price' => 1000 ] ) extends \Phpmystic\Liqx\Drop {
			/** @param array<string, mixed> $data */
			public function __construct( private array $data ) {}

			public function beforeMethod( string $method ): mixed {
				return $this->data[ $method ] ?? null;
			}
		};

		$this->assertSame(
			'<h3>Widget</h3><span>10.00 MAD</span>',
			$this->render( '<h3>{product.title}</h3><span>{product.price | money}</span>', [ 'product' => $product ] )
		);
	}

	public function testDropWithoutExtendingLiqxDropWorks(): void {
		// Sworen's drops extend their own engine Drop, not Phpmystic\Liqx\Drop —
		// the evaluator must duck-type beforeMethod, not instanceof.
		$product = new class {
			public function beforeMethod( string $method ): mixed {
				return 'title' === $method ? 'Widget' : null;
			}
		};

		$this->assertSame( '<h3>Widget</h3>', $this->render( '<h3>{product.title}</h3>', [ 'product' => $product ] ) );
	}

	public function testDropWithSnakeCaseAndCamelCaseLookups(): void {
		$section = new class {
			public function beforeMethod( string $method ): mixed {
				return match ( $method ) {
					'id' => 7,
					'lithos_attributes' => ' data-section="7"',
					default => null,
				};
			}
		};

		$this->assertSame(
			'<div data-section="7">7</div>',
			$this->render( '<div{section.lithos_attributes}>{section.id}</div>', [ 'section' => $section ] )
		);
	}

	public function testObjectStringifiesForOutput(): void {
		$image = new class {
			public function __toString(): string {
				return '/img.jpg';
			}
		};

		$this->assertSame( '<img src="/img.jpg" />', $this->render( '<img src={image} />', [ 'image' => $image ] ) );
	}

	public function testPrivatePropertiesFallThroughToBeforeMethod(): void {
		$drop = new class {
			public function beforeMethod( string $method ): mixed {
				return 'locale' === $method ? 'fr' : null;
			}
		};

		$this->assertSame( '<div lang="fr"></div>', $this->render( '<div lang={drop.locale}></div>', [ 'drop' => $drop ] ) );
	}

	public function testPublicPropertiesAreReadDirectly(): void {
		$drop = new class {
			public string $title = 'Widget';
		};

		$this->assertSame( '<h1>Widget</h1>', $this->render( '<h1>{drop.title}</h1>', [ 'drop' => $drop ] ) );
	}

	public function testDestructureFromDropObject(): void {
		$section = new class {
			public function beforeMethod( string $method ): mixed {
				return 'settings' === $method ? [ 'heading' => 'Sale' ] : null;
			}
		};

		$source = "---\nconst { settings } = section;\n---\n<p>{settings.heading}</p>";
		$this->assertSame( '<p>Sale</p>', $this->render( $source, [ 'section' => $section ] ) );
	}

	public function testMissingPartialThrows(): void {
		$this->expectException( \Phpmystic\Liqx\FileSystemException::class );
		$this->render( '{render("does-not-exist")}' );
	}
}