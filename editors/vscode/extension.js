// Liqx — lightweight IntelliSense for .liqx files.
//
// Highlighting is 100% TextMate (syntaxes/liqx.tmLanguage.json). This module
// adds: hover docs, context-aware completion (filters after `|`, methods
// after `.`, keywords/globals inside `{ }` / frontmatter), each entry sharing
// one docs registry.
//
// The registries mirror src/StandardFilters.php and src/Evaluator.php — keep
// them in sync when those change.

const vscode = require('vscode');

const SELECTOR = { language: 'liqx', scheme: '*' };

// --- docs registry ---------------------------------------------------------
// entry = { sig, kind, desc, ex? }

const GLOBALS = {
  render: {
    sig: 'render(name, props = {})',
    kind: 'global',
    desc: 'Render the named **snippet** through the host snippet FileSystem. `props` becomes the snippet\'s `props` object. Isolated — parent variables do not leak in.',
    ex: '{render("card", { product: featured })}',
  },
  section: {
    sig: 'section(name)',
    kind: 'global',
    desc: 'Render the named **section** through the section FileSystem, with the parent (page) scope visible plus `section.name`. Hosts usually override this global to inject the merchant\'s saved settings.',
    ex: '{section("header")}',
  },
  now: {
    sig: 'now(): int',
    kind: 'global',
    desc: 'Current Unix timestamp. Pair with the `date` filter.',
    ex: "{now() | date('%Y')}",
  },
};

const SPECIALS = {
  root: {
    sig: 'root',
    kind: 'reserved',
    desc: 'The whole render payload (outermost scope). Use it instead of long repeated paths. Cannot be shadowed or declared.',
    ex: '{root.section.settings.heading}',
  },
  props: {
    sig: 'props',
    kind: 'reserved',
    desc: 'The object returned by the frontmatter `return { … }`. In a snippet, also the input passed by `render("name", { … })`.',
    ex: '<h1>{props.title}</h1>',
  },
};

const KEYWORDS = {
  const: {
    sig: 'const name = expr;',
    kind: 'keyword',
    desc: 'Binding in frontmatter or a block-body arrow. Supports destructuring with defaults.',
    ex: "const { product, size = 'M' } = props;",
  },
  let: {
    sig: 'let name = expr;',
    kind: 'keyword',
    desc: 'Same as `const` here — the sandbox has no reassignment operators.',
  },
  return: {
    sig: 'return expr;',
    kind: 'keyword',
    desc: 'In **frontmatter**: must be the last statement; its value becomes the body\'s `props`. In a **block-body arrow** `x => { … }`: the arrow\'s result (`null` if omitted).',
    ex: 'return { title: title, count: 3 };',
  },
};

// Collection / string methods (src/Evaluator.php whitelist).
const METHODS = {
  map: { sig: '.map(fn)', kind: 'array method', desc: '`fn(element, index, array)` → new array. On `null`/undefined → `[]`.', ex: '{items.map(i => <li>{i.name}</li>)}' },
  filter: { sig: '.filter(fn)', kind: 'array method', desc: 'Keep elements where `fn(...)` is truthy. On `null` → `[]`.' },
  find: { sig: '.find(fn)', kind: 'array method', desc: 'First element where `fn(...)` is truthy, else `null`.' },
  some: { sig: '.some(fn): bool', kind: 'array method', desc: '`true` if any element passes (empty → `false`).' },
  every: { sig: '.every(fn): bool', kind: 'array method', desc: '`true` if every element passes (empty → `true`).' },
  join: { sig: ".join(separator = '')", kind: 'array method', desc: 'Join elements into a string.' },
  includes: { sig: '.includes(x): bool', kind: 'method', desc: 'Strict membership (arrays) / substring (strings).' },
  indexOf: { sig: '.indexOf(x): int', kind: 'array method', desc: 'Index of `x`, or `-1`.' },
  concat: { sig: '.concat(arr)', kind: 'array method', desc: 'Merge arrays.' },
  slice: { sig: '.slice(start, end?)', kind: 'method', desc: 'Sub-array / substring.' },
  length: { sig: '.length: int', kind: 'property', desc: 'Count of an array / string / `Countable` (lazy collections answer without hydrating).' },
  toUpperCase: { sig: '.toUpperCase()', kind: 'string method', desc: 'Uppercase.' },
  toLowerCase: { sig: '.toLowerCase()', kind: 'string method', desc: 'Lowercase.' },
  replace: { sig: '.replace(a, b)', kind: 'string method', desc: 'Replace every occurrence of `a` with `b`.' },
  replaceAll: { sig: '.replaceAll(a, b)', kind: 'string method', desc: 'Replace every occurrence of `a` with `b`.' },
  trim: { sig: '.trim()', kind: 'string method', desc: 'Trim whitespace from both ends.' },
  split: { sig: '.split(sep)', kind: 'string method', desc: 'Split into an array on `sep`.' },
  startsWith: { sig: '.startsWith(s): bool', kind: 'string method', desc: 'Prefix test.' },
  endsWith: { sig: '.endsWith(s): bool', kind: 'string method', desc: 'Suffix test.' },
};

