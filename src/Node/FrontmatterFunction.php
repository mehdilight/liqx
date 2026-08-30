<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;

/**
 * A local function statement in frontmatter:
 *
 * `function name(param1, param2 = 'default') { ... }`
 *
 * @phpstan-type Param array{name: string, default: ?Expr}
 */
final class FrontmatterFunction {

	/**
	 * @param list<Param> $params
	 * @param list<object> $body
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $params,
		public readonly array $body,
		public readonly ?Expr $return = null,
		public readonly int $line = 0,
	) {}
}
