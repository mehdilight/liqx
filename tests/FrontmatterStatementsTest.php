<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Compiler;
use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

final class FrontmatterStatementsTest extends TestCase {

	private Environment $env;
	private Compiler $compiler;

	protected function setUp(): void {
		$this->env = Environment::create();
		$this->compiler = new Compiler();
	}

	/** @param array<string, mixed> $data */
	private function render( string $source, array $data = [] ): string {
		$tpl = Template::parse( $source, $this->env );
		$interpreted = $tpl->render( $data );

		// Also verify compiled PHP parity
		$compiledTpl = \Phpmystic\Liqx\CompiledTemplate::fromSource( $this->compiler->compile( $tpl->document() ) );
		$compiled = $compiledTpl->render( $this->env, $data );

		$this->assertSame( $interpreted, $compiled, 'Interpreted and compiled output must match' );

		return $interpreted;
	}

	public function testCompoundDivisionAndModulo(): void {
		$source = "---\nlet value = 21;\nvalue /= 3;\nvalue %= 4;\n---\n<p>{value}</p>";

		$this->assertSame( '<p>3</p>', trim( $this->render( $source ) ) );
	}

	// ---------------------------------------------------------------------
	// Feature 1: if / else if / else in Frontmatter
	// ---------------------------------------------------------------------

	public function testIfElseInFrontmatter(): void {
		$source = <<<'LIQX'
---
let status = 'guest';
if (user && user.is_admin) {
  status = 'admin';
} else if (user) {
  status = 'member';
} else {
  status = 'anonymous';
}
---
<template>
  <p>{status}</p>
</template>
LIQX;

		$this->assertSame( "<p>admin</p>", trim( $this->render( $source, [ 'user' => [ 'is_admin' => true ] ] ) ) );
		$this->assertSame( "<p>member</p>", trim( $this->render( $source, [ 'user' => [ 'is_admin' => false ] ] ) ) );
		$this->assertSame( "<p>anonymous</p>", trim( $this->render( $source, [ 'user' => null ] ) ) );
	}

	public function testIfWithBlockDeclarations(): void {
		$source = <<<'LIQX'
---
let finalPrice = product.price;
if (product.on_sale) {
  const discount = product.price * 0.2;
  finalPrice = product.price - discount;
}
---
<template>
  <span>{finalPrice}</span>
</template>
LIQX;

		$this->assertSame( "<span>80</span>", trim( $this->render( $source, [ 'product' => [ 'price' => 100, 'on_sale' => true ] ] ) ) );
		$this->assertSame( "<span>100</span>", trim( $this->render( $source, [ 'product' => [ 'price' => 100, 'on_sale' => false ] ] ) ) );
	}

	public function testIfEarlyReturnGuardClause(): void {
		$source = <<<'LIQX'
---
if (!product || !product.available) {
  return;
}
const title = product.title;
---
<template>
  <div class="product">{title}</div>
</template>
LIQX;

		$this->assertSame( '<div class="product">Lipstick</div>', trim( $this->render( $source, [ 'product' => [ 'title' => 'Lipstick', 'available' => true ] ] ) ) );
		$this->assertSame( '', trim( $this->render( $source, [ 'product' => [ 'title' => 'Lipstick', 'available' => false ] ] ) ) );
		$this->assertSame( '', trim( $this->render( $source, [ 'product' => null ] ) ) );
	}

	// ---------------------------------------------------------------------
	// Feature 1: switch / case / default in Frontmatter
	// ---------------------------------------------------------------------

	public function testSwitchCaseInFrontmatter(): void {
		$source = <<<'LIQX'
---
let bg = '#ffffff';
let text = '#000000';

switch (scheme) {
  case 'dark':
    bg = '#111111';
    text = '#ffffff';
    break;
  case 'accent':
    bg = '#b3283f';
    text = '#ffe9f4';
    break;
  default:
    bg = '#f8f8f8';
    text = '#222222';
}
---
<template>
  <div style={`background:${bg};color:${text};`}>Content</div>
</template>
LIQX;

		$this->assertSame( '<div style="background:#111111;color:#ffffff;">Content</div>', trim( $this->render( $source, [ 'scheme' => 'dark' ] ) ) );
		$this->assertSame( '<div style="background:#b3283f;color:#ffe9f4;">Content</div>', trim( $this->render( $source, [ 'scheme' => 'accent' ] ) ) );
		$this->assertSame( '<div style="background:#f8f8f8;color:#222222;">Content</div>', trim( $this->render( $source, [ 'scheme' => 'other' ] ) ) );
	}

	// ---------------------------------------------------------------------
	// Feature 5: Local Functions & Block-body Arrow Helpers
	// ---------------------------------------------------------------------

	public function testLocalFunctionDeclaration(): void {
		$source = <<<'LIQX'
---
function formatMoney(amount, currency = 'MAD') {
  if (amount == null || amount === 0) {
    return 'Free';
  }
  return amount + '.00 ' + currency;
}

const priceTag = formatMoney(product.price);
---
<template>
  <div class="price">
    <span>{priceTag}</span>
    <small>{formatMoney(product.compare_at)}</small>
  </div>
</template>
LIQX;

		$this->assertSame(
			"<div class=\"price\">\n    <span>150.00 MAD</span>\n    <small>200.00 MAD</small>\n  </div>",
			trim( $this->render( $source, [ 'product' => [ 'price' => 150, 'compare_at' => 200 ] ] ) )
		);

		$this->assertSame(
			"<div class=\"price\">\n    <span>Free</span>\n    <small>Free</small>\n  </div>",
			trim( $this->render( $source, [ 'product' => [ 'price' => 0, 'compare_at' => null ] ] ) )
		);
	}

	public function testArrowFunctionAssignedToConst(): void {
		$source = <<<'LIQX'
---
const getBadgeClass = (status) => {
  if (status === 'active') return 'badge-green';
  if (status === 'pending') return 'badge-yellow';
  return 'badge-gray';
};
---
<template>
  <span class={getBadgeClass(item.status)}>{item.name}</span>
</template>
LIQX;

		$this->assertSame(
			'<span class="badge-green">Item A</span>',
			trim( $this->render( $source, [ 'item' => [ 'status' => 'active', 'name' => 'Item A' ] ] ) )
		);
		$this->assertSame(
			'<span class="badge-yellow">Item B</span>',
			trim( $this->render( $source, [ 'item' => [ 'status' => 'pending', 'name' => 'Item B' ] ] ) )
		);
	}
}
