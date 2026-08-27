<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Expr\ArrowFunction;
use Phpmystic\Liqx\Expr\ArrayLit;
use Phpmystic\Liqx\Expr\Binary;
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
			return array_map( fn ( Expr $e ) => $this->evaluate( $e, $ctx ), $expr->elements );
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

		if ( $expr instanceof TemplateString ) {
			return $this->evalTemplateString( $expr, $ctx );
		}

		if ( $expr instanceof Filtered ) {
			return $this->evalFiltered( $expr, $ctx );
		}

		if ( $expr instanceof Element ) {
			return $this->renderer->renderElement( $expr, $ctx );
		}

		throw new LiqxException( 'Cannot evaluate expression node ' . $expr::class );
	}

	// ---------------------------------------------------------------------
	// Evaluators
	// ---------------------------------------------------------------------

	private function evalIdentifier( Identifier $expr, Context $ctx ): mixed {
		[ 'found' => $found, 'value' => $value ] = $ctx->lookup( $expr->name );

		if ( ! $found && $ctx->strict ) {
			throw new UndefinedVariableException( sprintf( 'Undefined variable %s', $expr->name ) );
		}

		return $value;
	}

	private function evalMember( Member $expr, Context $ctx ): mixed {
		$object = $this->evaluate( $expr->object, $ctx );

		if ( $expr->computed ) {
			$key = $this->evaluate( $expr->access, $ctx );
		} else {
			$key = $expr->access;
		}

		return $this->getProperty( $object, $key );
	}

	private function evalCall( Call $expr, Context $ctx ): mixed {
		$args = array_map( fn ( Expr $a ) => $this->evaluate( $a, $ctx ), $expr->args );

		$callee = $expr->callee;

		if ( $callee instanceof Member ) {
			$object = $this->evaluate( $callee->object, $ctx );

			return $this->methodCall( $object, (string) $callee->access, $args, $ctx );
		}

		if ( $callee instanceof Identifier ) {
			$entry = $ctx->environment->globalEntry( $callee->name );

			if ( null !== $entry ) {
				[ $global, $wantsContext ] = $entry;

				return $wantsContext ? $global( $ctx, ...$args ) : $global( ...$args );
			}

			$filter = $ctx->environment->filter( $callee->name );

			if ( null !== $filter ) {
				return $filter( ...$args );
			}

			throw new LiqxException( sprintf( 'Unknown function %s', $callee->name ) );
		}

		$fn = $this->evaluate( $callee, $ctx );

		return $this->invoke( $fn, $args, $ctx );
	}

	private function evalBinary( Binary $expr, Context $ctx ): mixed {
		$left  = $this->evaluate( $expr->left, $ctx );
		$right = $this->evaluate( $expr->right, $ctx );

		return match ( $expr->op ) {
			'+'   => ( is_string( $left ) || is_string( $right ) ) ? (string) $left . (string) $right : $this->toNumber( $left ) + $this->toNumber( $right ),
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
		$raw = substr( $expr->raw, 1, -1 ); // strip surrounding backticks
		$out = '';
		$len = strlen( $raw );
		$i   = 0;

		while ( $i < $len ) {
			$start = strpos( $raw, '${', $i );

			if ( false === $start ) {
				$out .= substr( $raw, $i, $len - $i );

				break;
			}

			$out .= substr( $raw, $i, $start - $i );

			$depth = 1;
			$j     = $start + 2;

			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $raw[ $j ] ) {
					$depth++;
				} elseif ( '}' === $raw[ $j ] ) {
					$depth--;
				}

				$j++;
			}

			$inner = substr( $raw, $start + 2, $j - $start - 3 );
			$out  .= $this->renderer->renderValue( $this->evaluateString( $inner, $ctx ), $ctx );

			$i = $j;
		}

		return $out;
	}

	private function evalFiltered( Filtered $expr, Context $ctx ): mixed {
		$value = $this->evaluate( $expr->value, $ctx );

		foreach ( $expr->filters as $filter ) {
			$callable = $ctx->environment->filter( $filter->name );

			if ( null === $callable ) {
				throw new UnknownFilterException( sprintf( 'Unknown filter %s', $filter->name ) );
			}

			$args = array_map( fn ( Expr $a ) => $this->evaluate( $a, $ctx ), $filter->args );
			$value = $callable( $value, ...$args );
		}

		return $value;
	}

	// ---------------------------------------------------------------------
	// Method dispatch
	// ---------------------------------------------------------------------

	/** @param list<mixed> $args */
	private function methodCall( mixed $object, string $method, array $args, Context $ctx ): mixed {
		// Lenient on nothing — Liquid's `{% for line in nil %}` is an empty
		// loop, and themes write `{collection.products.map(...)}` where the
		// collection may be absent.
		if ( null === $object ) {
			return in_array( $method, [ 'map', 'filter', 'find' ], true ) ? [] : null;
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
		foreach ( array_values( $object ) as $element ) {
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
		foreach ( array_values( $object ) as $element ) {
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
		foreach ( array_values( $object ) as $element ) {
			if ( $this->truthy( $fn( $element, $i++, $object ) ) ) {
				return $element;
			}
		}

		return null;
	}

	/** @param array<mixed> $object */
	private function indexOf( array $object, mixed $needle ): int {
		$index = array_search( $needle, $object, true );

		return false === $index ? -1 : (int) $index;
	}

	private function asCallback( mixed $callback, Context $ctx ): callable {
		if ( $callback instanceof ArrowFunction ) {
			return fn ( mixed ...$args ) => $this->invoke( $callback, $args, $ctx );
		}

		if ( is_callable( $callback ) ) {
			return $callback;
		}

		throw new LiqxException( 'Callback is not callable' );
	}

	/** @param list<mixed> $args */
	private function invoke( mixed $fn, array $args, Context $ctx ): mixed {
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

	private function getProperty( mixed $object, mixed $key ): mixed {
		if ( 'length' === $key ) {
			if ( is_array( $object ) ) {
				return count( $object );
			}

			if ( is_string( $object ) ) {
				return mb_strlen( $object );
			}
		}

		if ( is_array( $object ) ) {
			return $object[ $key ] ?? null;
		}

		if ( $object instanceof \ArrayAccess ) {
			return $object->offsetExists( $key ) ? $object[ $key ] : null;
		}

		if ( is_object( $object ) ) {
			// Public properties only — a private/protected one must answer via
			// beforeMethod (drops resolve everything that way).
			if ( array_key_exists( (string) $key, get_object_vars( $object ) ) ) {
				return $object->{ (string) $key };
			}

			// Duck-typed: hosts bring their own Drop base classes (Sworen's
			// does), so any object answering `beforeMethod` counts.
			if ( $object instanceof Drop || method_exists( $object, 'beforeMethod' ) ) {
				return $object->beforeMethod( (string) $key );
			}
		}

		return null;
	}

	private function truthy( mixed $value ): bool {
		if ( null === $value || false === $value ) {
			return false;
		}

		if ( 0 === $value || 0.0 === $value || '' === $value || '0' === $value ) {
			return false;
		}

		return true;
	}

	private function toNumber( mixed $value ): int|float {
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

	private function compare( mixed $left, mixed $right ): int {
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return $this->toNumber( $left ) <=> $this->toNumber( $right );
		}

		return (string) $left <=> (string) $right;
	}

	private function looseEqual( mixed $left, mixed $right ): bool {
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
	 * Parse + evaluate a standalone expression string (used for `${…}`
	 * template parts and `<style>` interpolation).
	 */
	public function evaluateString( string $text, Context $ctx ): mixed {
		$stream = ( new Lexer() )->tokenize( '{' . $text . '}' );
		$stream->next(); // ExpressionStart
		$expr = ( new ExpressionParser( $stream ) )->parse();

		return $this->evaluate( $expr, $ctx );
	}
}
