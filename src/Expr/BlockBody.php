<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Expr;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;

/**
 * The body of a block-body arrow: `(x) => { const a = …; return …; }`.
 * Declarations run in the arrow's own scope; the trailing `return` is its
 * value (null when absent, like JS).
 */
final class BlockBody implements Expr {

	/**
	 * @param list<Frontmatter|FrontmatterDestructure> $declarations
	 */
	public function __construct(
		public readonly array $declarations,
		public readonly ?Expr $return = null,
	) {}
}