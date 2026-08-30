<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/** Internal control signal representing a return statement execution. */
final class ReturnSignal {

	public function __construct(
		public readonly mixed $value = null,
	) {}
}
