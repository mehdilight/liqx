<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

final class Style implements Node {

	/** @param list<array{name:string, value:Expr|null}> $attrs */
	public function __construct(
		public readonly string $body,
		public readonly array $attrs = [],
	) {}
}
