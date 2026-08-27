<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Node;

final class Style implements Node {

	public function __construct(
		public readonly string $body,
	) {}
}
