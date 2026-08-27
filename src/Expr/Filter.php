<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Filter {

	/** @param list<Expr> $args */
	public function __construct(
		public readonly string $name,
		public readonly array $args,
	) {}
}
