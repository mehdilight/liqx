<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\LocalFileSystem;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

final class ControlFlowTest extends TestCase {

	private Environment $env;
	private ?string $tempDir = null;

	protected function setUp(): void {
		$this->env = Environment::create();
	}

	protected function tearDown(): void {
		if ( null !== $this->tempDir && is_dir( $this->tempDir ) ) {
			foreach ( glob( $this->tempDir . '/snippets/*' ) ?: [] as $f ) {
				unlink( $f );
			}
			@rmdir( $this->tempDir . '/snippets' );
			foreach ( glob( $this->tempDir . '/*' ) ?: [] as $f ) {
				unlink( $f );
			}
			@rmdir( $this->tempDir );
		}
	}

	public function testIfTrue(): void {
		$tpl = Template::parse( '<If condition={show}><span>Visible</span></If>', $this->env );
		$this->assertSame( '<span>Visible</span>', $tpl->render( [ 'show' => true ] ) );
	}

	public function testIfFalse(): void {
		$tpl = Template::parse( '<If condition={show}><span>Visible</span></If>', $this->env );
		$this->assertSame( '', $tpl->render( [ 'show' => false ] ) );
	}

	public function testIfElse(): void {
		$src = '<If condition={isLoggedIn}>'
			. '<p>Welcome user</p>'
			. '<Else><p>Please log in</p></Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );
		$this->assertSame( '<p>Welcome user</p>', $tpl->render( [ 'isLoggedIn' => true ] ) );
		$this->assertSame( '<p>Please log in</p>', $tpl->render( [ 'isLoggedIn' => false ] ) );
	}

