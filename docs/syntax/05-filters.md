# Filters

Filters post-process a value in a pipe pipeline: `value | filter` or
`value | filter(arg1, arg2)`. The value comes first.

```liqx
<p>{product.title | upcase}</p>
<p>{product.price | money}</p>
```

Filter arguments are always parenthesized. Liquid's `filter: arg` colon syntax
is **not** supported:

```liqx
<p>{product.title | default('untitled')}</p>
<p>{product.price | divided_by(2) | round(1)}</p>
```

Each filter also exposes the Shopify camelCase alias (`divided_by` and
`dividedBy`, `url_encode` and `urlEncode`, …). Everything below lists the
snake_case name, and the camelCase twin works identically.

## Case

`upcase` — uppercase the value:

```liqx
<p>{'hello world' | upcase}</p>
```

`downcase` — lowercase the value:

```liqx
<p>{'Hello World' | downcase}</p>
```

`capitalize` — uppercase the first character only:

```liqx
<p>{'hello world' | capitalize}</p>
```

## Arithmetic

`plus` — add:

```liqx
<p>{price | plus(5)}</p>
```

`minus` — subtract:

```liqx
<p>{price | minus(2)}</p>
```

`times` — multiply:

```liqx
<p>{price | times(2)}</p>
```

`divided_by` — divide (with a protected return on division by zero):

```liqx
<p>{price | divided_by(4)}</p>
```

`modulo` — remainder:

```liqx
<p>{price | modulo(3)}</p>
```

`abs`, `at_least`, `at_most` — clamp and absolute value:

```liqx
<p>{count | at_least(1)}</p>
<p>{count | at_most(10)}</p>
<p>{count | abs}</p>
```

`ceil`, `floor`, `round` — numeric rounding:

```liqx
<p>{x | ceil}</p>
<p>{x | floor}</p>
<p>{x | round(2)}</p>
```

`sum` — add up an array of numbers:

```liqx
<p>{arr | sum}</p>
```

## Values

`default` — substitute a fallback for `null`, `false`, or `''`:

```liqx
<p>{error ?? 'none'}</p>
<p>{error | default('none')}</p>
```

`first` / `last` — first / last element of an array, or first character of a
string:

```liqx
<p>{arr | first}</p>
<p>{arr | last}</p>
<p>{name | first}</p>
```

`size` — length of an array/countable, or character count of a string:

```liqx
<p>{arr | size}</p>
<p>{name | size}</p>
```

## Concatenation

`append` / `prepend` — join strings:

```liqx
<p>{name | append('!')}</p>
<p>{name | prepend('Hi ')}</p>
```

`concat` — merge arrays element-wise, or concatenate as strings:

```liqx
<p>{arr | concat(arr) | join('-')}</p>
<p>{'a' | concat('b')}</p>
```

## Escaping / encoding

`escape` — HTML-escape entities:

```liqx
<p>{'<b>bold</b>' | escape}</p>
```

`escape_once` — escape only what is not already escaped:

```liqx
<p>{'&lt;b&gt;' | escape_once}</p>
```

`url_encode` / `url_decode`:

```liqx
<p>{'a b c' | url_encode}</p>
<p>{'a+b' | url_decode}</p>
```

## Strings

`join` — join array elements with a separator:

```liqx
<p>{tags | join(', ')}</p>
```

`split` — split a string into an array:

```liqx
<p>{'a-b-c' | split('-') | join('|')}</p>
```

`reverse` — reverse an array or string:

```liqx
<p>{arr | reverse | join(',')}</p>
```

`slice` — a substring or sub-array:

```liqx
<p>{name | slice(0, 2)}</p>
<p>{arr | slice(1, 2) | join(',')}</p>
```

`slugify` — lowercase, ASCII-fold, and join word runs with `-`:

```liqx
<p>{'Hello, World!' | slugify}</p>
```

`remove` / `remove_first` / `remove_last` — strip occurrences:

```liqx
<p>{'a-b-c' | remove('-')}</p>
<p>{'a-b-c' | remove_first('-')}</p>
<p>{'a-b-c' | remove_last('-')}</p>
```

