<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx\Node;

use Phpmystic\Liqx\Expr;
use Phpmystic\Liqx\Node;

final class Document implements Node {

	/** @var list<object> */
	public readonly array $frontmatter;

	/** The frontmatter `return <expr>;` — exposed to the body as `props`. */
	public readonly ?Expr $frontmatterReturn;

	/** @var list<Node> */
	public readonly array $body;

	public readonly ?Style $style;

	public readonly ?Schema $schema;

	/** @var array<string, TemplateBlock> */
	public readonly array $namedTemplates;

	/**
	 * @param list<object> $frontmatter
	 * @param list<Node> $body
	 * @param array<string, TemplateBlock> $namedTemplates
	 */
	public function __construct(
		array $frontmatter,
		array $body,
		?Style $style = null,
		?Schema $schema = null,
		?Expr $frontmatterReturn = null,
		array $namedTemplates = [],
	) {
		$this->frontmatter       = $frontmatter;
		$this->frontmatterReturn = $frontmatterReturn;
		$this->body              = $body;
		$this->style             = $style;
		$this->schema            = $schema;
		$this->namedTemplates    = $namedTemplates;
	}
}
