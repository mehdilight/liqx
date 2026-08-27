<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Tests;

use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;
use PHPUnit\Framework\TestCase;

/**
 * The pre-included filter set must mirror phpmystic/liquid's standard set —
 * generic, language-level filters only. Domain filters (img_url, t,
 * asset_url, …) are the host's job, not the library's.
 */
final class StandardFiltersTest extends TestCase {

	private Environment $env;

	protected function setUp(): void {
		$this->env = Environment::create();
	}

	/** @param array<string, mixed> $data */
	private function pipe( string $expr, array $data = [] ): string {
		return Template::parse( '{' . $expr . '}', $this->env )->render( $data );
	}

	public function testStringCaseFilters(): void {
		$this->assertSame( 'HELLO', $this->pipe( 'x | upcase', [ 'x' => 'hello' ] ) );
		$this->assertSame( 'hello', $this->pipe( 'x | downcase', [ 'x' => 'HeLLo' ] ) );
		$this->assertSame( 'Hello', $this->pipe( 'x | capitalize', [ 'x' => 'hello' ] ) );
	}

	public function testAppendPrepend(): void {
		$this->assertSame( 'abcd', $this->pipe( 'x | append("cd")', [ 'x' => 'ab' ] ) );
		$this->assertSame( 'cdab', $this->pipe( 'x | prepend("cd")', [ 'x' => 'ab' ] ) );
	}

	public function testArithmeticFilters(): void {
		$this->assertSame( '5', $this->pipe( 'x | abs', [ 'x' => -5 ] ) );
		$this->assertSame( '14', $this->pipe( 'x | plus(4)', [ 'x' => 10 ] ) );
		$this->assertSame( '6', $this->pipe( 'x | minus(4)', [ 'x' => 10 ] ) );
		$this->assertSame( '12', $this->pipe( 'x | times(4)', [ 'x' => 3 ] ) );
		$this->assertSame( '3', $this->pipe( 'x | divided_by(3)', [ 'x' => 10 ] ) );
		$this->assertSame( '3', $this->pipe( 'x | dividedBy(3)', [ 'x' => 10 ] ) ); // camelCase alias
		$this->assertSame( '1', $this->pipe( 'x | modulo(3)', [ 'x' => 10 ] ) );
		$this->assertSame( '5', $this->pipe( 'x | at_least(5)', [ 'x' => 3 ] ) );
		$this->assertSame( '3', $this->pipe( 'x | at_most(5)', [ 'x' => 3 ] ) );
	}

	public function testRoundingFilters(): void {
		$this->assertSame( '2', $this->pipe( 'x | ceil', [ 'x' => 1.2 ] ) );
		$this->assertSame( '1', $this->pipe( 'x | floor', [ 'x' => 1.8 ] ) );
		$this->assertSame( '1.3', $this->pipe( 'x | round(1)', [ 'x' => 1.25 ] ) );
	}

	public function testDefault(): void {
		$this->assertSame( '0', $this->pipe( 'x | default(0)', [ 'x' => '' ] ) );
		$this->assertSame( '7', $this->pipe( 'x | default(0)', [ 'x' => 7 ] ) );
	}

	public function testEscapingFilters(): void {
		$this->assertSame( '&lt;a href=&quot;x&quot;&gt;', $this->pipe( 'x | escape', [ 'x' => '<a href="x">' ] ) );
		$this->assertSame( '&lt;b&gt;', $this->pipe( 'x | escape_once', [ 'x' => '&lt;b&gt;' ] ) );
	}

