<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

final class Token {

	public function __construct(
		public readonly TokenType $type,
		public readonly string $value,
		public readonly int $line,
	) {}
}
