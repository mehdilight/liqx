<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Base class for every Liqx failure — parse (SyntaxException) and runtime
 * (UndefinedVariableException, UnknownFilterException). Catch this to treat a
 * template problem as a normal outcome instead of a 500.
 *
 * A host that renders by name (like Sworen's storefront) needs to point a dev
 * overlay at the broken template: `templateName` is stamped by Template when a
 * name was set at parse time, and `lineNumber` by the Renderer at the node
 * where the failure escaped.
 */
class LiqxException extends \RuntimeException {

	public ?int $lineNumber = null;

	public ?string $templateName = null;

	/**
	 * The human-readable marker a per-node error continuation prints in place
	 * of the broken node's output — e.g.
	 * `Liqx error (sections/hero line 12): message`.
	 */
	public function toErrorMessage(): string {
		$location = '';

		if ( null !== $this->lineNumber ) {
			$template = null !== $this->templateName ? $this->templateName . ' ' : '';
			$location = sprintf( ' (%sline %d)', $template, $this->lineNumber );
		} elseif ( null !== $this->templateName ) {
			$location = sprintf( ' (%s)', $this->templateName );
		}

		return sprintf( 'Liqx error%s: %s', $location, $this->getMessage() );
	}
}