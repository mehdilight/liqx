# Frontmatter

The frontmatter block sits between `---` fences at the top of a document and
runs *restricted* JavaScript before the body renders. It is a sandboxed subset:
data plus logic, never arbitrary script. The grammar itself rejects loops,
`new`, `class`, and `import`, and a validation pass rejects dangerous globals
and arbitrary method calls.

Because the docs' frontmatter snippets always open with a `---` fence, every
top-level `const`/`let`/`return` is a frontmatter declaration, not a block-body
local.

## Declarations

Declare values with `const`, `let`, or `var`. A declaration without an
initializer is treated as `null`:

```liqx
---
const title = "Hello";
let count = 3;
var label = null;
---
<p>{title} {count} {label}</p>
```

### Destructuring

Object destructuring pulls named properties (with optional defaults) from a
value. Missing keys fall back to the default, or to `null`:

```liqx
---
const { name, profile = {} } = user;
const { city } = profile;
---
<p>{name} lives in {city}</p>
```

Destructuring pairs naturally with member access and `.length` while reading
other values:

```liqx
---
const { name, profile = {} } = user;
const count = arr.length;
---
<p>{name} {profile.city} {count}</p>
```

## Assignment

Reassign a `let`/`var` with `=`, or with the compound operators `+=`, `-=`,
`*=`, `/=`, `%=`:

```liqx
---
let total = price;
total += 5;
total *= 2;
---
<p>{total}</p>
```

## Control flow

`if` / `else if` / `else` select between statement blocks:

```liqx
---
let status = 'guest';
if (user) {
  status = 'member';
} else {
  status = 'anonymous';
}
---
<span>{status}</span>
```

`switch` / `case` / `default` dispatch on a value. Each `case` body usually ends
with `break`:

```liqx
---
let bg = '#fff';
switch (layout) {
  case 'left': bg = '#eee'; break;
  default: bg = '#222';
}
---
<div style={`background:${bg}`}>{layout}</div>
```

A `return;` early in frontmatter aborts the whole render (nothing is emitted):

```liqx
---
if (!product || !product.available) {
  return;
}
const title = product.title;
---
<div class="product">{title}</div>
```

A bare `return;` (or any `return` in a non-final position) halts the render
gracefully — use it for guard clauses that suppress output entirely. An early
`return { ... };` with a value also aborts the render; it does **not** deliver
props to the body.

## Functions

Local `function` declarations are allowed, with optional default parameters:

```liqx
---
function label(value, fallback = 'n/a') {
  if (value == null) return fallback;
  return 'Value: ' + value;
}
const head = label(name);
---
<p>{head}</p>
```

Arrow functions can be assigned to a const and used as callbacks or helpers:

```liqx
---
const fmt = (n) => n + 'x';
const perRow = arr.map((n) => n * 2);
---
<p>{fmt(count)} {perRow.join(',')}</p>
```

Arrow functions with a block body run statements and take the trailing
`return` as their value:

```liqx
---
const greet = (who) => {
  const msg = 'Hello';
  return msg + ' ' + who;
};
const out = greet(name);
---
<p>{out}</p>
```

The body may call the curated array/string methods (`.map`, `.filter`,
`.join`, …), but not arbitrary methods on data — that is enforced at parse
time:

```liqx
---
const labels = arr.map((n) => 'n' + n);
const total = products.map((p) => p.price);
---
<p>{labels.join(',')} {total.join(',')}</p>
```

## The final `return` becomes `props`

Ending frontmatter with `return { ... };` exports an object as the body's
`props`. Object literals require explicit `key: value` pairs:

```liqx
---
const title = "Hello";
return { title: title, count: count };
---
<h1>{props.title}</h1>
<p>{props.count}</p>
```

A `return` statement must be the **last** statement in frontmatter.

## Sandbox rules

- No loops (`for`, `while`, `do`), `new`, `class`, `extends`, `import`,
  `export`, `super`.
- Forbidden identifiers cannot be referenced: `eval`, `Function`,
  `globalThis`, `window`, `document`, `process`, `require`, `fetch`, `import`,
  `exports`, `module`, `alert`, `confirm`, `prompt`, `constructor`,
  `__proto__`, `prototype`.
- Only the curated array/string methods are callable; computed / arbitrary
  method calls are rejected: `user.deleteAll()` fails at parse time.
- JSX elements are not allowed in frontmatter — markup belongs in the body.
- `root` is reserved and cannot be declared or reassigned.