const METHOD_SNIPPETS = {
  map: 'map(${1:item} => $0)',
  filter: 'filter(${1:item} => $0)',
  find: 'find(${1:item} => $0)',
  some: 'some(${1:item} => $0)',
  every: 'every(${1:item} => $0)',
  join: "join('${1:, }')",
  includes: 'includes($0)',
  indexOf: 'indexOf($0)',
  concat: 'concat($0)',
  slice: 'slice(${1:0}$0)',
  length: 'length',
  toUpperCase: 'toUpperCase()',
  toLowerCase: 'toLowerCase()',
  replace: "replace('${1:a}', '${2:b}')",
  replaceAll: "replaceAll('${1:a}', '${2:b}')",
  trim: 'trim()',
  split: "split('${1:,}')",
  startsWith: "startsWith('$0')",
  endsWith: "endsWith('$0')",
};

// Standard filters (src/StandardFilters.php).
const FILTERS = {
  abs: { sig: 'abs', desc: 'Absolute value (integer).' },
  append: { sig: 'append(suffix)', desc: 'Concatenate `suffix` onto the string.' },
  at_least: { sig: 'at_least(min)', desc: 'Clamp up to at least `min`.' },
  at_most: { sig: 'at_most(max)', desc: 'Clamp down to at most `max`.' },
  capitalize: { sig: 'capitalize', desc: 'Uppercase the first character.' },
  ceil: { sig: 'ceil', desc: 'Round up to an integer.' },
  compact: { sig: 'compact(key?)', desc: 'Drop `null` entries (by `key` if given).' },
  concat: { sig: 'concat(x)', desc: 'Merge two arrays, else concatenate as strings — type-predictable alternative to `+`.', ex: "{3 | concat(' items')} → 3 items" },
  date: { sig: 'date(format)', desc: 'Format a date. strftime tokens (`%Y %m %d %H:%M`). Input: timestamp, `\'now\'`, or a strtotime string.' },
  default: { sig: 'default(fallback)', desc: '`fallback` when the value is `null`, `false`, or `\'\'`.' },
  divided_by: { sig: 'divided_by(n)', desc: 'Divide. Integer division if both are ints; `/ 0` → `0`.' },
  downcase: { sig: 'downcase', desc: 'Lowercase.' },
  escape: { sig: 'escape', desc: 'HTML-escape `& < >` and quotes.' },
  escape_once: { sig: 'escape_once', desc: 'HTML-escape without double-escaping existing entities.' },
  find: { sig: 'find(key, value)', desc: 'First array item whose `key` equals `value`.' },
  find_index: { sig: 'find_index(key, value)', desc: 'Index of the first such item, or `null`.' },
  first: { sig: 'first', desc: 'First element of an array (or first char of a string).' },
  floor: { sig: 'floor', desc: 'Round down to an integer.' },
  format: { sig: 'format(kind, arg, locale)', desc: 'Locale-aware. `kind`: `currency` (arg = ISO code), `number`, `percent`, `date` (arg = format). Needs `intl`, degrades to `number_format`.', ex: "{x | format('currency', 'USD')} → $1,234.56" },
  group_by: { sig: 'group_by(key)', desc: 'Group array items by `key` → `[{ name, items }]`.' },
  has: { sig: 'has(needle)', desc: 'Substring test (strings) / membership test (arrays) → bool.' },
  join: { sig: "join(separator = ' ')", desc: 'Join an array into a string.' },
  last: { sig: 'last', desc: 'Last element of an array.' },
  lstrip: { sig: 'lstrip', desc: 'Trim leading whitespace.' },
  map: { sig: 'map(key)', desc: 'Pluck `key` (dotted path allowed) from each array item.' },
  minus: { sig: 'minus(n)', desc: 'Subtract `n`.' },
  modulo: { sig: 'modulo(n)', desc: 'Remainder of division by `n`.' },
  money: { sig: "money(currency = 'MAD')", desc: 'Format cents as money.', ex: '{1000 | money} → 10.00 MAD' },
  newline_to_br: { sig: 'newline_to_br', desc: 'Replace `\\n` with `<br />\\n`.' },
  plus: { sig: 'plus(n)', desc: 'Add `n` numerically.' },
  prepend: { sig: 'prepend(prefix)', desc: 'Concatenate `prefix` before the string.' },
  remove: { sig: 'remove(substr)', desc: 'Delete every occurrence of `substr`.' },
  remove_first: { sig: 'remove_first(substr)', desc: 'Delete the first occurrence.' },
  remove_last: { sig: 'remove_last(substr)', desc: 'Delete the last occurrence.' },
  reject: { sig: 'reject(key, value)', desc: 'Keep items whose `key` does NOT equal `value`.' },
  replace: { sig: 'replace(search, replacement)', desc: 'Replace every occurrence.' },
  replace_first: { sig: 'replace_first(search, replacement)', desc: 'Replace the first occurrence.' },
  replace_last: { sig: 'replace_last(search, replacement)', desc: 'Replace the last occurrence.' },
  reverse: { sig: 'reverse', desc: 'Reverse an array or string.' },
  round: { sig: 'round(precision = 0)', desc: 'Round to `precision` decimals.' },
  rstrip: { sig: 'rstrip', desc: 'Trim trailing whitespace.' },
  size: { sig: 'size', desc: 'Length of a string, array, or `Countable`.' },
  slice: { sig: 'slice(offset, length = 1)', desc: 'Sub-string / sub-array. Negative `offset` counts from the end.' },
  slugify: { sig: 'slugify', desc: 'Lowercase, ASCII-fold, join word runs with `-`.', ex: "{'Hello, World!' | slugify} → hello-world" },
  sort: { sig: 'sort(key?)', desc: 'Sort ascending, optionally by `key`.' },
  sort_natural: { sig: 'sort_natural(key?)', desc: 'Case-insensitive sort.' },
  split: { sig: 'split(separator)', desc: 'Split a string into an array (`\'\'` → characters).' },
  squish: { sig: 'squish', desc: 'Collapse whitespace runs and trim.' },
  strip: { sig: 'strip', desc: 'Trim both ends.' },
  strip_html: { sig: 'strip_html', desc: 'Remove HTML tags.' },
  strip_newlines: { sig: 'strip_newlines', desc: 'Remove `\\r` and `\\n`.' },
  sum: { sig: 'sum', desc: 'Sum of a numeric array.' },
  times: { sig: 'times(n)', desc: 'Multiply by `n`.' },
  truncate: { sig: "truncate(length = 50, ellipsis = '...')", desc: 'Clip to `length` characters.' },
  truncatewords: { sig: "truncatewords(count = 15, ellipsis = '...')", desc: 'Clip to `count` words.' },
  uniq: { sig: 'uniq(key?)', desc: 'Remove duplicates.' },
  upcase: { sig: 'upcase', desc: 'Uppercase.' },
  url_decode: { sig: 'url_decode', desc: 'URL-decode.' },
  url_encode: { sig: 'url_encode', desc: 'URL-encode.' },
  where: { sig: 'where(key, value)', desc: 'Keep items whose `key` equals `value`.' },
};

