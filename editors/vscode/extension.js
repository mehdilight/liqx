// Liqx — lightweight completion for .liqx files.
//
// Highlighting is 100% TextMate (syntaxes/liqx.tmLanguage.json). This module
// only adds context-aware completion: filters after `|`, methods after `.`,
// and keywords/globals inside `{ }` / frontmatter.

const vscode = require('vscode');

const SELECTOR = { language: 'liqx', scheme: '*' };

// --- registries (mirror src/StandardFilters.php + src/Evaluator.php) --------

const FILTERS = [
  'abs', 'append', 'at_least', 'at_most', 'capitalize', 'ceil', 'compact',
  'concat', 'date', 'default', 'divided_by', 'downcase', 'escape',
  'escape_once', 'find', 'find_index', 'first', 'floor', 'format', 'group_by',
  'has', 'join', 'last', 'lstrip', 'map', 'minus', 'modulo', 'money',
  'newline_to_br', 'plus', 'prepend', 'remove', 'remove_first', 'remove_last',
  'reject', 'replace', 'replace_first', 'replace_last', 'reverse', 'round',
  'rstrip', 'size', 'slice', 'slugify', 'sort', 'sort_natural', 'split',
  'squish', 'strip', 'strip_html', 'strip_newlines', 'sum', 'times',
  'truncate', 'truncatewords', 'uniq', 'upcase', 'url_decode', 'url_encode',
  'where',
  // camelCase aliases
  'atLeast', 'atMost', 'dividedBy', 'escapeOnce', 'findIndex', 'groupBy',
  'newlineToBr', 'removeFirst', 'removeLast', 'replaceFirst', 'replaceLast',
  'sortNatural', 'stripHtml', 'stripNewlines', 'truncateWords', 'urlDecode',
  'urlEncode',
];

const FILTER_SIGNATURES = {
  default: 'default(fallback)',
  truncate: 'truncate(length = 50, ellipsis = "...")',
  truncatewords: 'truncatewords(count = 15, ellipsis = "...")',
  replace: 'replace(search, replacement)',
  date: 'date(format)  — strftime-style %Y %m %d …',
  format: 'format(kind, arg, locale)  — currency|number|percent|date',
  money: 'money(currency = "MAD")  — value is cents',
  join: 'join(separator = " ")',
  split: 'split(separator)',
  slice: 'slice(offset, length = 1)',
  map: 'map(key)  — pluck dotted key from each item',
  where: 'where(key, value)',
  reject: 'reject(key, value)',
  find: 'find(key, value)',
  find_index: 'find_index(key, value)',
  group_by: 'group_by(key)  → [{ name, items }]',
  round: 'round(precision = 0)',
  at_least: 'at_least(min)',
  at_most: 'at_most(max)',
  plus: 'plus(n)', minus: 'minus(n)', times: 'times(n)',
  divided_by: 'divided_by(n)', modulo: 'modulo(n)',
  append: 'append(suffix)', prepend: 'prepend(prefix)', concat: 'concat(x)',
  has: 'has(needle)',
};

const METHODS = [
  ['map', 'map(item => $0)', 'array — (element, index, array) callback'],
  ['filter', 'filter(item => $0)', 'array — keep where callback is truthy'],
  ['find', 'find(item => $0)', 'array — first match or null'],
  ['some', 'some(item => $0)', 'array — any match → bool'],
  ['every', 'every(item => $0)', 'array — all match → bool'],
  ['join', "join('${1:, }')", 'array → string'],
  ['includes', 'includes($0)', 'array / string membership → bool'],
  ['indexOf', 'indexOf($0)', 'array — index or -1'],
  ['concat', 'concat($0)', 'array — merge'],
  ['slice', 'slice(${1:0}$0)', 'array / string sub-range'],
  ['length', 'length', 'count of array / string / Countable'],
  ['toUpperCase', 'toUpperCase()', 'string'],
  ['toLowerCase', 'toLowerCase()', 'string'],
  ['replace', "replace('${1:a}', '${2:b}')", 'string'],
  ['replaceAll', "replaceAll('${1:a}', '${2:b}')", 'string'],
  ['trim', 'trim()', 'string'],
  ['split', "split('${1:,}')", 'string → array'],
  ['startsWith', "startsWith('$0')", 'string → bool'],
  ['endsWith', "endsWith('$0')", 'string → bool'],
];

