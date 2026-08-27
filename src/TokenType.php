<?php
declare( strict_types=1 );

namespace Phpmystic\Liqx;

/**
 * Lexical categories in the single, mode-aware Liqx token stream.
 *
 * HTML/JSX structure tokens (Text, OpenTag, …) coexist with JS expression
 * tokens (Identifier, Number, …) because `{ }` expressions are real JS and
 * JSX elements can appear *inside* them. The Lexer switches modes as it
 * scans; the Parser reads the resulting flat stream.
 */
enum TokenType: string {

	// --- document / frontmatter ---------------------------------------------
	case FrontmatterStart = 'frontmatter_start';
	case FrontmatterEnd   = 'frontmatter_end';

	// --- HTML / JSX structure -----------------------------------------------
	case Text            = 'text';
	case OpenTag         = 'open_tag';    // `<`
	case CloseTag        = 'close_tag';   // `</`
	case SelfClose       = 'self_close';  // `/>`
	case TagEnd          = 'tag_end';     // `>`
	case AttrEquals      = 'attr_equals'; // `=`
	case AttrString      = 'attr_string'; // quoted attribute value
	case Comment         = 'comment';     // `{/* ... */}` (never rendered)

	// --- JS expression content ----------------------------------------------
	case ExpressionStart = 'expression_start'; // `{`
	case ExpressionEnd   = 'expression_end';   // `}`
	case Identifier      = 'identifier';
	case Number          = 'number';
	case String          = 'string';
	case TemplateString  = 'template_string';
	case Dot             = 'dot';
	case Comma           = 'comma';
	case Colon           = 'colon';
	case Semicolon       = 'semicolon';
	case OpenParen       = 'open_paren';
	case CloseParen      = 'close_paren';
	case OpenBracket     = 'open_bracket';
	case CloseBracket    = 'close_bracket';
	case Pipe            = 'pipe';     // `|` filter pipeline
	case Arrow           = 'arrow';    // `=>`
	case Operator        = 'operator'; // all other operators / punctuation
	case Keyword         = 'keyword';  // reserved words (const, let, return, …)

	// --- verbatim blocks -----------------------------------------------------
	case Style  = 'style';
	case Script = 'script';
	case Schema = 'schema';

	case EOF = 'eof';
}
