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
use Phpmystic\Liqx\Node\Document;
use Phpmystic\Liqx\Node\Element;
use Phpmystic\Liqx\Node\Frontmatter;
use Phpmystic\Liqx\Node\FrontmatterAssignment;
use Phpmystic\Liqx\Node\FrontmatterDestructure;
use Phpmystic\Liqx\Node\FrontmatterFunction;
use Phpmystic\Liqx\Node\FrontmatterIf;
use Phpmystic\Liqx\Node\FrontmatterReturn;
use Phpmystic\Liqx\Node\FrontmatterSwitch;
use Phpmystic\Liqx\Node\Output;
use Phpmystic\Liqx\Node\Script;
use Phpmystic\Liqx\Node\Style;
use Phpmystic\Liqx\Node\TemplateBlock;
use Phpmystic\Liqx\Node\Text;

/**
 * Emits a native PHP closure that renders a Document straight to a string —
 * no AST walking at runtime. The generated source is saved as a `.php` file
 * so OPcache compiles it to bytecode once and serves it from shared memory.
 *
 * All expression semantics (loose equality, truthiness, property lookup,
 * method dispatch, filters) are delegated to the same {@see Evaluator} helpers
 * the interpreter uses, so the two paths cannot drift. Only the *structure* —
 * the per-node/per-expression dispatch — is compiled away.
 */
final class Compiler {

	/**
	 * Bump when the emitter changes so existing cache artifacts (whose keys
	 * derive from the source hash alone) recompile instead of serving stale
	 * PHP.
	 */
	public const VERSION = 5;

	/**
	 * Guard against pathological deeply-nested templates exhausting the PHP
	 * stack during compilation. Reached only by adversarial or machine-generated
	 * input; valid merchant templates sit far below it.
	 */
	private const MAX_NESTING = 2000;

	/**
	 * A fingerprint of this emitter's generated code — the content hash of the
	 * compiler source itself plus the human-bumped {@see self::VERSION}. Cache
	 * artifact keys are derived from it, so editing the emitter automatically
	 * invalidates stale compiled templates without relying on anyone remembering
	 * to bump `VERSION`.
	 */
	/** Memoized {@see fingerprint()} — the compiler source can't change mid-process. */
	private static ?string $fingerprint = null;

	public static function fingerprint(): string {
		if ( null !== self::$fingerprint ) {
			return self::$fingerprint;
		}

		$source = @file_get_contents( __DIR__ . '/Compiler.php' );
		$hash   = false !== $source ? sha1( $source ) : (string) @filemtime( __DIR__ . '/Compiler.php' );

		return self::$fingerprint = self::VERSION . ':' . $hash;
	}

	/**
	 * HTML void elements that must not have closing tags — mirrors the
	 * Renderer so compiled markup matches interpreted markup.
	 *
	 * @var array<string, bool>
	 */
	private const VOID_ELEMENTS = [
		'area'   => true,
		'base'   => true,
		'br'     => true,
		'col'    => true,
		'embed'  => true,
		'hr'     => true,
		'img'    => true,
		'input'  => true,
		'link'   => true,
		'meta'   => true,
		'param'  => true,
		'source' => true,
		'track'  => true,
		'wbr'    => true,
	];

	/**
	 * Compile-time scope stack. Each entry maps a bound name to its local
	 * variable name (no `$` prefix). Arrow parameters and block-body constants
	 * bind to real PHP locals inside their closure, so runtime iteration never
	 * pushes/pops Context scopes — the biggest win over the interpreter.
	 *
	 * @var list<array<string, string>>
	 */
	private array $scopeStack = [];

	/**
	 * Position within {@see $scopeStack} where the innermost closure's own
	 * bindings begin. A name resolved from a shallower index comes from an
	 * enclosing closure and must be captured via `use`.
	 */
	private int $closureStart = 0;

	/**
	 * Local variables (no `$` prefix) the innermost closure references from
	 * enclosing closures — becomes its `use (...)` list. Maps each variable to
	 * the scope index where it is bound, so a capture only propagates up to
	 * closures that are themselves outside that binding. Transiently reset by
	 * {@see arrow()}.
	 *
	 * @var array<string, int>
	 */
	private array $captures = [];

	/**
	 * Global counter making local variable names unique across the whole
	 * generated source, so captured names collide with nothing.
	 */
	private int $varCounter = 0;

	/** Current expression-nesting depth, guarded by {@see self::MAX_NESTING}. */
	private int $exprDepth = 0;

	/**
	 * Names of frontmatter consts already resolved to a compile-time literal
	 * value this compile. Because declarations run in order, a later static
	 * const can reference an earlier one.
	 *
	 * @var array<string, mixed>
	 */
	private array $staticConsts = [];

	private function freshVar(): string {
		return '__v' . ( $this->varCounter++ );
	}

	/**
	 * The inner closure's accumulated captures. Returning through a method keeps
	 * PHPStan widening the set to its declared `array<string,int>` shape instead
	 * of narrowing it to empty during flow analysis inside {@see arrow()}.
	 *
	 * @return array<string, int>
	 */
	private function pendingCaptures(): array {
		return $this->captures;
	}

	/**
	 * @return array{string, int}|null [local variable name (no `$` prefix), scope index]
	 */
	private function resolveBound( string $name ): ?array {
		for ( $i = count( $this->scopeStack ) - 1; $i >= 0; $i-- ) {
			if ( isset( $this->scopeStack[ $i ][ $name ] ) ) {
				return [ $this->scopeStack[ $i ][ $name ], $i ];
			}
		}

		return null;
	}

	/** @var array<string, TemplateBlock> */
	private array $namedTemplates = [];

	private function bindLocal( string $name, string $var ): void {
		$this->scopeStack[ count( $this->scopeStack ) - 1 ][ $name ] = $var;
	}

	public function compile( Document $document ): string {
		$this->staticConsts   = [];
		$this->namedTemplates = $document->namedTemplates;

		// The document's own scope: frontmatter consts bind here, so nested
		// closures capture them via `use` like any other enclosing local.
		$this->scopeStack   = [ [] ];
		$this->closureStart = 0;
		$this->captures     = [];

		$lines = [];

		$lines[] = '<?php';
		$lines[] = 'declare(strict_types=1);';
		$lines[] = '';
		$lines[] = '// Generated by Phpmystic\\Liqx\\Compiler — do not edit.';
		$lines[] = 'return static function ( \\Phpmystic\\Liqx\\Context $ctx, \\Phpmystic\\Liqx\\Evaluator $eval, ?array $wrapper = null ): string {';
		$lines[] = "    \$out = '';";
		$lines[] = '    $ctx->push();';
		$lines[] = '    try {';

		foreach ( $document->namedTemplates as $tName => $tBlock ) {
			$subFnVar = '$__sub_tpl_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $tName );
			$lines[]  = '        ' . $subFnVar . ' = static function ( array $props, \\Phpmystic\\Liqx\\Context $ctx, \\Phpmystic\\Liqx\\Evaluator $eval ): string {';
			$lines[]  = "            \$out = '';";
			$lines[]  = '            $ctx->push( $props );';
			$lines[]  = '            $ctx->set( \'props\', $props );';
			$lines[]  = '            try {';
			foreach ( $this->nodeStatements( $tBlock->children, '                ' ) as $stmt ) {
				$lines[] = $stmt;
			}
			$lines[]  = '            } finally {';
			$lines[]  = '                $ctx->pop();';
			$lines[]  = '            }';
			$lines[]  = '';
			$lines[]  = '            return $out;';
			$lines[]  = '        };';
		}

		foreach ( $document->frontmatter as $declaration ) {
			if ( null !== $document->frontmatterReturn && $declaration instanceof FrontmatterReturn && $declaration->expr === $document->frontmatterReturn ) {
				continue;
			}

			foreach ( $this->declarationLines( $declaration ) as $line ) {
				$lines[] = '        ' . $line;
			}
		}

		// A frontmatter `return { … };` becomes the body's `props`. It is written
		// to the Context as well as a local because the schema validator reads
		// `props` back by name.
		if ( null !== $document->frontmatterReturn ) {
			$init = $this->expr( $document->frontmatterReturn );
			$var  = $this->freshVar();

			$lines[] = '        $ctx->set( \'props\', $' . $var . ' = ' . $init . ' );';

			$this->bindLocal( 'props', $var );
		}

		$hasTemplateBlock = false;

		foreach ( $document->body as $node ) {
			if ( $node instanceof TemplateBlock ) {
				$hasTemplateBlock = true;

				break;
			}
		}

		if ( ! $hasTemplateBlock ) {
			foreach ( $this->wrappedStatements( $document->body, '        ' ) as $statement ) {
				$lines[] = $statement;
			}
		} else {
			foreach ( $this->nodeStatements( $document->body, '        ' ) as $statement ) {
				$lines[] = $statement;
			}
		}

		$lines[] = '    } finally {';
		$lines[] = '        $ctx->pop();';
		$lines[] = '    }';
		$lines[] = '';
		$lines[] = '    return $out;';
		$lines[] = '};';

		return implode( "\n", $lines );
	}

