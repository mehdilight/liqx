# 9. Composition — Components, `render()`, `section()`

Liqx has no `{% include %}` / `{% section %}` tags. Named partials can be
rendered as **first-class JSX Components** (`<PascalCase>`) or via built-in
**globals** called from `{ }` expressions.

## First-Class Components (`<PascalCase>`) & Slots

Any JSX tag starting with an **uppercase letter** is automatically resolved as a
component snippet from the snippet `FileSystem` (e.g. `<Card>` resolves to `card`,
`<ProductCard>` resolves to `product-card`, `product_card`, or `ProductCard`).

```liqx
{/* Self-closing snippet with expression and boolean props */}
<Card product={featured} size="large" isFeatured />

{/* Component with spread attributes and default slot children */}
<Modal {...modalAttrs} title="Confirm Purchase">
  <p>Are you sure you want to buy <b>{featured.title}</b>?</p>
</Modal>
```

### Default slot (`props.children` / `<slot />`)

The component body is evaluated in the caller's scope and exposed to the snippet as
`props.children` or via a `<slot />` element:

`snippets/modal.liqx`:
```liqx
---
const { title } = props;
---
<div class="modal">
  <h2>{title}</h2>
  <div class="body">
    <slot />
  </div>
</div>
```

### Named slots (`<template slot="...">` / `<slot name="..." />`)

Named templates inside a component become `props.slots.<name>` or are rendered by
matching `<slot name="<name>" />`:

```liqx
<Card product={featured}>
  <template slot="header">
    <span class="badge">New</span>
  </template>

  <p>{featured.title}</p>

  <template slot="footer">
    <button>Buy Now</button>
  </template>
</Card>
```

`snippets/card.liqx`:
```liqx
<div class="card">
  <slot name="header" />
  <div class="content"><slot /></div>
  <slot name="footer" />
</div>
```

Slot elements support fallback content when no slot is passed:
`<slot name="header"><h3>Default Header</h3></slot>`.

## `render("name", props)` — functional snippets

```liqx
<main>
  {render("card", { product: featured, size: "large" })}
</main>
```

* Resolves `name` through the host's snippet `FileSystem`.
* The passed object is the snippet's `props`. **Isolated** — parent
  variables do not leak in.

The snippet reads `props` — `snippets/card.liqx`:

```liqx
---
const { product, size = 'medium' } = props;
---
<div class={size}>
  <h3>{product.title}</h3>
  <span>{product.price | money}</span>
</div>
```

Note the fence is the very first line: nothing may precede it, not even a
comment. See [document-structure.md](./01-document-structure.md#frontmatter).

`props` is also directly accessible without destructuring:
`<span>{props.label}</span>`.

## `section("name")` — sections

```liqx
{section("header")}
```

* Resolves through the host's section `FileSystem`.
* Renders with the **parent scope visible** (page-level `shop`, etc.), plus
  `section.name`:

```liqx
{/* sections/header.liqx */}
<h1>{shop.name}</h1><span>{section.name}</span>
```

* Hosts usually override the `section` global to inject the merchant's saved
  settings: `env.registerGlobal('section', fn ($name) => ...)`.

Scope note: a `section()` render inherits the parent scope, so it can read the
caller's frontmatter consts. A `render()` snippet cannot — it only sees the
`props` it was passed.

## Host extension points

| Point | Registered via | Called as |
|-------|----------------|-----------|
| Filter | `Environment::registerFilter($name, $fn)` | `x \| name(args)` — `$fn($x, ...$args)` |
| Global ("tag") | `Environment::registerGlobal($name, $fn)` | `{name(args)}` |
| Snippet FS | `Environment::setSnippetFileSystem(...)` | `render(...)` |
| Section FS | `Environment::setSectionFileSystem(...)` | `section(...)` |

A global whose first parameter is type-hinted `Context` receives the live
evaluation context automatically:

```php
$env->registerGlobal('site', fn (Context $ctx, string $k) => $ctx->lookup($k)['value']);
```

`Environment::capabilities()` returns the registered `filters` and `globals` by
name — useful for editor tooling and for asserting a host wired up what it meant
to.

### FileSystem lifetime

`Environment` caches each partial it parses (or compiles), keyed by the
FileSystem instance that resolved it. The cache is weakly held, so building a
FileSystem per render is fine: its entries are reclaimed with it. Two
FileSystems alive at once keep separate entries, so the same name may legitimately
resolve differently through each.

## Strict vs lenient rendering

```php
$template->render($data);                 // lenient: missing var → null → ''
$template->render($data, strict: true);   // missing var → UndefinedVariableException
```

* Strict mode is about **undefined variables only**. A missing *property* of a
  value that does exist is `null` in both modes — `{obj.nope}` never throws.
* `?.` and `??` stay null-safe even in strict mode, and they also suppress the
  undefined-variable error on their left side: `{nope?.x}` and
  `{nope ?? 'f'}` are safe, while `{nope.x}` throws.
* Runtime errors carry `templateName` and `lineNumber` for dev overlays.

## Wrapping the output

All three render entry points take an optional `$wrapper` that wraps the result
in one element, without touching the template source:

```php
$template->render($data, false, [ 'tag' => 'section' ]);
// <section><p>…</p></section>

$template->render($data, false, [ 'tag' => 'section', 'attrs' => [ 'class' => 's' ] ]);
// <section class="s"><p>…</p></section>

$template->render($data, false, [ 'tag' => 'div', 'attrs' => ' data-x="1"' ]);
// attrs may also be a pre-rendered string
```

`null`, `[]`, and `[ 'tag' => 'none' ]` all mean "no wrapper". Hosts use this to
attach section markup around a template they do not own.

## Drops

Objects extending `Phpmystic\Liqx\Drop` (or just exposing
`beforeMethod(string): mixed`) answer arbitrary property lookups:

```liqx
<h3>{product.title}</h3>      {/* → $product->beforeMethod('title') */}
```

Resolution: `beforeMethod` → public property → `ArrayAccess` →
`get_object_vars` → `null`. `beforeMethod` wins over a public property of the
same name.
