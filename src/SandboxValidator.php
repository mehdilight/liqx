<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Expr\ArrayLit;
use Phpmystic\Liqx\Expr\ArrowFunction;
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
 * Enforces PRD §5: the frontmatter block is a restricted, sandboxed subset of
 * JS — data plus restricted logic, not a script. The grammar already rules
 * out loops, `function`, `new`, assignment, and class/import; this pass
 * rejects the remaining escape hatches explicitly and with a clear error:
 *
 *   - dangerous globals / callables (eval, Function, window, process, …);
 *   - `constructor` / `__proto__` / `prototype` property access;
 *   - arrow functions not used as a `.map()` / `.filter()` / `.find()` /
 *     `.some()` / `.every()` callback (no arbitrary function definitions);
 *   - JSX elements (markup belongs in the render body).
 *
 * Runs at parse time, so a bad `.liqx` file fails before it ever compiles.
 */
final class SandboxValidator {

	private const FORBIDDEN = [
		'eval', 'Function', 'globalThis', 'window', 'document', 'process',
		'require', 'fetch', 'import', 'exports', 'module', 'alert', 'confirm',
		'prompt', 'constructor', '__proto__', 'prototype',
	];

	private const COLLECTION_CALLBACKS = [ 'map', 'filter', 'find', 'some', 'every' ];

	public function validateDeclaration( Frontmatter|FrontmatterDestructure $declaration ): void {
		$line = $declaration->line;

		if ( $declaration instanceof Frontmatter ) {
			$this->rejectReservedName( $declaration->name, $line );

			$this->validateExpr( $declaration->expr, arrowsAllowed: false, line: $line );

			return;
		}

		foreach ( $declaration->bindings as $binding ) {
			$this->rejectReservedName( $binding['name'], $line );

			if ( null !== $binding['default'] ) {
				$this->validateExpr( $binding['default'], arrowsAllowed: false, line: $line );
			}
		}

		$this->validateExpr( $declaration->init, arrowsAllowed: false, line: $line );
	}

	private function rejectReservedName( string $name, int $line ): void {
		if ( 'root' === $name ) {
			throw new SyntaxException( '`root` is reserved and cannot be declared in frontmatter', $line );
		}
	}

	/**
	 * Validate the frontmatter `return <expr>;` — the expression that becomes the
	 * body's `props`. It may reference data/consts and use globals + filters, but
	 * must not smuggle markup (JSX) or arbitrary functions into the props.
	 */
	public function validateExported( Expr $expr, int $line ): void {
		$this->validateExpr( $expr, arrowsAllowed: false, line: $line );
	}

	private function validateExpr( Expr $expr, bool $arrowsAllowed, int $line ): void {
		if ( $expr instanceof Identifier ) {
			if ( in_array( $expr->name, self::FORBIDDEN, true ) ) {
				throw new SyntaxException( sprintf( '%s is not allowed in frontmatter', $expr->name ), $line );
			}

			return;
		}

		if ( $expr instanceof Member ) {
			$this->validateExpr( $expr->object, $arrowsAllowed, $line );

			if ( $expr->computed ) {
				$this->validateExpr( $expr->access, $arrowsAllowed, $line );
			} elseif ( in_array( $expr->access, self::FORBIDDEN, true ) ) {
				throw new SyntaxException( sprintf( '%s is not allowed in frontmatter', $expr->access ), $line );
			}

			return;
		}

		if ( $expr instanceof Call ) {
			$isCollectionCallback = $expr->callee instanceof Member
				&& ! $expr->callee->computed
				&& in_array( $expr->callee->access, self::COLLECTION_CALLBACKS, true );

			$this->validateExpr( $expr->callee, $arrowsAllowed, $line );

			foreach ( $expr->args as $arg ) {
				$this->validateExpr( $arg, $isCollectionCallback, $line );
			}

			return;
		}

		if ( $expr instanceof ArrowFunction ) {
			if ( ! $arrowsAllowed ) {
				throw new SyntaxException( 'Arrow functions are only allowed as .map()/.filter()/.find() callbacks in frontmatter', $line );
			}

			$this->validateExpr( $expr->body, arrowsAllowed: false, line: $line );

			return;
		}

		if ( $expr instanceof BlockBody ) {
			foreach ( $expr->declarations as $declaration ) {
				$this->validateDeclaration( $declaration );
			}

			if ( null !== $expr->return ) {
				$this->validateExpr( $expr->return, arrowsAllowed: false, line: $line );
			}

			return;
		}

		if ( $expr instanceof Element ) {
			throw new SyntaxException( 'JSX elements are not allowed in frontmatter', $line );
		}

		if ( $expr instanceof Filtered ) {
			$this->validateExpr( $expr->value, $arrowsAllowed, $line );

			foreach ( $expr->filters as $filter ) {
				foreach ( $filter->args as $arg ) {
					$this->validateExpr( $arg, arrowsAllowed: false, line: $line );
				}
			}

			return;
		}

		if ( $expr instanceof Binary ) {
			$this->validateExpr( $expr->left, $arrowsAllowed, $line );
			$this->validateExpr( $expr->right, $arrowsAllowed, $line );

			return;
		}

		if ( $expr instanceof Logical ) {
			$this->validateExpr( $expr->left, $arrowsAllowed, $line );
			$this->validateExpr( $expr->right, $arrowsAllowed, $line );

			return;
		}

		if ( $expr instanceof Conditional ) {
			$this->validateExpr( $expr->test, $arrowsAllowed, $line );
			$this->validateExpr( $expr->consequent, $arrowsAllowed, $line );
			$this->validateExpr( $expr->alternate, $arrowsAllowed, $line );

			return;
		}

		if ( $expr instanceof Unary ) {
			$this->validateExpr( $expr->operand, $arrowsAllowed, $line );

			return;
		}

		if ( $expr instanceof ArrayLit ) {
			foreach ( $expr->elements as $element ) {
				$this->validateExpr( $element, $arrowsAllowed, $line );
			}

			return;
		}

		if ( $expr instanceof ObjectLit ) {
			foreach ( $expr->properties as [ , $value ] ) {
				$this->validateExpr( $value, $arrowsAllowed, $line );
			}

			return;
		}

		// Literal / TemplateString carry no structure to walk.
	}
}