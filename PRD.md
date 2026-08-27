# Liqx — A JSX-flavored Template Syntax (Liquid alternative)

A template language that keeps Liquid's section/schema/block model but uses
real HTML + real JSX expressions instead of `{{ }}` / `{% %}` tags.

---

## 1. Core principle

- Output markup is **plain HTML**.
- Dynamic values and logic use **real JSX-style `{ }` expressions** — ternaries,
  `&&`, `.map()`, template literals — not custom pseudo-elements.
- No invented control-flow tags like `<If>`, `<For>`, `<Switch>`. Logic is just JS.
- An optional **frontmatter block** (`---`) at the top of the file holds
  variable declarations / logic, similar to Astro.
- Schema (settings, blocks, presets) stays as a **plain JSON object**, same
  shape Liquid already uses, inside a `<schema>` block.

---

## 2. File anatomy

```jsx
---
// frontmatter: plain JS, runtime bindings & derived values
const { settings, blocks } = section;
const featured = products.filter(p => p.tags.includes('featured'));
---

<section>
  <!-- render body: plain HTML + JSX expressions -->
</section>

<style>
  /* scoped styles, { } interpolation allowed */
</style>

<schema>
{
  /* plain JSON, same shape as Liquid's {% schema %} */
}
</schema>
```

---

## 3. Interpolation

| Liquid | Liqx |
|---|---|
| `{{ var }}` | `{var}` |
| `{{ product.title }}` | `{product.title}` |

Just single braces, real JS expressions inside.

---

## 4. Control flow (no custom tags — real JS)

### Conditionals

```jsx
{settings.show_badge && (
  <span class="badge">New</span>
)}
```

```jsx
{settings.layout === 'left' ? (
  <div class="left">{heading}</div>
) : (
  <div class="center">{heading}</div>
)}
```

### Loops

```jsx
{blocks.map(block => (
  <li key={block.id}>{block.settings.text}</li>
))}
```

### Multi-branch ("case/when")

Use nested ternaries or a `switch` in the frontmatter that returns a value —
no `<Switch>/<Case>` elements:

```jsx
{blocks.map(block => (
  block.type === 'text' ? (
    <p {...block.attrs}>{block.settings.text}</p>
  ) : block.type === 'button' ? (
    <a href={block.settings.url} {...block.attrs}>{block.settings.label}</a>
  ) : null
))}
```

### Comments (not rendered)

```jsx
{/* this is a comment, works like real JSX comments */}
```

### Raw / literal braces

Escape by making the braces a string literal:

```jsx
{'{{ not interpolated }}'}
```

---

## 5. Frontmatter (replaces value-producing tags)

The frontmatter block **looks like JavaScript but isn't real JavaScript** —
it's a restricted, sandboxed subset that only permits template-safe
constructs. This mirrors Liquid's own safety model (no arbitrary code
execution, no imports beyond declared components, no I/O, no access to
globals) just expressed in JS-like syntax instead of Liquid's filter
grammar.

**Allowed:** `const`/`let` declarations, template literals, ternaries,
`&&`/`||`/`??`, basic arithmetic/comparison, `.map()`/`.filter()`/`.find()`
on arrays already in scope, pipe (`|`) filter chains.

**Not allowed:** arbitrary function definitions, `while`/`for` loops,
`eval`, dynamic `import()`, network/file access, mutation of anything
outside local variables, access to any global except the values the
runtime explicitly injects (`section`, `product`, `settings`, etc).

This distinction matters because it's what makes Liqx safe for
merchant/theme-author-authored templates the same way Liquid is — a
`.liqx` file is data-plus-restricted-logic, not a script that can do
anything. A build/parse step validates the frontmatter against this
subset before compiling; the value comes from JS-*familiar* syntax, not
from actually being an unrestricted JS runtime.

| Liquid tag | Liqx (frontmatter) |
|---|---|
| `{% assign x = 5 %}` | `const x = 5;` |
| `{% assign x = product.price \| money %}` | `const x = product.price \| money;` |
| `{% capture greeting %}Hi {{ name }}{% endcapture %}` | `const greeting = \`Hi ${name}\`;` |
| `{% liquid ... %}` (multi-statement block) | the whole frontmatter block |
| `{% increment count %}` | real loop index, e.g. `.map((x, i) => ...)` |
| `{% cycle 'odd','even' %}` | `i % 2 === 0 ? 'even' : 'odd'` inside `.map` |

