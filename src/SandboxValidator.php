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
use Phpmystic\Liqx\Node\FrontmatterAssignment;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\FrontmatterFunction;
use Phpmystic\Liqx\Node\FrontmatterIf;
use Phpmystic\Liqx\Node\FrontmatterReturn;
use Phpmystic\Liqx\Node\FrontmatterSwitch;

/**
 * Enforces PRD §5: the frontmatter block is a restricted, sandboxed subset of
 * JS — data plus restricted logic, not a script. The grammar already rules
 * out loops, `new`, and class/import; this pass
 * rejects dangerous escape hatches explicitly and with a clear error:
 *
 *   - dangerous globals / callables (eval, Function, window, process, …);
 *   - `constructor` / `__proto__` / `prototype` property access;
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

	/**
	 * The only method names callable on a value in frontmatter — the curated
	 * array/string methods the evaluator implements. Anything else (e.g.
	 * `user.deleteAll()`) is rejected at parse time so frontmatter can never
	 * invoke an arbitrary method on data.
	 */
	private const CALLABLE_METHODS = [
		'map', 'filter', 'find', 'some', 'every', 'join', 'includes',
		'concat', 'slice', 'indexOf',
		'toUpperCase', 'toLowerCase', 'replace', 'replaceAll', 'trim',
		'split', 'startsWith', 'endsWith',
	];

	public function validateDeclaration( object $declaration ): void {
		$this->validateStatement( $declaration );
	}

	public function validateStatement( object $stmt ): void {
		if ( $stmt instanceof Frontmatter ) {
			$this->rejectReservedName( $stmt->name, $stmt->line );
			$this->validateExpr( $stmt->expr, arrowsAllowed: true, line: $stmt->line );

			return;
		}

		if ( $stmt instanceof FrontmatterDestructure ) {
			foreach ( $stmt->bindings as $binding ) {
				$this->rejectReservedName( $binding['name'], $stmt->line );

				if ( null !== $binding['default'] ) {
					$this->validateExpr( $binding['default'], arrowsAllowed: true, line: $stmt->line );
				}
			}

			$this->validateExpr( $stmt->init, arrowsAllowed: true, line: $stmt->line );

			return;
		}

		if ( $stmt instanceof FrontmatterAssignment ) {
			$this->rejectReservedName( $stmt->name, $stmt->line );
			$this->validateExpr( $stmt->expr, arrowsAllowed: true, line: $stmt->line );

			return;
		}

		if ( $stmt instanceof FrontmatterIf ) {
			$this->validateExpr( $stmt->test, arrowsAllowed: true, line: $stmt->line );

			foreach ( $stmt->then as $inner ) {
				$this->validateStatement( $inner );
			}

			foreach ( $stmt->elseIfs as $elseIf ) {
				$this->validateExpr( $elseIf['test'], arrowsAllowed: true, line: $stmt->line );
				foreach ( $elseIf['body'] as $inner ) {
					$this->validateStatement( $inner );
				}
			}

			foreach ( $stmt->else as $inner ) {
				$this->validateStatement( $inner );
			}

			return;
		}

		if ( $stmt instanceof FrontmatterSwitch ) {
			$this->validateExpr( $stmt->discriminant, arrowsAllowed: true, line: $stmt->line );

			foreach ( $stmt->cases as $case ) {
				if ( null !== $case['test'] ) {
					$this->validateExpr( $case['test'], arrowsAllowed: true, line: $stmt->line );
				}
				foreach ( $case['body'] as $inner ) {
					$this->validateStatement( $inner );
				}
			}

			return;
		}

		if ( $stmt instanceof FrontmatterFunction ) {
			$this->rejectReservedName( $stmt->name, $stmt->line );

			foreach ( $stmt->params as $param ) {
				$this->rejectReservedName( $param['name'], $stmt->line );
				if ( null !== $param['default'] ) {
					$this->validateExpr( $param['default'], arrowsAllowed: true, line: $stmt->line );
				}
			}

			foreach ( $stmt->body as $inner ) {
				$this->validateStatement( $inner );
			}

			if ( null !== $stmt->return ) {
				$this->validateExpr( $stmt->return, arrowsAllowed: true, line: $stmt->line );
			}

			return;
		}

		if ( $stmt instanceof FrontmatterReturn ) {
			if ( null !== $stmt->expr ) {
				$this->validateExpr( $stmt->expr, arrowsAllowed: true, line: $stmt->line );
			}

			return;
		}

		if ( $stmt instanceof Expr ) {
			$this->validateExpr( $stmt, arrowsAllowed: true, line: 0 );
		}
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
		$this->validateExpr( $expr, arrowsAllowed: true, line: $line );
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
			// Only the curated collection/string methods are callable on a value;
			// arbitrary property method calls on data are refused at parse time.
			// Computed method calls (`x[fn]()`) can't be statically verified, so
			// they're refused too.
			if ( $expr->callee instanceof Member ) {
				$method = $expr->callee->access;

				if ( $expr->callee->computed || ! is_string( $method ) || ! in_array( $method, self::CALLABLE_METHODS, true ) ) {
					throw new SyntaxException(
						sprintf(
							'%s is not a callable method in frontmatter; use a defined const or a filter instead',
							is_string( $method ) ? $method : 'Computed method call'
						),
						$line
					);
				}

				$isCollectionCallback = in_array( $method, self::COLLECTION_CALLBACKS, true );
			} else {
				$isCollectionCallback = false;
			}

			$this->validateExpr( $expr->callee, $arrowsAllowed, $line );

			foreach ( $expr->args as $arg ) {
				$this->validateExpr( $arg, true, $line );
			}

			return;
		}

		if ( $expr instanceof ArrowFunction ) {
			$this->validateExpr( $expr->body, arrowsAllowed: true, line: $line );

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