// camelCase aliases → same doc, own display name.
const FILTER_ALIASES = {
  atLeast: 'at_least', atMost: 'at_most', dividedBy: 'divided_by',
  escapeOnce: 'escape_once', findIndex: 'find_index', groupBy: 'group_by',
  newlineToBr: 'newline_to_br', removeFirst: 'remove_first', removeLast: 'remove_last',
  replaceFirst: 'replace_first', replaceLast: 'replace_last', sortNatural: 'sort_natural',
  stripHtml: 'strip_html', stripNewlines: 'strip_newlines', truncateWords: 'truncatewords',
  urlDecode: 'url_decode', urlEncode: 'url_encode',
};

function filterEntry(name) {
  if (FILTERS[name]) return { ...FILTERS[name], kind: 'filter' };
  if (FILTER_ALIASES[name]) return { ...FILTERS[FILTER_ALIASES[name]], kind: `filter (alias of ${FILTER_ALIASES[name]})` };
  return undefined;
}

const FILTER_NAMES = [...Object.keys(FILTERS), ...Object.keys(FILTER_ALIASES)];

// --- markdown ------------------------------------------------------------

function md(name, entry) {
  const s = new vscode.MarkdownString();
  s.appendCodeblock(entry.sig, 'javascript');
  s.appendMarkdown(`\n_${entry.kind}_\n\n${entry.desc}`);
  if (entry.ex) s.appendMarkdown(`\n\n\`\`\`liqx\n${entry.ex}\n\`\`\``);
  return s;
}