	// ---------------------------------------------------------------------
	// Declarations
	// ---------------------------------------------------------------------

	/**
	 * Document-level frontmatter declarations.
	 *
	 * Each const is written to the Context *and* bound to a PHP local: the
	 * Context write keeps the name visible to things the compiler cannot see
	 * through (schema validation reading `props` back, nested `section()`
	 * renders that inherit the parent scope), while the local is what the body
	 * reads — a direct variable instead of a scope-stack walk per read.
	 *
	 * @return list<string> PHP statements
	 */
	private function declarationLines( object $declaration ): array {
		if ( $declaration instanceof FrontmatterDestructure ) {
			// Destructuring reads data at runtime — never static.
			$lines = [ '$__src = ' . $this->expr( $declaration->init ) . ';' ];

			foreach ( $declaration->bindings as $binding ) {
				$default = null !== $binding['default'] ? $this->expr( $binding['default'] ) : 'null';
				$var     = $this->freshVar();

				$lines[] = '$ctx->set( '
					. var_export( $binding['name'], true )
					. ', $' . $var . ' = ( ( $__hit = $eval->lookupProperty( $__src, ' . var_export( $binding['name'], true ) . ' ) )[\'found\'] ? $__hit[\'value\'] : ' . $default . ' ) );';

				// Bound after its own initializer is compiled, so a binding that
				// references an outer name of the same identifier still reads the
				// outer one.
				$this->bindLocal( $binding['name'], $var );
			}

			return $lines;
		}

		if ( $declaration instanceof Frontmatter ) {
			// A static `const x = <literal-tree>` is folded once at compile time and
			// baked into the artifact as a literal, so the render loop never
			// recomputes it. Non-static consts fall through to a runtime `$ctx->set`.
			$static = $this->tryStaticValue( $declaration->expr );

			if ( $static['folded'] ) {
				$this->staticConsts[ $declaration->name ] = $static['value'];
				$var                                      = $this->freshVar();

				$line = '$ctx->set( ' . var_export( $declaration->name, true ) . ', $' . $var . ' = ' . var_export( $static['value'], true ) . ' );';

				$this->bindLocal( $declaration->name, $var );

				return [ $line ];
			}

			unset( $this->staticConsts[ $declaration->name ] );

			$init = $this->expr( $declaration->expr );
			$var  = $this->freshVar();
			$line = '$ctx->set( ' . var_export( $declaration->name, true ) . ', $' . $var . ' = ' . $init . ' );';

			// Bound after the initializer compiles: `const x = x` reads the outer x.
			$this->bindLocal( $declaration->name, $var );

			return [ $line ];
		}

		if ( $declaration instanceof FrontmatterAssignment ) {
			$val = $this->expr( $declaration->expr );
			$resolved = $this->resolveBound( $declaration->name );
			if ( null !== $resolved ) {
				$var = $resolved[0];
				return [ '$ctx->set( ' . var_export( $declaration->name, true ) . ', $' . $var . ' ' . $declaration->operator . ' ' . $val . ' );' ];
			}

			$var = $this->freshVar();
			$this->bindLocal( $declaration->name, $var );
			return [ '$ctx->set( ' . var_export( $declaration->name, true ) . ', $' . $var . ' ' . $declaration->operator . ' ' . $val . ' );' ];
		}

		if ( $declaration instanceof FrontmatterIf ) {
			$lines = [ 'if ( $eval->truthy( ' . $this->expr( $declaration->test ) . ' ) ) {' ];
			foreach ( $declaration->then as $inner ) {
				foreach ( $this->declarationLines( $inner ) as $l ) {
					$lines[] = '    ' . $l;
				}
			}
			foreach ( $declaration->elseIfs as $elseIf ) {
				$lines[] = '} elseif ( $eval->truthy( ' . $this->expr( $elseIf['test'] ) . ' ) ) {';
				foreach ( $elseIf['body'] as $inner ) {
					foreach ( $this->declarationLines( $inner ) as $l ) {
						$lines[] = '    ' . $l;
					}
				}
			}
			if ( ! empty( $declaration->else ) ) {
				$lines[] = '} else {';
				foreach ( $declaration->else as $inner ) {
					foreach ( $this->declarationLines( $inner ) as $l ) {
						$lines[] = '    ' . $l;
					}
				}
			}
			$lines[] = '}';
			return $lines;
		}

		if ( $declaration instanceof FrontmatterSwitch ) {
			$lines = [ 'switch ( ' . $this->expr( $declaration->discriminant ) . ' ) {' ];
			foreach ( $declaration->cases as $case ) {
				if ( null !== $case['test'] ) {
					$lines[] = '    case ' . $this->expr( $case['test'] ) . ':';
				} else {
					$lines[] = '    default:';
				}
				foreach ( $case['body'] as $inner ) {
					foreach ( $this->declarationLines( $inner ) as $l ) {
						$lines[] = '        ' . $l;
					}
				}
				$lines[] = '        break;';
			}
			$lines[] = '}';
			return $lines;
		}

		if ( $declaration instanceof FrontmatterFunction ) {
			$var = $this->freshVar();
			$lines = [];
			$lines[] = '$' . $var . ' = function( ...$__args ) use ( &$ctx, $eval ) {';
			$lines[] = '    $ctx->push();';
			$lines[] = '    try {';
			foreach ( $declaration->params as $i => $param ) {
				$def = null !== $param['default'] ? $this->expr( $param['default'] ) : 'null';
				$paramVar = $this->freshVar();
				$lines[] = '        $' . $paramVar . ' = array_key_exists( ' . $i . ', $__args ) ? $__args[' . $i . '] : ' . $def . ';';
				$lines[] = '        $ctx->set( ' . var_export( $param['name'], true ) . ', $' . $paramVar . ' );';
				$this->bindLocal( $param['name'], $paramVar );
			}
			foreach ( $declaration->body as $inner ) {
				foreach ( $this->declarationLines( $inner ) as $l ) {
					$lines[] = '        ' . $l;
				}
			}
			if ( null !== $declaration->return ) {
				$lines[] = '        return ' . $this->expr( $declaration->return ) . ';';
			}
			$lines[] = '    } finally {';
			$lines[] = '        $ctx->pop();';
			$lines[] = '    }';
			$lines[] = '};';
			$lines[] = '$ctx->set( ' . var_export( $declaration->name, true ) . ', $' . $var . ' );';
			$this->bindLocal( $declaration->name, $var );
			return $lines;
		}

		if ( $declaration instanceof FrontmatterReturn ) {
			return [ 'return ' . ( null !== $declaration->expr ? $this->expr( $declaration->expr ) : "''" ) . ';' ];
		}

		if ( $declaration instanceof Expr ) {
			return [ $this->expr( $declaration ) . ';' ];
		}

		return [];
	}

