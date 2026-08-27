<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * The runtime evaluation state: a stack of lexical scopes plus the
 * Environment (for filters). Lookups search from the innermost scope out.
 */
final class Context {

	/** @var list<array<string, mixed>> */
	private array $scopes = [];

	/**
	 * @param array<string, mixed> $data
	 */
	public function __construct(
		public readonly Environment $environment,
		public readonly bool $strict = false,
		array $data = [],
	) {
		$this->scopes[] = $data;
	}

	/** @return array{found:bool, value:mixed} */
	public function lookup( string $name ): array {
		for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
			if ( array_key_exists( $name, $this->scopes[ $i ] ) ) {
				return [ 'found' => true, 'value' => $this->scopes[ $i ][ $name ] ];
			}
		}

		return [ 'found' => false, 'value' => null ];
	}

	public function set( string $name, mixed $value ): void {
		$this->scopes[ count( $this->scopes ) - 1 ][ $name ] = $value;
	}

	/** @param array<string, mixed> $scope */
	public function push( array $scope = [] ): void {
		$this->scopes[] = $scope;
	}

	public function pop(): void {
		array_pop( $this->scopes );
	}
}
