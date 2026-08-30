<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Parser;
use Phpmystic\Liqx\StandardFilters;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

/**
 * Keeps `docs/syntax/` honest: every `liqx` snippet in the reference must parse
 * (and, where it is a runnable document, render) against the real engine, and
 * the filter reference must list every registered filter.
 *
 * Without this, the docs drift silently — a grammar change makes an example
 * wrong and nothing fails.
 */
final class DocsSyntaxTest extends TestCase {

	private const DOCS = __DIR__ . '/../docs/syntax';

	/**
	 * Snippets that illustrate input → output, or a deliberate mistake, rather
	 * than being source to run. Marked by a `→` / `✗` in the block.
	 */
	private function isIllustrative( string $block ): bool {
		return str_contains( $block, '→' ) || str_contains( $block, '✗' );
	}

	/** @return array<string, list<array{int, string}>> file => [ [ ordinal, block ] ] */
	private function blocks(): array {
		$found = [];

		foreach ( glob( self::DOCS . '/*.md' ) as $file ) {
			$source = file_get_contents( $file );

			if ( false === $source || ! preg_match_all( '/```liqx\n(.*?)```/s', $source, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $index => $block ) {
				$found[ basename( $file ) ][] = [ $index + 1, $block ];
			}
		}

		return $found;
	}

	public function testEveryDocumentedSnippetParses(): void {
		$failures = [];
		$parsed   = 0;

		foreach ( $this->blocks() as $file => $blocks ) {
			foreach ( $blocks as [ $ordinal, $block ] ) {
				$parsed++;

				try {
					( new Parser() )->parse( $block );
				} catch ( \Throwable $e ) {
					$failures[] = sprintf( '%s block #%d: %s', $file, $ordinal, $e->getMessage() );
				}
			}
		}

		$this->assertSame( [], $failures, "Documented snippets that no longer parse:\n" . implode( "\n", $failures ) );
		$this->assertGreaterThan( 40, $parsed, 'the doc scanner found suspiciously few snippets — did the fence format change?' );
	}

	public function testEveryRunnableSnippetRenders(): void {
		$environment = Environment::create();

		foreach ( [ 'img_url', 't', 'asset_url' ] as $hostFilter ) {
			$environment->registerFilter( $hostFilter, static fn ( mixed $value ): mixed => $value );
		}

		// The docs reference `render()`/`section()` and `<Component />`; stub them so a snippet that
		// composes does not need a real filesystem.
		$environment->setSnippetFileSystem( new class implements \Phpmystic\Liqx\FileSystem {
			public function load( string $name ): string {
				return '<div>{props.children}</div>';
			}
		} );
		$environment->registerGlobal( 'render', static fn ( string $name, array $props = [] ): string => '' );
		$environment->registerGlobal( 'section', static fn ( string $name ): string => '' );

		$failures = [];

		foreach ( $this->blocks() as $file => $blocks ) {
			foreach ( $blocks as [ $ordinal, $block ] ) {
				if ( $this->isIllustrative( $block ) ) {
					continue;
				}

				try {
					Template::parse( $block, $environment )->render( $this->sampleData() );
				} catch ( \Throwable $e ) {
					$failures[] = sprintf( '%s block #%d: %s', $file, $ordinal, $e->getMessage() );
				}
			}
		}

		$this->assertSame( [], $failures, "Documented snippets that no longer render:\n" . implode( "\n", $failures ) );
	}

	/**
	 * A `---` fence is only recognised at the very start of the source, so a doc
	 * snippet that shows frontmatter must not indent or precede it — otherwise
	 * the example silently teaches the wrong thing.
	 *
	 * A declaration at column 0 is the frontmatter tell; an indented one is a
	 * block-body arrow's local, which is a different construct.
	 */
	public function testFrontmatterSnippetsPutTheFenceFirst(): void {
		$failures = [];

		foreach ( $this->blocks() as $file => $blocks ) {
			foreach ( $blocks as [ $ordinal, $block ] ) {
				if ( $this->isIllustrative( $block ) || ! preg_match( '/^(const|let|return)\s/m', $block ) ) {
					continue;
				}

				if ( ! str_starts_with( $block, '---' ) ) {
					$failures[] = sprintf( '%s block #%d: declares a top-level const/let/return but does not open with a --- fence', $file, $ordinal );
				}
			}
		}

		$this->assertSame( [], $failures, "Frontmatter snippets with an unreachable fence:\n" . implode( "\n", $failures ) );
	}

	public function testFilterReferenceListsEveryRegisteredFilter(): void {
		$reference = file_get_contents( self::DOCS . '/05-filters.md' );
		$this->assertIsString( $reference );

		$undocumented = [];

		foreach ( array_keys( StandardFilters::all() ) as $name ) {
			// A camelCase alias counts as documented when its snake_case twin is.
			$snake = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $name ) );

			foreach ( [ $name, $snake ] as $candidate ) {
				if ( 1 === preg_match( '/\b' . preg_quote( $candidate, '/' ) . '\b/', $reference ) ) {
					continue 2;
				}
			}

			$undocumented[] = $name;
		}