	/**
	 * Evaluate a frontmatter expression at compile time. Returns `folded: false`
	 * for anything that depends on render data or whose semantics PHP can't
	 * reproduce exactly (loose equality, JS truthiness), keeping this strictly
	 * parity-safe.
	 *
	 * @return array{folded: bool, value: mixed}
	 */
	private function tryStaticValue( Expr $expr ): array {
		if ( $expr instanceof Literal ) {
			return [ 'folded' => true, 'value' => $expr->value ];
		}

		if ( $expr instanceof Identifier ) {
			if ( ! array_key_exists( $expr->name, $this->staticConsts ) ) {
				return [ 'folded' => false, 'value' => null ];
			}

			return [ 'folded' => true, 'value' => $this->staticConsts[ $expr->name ] ];
		}

		if ( $expr instanceof ArrayLit ) {
			$values = [];

			foreach ( $expr->elements as $element ) {
				$folded = $this->tryStaticValue( $element );

				if ( ! $folded['folded'] ) {
					return [ 'folded' => false, 'value' => null ];
				}

				$values[] = $folded['value'];
			}

			return [ 'folded' => true, 'value' => $values ];
		}

		if ( $expr instanceof ObjectLit ) {
			$values = [];

			foreach ( $expr->properties as [ $key, $value ] ) {
				$folded = $this->tryStaticValue( $value );

				if ( ! $folded['folded'] ) {
					return [ 'folded' => false, 'value' => null ];
				}

				$values[ $key ] = $folded['value'];
			}

			return [ 'folded' => true, 'value' => $values ];
		}

		if ( $expr instanceof TemplateString ) {
			$out = '';

			foreach ( $expr->parts as $part ) {
				if ( is_string( $part ) ) {
					$out .= $part;

					continue;
				}

				$folded = $this->tryStaticValue( $part );

				if ( ! $folded['folded'] ) {
					return [ 'folded' => false, 'value' => null ];
				}

				// Template interpolation renders via stringify; fold only scalar
				// interpolations that stringify identically.
				if ( ! is_scalar( $folded['value'] ) && null !== $folded['value'] ) {
					return [ 'folded' => false, 'value' => null ];
				}

				$out .= $folded['value'];
			}

			return [ 'folded' => true, 'value' => $out ];
		}

		if ( $expr instanceof Unary ) {
			$folded = $this->tryStaticValue( $expr->operand );

			if ( ! $folded['folded'] ) {
				return [ 'folded' => false, 'value' => null ];
			}

			return match ( $expr->op ) {
				'-' => is_int( $folded['value'] ) || is_float( $folded['value'] )
					? [ 'folded' => true, 'value' => -$folded['value'] ]
					: [ 'folded' => false, 'value' => null ],
				'+' => is_int( $folded['value'] ) || is_float( $folded['value'] )
					? [ 'folded' => true, 'value' => +$folded['value'] ]
					: [ 'folded' => false, 'value' => null ],
				'!' => is_bool( $folded['value'] )
					? [ 'folded' => true, 'value' => ! $folded['value'] ]
					: [ 'folded' => false, 'value' => null ],
				default => [ 'folded' => false, 'value' => null ],
			};
		}

		if ( $expr instanceof Binary ) {
			$left  = $this->tryStaticValue( $expr->left );
			$right = $this->tryStaticValue( $expr->right );

			if ( ! $left['folded'] || ! $right['folded'] ) {
				return [ 'folded' => false, 'value' => null ];
			}

			// Numeric arithmetic folds only when both operands are numeric, where
			// Liqx's `toNumber` is the identity and PHP's `+ - * / %` agree.
			if ( in_array( $expr->op, [ '+', '-', '*', '/', '%' ], true ) ) {
				if ( ! ( is_int( $left['value'] ) || is_float( $left['value'] ) ) || ! ( is_int( $right['value'] ) || is_float( $right['value'] ) ) ) {
					return [ 'folded' => false, 'value' => null ];
				}

				return [ 'folded' => true, 'value' => match ( $expr->op ) {
					'+' => $left['value'] + $right['value'],
					'-' => $left['value'] - $right['value'],
					'*' => $left['value'] * $right['value'],
					'/' => $left['value'] / $right['value'],
					'%' => $left['value'] % $right['value'],
				} ];
			}

			// Strict identity — PHP `===`/`!==` match Liqx exactly on scalars.
			if ( '===' === $expr->op || '!==' === $expr->op ) {
				$identical = $left['value'] === $right['value'];

				return [ 'folded' => true, 'value' => '===' === $expr->op ? $identical : ! $identical ];
			}

			return [ 'folded' => false, 'value' => null ];
		}

		return [ 'folded' => false, 'value' => null ];
	}

	/**
	 * Block-body declarations bind to closure-local variables instead of the
	 * Context — no scope push/pop per invocation. Each name is resolved and
	 * bound in order, so a later declaration shadows an earlier one and
	 * initializers can reference previously-declared locals.
	 *
	 * @return list<string> PHP statements (each carrying its trailing `;`)
	 */
	private function declarationLinesLocals( object $declaration ): array {
		if ( $declaration instanceof FrontmatterDestructure ) {
			$srcVar = $this->freshVar();
			$init   = $this->expr( $declaration->init );
			$lines  = [ '( $' . $srcVar . ' = ' . $init . ' );' ];

			foreach ( $declaration->bindings as $binding ) {
				$default = null !== $binding['default'] ? $this->expr( $binding['default'] ) : 'null';
				$var     = $this->freshVar();

				$this->bindLocal( $binding['name'], $var );

				$lines[] = '( $' . $var . ' = ( ( $__hit = $eval->lookupProperty( $' . $srcVar . ', ' . var_export( $binding['name'], true ) . ' ) )[\'found\'] ? $__hit[\'value\'] : ' . $default . ' ) );';
			}

			return $lines;
		}

		if ( $declaration instanceof Frontmatter ) {
			$init = $this->expr( $declaration->expr );
			$var  = $this->freshVar();

			$this->bindLocal( $declaration->name, $var );

			return [ '( $' . $var . ' = ' . $init . ' );' ];
		}

		if ( $declaration instanceof FrontmatterAssignment ) {
			$val = $this->expr( $declaration->expr );
			$resolved = $this->resolveBound( $declaration->name );
			if ( null !== $resolved ) {
				$var = $resolved[0];
				return [ '( $' . $var . ' ' . $declaration->operator . ' ' . $val . ' );' ];
			}

			$var = $this->freshVar();
			$this->bindLocal( $declaration->name, $var );
			return [ '( $' . $var . ' ' . $declaration->operator . ' ' . $val . ' );' ];
		}

		if ( $declaration instanceof FrontmatterIf ) {
			$lines = [ 'if ( $eval->truthy( ' . $this->expr( $declaration->test ) . ' ) ) {' ];
			foreach ( $declaration->then as $inner ) {
				foreach ( $this->declarationLinesLocals( $inner ) as $l ) {
					$lines[] = '    ' . $l;
				}
			}
			foreach ( $declaration->elseIfs as $elseIf ) {
				$lines[] = '} elseif ( $eval->truthy( ' . $this->expr( $elseIf['test'] ) . ' ) ) {';
				foreach ( $elseIf['body'] as $inner ) {
					foreach ( $this->declarationLinesLocals( $inner ) as $l ) {
						$lines[] = '    ' . $l;
					}
				}
			}
			if ( ! empty( $declaration->else ) ) {
				$lines[] = '} else {';
				foreach ( $declaration->else as $inner ) {
					foreach ( $this->declarationLinesLocals( $inner ) as $l ) {
						$lines[] = '    ' . $l;
					}
				}
			}
			$lines[] = '}';
			return $lines;
		}

		if ( $declaration instanceof FrontmatterSwitch ) {
			$lines = [ 'switch ( ' . $this->expr( $declaration->discriminant ) . ' ) {' ];
			foreach ( $declaration->cases as $case ) {
				if ( null !== $case['test'] ) {
					$lines[] = '    case ' . $this->expr( $case['test'] ) . ':';
				} else {
					$lines[] = '    default:';
				}
				foreach ( $case['body'] as $inner ) {
					foreach ( $this->declarationLinesLocals( $inner ) as $l ) {
						$lines[] = '        ' . $l;
					}
				}
				$lines[] = '        break;';
			}
			$lines[] = '}';
			return $lines;
		}

		if ( $declaration instanceof FrontmatterReturn ) {
			return [ 'return ' . ( null !== $declaration->expr ? $this->expr( $declaration->expr ) : 'null' ) . ';' ];
		}

		if ( $declaration instanceof Expr ) {
			return [ $this->expr( $declaration ) . ';' ];
		}

		return [];
	}

