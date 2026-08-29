<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Expr\ArrowFunction;
use Phpmystic\Liqx\Expr\ArrayLit;
use Phpmystic\Liqx\Expr\Binary;
use Phpmystic\Liqx\Expr\BlockBody;
use Phpmystic\Liqx\Expr\Call;
use Phpmystic\Liqx\Expr\Conditional;
use Phpmystic\Liqx\Expr\Filtered;
use Phpmystic\Liqx\Expr\Identifier;
use Phpmystic\Liqx\Expr\Literal;
use Phpmystic\Liqx\Expr\Logical;
use Phpmystic\Liqx\Expr\Member;
use Phpmystic\Liqx\Expr\ObjectLit;
use Phpmystic\Liqx\Expr\TemplateString;
use Phpmystic\Liqx\Expr\Unary;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterDestructure;

/**
 * Evaluates expression values against a Context. This is the "real JS"
 * interpreter, but sandboxed: no eval, no arbitrary code — just the subset
 * the grammar produces, with array/string methods and filters routed through
 * a whitelist.
 */
final class Evaluator {

	public function __construct(
		private readonly Renderer $renderer,
	) {}

	public function evaluate( Expr $expr, Context $ctx ): mixed {
		if ( $expr instanceof Literal ) {
			return $expr->value;
		}

		if ( $expr instanceof Identifier ) {
			return $this->evalIdentifier( $expr, $ctx );
		}

		if ( $expr instanceof Member ) {
			return $this->evalMember( $expr, $ctx );
		}

		if ( $expr instanceof Call ) {
			return $this->evalCall( $expr, $ctx );
		}

		if ( $expr instanceof ArrayLit ) {
			$out = [];
			foreach ( $expr->elements as $element ) {
				$out[] = $this->evaluate( $element, $ctx );
			}

			return $out;
		}

		if ( $expr instanceof ObjectLit ) {
			$out = [];
			foreach ( $expr->properties as [ $key, $value ] ) {
				$out[ $key ] = $this->evaluate( $value, $ctx );
			}

			return $out;
		}

		if ( $expr instanceof Binary ) {
			return $this->evalBinary( $expr, $ctx );
		}

		if ( $expr instanceof Logical ) {
			return $this->evalLogical( $expr, $ctx );
		}

		if ( $expr instanceof Unary ) {
			return $this->evalUnary( $expr, $ctx );
		}

		if ( $expr instanceof Conditional ) {
			return $this->truthy( $this->evaluate( $expr->test, $ctx ) )
				? $this->evaluate( $expr->consequent, $ctx )
				: $this->evaluate( $expr->alternate, $ctx );
		}

		if ( $expr instanceof ArrowFunction ) {
			return $expr;
		}

		if ( $expr instanceof BlockBody ) {
			return $this->evalBlockBody( $expr, $ctx );
		}

		if ( $expr instanceof TemplateString ) {
			return $this->evalTemplateString( $expr, $ctx );
		}

		if ( $expr instanceof Filtered ) {
			return $this->evalFiltered( $expr, $ctx );
		}

		if ( $expr instanceof Element ) {
			return $this->renderer->renderElement( $expr, $ctx );
		}

		if ( $expr instanceof \Phpmystic\Liqx\Node\Style ) {
			return $this->renderer->renderStyle( $expr, $ctx );
		}

		if ( $expr instanceof \Phpmystic\Liqx\Node\Script ) {
			return $this->renderer->renderScript( $expr, $ctx );
		}

		throw new LiqxException( 'Cannot evaluate expression node ' . $expr::class );
	}

	// ---------------------------------------------------------------------
	// Evaluators
	// ---------------------------------------------------------------------

	private function evalIdentifier( Identifier $expr, Context $ctx ): mixed {
		return $this->identifier( $ctx, $expr->name );
	}

	/** Strict-aware identifier lookup — shared by the interpreter and compiled templates. */
	public function identifier( Context $ctx, string $name ): mixed {
		if ( ! $ctx->strict ) {
			return $ctx->get( $name );
		}

		[ 'found' => $found, 'value' => $value ] = $ctx->lookup( $name );

		if ( ! $found ) {
			throw new UndefinedVariableException( sprintf( 'Undefined variable %s', $name ) );
		}

		return $value;
	}

