<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class Member implements Expr {

	/**
	 * @param string|Expr $access dot-access property name, or the index
	 *                       expression when $computed is true
	 */
	public function __construct(
		public readonly Expr $object,
		public readonly string|Expr $access,
		public readonly bool $computed = false,
	) {}
}