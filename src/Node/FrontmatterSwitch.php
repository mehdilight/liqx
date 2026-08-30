<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/**
 * A `switch` statement in frontmatter:
 *
 * `switch (discriminant) { case val: ... break; default: ... }`
 *
 * @phpstan-type SwitchCase array{test: ?Expr, body: list<object>}
 */
final class FrontmatterSwitch {

	/**
	 * @param list<SwitchCase> $cases
	 */
	public function __construct(
		public readonly Expr $discriminant,
		public readonly array $cases = [],
		public readonly int $line = 0,
	) {}
}
