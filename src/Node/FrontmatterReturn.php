<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/** A return statement in frontmatter: `return;` or `return expr;` */
final class FrontmatterReturn {

	public function __construct(
		public readonly ?Expr $expr = null,
		public readonly int $line = 0,
	) {}
}
