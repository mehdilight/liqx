<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/** Thrown in strict mode when a variable is referenced but not in scope. */
final class UndefinedVariableException extends LiqxException {}
