<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

final class Output implements Node {

	public function __construct(
		public readonly Expr $expr,
		public readonly int $line = 0,
	) {}
}
