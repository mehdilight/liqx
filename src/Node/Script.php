<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

/** A `<script>` body — verbatim, with selective `{expr}` interpolation. */
final class Script implements Node, Expr {

	/**
	 * @param list<array{name:string|null, value:Expr|null, spread:bool}> $attrs
	 * @param list<string|Expr> $parts
	 */
	public function __construct(
		public readonly string $body,
		public readonly array $attrs = [],
		public readonly array $parts = [],
	) {}
}