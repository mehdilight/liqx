# 6. Frontmatter

A restricted, sandboxed subset of JS between `---` fences at the top of the
document. Runs at render time against the live data; the body reads what it
declares.

```liqx
---
const { settings, blocks } = section;
const featured = products.filter(p => p.tags.includes('featured'));
const heading  = settings.heading | truncate(40) | upcase;
const isLive   = now() >= settings.start && now() <= settings.end;
return { heading: heading, featured: featured };
---
<h1>{heading}</h1>
```

## Declarations

```liqx
const x = 5;
let y = x * 2;
const name = `Hi ${user.name}`;
const t = product?.title ?? 'Untitled';
```

* `const` and `let` only. One initializer each; `;` optional.
* Full expression grammar: literals, operators, ternary, pipes, member
  access, template strings, `?.`.

### Destructuring

```liqx
const { product, size = 'medium' } = props;
const { settings, blocks } = section;
```

Object destructuring with defaults. Keys pulled from arrays, objects, or
Drops (`beforeMethod`).

### `return` → `props`

An optional **final** statement. Its value is exposed to the body as `props`:

```liqx
---
const title = block.title | default('Hello');
return { title: title, count: 3, items: [1, 2] };
---
<h1>{props.title}</h1><b>{props.items.length}</b>
```

* Must be the last frontmatter statement (else syntax error).
* May not contain JSX or arbitrary arrows.

## `root`

Reserved identifier → the whole render payload (outermost scope). Not
shadowable, cannot be declared.

```liqx
---
const t = root.block.title | default('n/a');
---
<h1>{root.shop.name}</h1>
```

## `now()`

Global returning the current Unix timestamp. Pair with `date`:

```liqx
const year = now() | date('%Y');
const live = now() >= start && now() <= end;
```

## Sandbox rules

The grammar already forbids loops, `function`, `new`, `class`, `import`,
assignment. Additionally rejected **at parse time**:

| Rejected | Example |
|----------|---------|
| Dangerous globals | `eval`, `Function`, `window`, `document`, `process`, `require`, `fetch`, `globalThis`, `import`, `module`, `exports`, `alert`, `confirm`, `prompt` |
| Prototype access | `obj.constructor`, `__proto__`, `prototype` |
| Arbitrary arrows | `const double = x => x * 2;` — arrows are allowed **only** as `.map` / `.filter` / `.find` / `.some` / `.every` callbacks |
| Arbitrary method calls on data | `user.deleteAll()`, `x[fn]()` |
| JSX / markup | `const b = show && <span>hi</span>;` — markup belongs in the body |

### Methods callable in frontmatter

Only this curated set (see [collection-methods.md](./07-collection-methods.md)):

```
map  filter  find  some  every  join  includes  concat  slice  indexOf
toUpperCase  toLowerCase  replace  replaceAll  trim  split  startsWith  endsWith
```

Anything else → use a `const` or a filter instead.

## Compile-time constants

In the compiled path, `const`s that are pure literal trees (literals,
numeric `+ - * / %`, `===` / `!==`, unary, and chains of earlier static
consts) are folded once at compile time and inlined. Consts that touch data,
globals, filters, or parity-risky ops (`==`, `< >`, `&& || ??`, `?:`) stay
per-request.

## Errors & inspection

* Errors carry the frontmatter source line (and template name).
* In compiled mode, invalid frontmatter fails fast at `parse()`.
* `Template::frontmatter($data)` returns the evaluated `const → value` map
  (plus `props`) without rendering the body — a debug endpoint.
