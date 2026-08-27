<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Base class for objects passed into templates that should answer arbitrary
 * lookups. Override `beforeMethod()` to resolve a requested key.
 */
abstract class Drop {

	abstract public function beforeMethod( string $method ): mixed;
}
