# Document structure

A Liqx `.liqx` file is one linear byte stream made of up to four regions, in
this order:

1. An **optional frontmatter block** between `---` fences at the very top —
   restricted JavaScript that computes values the body can use.
2. The **render body** — HTML/JSX plus `{ expression }` interpolations.
3. Optional **`<style>`**, **`<script>`**, and **`<schema>`** blocks, captured
   verbatim wherever they appear.
4. Optional **named `<template name="...">` blocks** — local, reusable
   snippets.

## Frontmatter fences

A document may start with a frontmatter block. The opening `---` must be at the
*very start* of the file (blank lines and leading whitespace are allowed before
it, but it must be the first non-whitespace content). A line whose trimmed
content is exactly `---` closes the block.

```liqx
---
const title = "Hello";
const count = 3;
---
<h1>{title}</h1>
<p>{count}</p>
```

The frontmatter block is a *sandboxed* subset of JavaScript — declarations,
control flow, and functions, but no loops, `new`, `class`, or `import`. See
[02-frontmatter.md](./02-frontmatter.md).

A final `return { ... };` in frontmatter becomes the document's `props`:

```liqx
---
const greeting = "Hello " + name;
return { greeting: greeting, user: user };
---
<p>{props.greeting} {props.user.name}</p>
```

## Render body

The body is real HTML with JSX-style element syntax. Plain text is emitted
literally, `<tag>` markers are parsed as elements, and `{ ... }` delimiters
introduce expressions:

```liqx
<header>
  <h1 class="title">{heading}</h1>
  <p class="subtitle">Welcome, {name}</p>
</header>
```

## Verbatim blocks: `<style>`, `<script>`, `<schema>`

The bodies of `<style>`, `<script>`, and `<schema>` tags are captured verbatim
(they are never parsed as JSX children). They may appear in the body, or inside
a `{ }` expression. Each still supports `{ expression }` interpolation.

```liqx
<style>
  .hero { color: {settings.heading}; }
</style>
```
<style>
  .hero { color: {settings.heading}; }
</style>

```liqx
<script>
  window.initial = { JSON.stringify(props) };
</script>
```
<script>
  window.initial = { JSON.stringify(props) };
</script>

The `<schema>` block holds JSON that declares the types of the frontmatter
`props`, validated on every render:

```liqx
<schema>
  { "props": { "title": "string", "count": "int" } }
</schema>
<h1>{title}</h1>
```
<schema>
  { "props": { "title": "string", "count": "int" } }
</schema>
<h1>{title}</h1>

See [08-schema-style-script.md](./08-schema-style-script.md).

## Named templates

A `<template name="...">` block defines a reusable snippet scoped to the
document. It is *not* rendered in place; instead it can be invoked elsewhere in
the same document via a PascalCase element, or through `render()`.

```liqx
<template name="Chip">
  <span class="chip">{props.label}</span>
</template>

<div>
  {render("Chip", { props: { label: "sale" } })}
</div>
```

See [07-components.md](./07-components.md).

## Whitespace and comments

Whitespace between elements and expressions is preserved as text. To discard
an expression entirely, use a JS block comment — `{/* ... */}` renders nothing:

```liqx
<div>
  {/* this whole line renders nothing */}
  <p>{title}</p>
</div>
```

Comments are also available inside JS expressions as `//` line comments and
`/* ... */` block comments:

```liqx
<p>
  {/*
     multi-line
     comment
  */}
  {title}
</p>
```
