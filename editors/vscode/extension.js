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
const fs = require('fs');
const path = require('path');

const SELECTOR = { language: 'liqx', scheme: '*' };

// --- snippet component discovery -------------------------------------------

function findSnippetDirs(document) {
  const dirs = [];
  if (document && document.uri && document.uri.fsPath) {
    let cur = path.dirname(document.uri.fsPath);
    for (let i = 0; i < 6; i++) {
      const candidate = path.join(cur, 'snippets');
      if (fs.existsSync(candidate) && fs.statSync(candidate).isDirectory()) {
        dirs.push(candidate);
        break;
      }
      const parent = path.dirname(cur);
      if (parent === cur) break;
      cur = parent;
    }
  }
  if (vscode.workspace && vscode.workspace.workspaceFolders) {
    for (const folder of vscode.workspace.workspaceFolders) {
      for (const rel of ['apps/storefront/cein/snippets', 'apps/storefront/flora/snippets', 'snippets']) {
        const candidate = path.join(folder.uri.fsPath, rel);
        if (fs.existsSync(candidate) && fs.statSync(candidate).isDirectory() && !dirs.includes(candidate)) {
          dirs.push(candidate);
        }
      }
    }
  }
  return dirs;
}

function discoverSnippets(document) {
  const dirs = findSnippetDirs(document);
  const components = {};

  for (const dir of dirs) {
    let files = [];
    try {
      files = fs.readdirSync(dir).filter((f) => f.endsWith('.liqx'));
    } catch (e) {
      continue;
    }

    for (const file of files) {
      const baseName = path.basename(file, '.liqx');
      const compName = baseName.replace(/(?:^|[-_])([a-z0-9])/gi, (_, c) => c.toUpperCase());
      if (components[compName]) continue;

      const fullPath = path.join(dir, file);
      let content = '';
      try {
        content = fs.readFileSync(fullPath, 'utf8');
      } catch (e) {
        continue;
      }

      const props = [];
      const slots = [];

      // 1. const { a, b = 1 } = props;
      const pm = /(?:const|let|var)\s*\{\s*([^}]+)\s*\}\s*=\s*props\b/.exec(content);
      if (pm) {
        for (const part of pm[1].split(',')) {
          const m = /^\s*([a-zA-Z_]\w*)/.exec(part);
          if (m && !props.includes(m[1])) props.push(m[1]);
        }
      }

      // 2. props.xxx
      const propRegex = /\bprops\.([a-zA-Z_]\w*)\b/g;
      let pMatch;
      while ((pMatch = propRegex.exec(content)) !== null) {
        const p = pMatch[1];
        if (p !== 'children' && p !== 'slots' && !props.includes(p)) {
          props.push(p);
        }
      }

      // 3. <schema> props
      const sm = /<schema>([\s\S]*?)<\/schema>/.exec(content);
      if (sm) {
        try {
          const schema = JSON.parse(sm[1].trim());
          if (schema && schema.props && typeof schema.props === 'object') {
            for (const k of Object.keys(schema.props)) {
              if (!props.includes(k)) props.push(k);
            }
          }
        } catch (e) {}
      }

      // 4. <slot name="...">
      const slotRegex = /<slot\s+name=["']([^"']+)["']/gi;
      let slMatch;
      while ((slMatch = slotRegex.exec(content)) !== null) {
        if (!slots.includes(slMatch[1])) slots.push(slMatch[1]);
      }

      let doc = `### Component \`<${compName} />\`\n\n**Snippet:** \`snippets/${baseName}.liqx\`\n\n`;
      if (props.length > 0) {
        doc += `**Props:**\n${props.map((p) => `- \`${p}\``).join('\n')}\n`;
      }
      if (slots.length > 0) {
        doc += `\n**Slots:**\n${slots.map((s) => `- \`${s}\``).join('\n')}\n`;
      }

      components[compName] = {
        file: fullPath,
        component: compName,
        snippet: baseName,
        props,
        slots,
        doc,
      };
    }
  }

  return components;
}

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

    // Check Control Flow Components
    const CONTROL_FLOW_DOCS = {
      If: '### `<If condition={...}>`\n\nConditionally renders children when `condition` evaluates to truthy. Supports `<ElseIf condition={...}>` and `<Else>` children.',
      ElseIf: '### `<ElseIf condition={...}>`\n\nBranch condition within an `<If>` block.',
      Else: '### `<Else>`\n\nFallback branch within an `<If>` block.',
      Show: '### `<Show when={...} fallback={...}>`\n\nConditionally renders children when `when` is truthy, otherwise renders `fallback` or `<template slot="fallback">`.',
      Switch: '### `<Switch value={...}>`\n\nPattern-matching component. Evaluates `<Match>` branches in order until a match is found.',
      Match: '### `<Match when={...}>`\n\nBranch inside a `<Switch>` component.',
      Default: '### `<Default>`\n\nFallback branch inside a `<Switch>` component.',
    };
    if (CONTROL_FLOW_DOCS[word]) {
      return new vscode.Hover(new vscode.MarkdownString(CONTROL_FLOW_DOCS[word]), range);
    }

    // Check Component Snippets
    if (/^[A-Z][a-zA-Z0-9_]*$/.test(word)) {
      const snippets = discoverSnippets(document);
      if (snippets[word]) {
        return new vscode.Hover(new vscode.MarkdownString(snippets[word].doc), range);
      }
    }

    // Check Class Modifiers (class:active)
    const lineText = document.lineAt(position).text;
    const classModMatch = /class:([\w-]+)/.exec(lineText.slice(Math.max(0, position.character - 20), position.character + 20));
    if (word.startsWith('class:') || (classModMatch && classModMatch[1] === word)) {
      const modName = word.startsWith('class:') ? word.slice(6) : (classModMatch ? classModMatch[1] : word);
      const doc = new vscode.MarkdownString();
      doc.appendCodeblock(`class:${modName}={condition}`, 'html');
      doc.appendMarkdown(`\n_class modifier_\n\nConditionally applies the class \`${modName}\` when the expression evaluates to truthy.`);
      return new vscode.Hover(doc, range);
    }

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

