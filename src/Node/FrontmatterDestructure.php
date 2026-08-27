<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/**
 * A frontmatter destructuring declaration:
 * `const { a, b = 'd' } = props;` binds each key from the init value.
 *
 * @phpstan-type Binding array{name:string, default:?Expr}
 */
final class FrontmatterDestructure {

	/** @param list<Binding> $bindings */
	public function __construct(
		public readonly array $bindings,
		public readonly Expr $init,
		public readonly int $line = 0,
	) {}
}