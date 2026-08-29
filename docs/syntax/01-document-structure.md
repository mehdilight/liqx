# 1. Document structure

```liqx
---
const heading = section.settings.heading | upcase;
---
<section>
  <h1>{heading}</h1>
</section>

<style>.hero { color: red; }</style>
<schema>{ "name": "Hero" }</schema>
```

## Frontmatter

* Optional. Starts **only** if the very first line of the document is `---`
  (surrounding spaces on that line are fine).
* Ends at the next line whose trimmed content is exactly `---`.
* Contents = restricted JS declarations (`const` / `let`) plus an optional
  final `return <expr>;`. See [frontmatter.md](./06-frontmatter.md).

> **Nothing may precede the fence** — not a blank line, not a comment. The
> fence is only recognised at offset 0, so anything before it silently turns
> the whole frontmatter into body text (you get literal `---` and `const …` in
> your HTML, and every name it declared renders empty). This fails quietly
> rather than raising an error, so it is worth knowing.
>
> ```liqx
> {/* ✗ this comment disables the frontmatter below */}
> ---
> const x = 5;
> ---
> ```

```liqx
---
const x = 5;
const { title, size = 'M' } = props;
return { title: title };
---
```

Without frontmatter, just start with the body:

```liqx
<p>{name}</p>
```

## Body

The render body can be wrapped in `<template>...</template>` (which is omitted from the final rendered HTML output), or written directly:

* plain text / HTML
* JSX elements — [elements-and-attributes.md](./03-elements-and-attributes.md)
* `{ expression }` interpolation — [expressions.md](./02-expressions.md)
* `{/* comments */}` — never rendered

```liqx
---
const heading = section.settings.heading;
---
<template>
  <section>
    <h1>{heading}</h1>
  </section>
</template>

<schema>
{ "name": "Hero" }
</schema>
```

## `<style>`, `<script>`, `<schema>`

Verbatim blocks — bodies are captured raw (CSS / JS / JSON), not parsed as
JSX. `{ }` interpolation still works selectively. See
[verbatim-blocks.md](./08-verbatim-blocks.md).

* `<style>` and `<script>` render inline where they appear; multiple are kept.
* `<schema>` is metadata only (never rendered). Used for `props` type
  validation and host config. One per document (last wins).
* Order is free — a `<schema>` at the end is fine.
