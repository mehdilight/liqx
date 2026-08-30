<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

final class ClassModifierTest extends TestCase {

	private string $root;
	private Environment $env;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/liqx-class-mod-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/snippets', 0777, true );

		$this->env = Environment::create();
		$this->env->setSnippetFileSystem( new LocalFileSystem( $this->root . '/snippets' ) );

		file_put_contents( $this->root . '/snippets/card.liqx', "---
const className = props.class || '';
---
<div class={`card\${className ? ` \${className}` : ''}`}>{props.children}</div>" );
	}

	protected function tearDown(): void {
		$files = glob( $this->root . '/snippets/*' );
		foreach ( $files as $file ) {
			unlink( $file );
		}
		rmdir( $this->root . '/snippets' );
		rmdir( $this->root );
	}

	public function testBasicClassModifierWhenTrue(): void {
		$tpl = Template::parse( '<div class:active={true}>Hello</div>', $this->env );
		$this->assertSame( '<div class="active">Hello</div>', $tpl->render() );
	}

	public function testBasicClassModifierWhenFalse(): void {
		$tpl = Template::parse( '<div class:active={false}>Hello</div>', $this->env );
		$this->assertSame( '<div>Hello</div>', $tpl->render() );
	}

	public function testCombinesBaseClassAndModifiers(): void {
		$src = '<button class="btn" class:btn--primary={isPrimary} class:is-loading={isLoading} class:is-disabled={isDisabled}>Click</button>';
		$tpl = Template::parse( $src, $this->env );

		$res1 = $tpl->render( [ 'isPrimary' => true, 'isLoading' => true, 'isDisabled' => false ] );
		$this->assertSame( '<button class="btn btn--primary is-loading">Click</button>', $res1 );

		$res2 = $tpl->render( [ 'isPrimary' => false, 'isLoading' => false, 'isDisabled' => true ] );
		$this->assertSame( '<button class="btn is-disabled">Click</button>', $res2 );

		$res3 = $tpl->render( [ 'isPrimary' => false, 'isLoading' => false, 'isDisabled' => false ] );
		$this->assertSame( '<button class="btn">Click</button>', $res3 );
	}

	public function testDynamicBaseClassWithModifiers(): void {
		$src = '<div class={`card card--${size}`} class:active={isActive}>Content</div>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame(
			'<div class="card card--lg active">Content</div>',
			$tpl->render( [ 'size' => 'lg', 'isActive' => true ] )
		);

		$this->assertSame(
			'<div class="card card--sm">Content</div>',
			$tpl->render( [ 'size' => 'sm', 'isActive' => false ] )
		);
	}

	public function testModifiersWithSpecialCharacters(): void {
		$src = '<div class:btn--primary={true} class:text-red-500={hasError} class:opacity-50={faded}>Box</div>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame(
			'<div class="btn--primary text-red-500 opacity-50">Box</div>',
			$tpl->render( [ 'hasError' => true, 'faded' => true ] )
		);
	}

	public function testBooleanShorthandModifierWithoutValue(): void {
		$tpl = Template::parse( '<div class:active class:highlighted>Hello</div>', $this->env );
		$this->assertSame( '<div class="active highlighted">Hello</div>', $tpl->render() );
	}

	public function testClassModifiersOnComponent(): void {
		$src = '<Card class="custom" class:active={isActive} class:featured={isFeatured}>Body</Card>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame(
			'<div class="card custom active featured">Body</div>',
			$tpl->render( [ 'isActive' => true, 'isFeatured' => true ] )
		);

		$this->assertSame(
			'<div class="card custom">Body</div>',
			$tpl->render( [ 'isActive' => false, 'isFeatured' => false ] )
		);
	}

	public function testTruthyAndFalsyConditionValues(): void {
		$src = '<div class:non-empty-str={str} class:empty-str={emptyStr} class:positive-num={num} class:zero-num={zero} class:non-empty-arr={arr} class:empty-arr={emptyArr}>Edge</div>';
		$tpl = Template::parse( $src, $this->env );

		$res = $tpl->render( [
			'str' => 'hello',
			'emptyStr' => '',
			'num' => 42,
			'zero' => 0,
			'arr' => [ 1, 2 ],
			'emptyArr' => [],
		] );

		// In JS / Liqx, non-empty string, positive numbers, and arrays (even empty) are truthy; 0, '' are falsy.
		$this->assertSame( '<div class="non-empty-str positive-num non-empty-arr empty-arr">Edge</div>', $res );
	}

	public function testClassModifiersOnVoidElements(): void {
		$src = '<img src="/test.jpg" class="avatar" class:is-rounded={isRounded} />';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<img src="/test.jpg" class="avatar is-rounded" />', $tpl->render( [ 'isRounded' => true ] ) );
		$this->assertSame( '<img src="/test.jpg" class="avatar" />', $tpl->render( [ 'isRounded' => false ] ) );
	}

	public function testClassModifiersWithSpreadAttributes(): void {
		$src = '<div {...attrs} class:highlighted={isHighlighted}>Spread</div>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame(
			'<div id="main" class="highlighted">Spread</div>',
			$tpl->render( [ 'attrs' => [ 'id' => 'main' ], 'isHighlighted' => true ] )
		);
	}

	public function testCompiledModeParity(): void {
		$compiledCache = sys_get_temp_dir() . '/liqx-class-mod-cc-' . bin2hex( random_bytes( 4 ) );
		mkdir( $compiledCache, 0777, true );

		$compiledEnv = Environment::create();
		$compiledEnv->setCompiledTemplateDir( $compiledCache );

		$src = '<div class="base" class:active={isActive} class:disabled={isDisabled}>Compiled</div>';

		$res1 = Template::parse( $src, $compiledEnv )->render( [ 'isActive' => true, 'isDisabled' => false ] );
		$this->assertSame( '<div class="base active">Compiled</div>', $res1 );

		$res2 = Template::parse( $src, $compiledEnv )->render( [ 'isActive' => false, 'isDisabled' => true ] );
		$this->assertSame( '<div class="base disabled">Compiled</div>', $res2 );

		$res3 = Template::parse( $src, $compiledEnv )->render( [ 'isActive' => false, 'isDisabled' => false ] );
		$this->assertSame( '<div class="base">Compiled</div>', $res3 );

		foreach ( glob( $compiledCache . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		@rmdir( $compiledCache );
	}
}
