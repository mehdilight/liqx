<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Unary implements Expr {

	public function __construct(
		public readonly string $op,
		public readonly Expr $operand,
	) {}
}
