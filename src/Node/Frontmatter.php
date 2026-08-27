<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/** A frontmatter declaration: `const name = expr;` */
final class Frontmatter {

	public function __construct(
		public readonly string $name,
		public readonly Expr $expr,
		public readonly int $line = 0,
	) {}
}
