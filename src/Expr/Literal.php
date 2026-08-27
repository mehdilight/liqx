<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Literal implements Expr {

	public function __construct(
		public readonly mixed $value,
	) {}
}