	// ---------------------------------------------------------------------
	// Nodes
	// ---------------------------------------------------------------------

	/**
	 * The body wrapped in the optional host wrapper tag.
	 *
	 * The wrapper only contributes an opening and a closing tag, and whether it
	 * applies is known before any output exists — so the tag is emitted
	 * conditionally around a body that appears exactly once. Emitting the body
	 * inside both branches would double the artifact (and with it OPcache
	 * memory and first-request compile time).
	 *
	 * @param list<\Phpmystic\Liqx\Node> $nodes
	 * @return list<string>
	 */
	private function wrappedStatements( array $nodes, string $indent ): array {
		$statements = [];

		// A fresh local per wrapper: a nested `<template>` emits its own wrapper
		// block, and sharing one name would let the inner assignment clobber the
		// outer tag before its closing tag is written.
		$tag = '$' . $this->freshVar();

		$statements[] = $indent . $tag . ' = ( null !== $wrapper && ! empty( $wrapper[\'tag\'] ) && \'none\' !== $wrapper[\'tag\'] ) ? $wrapper[\'tag\'] : null;';
		$statements[] = $indent . 'if ( null !== ' . $tag . ' ) {';
		$statements[] = $indent . '    $out .= \'<\' . ' . $tag . ' . $eval->formatWrapperAttrs( $wrapper[\'attrs\'] ?? null ) . \'>\';';
		$statements[] = $indent . '}';

		foreach ( $this->nodeStatements( $nodes, $indent ) as $statement ) {
			$statements[] = $statement;
		}

		$statements[] = $indent . 'if ( null !== ' . $tag . ' ) {';
		$statements[] = $indent . '    $out .= \'</\' . ' . $tag . ' . \'>\';';
		$statements[] = $indent . '}';

		return $statements;
	}

	/**
	 * A flat list of `$out .= <expr>;` statements, merging adjacent text.
	 *
	 * @param list<\Phpmystic\Liqx\Node> $nodes
	 * @return list<string>
	 */
	private function nodeStatements( array $nodes, string $indent ): array {
		$statements = [];
		$text       = '';

		foreach ( $nodes as $node ) {
			if ( $node instanceof TemplateBlock ) {
				if ( '' !== $text ) {
					$statements[] = $indent . '$out .= ' . var_export( $text, true ) . ';';
					$text         = '';
				}

				foreach ( $this->wrappedStatements( $node->children, $indent ) as $stmt ) {
					$statements[] = $stmt;
				}

				continue;
			}

			if ( $node instanceof Text ) {
				$text .= $node->value;

				continue;
			}

			if ( '' !== $text ) {
				$statements[] = $indent . '$out .= ' . var_export( $text, true ) . ';';
				$text         = '';
			}

			$statements[] = $indent . '$out .= ' . $this->node( $node ) . ';';
		}

		if ( '' !== $text ) {
			$statements[] = $indent . '$out .= ' . var_export( $text, true ) . ';';
		}

		return $statements;
	}

	/**
	 * A parenthesized PHP expression producing this node's markup.
	 */
	private function node( object $node ): string {
		if ( $node instanceof TemplateBlock ) {
			return '( ( null !== $wrapper && ! empty( $wrapper[\'tag\'] ) && \'none\' !== $wrapper[\'tag\'] ) ? ( \'<\' . $wrapper[\'tag\'] . $eval->formatWrapperAttrs( $wrapper[\'attrs\'] ?? null ) . \'>\' . ' . $this->children( $node->children ) . ' . \'</\' . $wrapper[\'tag\'] . \'>\' ) : ' . $this->children( $node->children ) . ' )';
		}

		if ( $node instanceof Text ) {
			return '(' . var_export( $node->value, true ) . ')';
		}

		if ( $node instanceof Output ) {
			return $this->outputValue( $node->expr );
		}

		if ( $node instanceof Element ) {
			return $this->element( $node );
		}

		if ( $node instanceof Style ) {
			return $this->style( $node );
		}

		if ( $node instanceof Script ) {
			return $this->script( $node );
		}

		throw new LiqxException( 'Cannot compile node ' . $node::class );
	}

	private function element( Element $element ): string {
		if ( '' !== $element->tag ) {
			$tagLower = strtolower( $element->tag );
			if ( 'if' === $tagLower ) {
				return $this->compileIf( $element );
			}
			if ( 'show' === $tagLower ) {
				return $this->compileShow( $element );
			}
			if ( 'switch' === $tagLower ) {
				return $this->compileSwitch( $element );
			}
			if ( ctype_upper( $element->tag[0] ) ) {
				return $this->component( $element );
			}
		}

		if ( 'slot' === strtolower( $element->tag ) ) {
			return $this->slot( $element );
		}

		$tag = var_export( $element->tag, true );

		if ( $element->selfClosing ) {
			if ( isset( self::VOID_ELEMENTS[ strtolower( $element->tag ) ] ) ) {
				return "( '<' . {$tag} . " . $this->attrs( $element->attrs ) . " . ' />' )";
			}

			return "( '<' . {$tag} . " . $this->attrs( $element->attrs ) . " . '></' . {$tag} . '>' )";
		}

		return "( '<' . {$tag} . " . $this->attrs( $element->attrs ) . " . '>' . " . $this->children( $element->children ) . " . '</' . {$tag} . '>' )";
	}

