<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * The Lexer's scanning modes. Liqx source is a single stream that the Lexer
 * re-enters as it crosses boundaries: top-level/JSX content, inside a tag,
 * inside a `{ }` expression (which may itself hold JSX elements), inside the
 * frontmatter block, or inside the verbatim `<style>` / `<schema>` blocks.
 */
enum LexerMode: string {
	case Content     = 'content';
	case Tag         = 'tag';
	case TagClose    = 'tag_close';
	case Js          = 'js';
	case Frontmatter = 'frontmatter';
	case Style       = 'style';
	case Script      = 'script';
	case Schema      = 'schema';
}
