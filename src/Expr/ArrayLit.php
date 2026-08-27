<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class ArrayLit implements Expr {

	/** @param list<Expr> $elements */
	public function __construct(
		public readonly array $elements,
	) {}
}