	private function evalMember( Member $expr, Context $ctx ): mixed {
		if ( $expr->nullSafe ) {
			return $this->evalNullSafeMember( $expr, $ctx );
		}

		$object = $this->evaluate( $expr->object, $ctx );

		if ( $expr->computed ) {
			$key = $this->evaluate( $expr->access, $ctx );
		} else {
			$key = $expr->access;
		}

		return $this->getProperty( $object, $key );
	}

	/**
	 * Optional property access `a?.b`. If the object is undefined in strict mode
	 * (or null), the whole expression short-circuits to `null` without reading
	 * any further member — mirroring `??`-style leniency for the object side.
	 */
	private function evalNullSafeMember( Member $expr, Context $ctx ): mixed {
		$object = $this->evaluateLenient( $expr->object, $ctx );

		if ( null === $object ) {
			return null;
		}

		$key = $expr->computed ? $this->evaluate( $expr->access, $ctx ) : $expr->access;

		return $this->getProperty( $object, $key );
	}

	/** Evaluate an expression in strict-lenient mode — missing variables yield null. */
	private function evaluateLenient( Expr $expr, Context $ctx ): mixed {
		try {
			return $this->evaluate( $expr, $ctx );
		} catch ( UndefinedVariableException ) {
			return null;
		}
	}

	private function evalCall( Call $expr, Context $ctx ): mixed {
		$args = [];
		foreach ( $expr->args as $arg ) {
			$args[] = $this->evaluate( $arg, $ctx );
		}

		$callee = $expr->callee;

		if ( $callee instanceof Member ) {
			$object = $this->evaluate( $callee->object, $ctx );

			return $this->methodCall( $object, (string) $callee->access, $args, $ctx );
		}

		if ( $callee instanceof Identifier ) {
			return $this->callNamed( $ctx, $callee->name, $args );
		}

		$fn = $this->evaluate( $callee, $ctx );

		return $this->invoke( $fn, $args, $ctx );
	}

	/**
	 * A `name(...)` call — global first, then filter, mirroring Liquid where
	 * the distinction is tags vs filters.
	 *
	 * @param list<mixed> $args
	 */
	public function callNamed( Context $ctx, string $name, array $args ): mixed {
		$entry = $ctx->environment->globalEntry( $name );

		if ( null !== $entry ) {
			[ $global, $wantsContext ] = $entry;

			return $wantsContext ? $global( $ctx, ...$args ) : $global( ...$args );
		}

		$filter = $ctx->environment->filter( $name );

		if ( null !== $filter ) {
			return $filter( ...$args );
		}

		throw new LiqxException( sprintf( 'Unknown function %s', $name ) );
	}

	private function evalBinary( Binary $expr, Context $ctx ): mixed {
		$left  = $this->evaluate( $expr->left, $ctx );
		$right = $this->evaluate( $expr->right, $ctx );

		return match ( $expr->op ) {
			'+'   => $this->add( $left, $right ),
			'-'   => $this->toNumber( $left ) - $this->toNumber( $right ),
			'*'   => $this->toNumber( $left ) * $this->toNumber( $right ),
			'/'   => $this->toNumber( $left ) / $this->toNumber( $right ),
			'%'   => $this->toNumber( $left ) % $this->toNumber( $right ),
			'=='  => $this->looseEqual( $left, $right ),
			'!='  => ! $this->looseEqual( $left, $right ),
			'===' => $left === $right,
			'!==' => $left !== $right,
			'<'   => $this->compare( $left, $right ) < 0,
			'>'   => $this->compare( $left, $right ) > 0,
			'<='  => $this->compare( $left, $right ) <= 0,
			'>='  => $this->compare( $left, $right ) >= 0,
			default => throw new LiqxException( 'Unknown operator ' . $expr->op ),
		};
	}

	/**
	 * The `+` operator — string concatenation when either side is a string,
	 * numeric addition otherwise. Shared by the interpreter and compiled
	 * templates so each operand is evaluated exactly once.
	 */
	public function add( mixed $left, mixed $right ): string|int|float {
		if ( is_string( $left ) || is_string( $right ) ) {
			return (string) $left . (string) $right;
		}

		return $this->toNumber( $left ) + $this->toNumber( $right );
	}