`replace` / `replace_first` / `replace_last` — swap occurrences:

```liqx
<p>{'a-b-c' | replace('-', '+')}</p>
<p>{'a-b-c' | replace_first('-', '+')}</p>
<p>{'a-b-c' | replace_last('-', '+')}</p>
```

`has` — membership in an array, or substring match:

```liqx
<p>{arr | has(2)}</p>
<p>{'hello' | has('ell')}</p>
```

## Whitespace / HTML

`strip` / `lstrip` / `rstrip` — trim whitespace:

```liqx
<p>{'  hi  ' | strip}</p>
<p>{' x' | lstrip}</p>
<p>{'x ' | rstrip}</p>
```

`squish` — collapse consecutive whitespace to one space:

```liqx
<p>{'a  b   c' | squish}</p>
```

`strip_html` — remove HTML tags:

```liqx
<p>{'<p>x</p>' | strip_html}</p>
```

`strip_newlines` — remove all newlines:

```liqx
<p>{'a\nb' | strip_newlines}</p>
```

`newline_to_br` — turn newlines into `<br />`:

```liqx
<p>{'a\nb' | newline_to_br}</p>
```

`truncate` — cut a string to a length with an ellipsis:

```liqx
<p>{'Hello World' | truncate(7)}</p>
```

`truncatewords` — keep a number of words then add an ellipsis:

```liqx
<p>{'Hello beautiful world' | truncatewords(2)}</p>
```

## Money / date / format

`money` — format a cent amount in a currency:

```liqx
<p>{123 | money}</p>
```

`format` — locale-aware formatting (`number`, `percent`, `currency`, `date`):

```liqx
<p>{1234.56 | format('number')}</p>
<p>{0.5 | format('percent')}</p>
<p>{1234.56 | format('currency', 'USD')}</p>
```

`date` — format a timestamp with `strftime`-style tokens:

```liqx
<p>{123456 | date('%Y-%m-%d')}</p>
```

## Collections

`map` — project a key of each element:

```liqx
<p>{products | map('price') | join(',')}</p>
```

`where` — keep elements whose key equals a value:

```liqx
<p>{products | where('title', 'Watch') | size}</p>
```

`reject` — drop elements whose key equals a value:

```liqx
<p>{products | reject('title', 'Watch') | size}</p>
```

`find` — the first matching element:

```liqx
<p>{products | find('tags', 'featured') | map('title') | join(',')}</p>
```

`find_index` — the index of the first match:

```liqx
<p>{products | find_index('title', 'Watch')}</p>
```

`sort` / `sort_natural` — sort with an optional key:

```liqx
<p>{products | sort('price') | map('price') | join(',')}</p>
<p>{products | sort_natural('title') | map('title') | join(',')}</p>
```

`compact` — drop `null`s, optionally by key:

```liqx
<p>{products | compact('title') | size}</p>
```

`uniq` — unique elements, optionally by key:

```liqx
<p>{products | uniq('title') | size}</p>
```

`group_by` — group elements by a key into `{ name, items }` entries:

```liqx
<p>{products | group_by('title') | map('name') | join(',')}</p>
```

## Full filter list

The complete, always-registered set (both spellings work):

`abs`, `append`, `at_least`, `at_most`, `capitalize`, `ceil`, `compact`,
`concat`, `date`, `default`, `divided_by`, `downcase`, `escape`, `escape_once`,
`find`, `find_index`, `first`, `floor`, `format`, `group_by`, `has`, `join`,
`last`, `lstrip`, `map`, `minus`, `modulo`, `money`, `newline_to_br`, `plus`,
`prepend`, `remove`, `remove_first`, `remove_last`, `reject`, `replace`,
`replace_first`, `replace_last`, `reverse`, `round`, `rstrip`, `size`, `slice`,
`slugify`, `sort`, `sort_natural`, `split`, `squish`, `strip`, `strip_html`,
`strip_newlines`, `sum`, `times`, `truncate`, `truncatewords`, `uniq`, `upcase`,
`url_decode`, `url_encode`, `where`.

Hosts add project filters (`img_url`, `t`, `asset_url`, …) via
`Environment::registerFilter`.