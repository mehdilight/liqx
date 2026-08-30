<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/**
 * An `if / else if / else` statement in frontmatter:
 *
 * `if (test) { ... } else if (test2) { ... } else { ... }`
 *
 * @phpstan-type ElseIfBranch array{test: Expr, body: list<object>}
 */
final class FrontmatterIf {

	/**
	 * @param list<object> $then
	 * @param list<ElseIfBranch> $elseIfs
	 * @param list<object> $else
	 */
	public function __construct(
		public readonly Expr $test,
		public readonly array $then,
		public readonly array $elseIfs = [],
		public readonly array $else = [],
		public readonly int $line = 0,
	) {}
}
