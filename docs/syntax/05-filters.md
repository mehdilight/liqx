# 5. Filters

Value-first pipeline, like Liquid. `a | f(b)` calls `f(a, b)`.

```liqx
{title | upcase}
{title | truncate(40) | upcase}
{price | money('USD')}
{text | replace('a', 'b') | truncate(5) | upcase}
```

* Chainable left to right.
* Arguments are full expressions: `{x | default(fallback)}`.
* Filter names accept both `snake_case` and `camelCase`
  (`divided_by` == `dividedBy`).
* Usable in frontmatter too: `const h = settings.heading | truncate(40);`
* Unknown filter → error (names the template + line).

## Standard filters

Generic, language-level only. Domain filters (`img_url`, `t`, `asset_url`, …)
are registered by the host via `Environment::registerFilter`.

### Case & string

| Filter | Example → result |
|--------|------------------|
| `upcase` | `'hi' \| upcase` → `HI` |
| `downcase` | `'Hi' \| downcase` → `hi` |
| `capitalize` | `'hi there' \| capitalize` → `Hi there` |
| `append(s)` | `'ab' \| append('cd')` → `abcd` |
| `prepend(s)` | `'ab' \| prepend('cd')` → `cdab` |
| `concat(x)` | `3 \| concat(' items')` → `3 items`; merges two arrays |
| `remove(s)` / `remove_first(s)` / `remove_last(s)` | delete substring(s) |
| `replace(a,b)` / `replace_first(a,b)` / `replace_last(a,b)` | substitute |
| `slugify` | `'Hello, World!' \| slugify` → `hello-world` |
| `truncate(n=50, ell='...')` | clip to `n` chars |
| `truncatewords(n=15, ell='...')` | clip to `n` words |
| `strip` / `lstrip` / `rstrip` | trim whitespace |
| `squish` | collapse runs of whitespace + trim |
| `strip_html` | remove tags |
| `strip_newlines` | remove `\r` `\n` |
| `newline_to_br` | `\n` → `<br />\n` |
| `split(sep)` | `'a,b,c' \| split(',')` → `['a','b','c']` (`''` → chars) |
| `size` | length of string, array, or `Countable` |
| `slice(offset, len=1)` | substring / sub-array |
| `reverse` | reverse string or array |
| `has(x)` | substring / element membership → bool |

### Escaping / encoding

| Filter | Notes |
|--------|-------|
| `escape` | HTML-escape (`ENT_QUOTES`, UTF-8) |
| `escape_once` | escape without double-escaping entities |
| `url_encode` / `url_decode` | `urlencode` / `urldecode` |

### Numbers

| Filter | Example → result |
|--------|------------------|
| `abs` | `-5 \| abs` → `5` |
| `plus(n)` `minus(n)` `times(n)` | arithmetic |
| `divided_by(n)` | integer division if both ints; `/0` → `0` |
| `modulo(n)` | `10 \| modulo(3)` → `1` |
| `at_least(n)` `at_most(n)` | clamp |
| `ceil` `floor` `round(p=0)` | rounding |
| `sum` | sum of an array |
| `money(currency='MAD')` | `1000 \| money` → `10.00 MAD` (value is cents) |
| `format(kind, arg, locale)` | locale-aware — see below |

`format` modes (needs `intl`, degrades gracefully):

```liqx
{x | format('currency', 'USD')}          → $1,234.56
{x | format('currency', 'EUR', 'de_DE')}
{x | format('number', 'de_DE')}          → 1.234,56
{x | format('percent')}                  → 50%   (input 0.5)
{x | format('date', '%Y-%m-%d')}         → same rules as `date`
```

### Dates

| Filter | Notes |
|--------|-------|
| `date(fmt)` | `strftime`-style `%Y %m %d %H:%M …`; input: timestamp, `'now'`, or any `strtotime` string |

```liqx
{now() | date('%Y')}
{article.published_at | date('%b %d, %Y')}
```

### Values

| Filter | Notes |
|--------|-------|
| `default(fallback)` | fallback when value is `null` / `false` / `''` |
| `first` / `last` | first / last element (string: first char) |

### Collections

| Filter | Notes |
|--------|-------|
| `map(key)` | pluck `key` (dotted, e.g. `'a.b'`) from each item |
| `where(key, val)` | keep items whose `key === val` |
| `reject(key, val)` | drop items whose `key === val` |
| `find(key, val)` / `find_index(key, val)` | first match / its index |
| `sort(key?)` / `sort_natural(key?)` | sort, optionally by key |
| `uniq(key?)` | dedupe |
| `compact(key?)` | drop `null` entries |
| `group_by(key)` | → `[{ name, items }, …]` |
| `join(sep=' ')` | join array to string |
| `reverse` | reverse array |
| `sum` `size` `first` `last` | as above |
