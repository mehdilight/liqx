<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Filtered implements Expr {

	/** @param list<Filter> $filters */
	public function __construct(
		public readonly Expr $value,
		public readonly array $filters,
	) {}
}