		$this->assertSame( [], $undocumented, 'Filters registered but missing from docs/syntax/05-filters.md: ' . implode( ', ', $undocumented ) );
	}

	/** @return array<string, mixed> */
	private function sampleData(): array {
		$product = [ 'id' => 1, 'title' => 'Watch', 'price' => 100, 'image' => '/p.png', 'url' => '/p', 'tags' => [ 'sale' ] ];

		return [
			'name'      => 'Ada',
			'title'     => 'T',
			'heading'   => 'H',
			'count'     => 2,
			'show'      => true,
			'isActive'  => true,
			'error'     => null,
			'cond'      => true,
			'ready'     => true,
			'start'     => 0,
			'end'       => 4102444800,
			'x'         => 1234.56,
			'a'         => 1,
			'b'         => 2,
			'url'       => '/i.png',
			'layout'    => 'left',
			'menu'      => 'M',
			'price'     => 10,
			'quantity'  => 2,
			'greeting'  => 'Hi',
			'asset_url' => '/a.js',
			'arr'       => [ 1, 2, 3 ],
			'obj'       => [ 'prop' => 'P' ],
			'tags'      => [ 'sale' ],
			'user'      => [ 'name' => 'Ada', 'url' => '/u', 'profile' => [ 'city' => 'Fez' ] ],
			'cart'      => [ 'items' => [ 1, 2 ] ],
			'routes'    => [ 'cart_url' => '/c' ],
			'shop'      => [ 'name' => 'S' ],
			'article'   => [ 'published_at' => 0 ],
			'rows'      => [ [ 'label' => 'L' ] ],
			'maybe'     => [ 'items' => [] ],
			'attrs'      => [ 'class' => 'c' ],
			'modalAttrs' => [ 'class' => 'm' ],
			'product'    => $product,
			'featured'  => $product,
			'products'  => [ $product + [ 'tags' => [ 'featured' ] ] ],
			'items'     => [ [ 'id' => 1, 'name' => 'n', 'active' => true, 'title' => 'Watch', 'url' => '/x', 'inStock' => true ] ],
			'block'     => [ 'title' => 'BT', 'type' => 'text', 'text' => 'T', 'src' => '/s.png', 'attrs' => [] ],
			'blocks'    => [ [ 'type' => 'text', 'attrs' => [], 'settings' => [ 'text' => 'T' ] ] ],
			'props'     => [ 'label' => 'L', 'product' => $product ],
			'settings'  => [ 'heading' => 'Hd', 'bg_color' => 'red', 'layout' => 'w', 'start' => 0, 'end' => 4102444800 ],
			'section'   => [
				'id'                 => 1,
				'name'               => 'header',
				'lithos_attributes'  => ' data-s="1"',
				'settings'           => [ 'heading' => 'Hd', 'bg_color' => 'red', 'start' => 0, 'end' => 4102444800 ],
				'blocks'             => [ [ 'type' => 'text', 'attrs' => [], 'settings' => [ 'text' => 'T' ] ] ],
			],
			'root'      => [ 'shop' => [ 'name' => 'S' ], 'block' => [ 'title' => 'B' ] ],
		];
	}
}