const componentProvider = {
  provideCompletionItems(document, position) {
    const line = document.lineAt(position).text.slice(0, position.character);

    // 1. Tag position after `<`
    const tagMatch = /<([A-Za-z_]*)$/.exec(line);
    if (tagMatch) {
      const items = [];

      // Control flow components
      const ifItem = new vscode.CompletionItem('If', vscode.CompletionItemKind.Keyword);
      ifItem.detail = '<If condition={...}>';
      ifItem.insertText = new vscode.SnippetString('If condition={${1:condition}}>\n\t$0\n</If>');
      items.push(ifItem);

      const showItem = new vscode.CompletionItem('Show', vscode.CompletionItemKind.Keyword);
      showItem.detail = '<Show when={...} fallback={...}>';
      showItem.insertText = new vscode.SnippetString('Show when={${1:condition}}>\n\t$0\n</Show>');
      items.push(showItem);

      const switchItem = new vscode.CompletionItem('Switch', vscode.CompletionItemKind.Keyword);
      switchItem.detail = '<Switch value={...}>';
      switchItem.insertText = new vscode.SnippetString('Switch value={${1:value}}>\n\t<Match when=\"${2:case}\">\n\t\t$0\n\t</Match>\n\t<Default>\n\t</Default>\n</Switch>');
      items.push(switchItem);

      const matchItem = new vscode.CompletionItem('Match', vscode.CompletionItemKind.Keyword);
      matchItem.detail = '<Match when={...}>';
      matchItem.insertText = new vscode.SnippetString('Match when=\"${1:case}\">\n\t$0\n</Match>');
      items.push(matchItem);

      const elseIfItem = new vscode.CompletionItem('ElseIf', vscode.CompletionItemKind.Keyword);
      elseIfItem.detail = '<ElseIf condition={...}>';
      elseIfItem.insertText = new vscode.SnippetString('ElseIf condition={${1:condition}}>\n\t$0\n</ElseIf>');
      items.push(elseIfItem);

      const elseItem = new vscode.CompletionItem('Else', vscode.CompletionItemKind.Keyword);
      elseItem.detail = '<Else>';
      elseItem.insertText = new vscode.SnippetString('Else>\n\t$0\n</Else>');
      items.push(elseItem);

      const defaultItem = new vscode.CompletionItem('Default', vscode.CompletionItemKind.Keyword);
      defaultItem.detail = '<Default>';
      defaultItem.insertText = new vscode.SnippetString('Default>\n\t$0\n</Default>');
      items.push(defaultItem);

      const snippets = discoverSnippets(document);
      for (const s of Object.values(snippets)) {
        const item = new vscode.CompletionItem(s.component, vscode.CompletionItemKind.Class);
        item.detail = `Snippet Component (${s.snippet}.liqx)`;
        item.documentation = new vscode.MarkdownString(s.doc);
        const firstProp = s.props[0];
        const insert = firstProp ? `${s.component} ${firstProp}={$1} />$0` : `${s.component} />$0`;
        item.insertText = new vscode.SnippetString(insert);
        items.push(item);
      }

      return items;
    }

    // 2. Inside open tag `<Tag |` -> props and class modifiers
    const lastOpen = line.lastIndexOf('<');
    const lastClose = line.lastIndexOf('>');
    if (lastOpen !== -1 && (lastClose === -1 || lastClose < lastOpen)) {
      const items = [];

      // class: modifier suggestion
      const classModItem = new vscode.CompletionItem('class:name', vscode.CompletionItemKind.Snippet);
      classModItem.detail = 'class:modifier={condition}';
      classModItem.documentation = new vscode.MarkdownString('Svelte-style conditional class modifier. Appends class when condition is truthy.');
      classModItem.insertText = new vscode.SnippetString('class:${1:active}={${2:isActive}}');
      items.push(classModItem);

      const inside = line.slice(lastOpen + 1);
      const m = /^([A-Z][\w-]*)/.exec(inside);
      if (m) {
        const compName = m[1];
        const snippets = discoverSnippets(document);
        const snippetInfo = snippets[compName];
        if (snippetInfo && snippetInfo.props.length > 0) {
          for (const p of snippetInfo.props) {
            const item = new vscode.CompletionItem(p, vscode.CompletionItemKind.Field);
            item.detail = `Component Prop (${compName})`;
            item.insertText = new vscode.SnippetString(`${p}={$1}$0`);
            items.push(item);
          }
        }
      }

      return items;
    }

    return undefined;
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
    vscode.languages.registerCompletionItemProvider(SELECTOR, componentProvider, '<', ' '),
    vscode.languages.registerCompletionItemProvider(SELECTOR, codeProvider),
  );
}

function deactivate() {}

module.exports = { activate, deactivate };

