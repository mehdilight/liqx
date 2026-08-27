<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * A cursor over the flat token list emitted by the Lexer. The recursive-
 * descent parsers share one stream; lookahead is one token via `peek()`.
 */
final class TokenStream {

	/** @var list<Token> */
	private array $tokens;

	private int $index = 0;

	/** @param list<Token> $tokens */
	public function __construct( array $tokens ) {
		$this->tokens = $tokens;
	}

	public function current(): ?Token {
		return $this->tokens[ $this->index ] ?? null;
	}

	/** @return list<Token> */
	public function tokens(): array {
		return $this->tokens;
	}

	public function position(): int {
		return $this->index;
	}

	public function seek( int $index ): void {
		$this->index = $index;
	}

	public function peek( int $ahead = 1 ): ?Token {
		return $this->tokens[ $this->index + $ahead ] ?? null;
	}

	public function next(): ?Token {
		$token = $this->tokens[ $this->index ] ?? null;
		$this->index++;

		return $token;
	}

	public function accept( TokenType $type ): ?Token {
		$token = $this->current();

		if ( null !== $token && $type === $token->type ) {
			$this->index++;

			return $token;
		}

		return null;
	}

	public function acceptValue( TokenType $type, string $value ): ?Token {
		$token = $this->current();

		if ( null !== $token && $type === $token->type && $value === $token->value ) {
			$this->index++;

			return $token;
		}

		return null;
	}

	public function expect( TokenType $type ): Token {
		$token = $this->current();

		if ( null === $token || $type !== $token->type ) {
			throw new SyntaxException(
				sprintf( 'Expected %s, got %s', $type->value, null === $token ? 'end of input' : $token->value ),
				null !== $token ? $token->line : null
			);
		}

		$this->index++;

		return $token;
	}

	public function expectValue( TokenType $type, string $value ): Token {
		$token = $this->current();

		if ( null === $token || $type !== $token->type || $value !== $token->value ) {
			throw new SyntaxException(
				sprintf( 'Expected %s %s, got %s', $type->value, var_export( $value, true ), null === $token ? 'end of input' : $token->value ),
				null !== $token ? $token->line : null
			);
		}

		$this->index++;

		return $token;
	}

	public function eof(): bool {
		$token = $this->current();

		return null === $token || TokenType::EOF === $token->type;
	}

	/** Consume and discard until (not including) the next ExpressionEnd. */
	public function skipToExpressionEnd(): void {
		while ( null !== ( $token = $this->current() ) && TokenType::ExpressionEnd !== $token->type ) {
			$this->index++;
		}
	}
}
