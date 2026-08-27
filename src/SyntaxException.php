<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/** Raised when a `.liqx` document cannot be parsed. */
final class SyntaxException extends LiqxException {

	public function __construct( string $message, ?int $line = null ) {
		parent::__construct( $message );
		$this->lineNumber = $line;
	}

	public static function unexpectedCharacter( string $char, int $line ): self {
		return new self( sprintf( 'Unexpected character %s on line %d', var_export( $char, true ), $line ), $line );
	}

	public static function unterminated( string $what, int $line ): self {
		return new self( sprintf( 'Unterminated %s on line %d', $what, $line ), $line );
	}

	public static function tagNeverClosed( string $name, int $line ): self {
		return new self( sprintf( 'Tag <%s> never closed, starting on line %d', $name, $line ), $line );
	}

	public static function mismatchedCloseTag( string $name, int $line ): self {
		return new self( sprintf( 'Mismatched closing tag </%s> on line %d', $name, $line ), $line );
	}
}