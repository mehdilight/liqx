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

* Optional. Starts **only** if the very first non-blank line is exactly `---`.
* Ends at the next line whose trimmed content is exactly `---`.
* Contents = restricted JS declarations (`const` / `let`) plus an optional
  final `return <expr>;`. See [frontmatter.md](./06-frontmatter.md).
* Leading blank lines before the opening `---` are allowed.

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

Everything after the frontmatter (or from the top, if there is none). Free
mix of:

* plain text / HTML
* JSX elements — [elements-and-attributes.md](./03-elements-and-attributes.md)
* `{ expression }` interpolation — [expressions.md](./02-expressions.md)
* `{/* comments */}` — never rendered

## `<style>`, `<script>`, `<schema>`

Verbatim blocks — bodies are captured raw (CSS / JS / JSON), not parsed as
JSX. `{ }` interpolation still works selectively. See
[verbatim-blocks.md](./08-verbatim-blocks.md).

* `<style>` and `<script>` render inline where they appear; multiple are kept.
* `<schema>` is metadata only (never rendered). Used for `props` type
  validation and host config. One per document (last wins).
* Order is free — a `<schema>` at the end is fine.
