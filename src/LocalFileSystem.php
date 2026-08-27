<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Resolves a bare name to `$root/<name>.liqx`, mirroring Liquid's flat
 * `snippets/` / `sections/` convention — no nested paths.
 */
final class LocalFileSystem implements FileSystem {

	public function __construct(
		private readonly string $root,
	) {}

	public function load( string $name ): string {
		if ( str_contains( $name, '/' ) || str_contains( $name, '\\' ) ) {
			throw new FileSystemException( sprintf( 'Nested partial names are not allowed: %s', $name ) );
		}

		$path = $this->root . '/' . $name . '.liqx';

		if ( ! is_file( $path ) ) {
			throw new FileSystemException( sprintf( 'Template %s not found', $name ) );
		}

		$source = file_get_contents( $path );

		if ( false === $source ) {
			throw new FileSystemException( sprintf( 'Could not read template %s', $path ) );
		}

		return $source;
	}
}