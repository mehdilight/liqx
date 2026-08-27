<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class ArrowFunction implements Expr {

	/** @param list<string> $params */
	public function __construct(
		public readonly array $params,
		public readonly Expr $body,
	) {}
}
