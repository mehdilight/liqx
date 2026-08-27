<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Identifier implements Expr {

	public function __construct(
		public readonly string $name,
	) {}
}