	public function testArrayFilters(): void {
		$this->assertSame( '1, 2, 3', $this->pipe( 'x | join(", ")', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( '321', $this->pipe( 'x | reverse', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( '1', $this->pipe( 'x | first', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( '3', $this->pipe( 'x | last', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( '3', $this->pipe( 'x | size', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( '6', $this->pipe( 'x | sum', [ 'x' => [ 1, 2, 3 ] ] ) );
		$this->assertSame( 'abc', $this->pipe( 'x | split(",")', [ 'x' => 'a,b,c' ] ) );
	}

	public function testStringFilters(): void {
		$this->assertSame( '5', $this->pipe( 'x | size', [ 'x' => 'héllo' ] ) );
		$this->assertSame( 'ell', $this->pipe( 'x | slice(1, 3)', [ 'x' => 'hello' ] ) );
		$this->assertSame( 'cba', $this->pipe( 'x | reverse', [ 'x' => 'abc' ] ) );
	}

	public function testSortingFilters(): void {
		$this->assertSame( '123', $this->pipe( 'x | sort | join("")', [ 'x' => [ 3, 1, 2 ] ] ) );
		$this->assertSame( 'Aab', $this->pipe( 'x | sort_natural | join("")', [ 'x' => [ 'b', 'A', 'a' ] ] ) );
	}

	public function testWhitespaceFilters(): void {
		$this->assertSame( 'ab', $this->pipe( 'x | strip', [ 'x' => '  ab  ' ] ) );
		$this->assertSame( 'ab  ', $this->pipe( 'x | lstrip', [ 'x' => '  ab  ' ] ) );
		$this->assertSame( '  ab', $this->pipe( 'x | rstrip', [ 'x' => '  ab  ' ] ) );
		$this->assertSame( 'a b', $this->pipe( 'x | squish', [ 'x' => " a   b \n" ] ) );
		$this->assertSame( 'ab', $this->pipe( 'x | strip_html', [ 'x' => '<p>a</p><b>b</b>' ] ) );
		$this->assertSame( 'ab', $this->pipe( 'x | strip_newlines', [ 'x' => "a\nb" ] ) );
		$this->assertSame( "a<br />\nb", $this->pipe( 'x | newline_to_br', [ 'x' => "a\nb" ] ) );
	}

	public function testTruncationFilters(): void {
		$this->assertSame( 'he...', $this->pipe( 'x | truncate(5)', [ 'x' => 'hello world' ] ) );
		$this->assertSame( 'one two...', $this->pipe( 'x | truncatewords(2)', [ 'x' => 'one two three four' ] ) );
	}

	public function testUrlFilters(): void {
		$this->assertSame( 'a+b', $this->pipe( 'x | url_encode', [ 'x' => 'a b' ] ) );
		$this->assertSame( 'a b', $this->pipe( 'x | url_decode', [ 'x' => 'a+b' ] ) );
	}

	public function testMoney(): void {
		$this->assertSame( '10.00 MAD', $this->pipe( 'x | money', [ 'x' => 1000 ] ) );
		$this->assertSame( '10.00 USD', $this->pipe( 'x | money("USD")', [ 'x' => 1000 ] ) );
	}

	public function testDate(): void {
		$this->assertSame( '2026', $this->pipe( 'x | date("%Y")', [ 'x' => '2026-01-15' ] ) );
	}

	public function testCollectionFilters(): void {
		$list = [
			[ 'name' => 'a', 'cat' => 'x' ],
			[ 'name' => 'b', 'cat' => 'y' ],
		];

		$this->assertSame( 'a, b', $this->pipe( 'x | map("name") | join(", ")', [ 'x' => $list ] ) );
		$this->assertSame( '1', $this->pipe( 'x | where("cat", "x") | size', [ 'x' => $list ] ) );
		$this->assertSame( '1', $this->pipe( 'x | reject("cat", "x") | size', [ 'x' => $list ] ) );
		$this->assertSame( 'true', $this->pipe( 'x | has("a")', [ 'x' => [ 'a', 'b' ] ] ) );
	}

	public function testRemoveAndReplaceVariants(): void {
		$this->assertSame( 'ab', $this->pipe( 'x | remove("x")', [ 'x' => 'axb' ] ) );
		$this->assertSame( 'ab', $this->pipe( 'x | replace("c", "")', [ 'x' => 'acb' ] ) );
		$this->assertSame( 'xab', $this->pipe( 'x | remove_first("c")', [ 'x' => 'xcab' ] ) );
		$this->assertSame( 'abx', $this->pipe( 'x | remove_last("c")', [ 'x' => 'abcx' ] ) );
	}

	public function testDomainFiltersAreNotPreIncluded(): void {
		$this->expectException( \Phpmystic\Liqx\UnknownFilterException::class );
		$this->pipe( 'x | img_url("300x")', [ 'x' => '/a.jpg' ] );
	}
}