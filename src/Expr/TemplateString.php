<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;

final class TemplateString implements Expr {

	/**
	 * @param list<string|Expr> $parts
	 */
	public function __construct(
		public readonly string $raw,
		public readonly array $parts = [],
	) {}
}
