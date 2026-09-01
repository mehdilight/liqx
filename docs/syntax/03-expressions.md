# Expressions

`{ ... }` in the render body, in attribute values, and inside verbatim blocks
introduces a real JavaScript-flavored expression. Liqx parses a genuine
expression language — the same "real JS" subset runs in the frontmatter.

## Literals

Strings (`'...'` or `"..."`), numbers, booleans, `null`, and `undefined`:

```liqx
<p>{'a string'} {42} {true} {null} {undefined}</p>
```

`undefined` evaluates to `null`:

```liqx
<p>{empty ?? 'fallback'}</p>
```

An empty `{ }` — e.g. a lone comment — renders nothing:

```liqx
<div>{/* nothing below */}
  <p>{title}</p>
</div>
```

## Array and object literals

```liqx
<p>{[1, 2, 3].join('-')}</p>
<p>{({ a: 1, b: 2 }).b}</p>
```

Object keys always use `key: value` — shorthand `{ a }` is not supported:

```liqx
---
return { label: name, url: user.url };
---
<a href={props.url}>{props.label}</a>
```

## Template strings

Backtick strings interpolate `${...}` expressions:

```liqx
<p>{`Hello ${name}, you have ${count} items`}</p>
```

```liqx
<div style={`background:${settings.bg_color}`}>{heading}</div>
```

## Member access

Dot access, computed brackets, and null-safe `?.` (which short-circuits to
`null` when the left side is undefined/null):

```liqx
<p>{user.name} {user['url']}</p>
<p>{maybe.items?.length ?? 0}</p>
<p>{cart.items.length} {routes.cart_url}</p>
```

`root` always refers to the whole render payload and cannot be shadowed:

```liqx
<p>{root.shop.name}</p>
```

## Operators

Arithmetic `+ - * / %`, comparison, equality, and logical operators follow
JavaScript precedence and associativity:

```liqx
<p>{a + b} {b - a} {a * b} {b / a}</p>
<p>{a == 1} {a === 1} {a !== 2} {a < b}</p>
<p>{show && 'shown'} {error || 'none'} {error ?? 'none'}</p>
<p>{show ? 'yes' : 'no'}</p>
```

`+` concatenates when either side is a string, otherwise it adds numbers:

```liqx
<p>{name + '!'} {a + b}</p>
```

`??` is lenient about undefined left-hand variables, returning the right side:

```liqx
<p>{missing ?? 'default value'}</p>
```

Precedence: member/call → unary → `* / %` → `+ -` → relational → equality →
`&&` → `??` → `||` → conditional:

```liqx
<p>{a + b * 2 > 3 && show ? 'big' : 'small'}</p>
```

## Array and string methods

Values expose a curated set of methods. See
[06-methods-globals.md](./06-methods-globals.md) for the full list.

```liqx
<ul>{items.map((item) => <li>{item.title}</li>)}</ul>
```

```liqx
<p>{arr.filter((n) => n > 1).join(',')}</p>
<p>{arr.includes(2)}</p>
<p>{arr.slice(0, 2).join('+')}</p>
<p>{name.toUpperCase()} {name.toLowerCase()}</p>
<p>{'  hi  '.trim()} {greeting.split('-').join(' ')}</p>
```

Array callbacks receive `(element, index, array)`:

```liqx
<p>{arr.map((n, i) => `${i}:${n}`).join(' ')}</p>
```

## Arrow functions

Arrow functions are first-class values usable inline:

```liqx
<p>{items.find((item) => item.active).title}</p>
```

## JSX inside expressions

An element inside `{ }` evaluates to its rendered HTML:

```liqx
<p>{show ? <strong>{title}</strong> : <em>{title}</em>}</p>
```

```liqx
<ul>{products.map((p) => <li>{p.title} — {p.price}</li>)}</ul>
```

The `<style>` and `<script>` blocks inside expressions are also allowed and
evaluate to their rendered markup:

```liqx
<div>
  {<style>.x { color: red; }</style>}
  <p class="x">{title}</p>
</div>
```

## Comments inside expressions

`//` line comments and `/* ... */` block comments are dropped at lex time:

```liqx
<p>{heading /* the main heading */}</p>
```

```liqx
<p>{// a line comment
  heading}</p>
```
<div>{/* nothing below */}
  <p>T</p>
</div>

## Function calls

Functions resolve as: a local value, a registered global (`render`, `section`,
`now`, host globals), then a filter:

```liqx
<p>{now}</p>
<p>{render("sidebar")}</p>
<p>{section("hero")}</p>
```

Calls on members dispatch array/string methods; calls on other values invoke
callables:

```liqx
<p>{greeting.replace('i', 'o')}</p>
```

## The pipe pipeline

`value | filter` pipes a value through filters. Filter arguments use
parentheses — the Liquid `| filter: arg` syntax is **not** supported:

```liqx
<p>{product.title | upcase}</p>
<p>{product.title | append('!') | escape}</p>
<p>{product.price | divided_by(2) | round(1)}</p>
<p>{name | default('guest')}</p>
```

Filters apply in order, left to right, and any expression may be piped:

```liqx
<p>{items.map((i) => i.title) | join(' & ')}</p>
```

Note that member access binds *before* a pipe — a pipeline result cannot be
dereferenced directly with `.`:

```liqx
<p>{user.profile.city}</p>
```

## Supported expressions you might not expect

Computed member access works on any value, and `"length"` behaves like the
`.length` property on both arrays and strings:

```liqx
<p>{arr[0]} {arr['length']}</p>
<p>{'abcd'['length']}</p>
<p>{user['profile']['city']}</p>
```

Short-circuit logical operators return the deciding operand, not a boolean:

```liqx
<p>{a || 'fallback'}</p>
<p>{a && 'present'}</p>
<p>{maybe.items?.length ?? 0}</p>
```

Template strings nest:

```liqx
<p>{`a ${`b ${x}`} c`}</p>
```

Unary operators compose (there is no `++`/`--` increment — they parse as pairs
of `+`/`-`):

```liqx
<p>{++count}</p>
<p>{--price}</p>
```

## What is rejected

The lexer recognizes more JavaScript keywords than the evaluator implements.
Forms that produce a parse error (not silently ignored) include:

- Loops and declarations inside `{ }`: `for`, `while`, `do`, `class`,
  `import`.
- `new`, `typeof`, `instanceof`, `void`, `delete` — lexed as keywords but not
  evaluated.
- Operator forms not in the precedence table: `**` (exponent), `<<`, `>>`.
- Increment / decrement, postfix and prefix: `i++`, `--i` (as intended
  operators).
- Assignment as an expression: `x = 5`, `x += 1` inside `{ }` (assignment is
  only a frontmatter statement).
- Spread in literals: `[...arr]`, `{ ...obj }`.
- Regex literals: `/pattern/`.
- Non-decimal and exponent number literals: `0x1F`, `1e3` (the lexer reads
  decimal integers and floats only).
- Object shorthand: `{ a }` (use `{ a: a }`).
- Chaining a member access onto a pipe result: `arr | join('-') .length`.

This is intentional — the language is a sandboxed, predictable subset, and a
rejected form fails loudly at parse time rather than doing something
surprising.