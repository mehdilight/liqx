<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/** Raised when a `|` pipeline names a filter that isn't registered. */
final class UnknownFilterException extends LiqxException {}
