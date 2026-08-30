<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use PHPUnit\Framework\TestCase;
use Phpmystic\Liqx\CompiledTemplate;
use Phpmystic\Liqx\Compiler;
use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;

final class NamedTemplatesTest extends TestCase {

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

		$compiledTpl = CompiledTemplate::fromSource( $this->compiler->compile( $tpl->document() ) );
		$compiled = $compiledTpl->render( $this->env, $data );

		$this->assertSame( $interpreted, $compiled, 'Interpreted and compiled output must match' );

		return $interpreted;
	}

	public function testBasicNamedTemplateSubComponent(): void {
		$source = <<<'LIQX'
<template name="Badge">
  <span class="badge">{text}</span>
</template>

<template>
  <div class="product">
    <Badge text="New Arrival" />
  </div>
</template>
LIQX;

		$this->assertSame(
			'<div class="product"><span class="badge">New Arrival</span></div>',
			preg_replace( '/>\s+</', '><', trim( $this->render( $source ) ) )
		);
	}

	public function testMultipleNamedTemplates(): void {
		$source = <<<'LIQX'
<template name="Price">
  <div class="price">
    <span class="current">{price | money}</span>
    <If condition={compare_at > price}>
      <s class="old">{compare_at | money}</s>
    </If>
  </div>
</template>

<template name="Rating">
  <div class="stars">
    <span class="score">{score} / 5</span>
  </div>
</template>

<template>
  <div class="card">
    <Rating score={4.8} />
    <Price price={1999} compare_at={2499} />
  </div>
</template>
LIQX;

		$out = preg_replace( '/>\s+</', '><', trim( $this->render( $source ) ) );
		$this->assertStringContainsString( '<span class="score">4.8 / 5</span>', $out );
		$this->assertStringContainsString( '<span class="current">19.99 MAD</span>', $out );
		$this->assertStringContainsString( '<s class="old">24.99 MAD</s>', $out );
	}

	public function testNamedTemplateWithChildrenAndSlots(): void {
		$source = <<<'LIQX'
<template name="Card">
  <div class="card">
    <div class="card-header">{header}</div>
    <div class="card-body">{children}</div>
  </div>
</template>

<template>
  <Card header="Featured Product">
    <p>Product description goes here.</p>
  </Card>
</template>
LIQX;

		$out = preg_replace( '/>\s+</', '><', trim( $this->render( $source ) ) );
		$this->assertStringContainsString( '<div class="card-header">Featured Product</div>', $out );
		$this->assertStringContainsString( '<div class="card-body"><p>Product description goes here.</p></div>', $out );
	}

	public function testNamedTemplateWithSpreadAndClasses(): void {
		$source = <<<'LIQX'
<template name="Button">
  <button type="button" class="btn" class:active={active} class:btn--lg={size === 'lg'}>{label}</button>
</template>

<template>
  <div class="actions">
    <Button label="Click Me" active={true} size="lg" />
  </div>
</template>
LIQX;

		$out = preg_replace( '/>\s+</', '><', trim( $this->render( $source ) ) );
		$this->assertSame(
			'<div class="actions"><button type="button" class="btn active btn--lg">Click Me</button></div>',
			$out
		);
	}

	public function testNamedTemplateWithFrontmatterInRoot(): void {
		$source = <<<'LIQX'
---
const currentProduct = product;
---
<template name="ProductPrice">
  <div class="price">
    <span>{item.price | money}</span>
  </div>
</template>

<template>
  <div class="product-page">
    <h1>{currentProduct.title}</h1>
    <ProductPrice item={currentProduct} />
  </div>
</template>
LIQX;

		$data = [
			'product' => [
				'title' => 'Silk Dress',
				'price' => 8900,
			],
		];

		$out = preg_replace( '/>\s+</', '><', trim( $this->render( $source, $data ) ) );
		$this->assertStringContainsString( '<h1>Silk Dress</h1>', $out );
		$this->assertStringContainsString( '<span>89.00 MAD</span>', $out );
	}
}
