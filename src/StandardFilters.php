<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * The standard filter set — the generic, language-level filters phpmystic/
 * liquid ships, adapted to Liqx's value-first pipe signature (`a | f(b)`
 * compiles to `f(a, b)`). Project-specific filters (img_url, t, asset_url,
 * …) are the host's job via `Environment::registerFilter`, never this class.
 */
final class StandardFilters {

	/** @return array<string, callable> */
	public static function all(): array {
		$filters = [
			'abs'             => [ self::class, 'abs' ],
			'append'          => [ self::class, 'append' ],
			'at_least'        => [ self::class, 'atLeast' ],
			'at_most'         => [ self::class, 'atMost' ],
			'capitalize'      => [ self::class, 'capitalize' ],
			'ceil'            => [ self::class, 'ceil' ],
			'compact'         => [ self::class, 'compact' ],
			'concat'          => [ self::class, 'concat' ],
			'date'            => [ self::class, 'date' ],
			'default'         => [ self::class, 'withDefault' ],
			'divided_by'      => [ self::class, 'dividedBy' ],
			'downcase'        => [ self::class, 'downcase' ],
			'escape'          => [ self::class, 'escape' ],
			'escape_once'     => [ self::class, 'escapeOnce' ],
			'find'            => [ self::class, 'find' ],
			'find_index'      => [ self::class, 'findIndex' ],
			'first'           => [ self::class, 'first' ],
			'floor'           => [ self::class, 'floor' ],
			'group_by'        => [ self::class, 'groupBy' ],
			'has'             => [ self::class, 'has' ],
			'join'            => [ self::class, 'join' ],
			'last'            => [ self::class, 'last' ],
			'lstrip'          => [ self::class, 'lstrip' ],
			'map'             => [ self::class, 'map' ],
			'minus'           => [ self::class, 'minus' ],
			'modulo'          => [ self::class, 'modulo' ],
			'money'           => [ self::class, 'money' ],
			'newline_to_br'   => [ self::class, 'newlineToBr' ],
			'plus'            => [ self::class, 'plus' ],
			'prepend'         => [ self::class, 'prepend' ],
			'remove'          => [ self::class, 'remove' ],
			'remove_first'    => [ self::class, 'removeFirst' ],
			'remove_last'     => [ self::class, 'removeLast' ],
			'reject'          => [ self::class, 'reject' ],
			'replace'         => [ self::class, 'replace' ],
			'replace_first'   => [ self::class, 'replaceFirst' ],
			'replace_last'    => [ self::class, 'replaceLast' ],
			'reverse'         => [ self::class, 'reverse' ],
			'round'           => [ self::class, 'round' ],
			'rstrip'          => [ self::class, 'rstrip' ],
			'size'            => [ self::class, 'size' ],
			'slice'           => [ self::class, 'slice' ],
			'sort'            => [ self::class, 'sort' ],
			'sort_natural'    => [ self::class, 'sortNatural' ],
			'split'           => [ self::class, 'split' ],
			'squish'          => [ self::class, 'squish' ],
			'strip'           => [ self::class, 'strip' ],
			'strip_html'      => [ self::class, 'stripHtml' ],
			'strip_newlines'  => [ self::class, 'stripNewlines' ],
			'sum'             => [ self::class, 'sum' ],
			'times'           => [ self::class, 'times' ],
			'truncate'        => [ self::class, 'truncate' ],
			'truncatewords'   => [ self::class, 'truncateWords' ],
			'uniq'            => [ self::class, 'uniq' ],
			'upcase'          => [ self::class, 'upcase' ],
			'url_decode'      => [ self::class, 'urlDecode' ],
			'url_encode'      => [ self::class, 'urlEncode' ],
			'where'           => [ self::class, 'where' ],
		];

		// Shopify camelCase aliases — `divided_by` and `dividedBy` are the
		// same filter, so a template written for either spelling works.
		foreach ( $filters as $name => $filter ) {
			$camel = self::toCamel( $name );

			if ( $camel !== $name && ! isset( $filters[ $camel ] ) ) {
				$filters[ $camel ] = $filter;
			}
		}

		return $filters;
	}