const GLOBALS = [
  ['render', 'render("${1:name}", { $2 })', 'Render a named snippet with isolated props'],
  ['section', 'section("${1:name}")', 'Render a named section (parent scope visible)'],
  ['now', 'now()', 'Current Unix timestamp'],
];

const KEYWORDS = ['const', 'let', 'return'];
const SPECIALS = [
  ['root', 'The whole render payload (outermost scope); cannot be shadowed'],
  ['props', 'Object exposed by the frontmatter `return { … }`'],
];

// --- helpers --------------------------------------------------------------

/** Rough check: is the cursor inside a `{ }` expression or the frontmatter? */
function inLiqxCode(document, position) {
  const head = document.getText(new vscode.Range(new vscode.Position(0, 0), position));

  // Inside an unterminated frontmatter fence at the top of the file.
  const fm = /^---[ \t]*\r?\n/.exec(head);
  if (fm && head.indexOf('\n---', fm[0].length) === -1) {
    return true;
  }

  // Unbalanced `{` before the cursor (ignores strings/comments — good enough).
  let depth = 0;
  for (let i = 0; i < head.length; i++) {
    const c = head[i];
    if (c === '{') depth++;
    else if (c === '}' && depth > 0) depth--;
  }
  return depth > 0;
}

function item(label, kind, detail, snippet) {
  const it = new vscode.CompletionItem(label, kind);
  if (detail) it.detail = detail;
  if (snippet) it.insertText = new vscode.SnippetString(snippet);
  return it;
}

// --- providers ---------------------------------------------------------------

const filterProvider = {
  provideCompletionItems(document, position) {
    const line = document.lineAt(position).text.slice(0, position.character);
    if (!/\|\s*[A-Za-z_]*$/.test(line)) return undefined;

    return FILTERS.map((name) =>
      item(
        name,
        vscode.CompletionItemKind.Function,
        FILTER_SIGNATURES[name] ? `filter — ${FILTER_SIGNATURES[name]}` : 'liqx filter',
      ),
    );
  },
};

const methodProvider = {
  provideCompletionItems(document, position) {
    const line = document.lineAt(position).text.slice(0, position.character);
    if (!/[\w$)\]]\.\s*[A-Za-z_]*$/.test(line)) return undefined;

    return METHODS.map(([label, snippet, detail]) =>
      item(label, vscode.CompletionItemKind.Method, `method — ${detail}`, snippet),
    );
  },
};

const codeProvider = {
  provideCompletionItems(document, position) {
    if (!inLiqxCode(document, position)) return undefined;

    const items = [];
    for (const k of KEYWORDS) {
      items.push(item(k, vscode.CompletionItemKind.Keyword, 'frontmatter / block-body keyword'));
    }
    for (const [label, snippet, detail] of GLOBALS) {
      items.push(item(label, vscode.CompletionItemKind.Function, `global — ${detail}`, snippet));
    }
    for (const [label, detail] of SPECIALS) {
      items.push(item(label, vscode.CompletionItemKind.Variable, detail));
    }
    return items;
  },
};

function activate(context) {
  context.subscriptions.push(
    vscode.languages.registerCompletionItemProvider(SELECTOR, filterProvider, '|', ' '),
    vscode.languages.registerCompletionItemProvider(SELECTOR, methodProvider, '.'),
    vscode.languages.registerCompletionItemProvider(SELECTOR, codeProvider),
  );
}

function deactivate() {}

module.exports = { activate, deactivate };
