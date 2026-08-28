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
		// `root` always refers to the whole render payload — the outermost
		// scope — regardless of nesting, so it can't be shadowed.
		if ( 'root' === $name ) {
			return [ 'found' => true, 'value' => $this->scopes[0] ];
		}

		$count = count( $this->scopes );

		if ( 1 === $count ) {
			if ( array_key_exists( $name, $this->scopes[0] ) ) {
				return [ 'found' => true, 'value' => $this->scopes[0][ $name ] ];
			}

			return [ 'found' => false, 'value' => null ];
		}

		for ( $i = $count - 1; $i >= 0; $i-- ) {
			if ( array_key_exists( $name, $this->scopes[ $i ] ) ) {
				return [ 'found' => true, 'value' => $this->scopes[ $i ][ $name ] ];
			}
		}

		return [ 'found' => false, 'value' => null ];
	}

	public function get( string $name ): mixed {
		if ( 'root' === $name ) {
			return $this->scopes[0];
		}

		// Walk innermost scope out. `array_key_exists` (not `??`) so a scope that
		// explicitly holds `null` still shadows outer scopes.
		for ( $i = count( $this->scopes ) - 1; $i >= 0; $i-- ) {
			$scope = $this->scopes[ $i ];

			if ( array_key_exists( $name, $scope ) ) {
				return $scope[ $name ];
			}
		}

		return null;
	}

	public function set( string $name, mixed $value ): void {
		// `root` is reserved — it always means the whole payload.
		if ( 'root' === $name ) {
			throw new \InvalidArgumentException( '`root` is reserved and cannot be reassigned' );
		}

		$this->scopes[ count( $this->scopes ) - 1 ][ $name ] = $value;
	}

	/** @param array<string, mixed> $scope */
	public function push( array $scope = [] ): void {
		$this->scopes[] = $scope;
	}

	public function pop(): void {
		array_pop( $this->scopes );
	}

	/**
	 * A child context that sees the parent's scopes plus its own data — how
	 * `{% section %}`-style nested renders share the page scope without
	 * leaking anything back.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function inherit( Environment $environment, bool $strict, self $parent, array $data = [] ): self {
		$context          = new self( $environment, $strict );
		$context->scopes  = $parent->scopes;
		$context->scopes[] = $data;

		return $context;
	}
}
