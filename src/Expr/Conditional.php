<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Conditional implements Expr {

	public function __construct(
		public readonly Expr $test,
		public readonly Expr $consequent,
		public readonly Expr $alternate,
	) {}
}