	private function compileIf( Element $element ): string {
		$mainCondCode = $this->compileConditionAttr( $element );

		$mainChildren = [];
		$elseIfBranches = [];
		$elseChildren = null;

		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$tagLower = strtolower( $child->tag );
				if ( 'elseif' === $tagLower ) {
					$elseIfBranches[] = [
						'cond' => $this->compileConditionAttr( $child ),
						'children' => $child->children,
					];
					continue;
				}
				if ( 'else' === $tagLower ) {
					$elseChildren = $child->children;
					continue;
				}
			}
			if ( [] === $elseIfBranches && null === $elseChildren ) {
				$mainChildren[] = $child;
			}
		}

		$fallbackCode = null !== $elseChildren ? $this->children( $elseChildren ) : "''";

		for ( $i = count( $elseIfBranches ) - 1; $i >= 0; $i-- ) {
			$branch = $elseIfBranches[ $i ];
			$fallbackCode = "( \$eval->truthy( {$branch['cond']} ) ? {$this->children( $branch['children'] )} : {$fallbackCode} )";
		}

		return "( \$eval->truthy( {$mainCondCode} ) ? {$this->children( $mainChildren )} : {$fallbackCode} )";
	}

	private function compileShow( Element $element ): string {
		$whenCode = $this->compileConditionAttr( $element, [ 'when', 'condition', 'cond', 'is' ] );

		$fallbackAttrCode = null;
		foreach ( $element->attrs as $attr ) {
			if ( 'fallback' === $attr['name'] ) {
				$fallbackAttrCode = null === $attr['value'] ? "''" : $this->outputValue( $attr['value'] );
				break;
			}
		}

		$mainChildren = [];
		$fallbackSlotChildren = null;

		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$tagLower = strtolower( $child->tag );
				if ( 'template' === $tagLower ) {
					$isFallbackSlot = false;
					foreach ( $child->attrs as $a ) {
						if ( 'slot' === $a['name'] ) {
							$valStr = null === $a['value'] ? 'default' : ( $a['value'] instanceof Literal ? (string) $a['value']->value : null );
							if ( 'fallback' === $valStr ) {
								$isFallbackSlot = true;
								break;
							}
						}
					}
					if ( $isFallbackSlot ) {
						$fallbackSlotChildren = $child->children;
						continue;
					}
				} elseif ( 'fallback' === $tagLower ) {
					$fallbackSlotChildren = $child->children;
					continue;
				}
			}
			$mainChildren[] = $child;
		}

		if ( null !== $fallbackSlotChildren ) {
			$fallbackCode = $this->children( $fallbackSlotChildren );
		} elseif ( null !== $fallbackAttrCode ) {
			$fallbackCode = $fallbackAttrCode;
		} else {
			$fallbackCode = "''";
		}

		return "( \$eval->truthy( {$whenCode} ) ? {$this->children( $mainChildren )} : {$fallbackCode} )";
	}

	private function compileSwitch( Element $element ): string {
		$hasTarget = false;
		$targetCode = null;
		foreach ( $element->attrs as $attr ) {
			if ( 'value' === $attr['name'] || 'val' === $attr['name'] ) {
				$hasTarget = true;
				$targetCode = null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
				break;
			}
		}

		$defaultChildren = null;
		$matches = [];

		foreach ( $element->children as $child ) {
			if ( $child instanceof Element ) {
				$tagLower = strtolower( $child->tag );
				if ( 'match' === $tagLower ) {
					$isDefault = false;
					$matchValCode = null;
					$hasMatchVal = false;
					foreach ( $child->attrs as $a ) {
						if ( 'default' === $a['name'] ) {
							$isDefault = true;
							break;
						}
						if ( 'when' === $a['name'] || 'value' === $a['name'] || 'val' === $a['name'] || 'is' === $a['name'] ) {
							$hasMatchVal = true;
							$matchValCode = null === $a['value'] ? 'true' : $this->expr( $a['value'], false );
							break;
						}
					}

					if ( $isDefault ) {
						$defaultChildren = $child->children;
						continue;
					}

					if ( $hasMatchVal ) {
						if ( $hasTarget ) {
							$condCode = "\$eval->looseEqual( {$targetCode}, {$matchValCode} )";
						} else {
							$condCode = "\$eval->truthy( {$matchValCode} )";
						}
						$matches[] = [
							'cond' => $condCode,
							'children' => $child->children,
						];
					}
				} elseif ( 'default' === $tagLower ) {
					$defaultChildren = $child->children;
				}
			}
		}

		$fallbackCode = null !== $defaultChildren ? $this->children( $defaultChildren ) : "''";

		for ( $i = count( $matches ) - 1; $i >= 0; $i-- ) {
			$m = $matches[ $i ];
			$fallbackCode = "( {$m['cond']} ? {$this->children( $m['children'] )} : {$fallbackCode} )";
		}

		return $fallbackCode;
	}

	private function compileConditionAttr( Element $element, array $candidates = [ 'condition', 'cond', 'when', 'is' ] ): string {
		foreach ( $element->attrs as $attr ) {
			if ( null !== $attr['name'] && in_array( strtolower( $attr['name'] ), $candidates, true ) ) {
				return null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
			}
		}
		if ( isset( $element->attrs[0] ) && null === $element->attrs[0]['name'] && ! $element->attrs[0]['spread'] ) {
			return $this->expr( $element->attrs[0]['value'], false );
		}
		return 'false';
	}

	private function component( Element $element ): string {
		$propsInit      = [];
		$chunks         = [];
		$classBaseExpr  = null;
		$hasClassBase   = false;
		$classModifiers = [];

		foreach ( $element->attrs as $attr ) {
			if ( null === $attr['name'] ) {
				if ( $attr['spread'] ) {
					if ( [] !== $propsInit ) {
						$chunks[]  = '[' . implode( ', ', $propsInit ) . ']';
						$propsInit = [];
					}
					$chunks[] = $this->expr( $attr['value'], false );
				}
				continue;
			}

			if ( 'key' === $attr['name'] ) {
				continue;
			}

			if ( str_starts_with( $attr['name'], 'class:' ) ) {
				$modifier         = substr( $attr['name'], 6 );
				$valCode          = null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
				$classModifiers[] = var_export( $modifier, true ) . ' => ' . $valCode;
				continue;
			}

			if ( 'class' === $attr['name'] ) {
				$hasClassBase  = true;
				$classBaseExpr = null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
				continue;
			}

			$val         = null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
			$propsInit[] = var_export( $attr['name'], true ) . ' => ' . $val;
		}

		if ( $hasClassBase || [] !== $classModifiers ) {
			$baseCode     = null === $classBaseExpr ? 'null' : $classBaseExpr;
			$modArrayCode = '[' . implode( ', ', $classModifiers ) . ']';
			$propsInit[]  = "'class' => \$eval->resolveClass( " . $baseCode . ', ' . $modArrayCode . ' )';
		}

		if ( [] !== $propsInit ) {
			$chunks[] = '[' . implode( ', ', $propsInit ) . ']';
		}

		$slotsCode     = [];
		$childrenNodes = [];

		if ( ! $element->selfClosing ) {
			foreach ( $element->children as $child ) {
				if ( $child instanceof Element && 'template' === strtolower( $child->tag ) ) {
					$slotName = null;
					$slotExpr = null;
					foreach ( $child->attrs as $a ) {
						if ( 'slot' === $a['name'] ) {
							if ( null === $a['value'] ) {
								$slotName = 'default';
							} else {
								$slotExpr = $this->expr( $a['value'], false );
							}
							break;
						}
					}

					if ( null !== $slotName || null !== $slotExpr ) {
						if ( [] !== $childrenNodes ) {
							$lastIdx = count( $childrenNodes ) - 1;
							if ( $childrenNodes[ $lastIdx ] instanceof Text && '' === trim( $childrenNodes[ $lastIdx ]->value ) ) {
								array_pop( $childrenNodes );
							}
						}

						$slotContentCode = $this->children( $child->children );
						if ( null !== $slotName ) {
							$slotsCode[] = var_export( $slotName, true ) . ' => ' . $slotContentCode;
						} else {
							$slotsCode[] = '(string) ' . $slotExpr . ' => ' . $slotContentCode;
						}
						continue;
					}
				}

				$childrenNodes[] = $child;
			}
		}

		$childrenCode   = $element->selfClosing ? 'null' : $this->children( $childrenNodes );
		$slotsArrayCode = [] === $slotsCode ? '[]' : '[' . implode( ', ', $slotsCode ) . ']';

		$slotAndChildrenChunk = '[ \'children\' => ' . $childrenCode . ', \'slots\' => ' . $slotsArrayCode . ' ]';

		if ( [] === $chunks ) {
			$finalPropsCode = $slotAndChildrenChunk;
		} else {
			$propsCode = '[]';
			foreach ( $chunks as $chunk ) {
				$propsCode = '$eval->mergeProps( ' . $propsCode . ', ' . $chunk . ' )';
			}
			$finalPropsCode = '$eval->mergeProps( ' . $propsCode . ', ' . $slotAndChildrenChunk . ' )';
		}

		foreach ( $this->namedTemplates as $tName => $tBlock ) {
			if ( 0 === strcasecmp( $tName, $element->tag ) ) {
				$subFnVar = '$__sub_tpl_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $tName );

				return '( ' . $subFnVar . '( ' . $finalPropsCode . ', $ctx, $eval ) )';
			}
		}

		return '( $eval->renderComponent( $ctx, ' . var_export( $element->tag, true ) . ', ' . $finalPropsCode . ' ) )';
	}

	private function slot( Element $element ): string {
		$nameExpr = 'null';

		foreach ( $element->attrs as $attr ) {
			if ( 'name' === $attr['name'] ) {
				$nameExpr = null === $attr['value'] ? "''" : '(string) ' . $this->expr( $attr['value'], false );
				break;
			}
		}

		$fallback = $this->children( $element->children );

		return '( $eval->renderSlot( $ctx, ' . $nameExpr . ', ' . $fallback . ' ) )';
	}

	/**
	 * @param list<array{name:string|null, value:Expr|null, spread:bool}> $attrs
	 */
	private function attrs( array $attrs ): string {
		$parts          = [];
		$classBase      = null;
		$classModifiers = [];
		$hasModifiers   = false;

		foreach ( $attrs as $attr ) {
			$name = $attr['name'];
			if ( null !== $name && str_starts_with( $name, 'class:' ) ) {
				$hasModifiers     = true;
				$modifier         = substr( $name, 6 );
				$valCode          = null === $attr['value'] ? 'true' : $this->expr( $attr['value'], false );
				$classModifiers[] = var_export( $modifier, true ) . ' => ' . $valCode;
			} elseif ( 'class' === $name ) {
				$classBase = $attr['value'];
			}
		}

		$classHandled = false;

		foreach ( $attrs as $attr ) {
			[ 'name' => $name, 'value' => $value, 'spread' => $spread ] = $attr;

			if ( null !== $name && ( 'class' === $name || str_starts_with( $name, 'class:' ) ) ) {
				if ( $classHandled ) {
					continue;
				}
				$classHandled = true;

				if ( $hasModifiers ) {
					$baseCode     = null === $classBase ? 'null' : $this->expr( $classBase, false );
					$modArrayCode = '[' . implode( ', ', $classModifiers ) . ']';
					$parts[]      = '$eval->attribute( \'class\', $eval->resolveClass( ' . $baseCode . ', ' . $modArrayCode . ' ) )';
					continue;
				}
			}

			if ( null === $name ) {
				if ( null === $value ) {
					throw new LiqxException( 'Compile error: attribute without a name and without a value' );
				}

				$parts[] = $spread
					? '$eval->spreadAttrs( ' . $this->expr( $value ) . ' )'
					: '$eval->stringify( ' . $this->expr( $value ) . ' )';

				continue;
			}

			if ( 'key' === $name ) {
				continue;
			}

			if ( null === $value ) {
				$parts[] = var_export( ' ' . $name, true );

				continue;
			}

			// `attribute()` exists to apply the boolean/null attribute rules
			// (`true` prints the bare name, `false`/null omit it). A value that
			// can only be a string never triggers them, so the name and quotes
			// bake straight into the artifact.
			$parts[] = $this->yieldsString( $value )
				? var_export( ' ' . $name . '="', true ) . ' . ' . $this->expr( $value ) . " . '\"'"
				: '$eval->attribute( ' . var_export( $name, true ) . ', ' . $this->expr( $value ) . ' )';
		}

		if ( [] === $parts ) {
			return "( '' )";
		}

		return '( ' . implode( ' . ', $parts ) . ' )';
	}

	/**
	 * @param list<\Phpmystic\Liqx\Node> $children
	 */
	private function children( array $children ): string {
		$parts = [];

		foreach ( $children as $child ) {
			if ( $child instanceof Text ) {
				$parts[] = var_export( $child->value, true );
			} else {
				$parts[] = $this->node( $child );
			}
		}

		if ( [] === $parts ) {
			return "( '' )";
		}

		return '( ' . implode( ' . ', $parts ) . ' )';
	}

	/**
	 * @param list<string|Expr> $parts
	 */
	private function verbatimParts( array $parts, string $fallbackBody ): string {
		if ( [] === $parts ) {
			return '(' . var_export( $fallbackBody, true ) . ')';
		}

		$compiled = [];

		foreach ( $parts as $part ) {
			$compiled[] = is_string( $part )
				? var_export( $part, true )
				: $this->outputValue( $part );
		}

		return '(' . implode( ' . ', $compiled ) . ')';
	}

	private function style( Style $node ): string {
		return "( '<style>' . " . $this->verbatimParts( $node->parts, $node->body ) . " . '</style>' )";
	}

	private function script( Script $node ): string {
		return "( '<script' . " . $this->attrs( $node->attrs ) . " . '>' . " . $this->verbatimParts( $node->parts, $node->body ) . " . '</script>' )";
	}

	private function renderValue( string $expr ): string {
		return '$eval->renderValue( ' . $expr . ', $ctx )';
	}

	/**
	 * An expression in output position. `renderValue()` exists to apply the
	 * stringify rules for values whose type is unknown at compile time
	 * (`false`/null vanish, `true` prints, arrays concatenate); an expression
	 * that can only ever be a string already satisfies those rules, so the call
	 * is dead work.
	 */
	private function outputValue( Expr $expression, bool $lenient = false ): string {
		// `{items.map(fn)}` builds an array of rendered strings that only
		// `renderValue()` ever sees. In output position the array is
		// unobservable, so the projection and the concatenation fuse.
		$joined = $this->mapJoin( $expression, $lenient );

		if ( null !== $joined ) {
			return $joined;
		}

		$compiled = $this->expr( $expression, $lenient );

		return $this->yieldsString( $expression ) ? $compiled : $this->renderValue( $compiled );
	}

	/**
	 * The single-pass form of a `map` call, or null when this expression is not
	 * one. Restricted to a one-argument `<expr>.map( <callback> )`: extra
	 * arguments are not part of the mapping contract, and a zero-argument call
	 * must keep the helper so it raises the same error.
	 */
	private function mapJoin( Expr $expression, bool $lenient ): ?string {
		if ( ! $expression instanceof Call ) {
			return null;
		}

		$callee = $expression->callee;

		if ( ! $callee instanceof Member || $callee->computed || 'map' !== (string) $callee->access ) {
			return null;
		}

		if ( 1 !== count( $expression->args ) ) {
			return null;
		}

		return '( $eval->mapJoin( '
			. $this->expr( $callee->object, $lenient )
			. ', '
			. $this->expr( $expression->args[0], $lenient )
			. ', $ctx ) )';
	}

	/**
	 * Whether this expression's value is statically known to be a string, so the
	 * runtime stringify helpers cannot change it.
	 */
	private function yieldsString( Expr $expression ): bool {
		if ( $expression instanceof Literal ) {
			return is_string( $expression->value );
		}

		// A template literal compiles to concatenation, and markup nodes compile
		// to concatenated tag/attribute/child strings — all strings by
		// construction.
		return $expression instanceof TemplateString
			|| $expression instanceof Element
			|| $expression instanceof Style
			|| $expression instanceof Script;
	}

	// ---------------------------------------------------------------------
	// Expressions
	// ---------------------------------------------------------------------

	/**
	 * Compile an expression to a parenthesized PHP expression. `$lenient`
	 * disables strict identifier lookups — used for the left side of `??`,
	 * where the interpreter suppresses undefined-variable errors.
	 */
	private function expr( Expr $expression, bool $lenient = false ): string {
		if ( ++$this->exprDepth > self::MAX_NESTING ) {
			throw new LiqxException(
				'Template nesting exceeds the compiler depth limit ('
				. self::MAX_NESTING
				. '). This usually means the source is deeply/recursively nested — '
				. 'flatten deeply-chained helper expressions or break them into '
				. 'frontmatter constants.'
			);
		}

		try {
			return $this->exprInner( $expression, $lenient );
		} finally {
			--$this->exprDepth;
		}
	}

	private function exprInner( Expr $expression, bool $lenient = false ): string {
		if ( $expression instanceof Literal ) {
			return '(' . var_export( $expression->value, true ) . ')';
		}

		if ( $expression instanceof Identifier ) {
			$resolved = $this->resolveBound( $expression->name );

			if ( null !== $resolved ) {
				[ $var, $index ] = $resolved;

				if ( $index < $this->closureStart ) {
					$this->captures[ $var ] = $index;
				}

				return '( $' . $var . ' )';
			}

			return $lenient
				? '( $ctx->get( ' . var_export( $expression->name, true ) . ' ) )'
				: '( ( $ctx->strict ? $eval->identifier( $ctx, ' . var_export( $expression->name, true ) . ' ) : $ctx->get( ' . var_export( $expression->name, true ) . ' ) ) )';
		}

		if ( $expression instanceof Member ) {
			if ( $expression->computed ) {
				if ( ! $expression->access instanceof Expr ) {
					throw new LiqxException( 'Compile error: computed member access is not an expression' );
				}

				$key = $this->expr( $expression->access, $lenient );
			} else {
				$key = var_export( (string) $expression->access, true );
			}

			if ( $expression->nullSafe ) {
				// `a?.b` — evaluate the object leniently (undefined → null) and
				// short-circuit to null, never reading a member off null.
				$object = $this->expr( $expression->object, true );
				$var    = $this->freshVar();

				return '( ( ( $' . $var . ' = ' . $object . ' ) === null ) ? null : ' . $this->propertyRead( '$' . $var, $key, $expression ) . ' )';
			}

			$object = $this->expr( $expression->object, $lenient );

			return '( ' . $this->propertyRead( $object, $key, $expression ) . ' )';
		}

		if ( $expression instanceof Call ) {
			return $this->call( $expression, $lenient );
		}

		if ( $expression instanceof ArrayLit ) {
			$elements = array_map( fn ( Expr $e ) => $this->expr( $e, $lenient ), $expression->elements );

			return '( [ ' . implode( ', ', $elements ) . ' ] )';
		}

		if ( $expression instanceof ObjectLit ) {
			$properties = array_map(
				fn ( array $property ) => var_export( $property[0], true ) . ' => ' . $this->expr( $property[1], $lenient ),
				$expression->properties
			);

			return '( [ ' . implode( ', ', $properties ) . ' ] )';
		}

		if ( $expression instanceof Binary ) {
			return $this->binary( $expression, $lenient );
		}

		if ( $expression instanceof Logical ) {
			return $this->logical( $expression, $lenient );
		}

		if ( $expression instanceof Unary ) {
			$operand = $this->expr( $expression->operand, $lenient );

			return match ( $expression->op ) {
				'!' => '( ! $eval->truthy( ' . $operand . ' ) )',
				'-' => '( - $eval->toNumber( ' . $operand . ' ) )',
				'+' => '( $eval->toNumber( ' . $operand . ' ) )',
				default => throw new LiqxException( 'Unknown unary operator ' . $expression->op ),
			};
		}

		if ( $expression instanceof Conditional ) {
			return '( $eval->truthy( ' . $this->expr( $expression->test, $lenient ) . ' ) ? ' . $this->expr( $expression->consequent, $lenient ) . ' : ' . $this->expr( $expression->alternate, $lenient ) . ' )';
		}

		if ( $expression instanceof ArrowFunction ) {
			return $this->arrow( $expression, $lenient );
		}

		if ( $expression instanceof BlockBody ) {
			// Only reachable as an arrow body; the grammar never produces a
			// bare block body in expression position.
			throw new LiqxException( 'A block body cannot be compiled outside an arrow function' );
		}

		if ( $expression instanceof TemplateString ) {
			return $this->templateString( $expression, $lenient );
		}

		if ( $expression instanceof Filtered ) {
			return $this->filtered( $expression, $lenient );
		}

		if ( $expression instanceof Element ) {
			return $this->element( $expression );
		}

		if ( $expression instanceof Style ) {
			return $this->style( $expression );
		}

		if ( $expression instanceof Script ) {
			return $this->script( $expression );
		}

		throw new LiqxException( 'Cannot compile expression node ' . $expression::class );
	}

	/**
	 * A property read. The overwhelmingly common receiver is a plain array with
	 * a compile-time-known key, so that case is emitted inline and only other
	 * receivers (objects, Drops, ArrayAccess, strings) fall through to
	 * {@see Evaluator::getProperty()} — the same helper, unchanged semantics,
	 * one fewer method call per access on the hot path.
	 *
	 * The receiver is bound to a temp so it is evaluated exactly once even
	 * though both branches read it; a receiver that is already a plain local
	 * skips the temp.
	 *
	 * `length` never takes the fast path: on an array it means `count()`, not a
	 * key lookup.
	 */
	private function propertyRead( string $object, string $key, Member $expression ): string {
		if ( $expression->computed || 'length' === (string) $expression->access ) {
			return '$eval->getProperty( ' . $object . ', ' . $key . ' )';
		}

		$operand = trim( $object );

		// Strip the redundant parens the emitter wraps operands in, so a bound
		// local (`( $__v0 )`) is recognised as the simple variable it is.
		while ( str_starts_with( $operand, '(' ) && str_ends_with( $operand, ')' ) ) {
			$operand = trim( substr( $operand, 1, -1 ) );
		}

		if ( 1 === preg_match( '/^\$[A-Za-z_][A-Za-z0-9_]*$/', $operand ) ) {
			return '( is_array( ' . $operand . ' ) ? ( ' . $operand . '[ ' . $key . ' ] ?? null ) : $eval->getProperty( ' . $operand . ', ' . $key . ' ) )';
		}

		$temp = '$' . $this->freshVar();

		return '( is_array( ' . $temp . ' = ' . $object . ' ) ? ( ' . $temp . '[ ' . $key . ' ] ?? null ) : $eval->getProperty( ' . $temp . ', ' . $key . ' ) )';
	}

	/**
	 * @param list<Expr> $args
	 */
	private function argList( array $args, bool $lenient ): string {
		$compiled = array_map( fn ( Expr $arg ) => $this->expr( $arg, $lenient ), $args );

		return implode( ', ', $compiled );
	}

	private function call( Call $expression, bool $lenient ): string {
		$args   = $this->argList( $expression->args, $lenient );
		$callee = $expression->callee;

		if ( $callee instanceof Member ) {
			$object = $this->expr( $callee->object, $lenient );

			return '( $eval->methodCall( ' . $object . ', ' . var_export( (string) $callee->access, true ) . ', [ ' . $args . ' ], $ctx ) )';
		}

		if ( $callee instanceof Identifier ) {
			return '( $eval->callNamed( $ctx, ' . var_export( $callee->name, true ) . ', [ ' . $args . ' ] ) )';
		}

		return '( $eval->invoke( ' . $this->expr( $callee, $lenient ) . ', [ ' . $args . ' ], $ctx ) )';
	}

	private function binary( Binary $expression, bool $lenient ): string {
		$left  = $this->expr( $expression->left, $lenient );
		$right = $this->expr( $expression->right, $lenient );

		return match ( $expression->op ) {
			'+'   => '( $eval->add( ' . $left . ', ' . $right . ' ) )',
			'-'   => '( $eval->toNumber( ' . $left . ' ) - $eval->toNumber( ' . $right . ' ) )',
			'*'   => '( $eval->toNumber( ' . $left . ' ) * $eval->toNumber( ' . $right . ' ) )',
			'/'   => '( $eval->toNumber( ' . $left . ' ) / $eval->toNumber( ' . $right . ' ) )',
			'%'   => '( $eval->toNumber( ' . $left . ' ) % $eval->toNumber( ' . $right . ' ) )',
			'=='  => '( $eval->looseEqual( ' . $left . ', ' . $right . ' ) )',
			'!='  => '( ! $eval->looseEqual( ' . $left . ', ' . $right . ' ) )',
			'===' => '( ' . $left . ' === ' . $right . ' )',
			'!==' => '( ' . $left . ' !== ' . $right . ' )',
			'<'   => '( $eval->compare( ' . $left . ', ' . $right . ' ) < 0 )',
			'>'   => '( $eval->compare( ' . $left . ', ' . $right . ' ) > 0 )',
			'<='  => '( $eval->compare( ' . $left . ', ' . $right . ' ) <= 0 )',
			'>='  => '( $eval->compare( ' . $left . ', ' . $right . ' ) >= 0 )',
			default => throw new LiqxException( 'Unknown operator ' . $expression->op ),
		};
	}

	private function logical( Logical $expression, bool $lenient ): string {
		$left  = $this->expr( $expression->left, $lenient );
		$right = $this->expr( $expression->right, $lenient );

		// Each short-circuit form binds its left operand to a temp so a method
		// call or property read on that side runs once, not twice, while the
		// right operand stays lazily evaluated.
		$tmp = '$' . $this->freshVar();

		if ( '??' === $expression->op ) {
			// The interpreter evaluates the left side leniently — a missing
			// variable collapses to null instead of throwing.
			$lenientLeft = $this->expr( $expression->left, true );

			return '( ( ( ' . $tmp . ' = ' . $lenientLeft . ' ) === null ) ? ' . $right . ' : ' . $tmp . ' )';
		}

		if ( '||' === $expression->op ) {
			return '( $eval->truthy( ' . $tmp . ' = ' . $left . ' ) ? ' . $tmp . ' : ' . $right . ' )';
		}

		return '( $eval->truthy( ' . $tmp . ' = ' . $left . ' ) ? ' . $right . ' : ' . $tmp . ' )';
	}

	private function filtered( Filtered $expression, bool $lenient ): string {
		// Each filter becomes one direct `applyFilter` call, nested so the first
		// filter in the pipeline is the innermost (and therefore first-applied)
		// call — no pipeline array is rebuilt or walked per evaluation.
		//
		// The callable is deliberately *not* resolved ahead of the value: the
		// interpreter evaluates the value first and only then looks the filter
		// up, so hoisting the lookup would change which error surfaces when both
		// are bad (PHP resolves a callee before its arguments).
		$out = $this->expr( $expression->value, $lenient );

		foreach ( $expression->filters as $filter ) {
			$args = $this->argList( $filter->args, $lenient );

			$out = '( $eval->applyFilter( ' . $out . ', ' . var_export( $filter->name, true ) . ', $ctx'
				. ( '' === $args ? '' : ', ' . $args ) . ' ) )';
		}

		return $out;
	}

	/**
	 * A template literal lowers to native PHP concatenation. Literal segments
	 * are known here, so they become string literals in the artifact instead of
	 * array elements a helper walks on every render.
	 *
	 * Interpolations still stringify through `renderValue` — exactly what
	 * {@see Evaluator::templateString()} does per part — so the result is
	 * unchanged for every value shape (`false`/null vanish, `true` prints,
	 * arrays concatenate their items, objects without `__toString` vanish).
	 */
	private function templateString( TemplateString $expression, bool $lenient ): string {
		if ( [] === $expression->parts ) {
			return "( '' )";
		}

		$parts = [];

		foreach ( $expression->parts as $part ) {
			$parts[] = is_string( $part )
				? var_export( $part, true )
				: $this->outputValue( $part, $lenient );
		}

		// A template literal always yields a string, so a lone interpolation
		// still needs the concat to coerce it (`` `${n}` `` is "1", not 1).
		if ( 1 === count( $parts ) && ! is_string( $expression->parts[0] ) ) {
			return "( '' . " . $parts[0] . ' )';
		}

		return '( ' . implode( ' . ', $parts ) . ' )';
	}

	/**
	 * An arrow function becomes a real PHP closure whose parameters bind to
	 * closure-local variables — no Context scope push/pop per invocation.
	 * Constants and destructure bindings from a block body also become locals.
	 * Any local referenced from an enclosing closure is captured via `use`.
	 */
	private function arrow( ArrowFunction $expression, bool $lenient ): string {
		$savedClosureStart = $this->closureStart;
		$savedCaptures     = $this->captures;

		$paramLines = [];
		$bindings   = [];

		foreach ( $expression->params as $index => $param ) {
			$var             = $this->freshVar();
			$bindings[ $param ] = $var;
			$paramLines[]    = '    $' . $var . ' = $__args[' . $index . '] ?? null;';
		}

		$this->scopeStack[]  = $bindings;
		$this->closureStart  = count( $this->scopeStack ) - 1;
		$this->captures      = [];

		$bodyLines = [];

		if ( $expression->body instanceof BlockBody ) {
			foreach ( $expression->body->declarations as $declaration ) {
				foreach ( $this->declarationLinesLocals( $declaration ) as $statement ) {
					$bodyLines[] = '        ' . $statement;
				}
			}

			$bodyLines[] = $expression->body->return !== null
				? '        return ' . $this->expr( $expression->body->return, $lenient ) . ';'
				: '        return null;';
		} else {
			$bodyLines[] = '        return ' . $this->expr( $expression->body, $lenient ) . ';';
		}

		// First build this arrow's own `use (...)` from the captures its body
		// discovered. Then propagate each capture up to the enclosing closure —
		// but only when the variable lives beyond that closure's own scope
		// (index < savedClosureStart); a variable the enclosing closure binds
		// itself (its own param or local) is already in scope there.
		$bodyCaptures = $this->pendingCaptures();

		$use     = 'use ( $ctx, $eval';
		foreach ( array_keys( $bodyCaptures ) as $useVar ) {
			$use .= ', $' . $useVar;
		}
		$use .= ' )';

		foreach ( $bodyCaptures as $useVar => $index ) {
			if ( $index < $savedClosureStart ) {
				$savedCaptures[ $useVar ] = $index;
			}
		}

		array_pop( $this->scopeStack );
		$this->closureStart = $savedClosureStart;
		$this->captures     = $savedCaptures;

		$lines   = [];
		$lines[] = 'static function ( mixed ...$__args ) ' . $use . ' {';
		$lines[] = implode( "\n", $paramLines );

		foreach ( $bodyLines as $bodyLine ) {
			$lines[] = $bodyLine;
		}

		$lines[] = '}';

		return '(' . implode( "\n", $lines ) . ')';
	}
}