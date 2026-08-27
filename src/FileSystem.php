<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Resolves a bare template name to its source. This is the hook that keeps
 * Liqx data-agnostic: the engine never knows a `"header"` maps to a file on
 * disk — the host decides, via the FileSystem it configures.
 */
interface FileSystem {

	public function load( string $name ): string;
}