	public function testIfElseIfElse(): void {
		$src = '<If condition={status === "delivered"}>'
			. '<span class="delivered">Delivered</span>'
			. '<ElseIf condition={status === "shipped"}>'
			. '<span class="shipped">Shipped</span>'
			. '</ElseIf>'
			. '<ElseIf condition={status === "confirmed"}>'
			. '<span class="confirmed">Confirmed</span>'
			. '</ElseIf>'
			. '<Else>'
			. '<span class="pending">Pending</span>'
			. '</Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<span class="delivered">Delivered</span>', $tpl->render( [ 'status' => 'delivered' ] ) );
		$this->assertSame( '<span class="shipped">Shipped</span>', $tpl->render( [ 'status' => 'shipped' ] ) );
		$this->assertSame( '<span class="confirmed">Confirmed</span>', $tpl->render( [ 'status' => 'confirmed' ] ) );
		$this->assertSame( '<span class="pending">Pending</span>', $tpl->render( [ 'status' => 'unknown' ] ) );
	}

	public function testIfConditionAliases(): void {
		$src1 = '<If when={active}>Active</If>';
		$src2 = '<If cond={active}>Active</If>';
		$this->assertSame( 'Active', Template::parse( $src1, $this->env )->render( [ 'active' => true ] ) );
		$this->assertSame( 'Active', Template::parse( $src2, $this->env )->render( [ 'active' => true ] ) );
	}

	public function testShowWhenTrue(): void {
		$src = '<Show when={user} fallback={<p>No user</p>}><p>Hello {user.name}</p></Show>';
		$tpl = Template::parse( $src, $this->env );
		$this->assertSame( '<p>Hello Alice</p>', $tpl->render( [ 'user' => [ 'name' => 'Alice' ] ] ) );
	}

	public function testShowWhenFalseWithFallbackProp(): void {
		$src = '<Show when={user} fallback={<p>No user</p>}><p>Hello {user.name}</p></Show>';
		$tpl = Template::parse( $src, $this->env );
		$this->assertSame( '<p>No user</p>', $tpl->render( [ 'user' => null ] ) );
	}

	public function testShowWithSlotFallback(): void {
		$src = '<Show when={user}>'
			. '<p>Hello {user.name}</p>'
			. '<template slot="fallback"><p>Guest login</p></template>'
			. '</Show>';
		$tpl = Template::parse( $src, $this->env );
		$this->assertSame( '<p>Hello Bob</p>', $tpl->render( [ 'user' => [ 'name' => 'Bob' ] ] ) );
		$this->assertSame( '<p>Guest login</p>', $tpl->render( [ 'user' => null ] ) );
	}

	public function testSwitchWithValue(): void {
		$src = '<Switch value={role}>'
			. '<Match when="admin"><h1>Admin Panel</h1></Match>'
			. '<Match when="editor"><h2>Editor View</h2></Match>'
			. '<Default><p>User View</p></Default>'
			. '</Switch>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<h1>Admin Panel</h1>', $tpl->render( [ 'role' => 'admin' ] ) );
		$this->assertSame( '<h2>Editor View</h2>', $tpl->render( [ 'role' => 'editor' ] ) );
		$this->assertSame( '<p>User View</p>', $tpl->render( [ 'role' => 'viewer' ] ) );
	}

	public function testSwitchWithMatchDefault(): void {
		$src = '<Switch value={val}>'
			. '<Match when={1}>One</Match>'
			. '<Match when={2}>Two</Match>'
			. '<Match default>Other</Match>'
			. '</Switch>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( 'One', $tpl->render( [ 'val' => 1 ] ) );
		$this->assertSame( 'Two', $tpl->render( [ 'val' => 2 ] ) );
		$this->assertSame( 'Other', $tpl->render( [ 'val' => 99 ] ) );
	}

	public function testSwitchBooleanConditions(): void {
		$src = '<Switch>'
			. '<Match when={score >= 90}><b>Grade A</b></Match>'
			. '<Match when={score >= 75}><b>Grade B</b></Match>'
			. '<Match when={score >= 50}><b>Grade C</b></Match>'
			. '<Default><b>Fail</b></Default>'
			. '</Switch>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<b>Grade A</b>', $tpl->render( [ 'score' => 95 ] ) );
		$this->assertSame( '<b>Grade B</b>', $tpl->render( [ 'score' => 80 ] ) );
		$this->assertSame( '<b>Grade C</b>', $tpl->render( [ 'score' => 60 ] ) );
		$this->assertSame( '<b>Fail</b>', $tpl->render( [ 'score' => 30 ] ) );
	}

	public function testNestedIfInElseIfAndElse(): void {
		$src = '<If condition={level === "admin"}>'
			. '<p>Admin</p>'
			. '<ElseIf condition={level === "user"}>'
			. '<If condition={isVerified}>'
			. '<p>Verified User</p>'
			. '<Else>'
			. '<p>Unverified User</p>'
			. '</Else>'
			. '</If>'
			. '</ElseIf>'
			. '<Else>'
			. '<p>Guest</p>'
			. '</Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<p>Admin</p>', $tpl->render( [ 'level' => 'admin', 'isVerified' => false ] ) );
		$this->assertSame( '<p>Verified User</p>', $tpl->render( [ 'level' => 'user', 'isVerified' => true ] ) );
		$this->assertSame( '<p>Unverified User</p>', $tpl->render( [ 'level' => 'user', 'isVerified' => false ] ) );
		$this->assertSame( '<p>Guest</p>', $tpl->render( [ 'level' => 'guest', 'isVerified' => false ] ) );
	}

	public function testSwitchInsideIf(): void {
		$src = '<If condition={isEnabled}>'
			. '<Switch value={mode}>'
			. '<Match when="dark"><span>Night</span></Match>'
			. '<Match when="light"><span>Day</span></Match>'
			. '<Default><span>Auto</span></Default>'
			. '</Switch>'
			. '<Else>'
			. '<span>Disabled</span>'
			. '</Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<span>Night</span>', $tpl->render( [ 'isEnabled' => true, 'mode' => 'dark' ] ) );
		$this->assertSame( '<span>Day</span>', $tpl->render( [ 'isEnabled' => true, 'mode' => 'light' ] ) );
		$this->assertSame( '<span>Auto</span>', $tpl->render( [ 'isEnabled' => true, 'mode' => 'custom' ] ) );
		$this->assertSame( '<span>Disabled</span>', $tpl->render( [ 'isEnabled' => false, 'mode' => 'dark' ] ) );
	}

	public function testIfInsideMapLoop(): void {
		$src = '<ul>'
			. '{items.map(item => ('
			. '<If condition={item.visible}>'
			. '<li key={item.id}>{item.name}</li>'
			. '<Else>'
			. '<li class="hidden" key={item.id}>Hidden</li>'
			. '</Else>'
			. '</If>'
			. '))}'
			. '</ul>';
		$tpl = Template::parse( $src, $this->env );

		$data = [
			'items' => [
				[ 'id' => 1, 'name' => 'First', 'visible' => true ],
				[ 'id' => 2, 'name' => 'Second', 'visible' => false ],
				[ 'id' => 3, 'name' => 'Third', 'visible' => true ],
			],
		];
		$this->assertSame( '<ul><li>First</li><li class="hidden">Hidden</li><li>Third</li></ul>', $tpl->render( $data ) );
	}

	public function testControlFlowWithSvelteClassModifiers(): void {
		$src = '<If condition={showButton}>'
			. '<button class="btn" class:btn--primary={isPrimary} class:is-loading={loading}>Click</button>'
			. '<Else>'
			. '<span class="label" class:text-muted={muted}>Disabled</span>'
			. '</Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );

		$res1 = $tpl->render( [ 'showButton' => true, 'isPrimary' => true, 'loading' => true, 'muted' => false ] );
		$this->assertSame( '<button class="btn btn--primary is-loading">Click</button>', $res1 );

		$res2 = $tpl->render( [ 'showButton' => false, 'isPrimary' => false, 'loading' => false, 'muted' => true ] );
		$this->assertSame( '<span class="label text-muted">Disabled</span>', $res2 );
	}

	public function testControlFlowWithCustomComponentsAndSlots(): void {
		$this->tempDir = sys_get_temp_dir() . '/liqx-cf-comp-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir . '/snippets', 0777, true );

		file_put_contents( $this->tempDir . '/snippets/card.liqx', '---
const { title = "Default" } = props;
---
<div class="card"><h3>{title}</h3><div class="body"><slot /></div></div>' );

		$this->env->setSnippetFileSystem( new LocalFileSystem( $this->tempDir . '/snippets' ) );

		$src = '<If condition={hasCard}>'
			. '<Card title="My Card"><p>Card Content</p></Card>'
			. '<Else>'
			. '<p>No card</p>'
			. '</Else>'
			. '</If>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<div class="card"><h3>My Card</h3><div class="body"><p>Card Content</p></div></div>', $tpl->render( [ 'hasCard' => true ] ) );
		$this->assertSame( '<p>No card</p>', $tpl->render( [ 'hasCard' => false ] ) );
	}

	public function testTruthinessRulesInIf(): void {
		$src = '<If condition={val}>Truthy</If>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '', $tpl->render( [ 'val' => 0 ] ) );
		$this->assertSame( '', $tpl->render( [ 'val' => '0' ] ) );
		$this->assertSame( '', $tpl->render( [ 'val' => '' ] ) );
		$this->assertSame( '', $tpl->render( [ 'val' => null ] ) );
		$this->assertSame( '', $tpl->render( [ 'val' => false ] ) );

		$this->assertSame( 'Truthy', $tpl->render( [ 'val' => [] ] ) );
		$this->assertSame( 'Truthy', $tpl->render( [ 'val' => [ 0 ] ] ) );
		$this->assertSame( 'Truthy', $tpl->render( [ 'val' => 'false' ] ) );
		$this->assertSame( 'Truthy', $tpl->render( [ 'val' => 1 ] ) );
	}

	public function testSwitchLooseTypeMatching(): void {
		$src = '<Switch value={val}>'
			. '<Match when={1}><span>Matched Number</span></Match>'
			. '<Default><span>Default</span></Default>'
			. '</Switch>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<span>Matched Number</span>', $tpl->render( [ 'val' => 1 ] ) );
		$this->assertSame( '<span>Matched Number</span>', $tpl->render( [ 'val' => '1' ] ) );
		$this->assertSame( '<span>Default</span>', $tpl->render( [ 'val' => 2 ] ) );
	}

	public function testSelfClosingControlFlowElements(): void {
		$src = '<div><If condition={true} /><Show when={false} /><Switch value="x" /></div>';
		$tpl = Template::parse( $src, $this->env );
		$this->assertSame( '<div></div>', $tpl->render() );
	}

	public function testControlFlowInsideExpressionAtom(): void {
		$src = '<div>{ready ? <Show when={isOk} fallback="ERR">OK</Show> : "Loading..."}</div>';
		$tpl = Template::parse( $src, $this->env );

		$this->assertSame( '<div>OK</div>', $tpl->render( [ 'ready' => true, 'isOk' => true ] ) );
		$this->assertSame( '<div>ERR</div>', $tpl->render( [ 'ready' => true, 'isOk' => false ] ) );
		$this->assertSame( '<div>Loading...</div>', $tpl->render( [ 'ready' => false, 'isOk' => true ] ) );
	}

	public function testFrontmatterCalculationsInControlFlow(): void {
		$src = "---
const prices = items.map(item => item.price);
const total = prices | sum;
const qualifies = total >= 100;
---
<If condition={qualifies}>
  <span>Free Shipping (total: {total})</span>
<Else>
  <span>Add more items (total: {total})</span>
</Else>
</If>";
		$tpl = Template::parse( $src, $this->env );

		$res1 = $tpl->render( [ 'items' => [ [ 'price' => 60 ], [ 'price' => 50 ] ] ] );
		$this->assertStringContainsString( 'Free Shipping (total: 110)', $res1 );

		$res2 = $tpl->render( [ 'items' => [ [ 'price' => 20 ], [ 'price' => 30 ] ] ] );
		$this->assertStringContainsString( 'Add more items (total: 50)', $res2 );
	}

	public function testCompiledModeParity(): void {
		$cacheDir = sys_get_temp_dir() . '/liqx-cf-compile-' . bin2hex( random_bytes( 4 ) );
		mkdir( $cacheDir, 0777, true );

		$compiledEnv = Environment::create();
		$compiledEnv->setCompiledTemplateDir( $cacheDir );

		$src = '<div>'
			. '<If condition={count > 5}>'
			. '<span>Many ({count})</span>'
			. '<ElseIf condition={count > 0}>'
			. '<span>Few ({count})</span>'
			. '</ElseIf>'
			. '<Else>'
			. '<span>None</span>'
			. '</Else>'
			. '</If>'
			. '<Show when={ready} fallback="Waiting...">'
			. 'Ready!'
			. '</Show>'
			. '<Switch value={status}>'
			. '<Match when="ok">OK</Match>'
			. '<Default>ERR</Default>'
			. '</Switch>'
			. '</div>';

		$data1 = [ 'count' => 10, 'ready' => true, 'status' => 'ok' ];
		$data2 = [ 'count' => 2, 'ready' => false, 'status' => 'other' ];
		$data3 = [ 'count' => 0, 'ready' => true, 'status' => 'err' ];

		$interp = Template::parse( $src, $this->env );
		$comp = Template::parse( $src, $compiledEnv );

		$this->assertSame( $interp->render( $data1 ), $comp->render( $data1 ) );
		$this->assertSame( $interp->render( $data2 ), $comp->render( $data2 ) );
		$this->assertSame( $interp->render( $data3 ), $comp->render( $data3 ) );

		foreach ( glob( $cacheDir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		rmdir( $cacheDir );
	}
}
