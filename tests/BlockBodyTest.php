<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

final class BlockBodyTest extends TestCase {

	private Environment $env;

	protected function setUp(): void {
		$this->env = Environment::create();
	}

	/** @param array<string, mixed> $data */
	private function render( string $source, array $data = [] ): string {
		return Template::parse( $source, $this->env )->render( $data );
	}

	public function testBlockBodyArrowInMap(): void {
		$source = '<ul>{items.map(x => { const d = x * 2; return d + 1; })}</ul>';
		$this->assertSame( '<ul>35</ul>', $this->render( $source, [ 'items' => [ 1, 2 ] ] ) );
	}

	public function testBlockBodyArrowWithDestructure(): void {
		$source = '<ul>{items.map(p => { const { title } = p; return <li>{title}</li>; })}</ul>';
		$this->assertSame(
			'<ul><li>Watch</li><li>Ring</li></ul>',
			$this->render( $source, [ 'items' => [ [ 'title' => 'Watch' ], [ 'title' => 'Ring' ] ] ] )
		);
	}

	public function testBlockBodyWithoutReturnYieldsNull(): void {
		$source = '{items.map(x => { const y = x + 1; })}';
		$this->assertSame( '', $this->render( $source, [ 'items' => [ 1 ] ] ) );
	}

	public function testBlockBodyCapturesOuterScope(): void {
		$source = '{items.map(x => { const y = x * factor; return y; })}';
		$this->assertSame( '48', $this->render( $source, [ 'items' => [ 2 ], 'factor' => 24 ] ) );
	}

	public function testBlockBodyAllowedAsFilterCallbackInFrontmatter(): void {
		$source = "---\nconst featured = items.filter(p => { const t = p.title | downcase; return t.includes('watch'); });\n---\n<p>{featured.length}</p>";
		$this->assertSame( '<p>1</p>', $this->render( $source, [ 'items' => [ [ 'title' => 'Watch' ], [ 'title' => 'Ring' ] ] ] ) );
	}
}