---

## 6. Snippets

A Liquid snippet (`snippets/card.liquid`) is a small reusable chunk of
markup, invoked with `{% render 'card', product: p %}`. It has **no schema**
of its own (unlike sections) — it just receives whatever variables the
caller passes in.

### Liquid snippet

```liquid
<!-- snippets/card.liquid -->
<div class="card">
  <img src="{{ product.image | img_url: '300x' }}" alt="{{ product.title }}">
  <h3>{{ product.title }}</h3>
  <span>{{ product.price | money }}</span>
</div>
```

```liquid
{% render 'card', product: featured_product %}
```

### Liqx equivalent

A snippet is just a `.liqx` **component file** — same anatomy as a section
minus the `<schema>` block, since snippets don't have merchant-editable
settings. Parameters arrive as normal function-style props, not a magic
`render`-scoped variable bag.

```jsx
// snippets/card.liqx
---
const { product } = props;
---

<div class="card">
  <img src={product.image | img_url('300x')} alt={product.title} />
  <h3>{product.title}</h3>
  <span>{product.price | money}</span>
</div>
```

Using it:

```jsx
import Card from './snippets/card.liqx';

<Card product={featuredProduct} />
```

### Key differences from sections

| | Section (`.liqx`) | Snippet (`.liqx`) |
|---|---|---|
| Has `<schema>` block | Yes — merchant-editable settings/blocks | No — snippets aren't merchant-configurable |
| Has `<style>` block | Yes, typically scoped per section instance | Optional, usually omitted (styles live with the parent) |
| Receives data via | `section.settings`, `section.blocks` (schema-resolved) | plain `props` passed by the caller, like any component |
| Registered in theme editor | Yes (shows up as an addable section) | No — snippets are implementation details, not editor-facing |

### Multiple/named parameters

Liquid's `{% render 'card', product: p, size: 'large' %}` maps directly to
multiple JSX props — no special syntax needed:

```jsx
<Card product={featuredProduct} size="large" />
```

```jsx
// snippets/card.liqx
---
const { product, size = 'medium' } = props;
---
```

