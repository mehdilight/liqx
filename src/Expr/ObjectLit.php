<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class ObjectLit implements Expr {

	/** @param list<array{0:string, 1:Expr}> $properties */
	public function __construct(
		public readonly array $properties,
	) {}
}