// --- helpers -----------------------------------------------------------------

/** Is the cursor inside a `{ }` expression or the frontmatter? (rough) */
function inLiqxCode(document, position) {
  const head = document.getText(new vscode.Range(new vscode.Position(0, 0), position));
  const fm = /^---[ \t]*\r?\n/.exec(head);
  if (fm && head.indexOf('\n---', fm[0].length) === -1) return true;
  let depth = 0;
  for (let i = 0; i < head.length; i++) {
    const c = head[i];
    if (c === '{') depth++;
    else if (c === '}' && depth > 0) depth--;
  }
  return depth > 0;
}

/** 'filter' if the word is preceded by `|`, 'method' if by `.`, else 'any'. */
function classify(document, wordRange) {
  const before = document.getText(
    new vscode.Range(new vscode.Position(wordRange.start.line, 0), wordRange.start),
  );
  const m = before.match(/([|.])\s*$/);
  if (!m) return 'any';
  return m[1] === '|' ? 'filter' : 'method';
}

function ci(label, kind, entry, snippet) {
  const it = new vscode.CompletionItem(label, kind);
  it.detail = entry.sig;
  it.documentation = md(label, entry);
  if (snippet) it.insertText = new vscode.SnippetString(snippet);
  return it;
}

// --- providers -------------------------------------------------------------

const hoverProvider = {
  provideHover(document, position) {
    const range = document.getWordRangeAtPosition(position);
    if (!range) return undefined;
    const word = document.getText(range);
    const kind = classify(document, range);

    let entry;
    if (kind === 'filter') entry = filterEntry(word);
    else if (kind === 'method') entry = METHODS[word] && { ...METHODS[word] };
    else if (inLiqxCode(document, position)) {
      entry =
        GLOBALS[word] || SPECIALS[word] || KEYWORDS[word] ||
        (METHODS[word] && { ...METHODS[word] }) || filterEntry(word);
    }
    if (!entry) return undefined;
    return new vscode.Hover(md(word, entry), range);
  },
};

const filterProvider = {
  provideCompletionItems(document, position) {
    const line = document.lineAt(position).text.slice(0, position.character);
    if (!/\|\s*[A-Za-z_]*$/.test(line)) return undefined;
    return FILTER_NAMES.map((name) =>
      ci(name, vscode.CompletionItemKind.Function, filterEntry(name)),
    );
  },
};

const methodProvider = {
  provideCompletionItems(document, position) {
    const line = document.lineAt(position).text.slice(0, position.character);
    if (!/[\w$)\]]\.\s*[A-Za-z_]*$/.test(line)) return undefined;
    return Object.keys(METHODS).map((name) =>
      ci(name, vscode.CompletionItemKind.Method, METHODS[name], METHOD_SNIPPETS[name]),
    );
  },
};

const codeProvider = {
  provideCompletionItems(document, position) {
    if (!inLiqxCode(document, position)) return undefined;
    const out = [];
    for (const k of Object.keys(KEYWORDS)) {
      out.push(ci(k, vscode.CompletionItemKind.Keyword, KEYWORDS[k]));
    }
    for (const g of Object.keys(GLOBALS)) {
      const snip = { render: 'render("${1:name}", { $2 })', section: 'section("${1:name}")', now: 'now()' }[g];
      out.push(ci(g, vscode.CompletionItemKind.Function, GLOBALS[g], snip));
    }
    for (const s of Object.keys(SPECIALS)) {
      out.push(ci(s, vscode.CompletionItemKind.Variable, SPECIALS[s]));
    }
    return out;
  },
};

function activate(context) {
  context.subscriptions.push(
    vscode.languages.registerHoverProvider(SELECTOR, hoverProvider),
    vscode.languages.registerCompletionItemProvider(SELECTOR, filterProvider, '|', ' '),
    vscode.languages.registerCompletionItemProvider(SELECTOR, methodProvider, '.'),
    vscode.languages.registerCompletionItemProvider(SELECTOR, codeProvider),
  );
}

function deactivate() {}

module.exports = { activate, deactivate };
