<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Binary implements Expr {

	public function __construct(
		public readonly string $op,
		public readonly Expr $left,
		public readonly Expr $right,
	) {}
}
