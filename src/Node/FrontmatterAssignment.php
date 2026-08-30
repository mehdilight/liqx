<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/** An assignment statement in frontmatter: `name = expr;` or `name += expr;` */
final class FrontmatterAssignment {

	public function __construct(
		public readonly string $name,
		public readonly string $operator,
		public readonly Expr $expr,
		public readonly int $line = 0,
	) {}
}