(Default values, e.g. `size = 'medium'`, are just normal JS default
destructuring — replacing Liquid's `{% unless size %}{% assign size = 'medium' %}{% endunless %}` pattern.)

| Liquid | Liqx |
|---|---|
| `{% render 'card', product: p %}` | `<Card product={p} />` |
| `{% render 'card', product: p, size: 'large' %}` | `<Card product={p} size="large" />` |
| snippet body accessing `product` | `const { product } = props;` in frontmatter |
| `{% render 'card' %}` (implicit parent scope — Liquid discourages this) | not supported — Liqx snippets are always explicit-props-only, no scope leakage |

### File structure — flat, no nested folders

Same as Liquid: **`snippets/` is flat**, no subfolders. All snippet files
live directly under `snippets/`, referenced by filename only:

```
snippets/
  card.liqx
  product-badge.liqx
  price-range.liqx
```

```jsx
import Card from './card.liqx';          // ✅ same folder
import Card from './snippets/card.liqx';  // ❌ no nested paths, matches Liquid's flat convention
```

This matches Liquid's own constraint — `{% render 'card' %}` only ever
resolves a snippet by bare name from the single `snippets/` directory, never
a path like `{% render 'products/card' %}`. Liqx keeps this restriction
rather than allowing arbitrary import paths, so snippet resolution stays
predictable and matches existing Liquid theme tooling/conventions. The same
flat rule applies to `sections/` — no subfolders there either.

---

## 6a. `{% section %}` — rendering a section file

`{% section %}` is different from `{% render %}`: it renders a **whole
section file** (with its own schema/settings/blocks), and it's what
theme-editor "sections" are built on.

```liquid
<!-- theme.liquid or a template -->
{% section 'header' %}
{% section 'hero' %}
```

This looks up `sections/header.liquid`, resolves its schema against the
merchant's saved settings for that instance, and renders the result.

### Liqx equivalent

Since section files are just modules that export a component, `{% section %}`
becomes a plain **import + usage** — no special tag needed:

```jsx
import Header from './sections/header.liqx';
import Hero from './sections/hero.liqx';

<Header />
<Hero />
```

Settings/blocks still come from the section's own `<schema>` block, resolved
at render time against the merchant's stored config for that instance — same
runtime resolution Liquid already does, just triggered by an import + JSX
tag instead of a template keyword.

### JSON templates (`templates/index.json`)

Shopify's JSON templates reference sections **by id/type**, not by tag:

```json
{
  "sections": {
    "hero": { "type": "hero", "settings": { "heading": "Sale" } }
  },
  "order": ["hero"]
}
```

This format is unchanged in Liqx — it's describing *which section files and
settings to use*, not markup. The runtime maps each `"type"` to its
`.liqx` file (e.g. `"hero"` → `sections/hero.liqx`) and renders it with the
given `settings` override, same as today.

| Liquid | Liqx |
|---|---|
| `{% section 'header' %}` (static reference in a layout) | `import Header from './sections/header.liqx'` + `<Header />` |
| `templates/*.json` `"sections"` block (theme-editor driven) | unchanged — plain JSON, `"type"` maps to `sections/<type>.liqx` |
| `{% form 'contact' %}...{% endform %}` | plain `<form {...formAttrs('contact')}>...</form>` |

---

## 7. Filters → pipe syntax (`|`)

Filters use **pipe syntax**, matching Liquid's own `|` — this is the one
place Liqx keeps Liquid's literal operator instead of going full JS-call
style. Allowed **only inside `{ }` expressions**.

`a | fn(args)` compiles to `fn(a, args)` — the piped value becomes the
function's first argument. Chains read left to right, same as Liquid:

```jsx
{text | replace('a', 'b') | truncate(20) | upcase}
```

compiles to:

```js
upcase(truncate(replace(text, 'a', 'b'), 20))
```

Filters themselves are plain functions, **value-first**, same argument order
Liquid already uses:

```js
function replace(str, a, b) { return str.replaceAll(a, b); }
function truncate(str, n) { return str.length > n ? str.slice(0, n) + '…' : str; }
function upcase(str) { return str.toUpperCase(); }
function money(cents) { /* ... */ }
```

They don't need to be called directly — the pipe is the standard way to
apply them. Direct function-call syntax (`upcase(text)`) still works since
`{ }` is real JS, but `|` is the idiomatic/preferred form throughout Liqx
templates.

### Native JS still covers some cases — no filter needed

| Liquid | Liqx |
|---|---|
| `{{ array \| join: ', ' }}` | `{array.join(', ')}` |
| `{{ str \| size }}` | `{str.length}` |
| `{{ price \| default: 0 }}` | `{price ?? 0}` |

### Domain filters → imported, then piped

```jsx
import { money, img_url, t } from '@shop/filters';

<span>{product.price | money}</span>
<img src={product.image | img_url('400x')} />
<h1>{'hero.heading' | t}</h1>
```

### More pipe examples

| Liquid | Liqx |
|---|---|
| `{{ price \| money }}` | `{price \| money}` |
| `{{ title \| truncate: 20 }}` | `{title \| truncate(20)}` |
| `{{ text \| replace: 'a', 'b' \| upcase }}` | `{text \| replace('a', 'b') \| upcase}` |
| `{{ price \| default: 0 }}` | `{price \| default(0)}` *(compiles to `withDefault(price, 0)` internally, since `default` is a reserved word in JS)* |

Parser note: split on top-level `|` only (respecting nested parens/strings),
so `{text | replace('|', ',')}` isn't broken by the literal `|` inside the
string argument.

---

## 8. Schema block

Identical structure to Liquid's `{% schema %}` JSON, just under a `<schema>`
tag instead of `{% schema %}...{% endschema %}`:

```jsx
<schema>
{
  "name": "Hero",
  "settings": [
    { "type": "text", "id": "heading", "label": "Heading", "default": "Welcome" },
    {
      "type": "select", "id": "layout", "label": "Layout",
      "options": [
        { "value": "left", "label": "Left" },
        { "value": "center", "label": "Center" }
      ]
    },
    { "type": "color", "id": "bg_color", "label": "Background", "default": "#fff" },
    { "type": "checkbox", "id": "show_badge", "label": "Show badge", "default": false }
  ],
  "blocks": [
    {
      "type": "text", "name": "Text",
      "settings": [{ "type": "richtext", "id": "text", "label": "Text" }]
    },
    {
      "type": "button", "name": "Button",
      "settings": [
        { "type": "text", "id": "label", "label": "Label" },
        { "type": "url", "id": "url", "label": "Link" }
      ]
    }
  ],
  "max_blocks": 8,
  "presets": [{ "name": "Hero", "category": "Image" }]
}
</schema>
```

---

## 9. Style block

Plain `<style>` tag, `{ }` interpolation allowed inside:

```jsx
<style>
  .hero--{section.id} { background: {settings.bg_color}; }
</style>
```

---

## 10. Full worked example

```jsx
---
const { settings, blocks } = section;
const featured = products.filter(p => p.tags.includes('featured'));
const heading = settings.heading | truncate(40) | upcase;
---

<section>
  <h2>{heading}</h2>

  {featured.map((product, i) => (
    <div class={i % 2 === 0 ? 'row-even' : 'row-odd'} key={product.id}>
      <img src={product.image | img_url('300x')} alt={product.title} />
      <span>{product.price | money}</span>
    </div>
  ))}

  {featured.length === 0 && <p>No featured products.</p>}

  {blocks.map(block => (
    block.type === 'text' ? (
      <p {...block.attrs}>{block.settings.text}</p>
    ) : block.type === 'button' ? (
      <a href={block.settings.url} {...block.attrs}>{block.settings.label}</a>
    ) : null
  ))}
</section>

<style>
  .hero--{section.id} { background: {settings.bg_color}; }
</style>

<schema>
{
  "name": "Hero",
  "settings": [
    { "type": "text", "id": "heading", "label": "Heading", "default": "Welcome" },
    { "type": "color", "id": "bg_color", "label": "Background", "default": "#fff" }
  ],
  "blocks": [
    { "type": "text", "name": "Text", "settings": [
      { "type": "richtext", "id": "text", "label": "Text" }
    ]},
    { "type": "button", "name": "Button", "settings": [
      { "type": "text", "id": "label", "label": "Label" },
      { "type": "url", "id": "url", "label": "Link" }
    ]}
  ],
  "max_blocks": 8,
  "presets": [{ "name": "Hero", "category": "Image" }]
}
</schema>
```

---

## 11. Full mapping reference (quick lookup)

| Liquid | Liqx |
|---|---|
| `{{ var }}` | `{var}` |
| `{{ var \| upcase }}` | `{var \| upcase}` |
| `{% if x %}...{% endif %}` | `{x && (...)}` |
| `{% if x %}...{% else %}...{% endif %}` | `{x ? (...) : (...)}` |
| `{% for i in list %}...{% endfor %}` | `{list.map(i => (...))}` |
| `{% case %}{% when %}` | nested ternary / `switch` in frontmatter |
| `{% assign x = 5 %}` | `const x = 5;` (frontmatter) |
| `{% capture x %}...{% endcapture %}` | `` const x = `...`; `` (frontmatter) |
| `{% increment %}` / `{% cycle %}` | loop index from `.map((x, i) => ...)` |
| `{% comment %}...{% endcomment %}` | `{/* ... */}` |
| `{% raw %}...{% endraw %}` | `{'literal text'}` |
| `{% render 'x' %}` | `import X from './x.jsx'` + `<X />` |
| `{% form %}...{% endform %}` | `<form {...formAttrs('x')}>` |
| `{% style %}...{% endstyle %}` | `<style>{ }</style>` |
| `{% schema %}...{% endschema %}` | `<schema>{ }</schema>` |
| filters (`\|`) | same `\|` syntax, compiles to `fn(value, args)` |

---

## 12. Design principles this spec settles on

1. **Markup stays HTML.** No custom elements standing in for logic or schema.
2. **`{ }` is real JS**, not a restricted filter grammar — full expressive power.
3. **Filters use pipe syntax (`|`)**, the one place Liqx keeps Liquid's own
   operator. Filters themselves are plain, value-first functions, importable
   and inspectable — no implicit global filter namespace.
4. **`|` is real parser-level sugar**, scoped only to `{ }` expressions,
   compiling to nested calls (`a | fn(args)` → `fn(a, args)`). Doesn't touch
   any other part of the syntax.
5. **Schema is data, not markup** — a plain JSON object, same shape as today's
   Liquid schema, just relocated under a `<schema>` tag.
6. **Frontmatter is optional** but is the natural home for `assign`/`capture`-
   style logic, keeping the render body focused on markup.