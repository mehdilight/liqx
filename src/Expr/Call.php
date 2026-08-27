<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Call implements Expr {

	/** @param list<Expr> $args */
	public function __construct(
		public readonly Expr $callee,
		public readonly array $args,
	) {}
}