	private function evalLogical( Logical $expr, Context $ctx ): mixed {
		if ( '&&' === $expr->op ) {
			$left = $this->evaluate( $expr->left, $ctx );

			return $this->truthy( $left ) ? $this->evaluate( $expr->right, $ctx ) : $left;
		}

		if ( '||' === $expr->op ) {
			$left = $this->evaluate( $expr->left, $ctx );

			return $this->truthy( $left ) ? $left : $this->evaluate( $expr->right, $ctx );
		}

		// `??` — suppress undefined-variable errors from a missing left side.
		try {
			$left = $this->evaluate( $expr->left, $ctx );
		} catch ( UndefinedVariableException ) {
			$left = null;
		}

		return null === $left ? $this->evaluate( $expr->right, $ctx ) : $left;
	}

	private function evalUnary( Unary $expr, Context $ctx ): mixed {
		$value = $this->evaluate( $expr->operand, $ctx );

		return match ( $expr->op ) {
			'!' => ! $this->truthy( $value ),
			'-' => -$this->toNumber( $value ),
			'+' => $this->toNumber( $value ),
			default => throw new LiqxException( 'Unknown unary operator ' . $expr->op ),
		};
	}

	private function evalTemplateString( TemplateString $expr, Context $ctx ): string {
		$parts = [];
		foreach ( $expr->parts as $part ) {
			$parts[] = is_string( $part ) ? $part : $this->evaluate( $part, $ctx );
		}

		return $this->templateString( $parts, $ctx );
	}

	/**
	 * Concatenate a template-literal part list. `$parts` mixes literal strings
	 * with already-evaluated values.
	 *
	 * @param list<string|mixed> $parts
	 */
	public function templateString( array $parts, Context $ctx ): string {
		$out = '';

		foreach ( $parts as $part ) {
			$out .= is_string( $part ) ? $part : $this->renderer->renderValue( $part, $ctx );
		}

		return $out;
	}

	private function evalBlockBody( BlockBody $expr, Context $ctx ): mixed {
		foreach ( $expr->declarations as $declaration ) {
			if ( $declaration instanceof FrontmatterDestructure ) {
				$value = $this->evaluate( $declaration->init, $ctx );

				foreach ( $declaration->bindings as $binding ) {
					[ 'found' => $found, 'value' => $resolved ] = $this->lookupProperty( $value, $binding['name'] );

					if ( $found ) {
						$ctx->set( $binding['name'], $resolved );

						continue;
					}

					$ctx->set(
						$binding['name'],
						null !== $binding['default'] ? $this->evaluate( $binding['default'], $ctx ) : null
					);
				}

				continue;
			}

			$ctx->set( $declaration->name, $this->evaluate( $declaration->expr, $ctx ) );
		}

		return null !== $expr->return ? $this->evaluate( $expr->return, $ctx ) : null;
	}

	private function evalFiltered( Filtered $expr, Context $ctx ): mixed {
		$value = $this->evaluate( $expr->value, $ctx );
		$pipeline = [];

		foreach ( $expr->filters as $filter ) {
			$args = [];
			foreach ( $filter->args as $arg ) {
				$args[] = $this->evaluate( $arg, $ctx );
			}

			$pipeline[] = [ $filter->name, $args ];
		}

		return $this->filtered( $value, $pipeline, $ctx );
	}

	/**
	 * Apply a pipeline of `[$name, $args]` filters to a value. Shared by the
	 * interpreter and compiled templates.
	 *
	 * @param list<array{0:string, 1:list<mixed>}> $pipeline
	 */
	public function filtered( mixed $value, array $pipeline, Context $ctx ): mixed {
		foreach ( $pipeline as [ $name, $args ] ) {
			$value = $this->applyFilter( $value, $name, $ctx, ...$args );
		}

		return $value;
	}

	/**
	 * Apply one filter to a value. The value comes first so callers evaluate it
	 * before the filter is looked up — the interpreter's order, which decides
	 * which error surfaces when both the value and the filter name are bad.
	 * Compiled templates emit one nested call per filter instead of building a
	 * pipeline array.
	 */
	public function applyFilter( mixed $value, string $name, Context $ctx, mixed ...$args ): mixed {
		$callable = $ctx->environment->filter( $name );

		if ( null === $callable ) {
			throw new UnknownFilterException( sprintf( 'Unknown filter %s', $name ) );
		}

		return $callable( $value, ...$args );
	}

