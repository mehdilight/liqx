<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

/**
 * A JSX/HTML element. Doubles as an expression atom so JSX elements can
 * appear inside `{ }` expressions (`{x && <span>…</span>}`).
 */
final class Element implements Node, Expr {

	/**
	 * @param list<array{name:string|null, value:Expr|null, spread:bool}> $attrs
	 * @param list<Node> $children
	 */
	public function __construct(
		public readonly string $tag,
		public readonly array $attrs,
		public readonly array $children,
		public readonly bool $selfClosing,
		public readonly int $line = 0,
	) {}
}
