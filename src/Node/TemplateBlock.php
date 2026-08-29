<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Node;

/**
 * Top-level `<template>` SFC body block.
 */
final class TemplateBlock implements Node {

	/**
	 * @param list<Node> $children
	 * @param list<array{name:string|null, value:\Phpmystic\Liqx\Expr|null, spread:bool}> $attrs
	 */
	public function __construct(
		public readonly array $children,
		public readonly array $attrs = [],
		public readonly int $line = 0,
	) {}
}