	// ---------------------------------------------------------------------
	// Method dispatch
	// ---------------------------------------------------------------------

	/**
	 * @param list<mixed> $args
	 */
	public function methodCall( mixed $object, string $method, array $args, Context $ctx ): mixed {
		// Lenient on nothing — Liquid's `{% for line in nil %}` is an empty
		// loop, and themes write `{collection.products.map(...)}` where the
		// collection may be absent.
		if ( null === $object ) {
			return match ( $method ) {
				'map', 'filter', 'find' => [],
				'some'                   => false,
				'every'                  => true,
				default                  => null,
			};
		}

		if ( is_array( $object ) ) {
			return $this->arrayMethod( $object, $method, $args, $ctx );
		}

		if ( is_string( $object ) ) {
			return $this->stringMethod( $object, $method, $args );
		}

		if ( is_object( $object ) && is_callable( [ $object, $method ] ) ) {
			return $object->$method( ...$args );
		}

		// Hosts hand over lazy collections (`\Countable` + `\Traversable` —
		// e.g. a DB-backed paginated list) as plain values. Treat them like the
		// arrays they stand in for: `.map`/`.filter`/`.length` fetch only what
		// the collection itself decides to expose, never a full table read.
		if ( is_object( $object ) && $object instanceof \Traversable ) {
			return $this->arrayMethod( iterator_to_array( $object ), $method, $args, $ctx );
		}

		throw new LiqxException( sprintf( 'Cannot call method %s on %s', $method, get_debug_type( $object ) ) );
	}

	/**
	 * @param array<mixed> $object
	 * @param list<mixed>  $args
	 */
	private function arrayMethod( array $object, string $method, array $args, Context $ctx ): mixed {
		return match ( $method ) {
			'map'    => $this->arrayMap( $object, $args[0] ?? null, $ctx ),
			'filter' => $this->arrayFilter( $object, $args[0] ?? null, $ctx ),
			'find'   => $this->arrayFind( $object, $args[0] ?? null, $ctx ),
			'some'   => $this->arraySome( $object, $args[0] ?? null, $ctx ),
			'every'  => $this->arrayEvery( $object, $args[0] ?? null, $ctx ),
			'join'   => implode( (string) ( $args[0] ?? '' ), array_values( $object ) ),
			'includes' => in_array( $args[0] ?? null, $object, true ),
			'concat'   => array_merge( $object, is_array( $args[0] ?? null ) ? $args[0] : [] ),
			'slice'    => array_values( array_slice( $object, (int) ( $args[0] ?? 0 ), isset( $args[1] ) ? (int) $args[1] : null ) ),
			'length'   => count( $object ),
			'indexOf'  => $this->indexOf( $object, $args[0] ?? null ),
			default    => throw new LiqxException( sprintf( 'Unknown array method %s', $method ) ),
		};
	}