	private static function toCamel( string $name ): string {
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $name ) ) ) );
	}

	// ---------------------------------------------------------------------
	// Case
	// ---------------------------------------------------------------------

	public static function upcase( mixed $value ): string {
		return mb_strtoupper( (string) $value );
	}

	public static function downcase( mixed $value ): string {
		return mb_strtolower( (string) $value );
	}

	public static function capitalize( mixed $value ): string {
		$value = (string) $value;

		return mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
	}

	// ---------------------------------------------------------------------
	// Concatenation
	// ---------------------------------------------------------------------

	public static function append( mixed $value, mixed $suffix = '' ): string {
		return (string) $value . (string) $suffix;
	}

	public static function prepend( mixed $value, mixed $prefix = '' ): string {
		return (string) $prefix . (string) $value;
	}

	/** @return array<mixed> */
	public static function concat( mixed $value, mixed $other ): array {
		return array_merge( is_array( $value ) ? $value : [], is_array( $other ) ? $other : [] );
	}

	// ---------------------------------------------------------------------
	// Arithmetic
	// ---------------------------------------------------------------------

	public static function abs( mixed $value ): int {
		return abs( (int) $value );
	}

	public static function plus( mixed $value, mixed $operand = 0 ): int|float {
		return self::toNumber( $value ) + self::toNumber( $operand );
	}

	public static function minus( mixed $value, mixed $operand = 0 ): int|float {
		return self::toNumber( $value ) - self::toNumber( $operand );
	}

	public static function times( mixed $value, mixed $operand = 1 ): int|float {
		return self::toNumber( $value ) * self::toNumber( $operand );
	}

	public static function dividedBy( mixed $value, mixed $operand ): int|float {
		$value   = self::toNumber( $value );
		$operand = self::toNumber( $operand );

		if ( 0.0 === (float) $operand ) {
			return 0;
		}

		$result = $value / $operand;

		return is_int( $value ) && is_int( $operand ) ? (int) $result : $result;
	}

	public static function modulo( mixed $value, mixed $operand = 1 ): int {
		return (int) $value % max( 1, (int) $operand );
	}

	public static function atLeast( mixed $value, mixed $min = 0 ): int {
		return max( (int) $value, (int) $min );
	}

	public static function atMost( mixed $value, mixed $max = PHP_INT_MAX ): int {
		return min( (int) $value, (int) $max );
	}

	public static function ceil( mixed $value ): int {
		return (int) ceil( (float) $value );
	}

	public static function floor( mixed $value ): int {
		return (int) floor( (float) $value );
	}

	public static function round( mixed $value, mixed $precision = 0 ): float {
		return round( (float) $value, (int) $precision );
	}

	public static function sum( mixed $value ): int|float {
		return is_array( $value ) ? array_sum( $value ) : 0;
	}

	// ---------------------------------------------------------------------
	// Values
	// ---------------------------------------------------------------------

	public static function withDefault( mixed $value, mixed $fallback = null ): mixed {
		return ( null === $value || false === $value || '' === $value ) ? $fallback : $value;
	}

	public static function first( mixed $value ): mixed {
		return is_array( $value ) ? ( $value[0] ?? null ) : ( is_string( $value ) ? ( '' === $value ? '' : mb_substr( $value, 0, 1 ) ) : null );
	}

	public static function last( mixed $value ): mixed {
		return is_array( $value ) ? ( [] === $value ? null : $value[ array_key_last( $value ) ] ) : null;
	}

	public static function size( mixed $value ): int {
		return is_array( $value ) || $value instanceof \Countable ? count( $value ) : mb_strlen( (string) $value );
	}

	// ---------------------------------------------------------------------
	// Escaping / encoding
	// ---------------------------------------------------------------------

	public static function escape( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	public static function escapeOnce( mixed $value ): string {
		return htmlspecialchars( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ), ENT_QUOTES, 'UTF-8' );
	}

	public static function urlEncode( mixed $value ): string {
		return urlencode( (string) $value );
	}

	public static function urlDecode( mixed $value ): string {
		return urldecode( (string) $value );
	}

	// ---------------------------------------------------------------------
	// Strings
	// ---------------------------------------------------------------------

	public static function join( mixed $value, mixed $separator = ' ' ): string {
		return is_array( $value ) ? implode( (string) $separator, $value ) : (string) $value;
	}

	/** @return list<string> */
	public static function split( mixed $value, mixed $separator = '' ): array {
		return '' === (string) $separator ? mb_str_split( (string) $value ) : explode( (string) $separator, (string) $value );
	}

	public static function reverse( mixed $value ): mixed {
		return is_array( $value ) ? array_reverse( $value ) : strrev( (string) $value );
	}

	/** @return string|list<mixed> */
	public static function slice( mixed $value, mixed $offset = 0, mixed $length = null ): string|array {
		$length ??= 1;

		if ( is_array( $value ) ) {
			return abs( (int) $offset ) >= count( $value ) ? [] : array_slice( $value, (int) $offset, (int) $length );
		}

		$string = (string) $value;

		return abs( (int) $offset ) >= mb_strlen( $string ) ? '' : mb_substr( $string, (int) $offset, (int) $length );
	}

	public static function remove( mixed $value, mixed $needle = '' ): string {
		return str_replace( (string) $needle, '', (string) $value );
	}

	public static function removeFirst( mixed $value, mixed $needle = '' ): string {
		return self::replaceFirstHelper( (string) $needle, '', (string) $value );
	}

	public static function removeLast( mixed $value, mixed $needle = '' ): string {
		return self::replaceLastHelper( (string) $needle, '', (string) $value );
	}

	public static function replace( mixed $value, mixed $search = '', mixed $replace = '' ): string {
		return str_replace( (string) $search, (string) $replace, (string) $value );
	}

	public static function replaceFirst( mixed $value, mixed $search = '', mixed $replace = '' ): string {
		return self::replaceFirstHelper( (string) $search, (string) $replace, (string) $value );
	}

	public static function replaceLast( mixed $value, mixed $search = '', mixed $replace = '' ): string {
		return self::replaceLastHelper( (string) $search, (string) $replace, (string) $value );
	}

	public static function has( mixed $value, mixed $needle ): bool {
		return is_array( $value ) ? in_array( $needle, $value, true ) : ( is_string( $value ) && str_contains( $value, (string) $needle ) );
	}

	// ---------------------------------------------------------------------
	// Whitespace / HTML
	// ---------------------------------------------------------------------

	public static function strip( mixed $value ): string {
		return trim( (string) $value );
	}

	public static function lstrip( mixed $value ): string {
		return ltrim( (string) $value );
	}

	public static function rstrip( mixed $value ): string {
		return rtrim( (string) $value );
	}

	public static function squish( mixed $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	public static function stripHtml( mixed $value ): string {
		return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
	}

	public static function stripNewlines( mixed $value ): string {
		return str_replace( [ "\r", "\n" ], '', (string) $value );
	}

	public static function newlineToBr( mixed $value ): string {
		return str_replace( "\n", "<br />\n", (string) $value );
	}

	public static function truncate( mixed $value, mixed $length = 50, mixed $ellipsis = '...' ): string {
		return self::truncateHelper( (string) $value, (int) $length, (string) $ellipsis );
	}

	public static function truncateWords( mixed $value, mixed $count = 15, mixed $ellipsis = '...' ): string {
		$words = preg_split( '/\s+/', trim( (string) $value ) ) ?: [];

		if ( count( $words ) <= (int) $count ) {
			return (string) $value;
		}

		return implode( ' ', array_slice( $words, 0, (int) $count ) ) . $ellipsis;
	}

	// ---------------------------------------------------------------------
	// Money / date
	// ---------------------------------------------------------------------

	public static function money( mixed $value, mixed $currency = 'MAD' ): string {
		return sprintf( '%.2f %s', ( (int) $value ) / 100, $currency );
	}

	public static function date( mixed $value, mixed $format = '' ): string {
		$format = (string) $format;

		if ( '' === $format ) {
			return (string) $value;
		}

		$input = (string) $value;

		if ( 'now' === $input ) {
			$timestamp = time();
		} elseif ( ctype_digit( $input ) ) {
			$timestamp = (int) $input;
		} else {
			$timestamp = strtotime( $input );

			if ( false === $timestamp ) {
				return $input;
			}
		}

		return date( self::strftimeToDate( $format ), $timestamp );
	}

	/** Map strftime `%` tokens to PHP `date()` tokens — keepsuit's exact map. */
	private static function strftimeToDate( string $format ): string {
		if ( ! str_contains( $format, '%' ) ) {
			return $format;
		}

		return strtr( $format, [
			'at'  => '\a\t',
			'%a'  => 'D',  '%A'  => 'l',  '%d'  => 'd',  '%e'  => 'j',  '%u' => 'N',
			'%w'  => 'w',  '%W'  => 'W',  '%b'  => 'M',  '%h'  => 'M',  '%B' => 'F',
			'%m'  => 'm',  '%y'  => 'y',  '%Y'  => 'Y',  '%D'  => 'm/d/y', '%F' => 'Y-m-d',
			'%x'  => 'm/d/y', '%n' => "\n", '%t' => "\t",
			'%H'  => 'H',  '%k'  => 'G',  '%I'  => 'h',  '%l'  => 'g',  '%M' => 'i',
			'%p'  => 'A',  '%P'  => 'a',  '%r'  => 'h:i:s A', '%R' => 'H:i',
			'%S'  => 's',  '%T'  => 'H:i:s', '%X' => 'H:i:s',
			'%z'  => 'O',  '%Z'  => 'T',  '%c'  => 'D M j H:i:s Y', '%s' => 'U', '%%' => '%',
		] );
	}

	// ---------------------------------------------------------------------
	// Collections
	// ---------------------------------------------------------------------

	/** @return array<mixed> */
	public static function map( mixed $value, mixed $key = '' ): array {
		return array_map(
			static fn ( mixed $item ): mixed => self::lookupKey( $item, (string) $key ),
			is_array( $value ) ? $value : []
		);
	}

	/** @return array<mixed> */
	public static function where( mixed $value, mixed $key = '', mixed $expected = null ): array {
		return array_values( array_filter(
			is_array( $value ) ? $value : [],
			static fn ( mixed $item ): bool => self::lookupKey( $item, (string) $key ) === $expected
		) );
	}

	/** @return array<mixed> */
	public static function reject( mixed $value, mixed $key = '', mixed $expected = null ): array {
		return array_values( array_filter(
			is_array( $value ) ? $value : [],
			static fn ( mixed $item ): bool => self::lookupKey( $item, (string) $key ) !== $expected
		) );
	}

	public static function find( mixed $value, mixed $key = '', mixed $expected = null ): mixed {
		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( $value as $item ) {
			if ( self::lookupKey( $item, (string) $key ) === $expected ) {
				return $item;
			}
		}

		return null;
	}

	public static function findIndex( mixed $value, mixed $key = '', mixed $expected = null ): ?int {
		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( $value as $index => $item ) {
			if ( self::lookupKey( $item, (string) $key ) === $expected ) {
				return $index;
			}
		}

		return null;
	}

	/** @return list<mixed> */
	public static function sort( mixed $value, mixed $key = null ): array {
		return self::sortHelper( $value, false, $key );
	}

	/** @return list<mixed> */
	public static function sortNatural( mixed $value, mixed $key = null ): array {
		return self::sortHelper( $value, true, $key );
	}

	/** @return list<mixed> */
	public static function compact( mixed $value, mixed $key = null ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		if ( null === $key ) {
			return array_values( array_filter( $value, static fn ( mixed $v ): bool => null !== $v ) );
		}

		return array_values( array_filter(
			$value,
			static fn ( mixed $item ): bool => null !== self::lookupKey( $item, (string) $key )
		) );
	}

	/** @return list<mixed> */
	public static function uniq( mixed $value, mixed $key = null ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		if ( null === $key ) {
			return array_values( array_unique( $value ) );
		}

		$seen   = [];
		$result = [];

		foreach ( $value as $item ) {
			$signature = serialize( self::lookupKey( $item, (string) $key ) );

			if ( ! isset( $seen[ $signature ] ) ) {
				$seen[ $signature ] = true;
				$result[] = $item;
			}
		}

		return $result;
	}

	/** @return list<array{name:string, items:list<mixed>}> */
	public static function groupBy( mixed $value, mixed $key = '' ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$groups = [];

		foreach ( $value as $item ) {
			$name = self::lookupKey( $item, (string) $key );
			$groups[ (string) ( $name ?? '' ) ][] = $item;
		}

		$result = [];

		foreach ( $groups as $name => $items ) {
			$result[] = [ 'name' => $name, 'items' => $items ];
		}

		return $result;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private static function toNumber( mixed $value ): int|float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$string = (string) $value;

		return str_contains( $string, '.' ) ? (float) $string : (int) $string;
	}

	/**
	 * Resolve a dotted key against an item — arrays, public properties, and
	 * drop `beforeMethod` lookups. `where: 'a.b', 1` matches an item whose
	 * `a.b` is `1`.
	 */
	private static function lookupKey( mixed $item, string $key ): mixed {
		if ( '' === $key ) {
			return $item;
		}

		$value = $item;

		foreach ( explode( '.', $key ) as $segment ) {
			if ( is_array( $value ) ) {
				if ( ! array_key_exists( $segment, $value ) ) {
					return null;
				}

				$value = $value[ $segment ];

				continue;
			}

			if ( is_object( $value ) ) {
				if ( property_exists( $value, $segment ) ) {
					$value = $value->{ $segment };

					continue;
				}

				if ( method_exists( $value, 'beforeMethod' ) ) {
					$value = $value->beforeMethod( $segment );

					continue;
				}

				return null;
			}

			return null;
		}

		return $value;
	}

	/** @return list<mixed> */
	private static function sortHelper( mixed $value, bool $natural, mixed $key ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$items = array_values( $value );

		if ( null !== $key ) {
			usort( $items, static fn ( mixed $a, mixed $b ): int => self::compare( self::lookupKey( $a, (string) $key ), self::lookupKey( $b, (string) $key ), $natural ) );

			return $items;
		}

		usort( $items, static fn ( mixed $a, mixed $b ): int => self::compare( $a, $b, $natural ) );

		return $items;
	}

	private static function compare( mixed $a, mixed $b, bool $natural ): int {
		if ( $a === $b ) {
			return 0;
		}

		if ( null === $a ) {
			return 1;
		}

		if ( null === $b ) {
			return -1;
		}

		if ( is_string( $a ) && is_string( $b ) ) {
			return $natural ? strcasecmp( $a, $b ) : strcmp( $a, $b );
		}

		return $a <=> $b;
	}

	private static function replaceFirstHelper( string $search, string $replace, string $subject ): string {
		if ( '' === $search ) {
			return $subject;
		}

		$position = strpos( $subject, $search );

		if ( false === $position ) {
			return $subject;
		}

		return substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	private static function replaceLastHelper( string $search, string $replace, string $subject ): string {
		if ( '' === $search ) {
			return $subject;
		}

		$position = strrpos( $subject, $search );

		if ( false === $position ) {
			return $subject;
		}

		return substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	private static function truncateHelper( string $value, int $length, string $ellipsis ): string {
		if ( mb_strlen( $value ) <= $length ) {
			return $value;
		}

		return mb_substr( $value, 0, max( 0, $length - mb_strlen( $ellipsis ) ) ) . $ellipsis;
	}
}