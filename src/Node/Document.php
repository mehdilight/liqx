<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

final class Document implements Node {

	/** @var list<Frontmatter|FrontmatterDestructure> */
	public readonly array $frontmatter;

	/** The frontmatter `return <expr>;` — exposed to the body as `props`. */
	public readonly ?Expr $frontmatterReturn;

	/** @var list<Node> */
	public readonly array $body;

	public readonly ?Style $style;

	public readonly ?Schema $schema;

	/**
	 * @param list<Frontmatter|FrontmatterDestructure> $frontmatter
	 * @param list<Node> $body
	 */
	public function __construct( array $frontmatter, array $body, ?Style $style, ?Schema $schema, ?Expr $frontmatterReturn = null ) {
		$this->frontmatter      = $frontmatter;
		$this->frontmatterReturn = $frontmatterReturn;
		$this->body             = $body;
		$this->style            = $style;
		$this->schema           = $schema;
	}
}