	/** @param list<mixed> $args */
	private function stringMethod( string $object, string $method, array $args ): mixed {
		return match ( $method ) {
			'toUpperCase' => mb_strtoupper( $object ),
			'toLowerCase' => mb_strtolower( $object ),
			'includes'    => false !== mb_strpos( $object, (string) ( $args[0] ?? '' ) ),
			'slice'       => mb_substr( $object, (int) ( $args[0] ?? 0 ), isset( $args[1] ) ? (int) $args[1] : null ),
			'replaceAll'  => str_replace( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ), $object ),
			'replace'     => str_replace( (string) ( $args[0] ?? '' ), (string) ( $args[1] ?? '' ), $object ),
			'trim'        => trim( $object ),
			'split'       => explode( (string) ( $args[0] ?? '' ), $object ),
			'startsWith'  => str_starts_with( $object, (string) ( $args[0] ?? '' ) ),
			'endsWith'    => str_ends_with( $object, (string) ( $args[0] ?? '' ) ),
			'length'      => mb_strlen( $object ),
			default       => throw new LiqxException( sprintf( 'Unknown string method %s', $method ) ),
		};
	}

	/**
	 * @param array<mixed> $object
	 * @return array<mixed>
	 */
	private function arrayMap( array $object, mixed $callback, Context $ctx ): array {
		$fn = $this->asCallback( $callback, $ctx );
		$out = [];
		$i = 0;
		foreach ( $object as $element ) {
			$out[] = $fn( $element, $i++, $object );
		}

		return $out;
	}

	/**
	 * @param array<mixed> $object
	 * @return array<mixed>
	 */
	private function arrayFilter( array $object, mixed $callback, Context $ctx ): array {
		$fn = $this->asCallback( $callback, $ctx );
		$out = [];
		$i = 0;
		foreach ( $object as $element ) {
			if ( $this->truthy( $fn( $element, $i++, $object ) ) ) {
				$out[] = $element;
			}
		}

		return $out;
	}

	/**
	 * @param array<mixed> $object
	 */
	private function arrayFind( array $object, mixed $callback, Context $ctx ): mixed {
		$fn = $this->asCallback( $callback, $ctx );
		$i = 0;
		foreach ( $object as $element ) {
			if ( $this->truthy( $fn( $element, $i++, $object ) ) ) {
				return $element;
			}
		}

		return null;
	}

	/**
	 * `some` — any element passes the predicate (empty → false, like JS).
	 * @param array<mixed> $object
	 */
	private function arraySome( array $object, mixed $callback, Context $ctx ): bool {
		$fn = $this->asCallback( $callback, $ctx );
		$i  = 0;
		foreach ( $object as $element ) {
			if ( $this->truthy( $fn( $element, $i++, $object ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `every` — all elements pass the predicate (empty → true, like JS).
	 * @param array<mixed> $object
	 */
	private function arrayEvery( array $object, mixed $callback, Context $ctx ): bool {
		$fn = $this->asCallback( $callback, $ctx );
		$i  = 0;
		foreach ( $object as $element ) {
			if ( ! $this->truthy( $fn( $element, $i++, $object ) ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<mixed> $object */
	private function indexOf( array $object, mixed $needle ): int {
		$index = array_search( $needle, $object, true );

		return false === $index ? -1 : (int) $index;
	}

	public function asCallback( mixed $callback, Context $ctx ): callable {
		if ( $callback instanceof ArrowFunction ) {
			return fn ( mixed ...$args ) => $this->invoke( $callback, $args, $ctx );
		}

		if ( is_callable( $callback ) ) {
			return $callback;
		}

		throw new LiqxException( 'Callback is not callable' );
	}

	/** @param list<mixed> $args */
	public function invoke( mixed $fn, array $args, Context $ctx ): mixed {
		if ( $fn instanceof ArrowFunction ) {
			$scope = [];
			foreach ( $fn->params as $i => $param ) {
				$scope[ $param ] = $args[ $i ] ?? null;
			}

			$ctx->push( $scope );

			try {
				return $this->evaluate( $fn->body, $ctx );
			} finally {
				$ctx->pop();
			}
		}

		if ( is_callable( $fn ) ) {
			return $fn( ...$args );
		}

		throw new LiqxException( 'Expression is not callable' );
	}

	// ---------------------------------------------------------------------
	// Value helpers
	// ---------------------------------------------------------------------

	/**
	 * Resolve a destructuring key against a value — arrays, public properties,
	 * and drop `beforeMethod` lookups.
	 *
	 * @return array{found:bool, value:mixed}
	 */
	public function lookupProperty( mixed $object, string $key ): array {
		if ( is_array( $object ) ) {
			return [ 'found' => array_key_exists( $key, $object ), 'value' => $object[ $key ] ?? null ];
		}

		if ( is_object( $object ) ) {
			return [ 'found' => true, 'value' => $this->getProperty( $object, $key ) ];
		}

		return [ 'found' => false, 'value' => null ];
	}

	public function getProperty( mixed $object, mixed $key ): mixed {
		if ( is_array( $object ) ) {
			if ( 'length' === $key ) {
				return count( $object );
			}

			return $object[ $key ] ?? null;
		}

		if ( 'length' === $key ) {
			if ( is_string( $object ) ) {
				return mb_strlen( $object );
			}

			// Lazy collections answer size without hydrating items (a COUNT).
			if ( $object instanceof \Countable ) {
				return count( $object );
			}
		}

		if ( is_object( $object ) ) {
			if ( $object instanceof Drop ) {
				return $object->beforeMethod( (string) $key );
			}

			if ( method_exists( $object, 'beforeMethod' ) ) {
				return $object->beforeMethod( (string) $key );
			}

			if ( isset( $object->{ (string) $key } ) ) {
				return $object->{ (string) $key };
			}

			if ( $object instanceof \ArrayAccess ) {
				return $object->offsetExists( $key ) ? $object[ $key ] : null;
			}

			if ( array_key_exists( (string) $key, get_object_vars( $object ) ) ) {
				return $object->{ (string) $key };
			}
		}

		return null;
	}

	public function truthy( mixed $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}

		if ( 0 === $value || 0.0 === $value || '' === $value || '0' === $value ) {
			return false;
		}

		return true;
	}

	public function toNumber( mixed $value ): int|float {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( null === $value || '' === $value ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			return $value + 0;
		}

		return 0;
	}

	public function compare( mixed $left, mixed $right ): int {
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return $this->toNumber( $left ) <=> $this->toNumber( $right );
		}

		return (string) $left <=> (string) $right;
	}

	public function looseEqual( mixed $left, mixed $right ): bool {
		if ( $left === $right ) {
			return true;
		}

		if ( is_bool( $left ) || is_bool( $right ) ) {
			return $this->truthy( $left ) === $this->truthy( $right );
		}

		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return (float) $left === (float) $right;
		}

		if ( null === $left || null === $right ) {
			return null === $left && null === $right;
		}

		if ( is_string( $left ) || is_string( $right ) ) {
			return (string) $left === (string) $right;
		}

		return $left == $right;
	}

	/**
	 * Build one `name="value"` attribute from a value, honoring Liqx's
	 * attribute rules: `true` → bare attribute, `false`/`null` → dropped.
	 */
	public function attribute( string $name, mixed $value ): string {
		if ( true === $value ) {
			return ' ' . $name;
		}

		if ( false === $value || null === $value ) {
			return '';
		}

		return ' ' . $name . '="' . $this->stringify( $value ) . '"';
	}

	/** Spread `{...attrs}` — only arrays spread, and `key` is skipped. */
	public function spreadAttrs( mixed $value ): string {
		$out = '';

		if ( is_array( $value ) ) {
			foreach ( $value as $name => $v ) {
				if ( 'key' === $name ) {
					continue;
				}

				$out .= ' ' . $name . '="' . $this->stringify( $v ) . '"';
			}
		}

		return $out;
	}

	public function stringify( mixed $value ): string {
		if ( null === $value || false === $value ) {
			return '';
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( is_array( $value ) ) {
			return implode( ' ', array_map( fn ( $v ) => $this->stringify( $v ), $value ) );
		}

		return (string) $value;
	}

	/** Rendering an output value to markup — mirrors Renderer::renderValue. */
	public function renderValue( mixed $value, Context $ctx ): string {
		return $this->renderer->renderValue( $value, $ctx );
	}

	/**
	 * Parse + evaluate a standalone expression string (used for `${…}`
	 * template parts and `<style>` interpolation).
	 */
	public function evaluateString( string $text, Context $ctx ): mixed {
		$stream = ( new Lexer() )->tokenize( '{' . $text . '}' );
		$stream->next(); // ExpressionStart
		$expr = ( new ExpressionParser( $stream ) )->parse();

		return $this->evaluate( $expr, $ctx );
	}

	/**
	 * Formats wrapper attributes (raw string or key-value map).
	 *
	 * @param array<string, mixed>|string|null $attrs
	 */
	public function formatWrapperAttrs( array|string|null $attrs ): string {
		if ( null === $attrs || '' === $attrs || [] === $attrs ) {
			return '';
		}

		if ( is_string( $attrs ) ) {
			$trimmed = trim( $attrs );

			return '' === $trimmed ? '' : ' ' . $trimmed;
		}

		$out = '';

		foreach ( $attrs as $name => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}

			if ( true === $value ) {
				$out .= ' ' . $name;

				continue;
			}

			$out .= ' ' . $name . '="' . $this->stringify( $value ) . '"';
		}

		return $out;
	}
}
