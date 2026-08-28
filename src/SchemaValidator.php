<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Node\Document;

/**
 * Validates a template's evaluated frontmatter `props` against the types
 * declared in its `<schema>` block, turning data-shape mistakes into early,
 * typed failures instead of silent body mis-renders.
 *
 * Supported schema shape:
 *
 *   <schema>
 *   { "props": { "title": "string", "count": "int", "tags": ["string","null"] } }
 *   </schema>
 *
 * A type is either a scalar token (`string`, `int`, `number`, `bool`, `array`,
 * `object`, `null`) or a list of tokens forming a union. Only props that are
 * actually present are validated; missing/optional props are allowed.
 */
final class SchemaValidator {

	/**
	 * Validate `$values['props']` (if present) against the document's schema.
	 *
	 * @param array<string, mixed> $values the const-name → value map from evaluating frontmatter
	 */
	public function validate( Document $document, array $values ): void {
		if ( null === $document->schema || ! array_key_exists( 'props', $values ) || ! is_array( $values['props'] ) ) {
			return;
		}

		$schema = json_decode( $document->schema->json, true );

		if ( ! is_array( $schema ) || ! isset( $schema['props'] ) || ! is_array( $schema['props'] ) ) {
			throw new LiqxException( 'Invalid schema: expected an object with a "props" map of prop-name → type.' );
		}

		$props  = $values['props'];
		$errors = [];

		foreach ( $schema['props'] as $name => $type ) {
			if ( ! is_array( $type ) ) {
				$type = [ $type ];
			}

			$types = array_values( array_map( 'strval', $type ) );

			foreach ( $types as &$t ) {
				$t = strtolower( $t );
			}
			unset( $t );

			if ( ! array_key_exists( $name, $props ) ) {
				continue;
			}

			if ( ! $this->matches( $props[ $name ], $types ) ) {
				$errors[] = sprintf( 'prop "%s": expected %s, got %s', $name, implode( '|', $types ), $this->describe( $props[ $name ] ) );
			}
		}

		if ( [] !== $errors ) {
			throw new LiqxException( 'Schema type mismatch: ' . implode( '; ', $errors ) );
		}
	}

	/**
	 * @param list<string> $types
	 */
	private function matches( mixed $value, array $types ): bool {
		foreach ( $types as $type ) {
			if ( $this->matchesOne( $value, $type ) ) {
				return true;
			}
		}

		return false;
	}

	private function matchesOne( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string' => is_string( $value ),
			'int'    => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'float'  => is_float( $value ),
			'bool'   => is_bool( $value ),
			'array'  => is_array( $value ),
			'object' => is_array( $value ),
			'null'   => null === $value,
			default  => false,
		};
	}

	private function describe( mixed $value ): string {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return get_debug_type( $value );
		}

		if ( is_array( $value ) ) {
			return 'array';
		}

		return 'mixed';
	}
}
