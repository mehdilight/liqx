<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Node;

/** A `<script>` body — verbatim, with selective `{expr}` interpolation. */
final class Script implements Node {

	public function __construct(
		public readonly string $body,
	) {}
}