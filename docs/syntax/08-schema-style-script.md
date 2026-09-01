# Schema, style & script

## `<schema>` — typed props

A `<schema>` block at the end of a document declares the types of the
frontmatter `return { ... }` props. On every render the evaluated `props` are
checked against it — a mismatch throws a typed error before the body renders.

```liqx
---
return {
  title: title,
  count: count,
  tags: tags
};
---
<schema>
  { "props": { "title": "string", "count": "int", "tags": ["array", "null"] } }
</schema>
<h1>{props.title}</h1>
```

Supported scalar types: `string`, `int`, `number`, `float`, `bool`, `array`,
`object`, `null`. A type may be a union — a JSON array of tokens. Only props
that are *present* are validated; missing props are allowed:

```liqx
---
return { heading: heading, price: price };
---
<schema>
  {
    "props": {
      "heading": "string",
      "price": "number"
    }
  }
</schema>
<h1>{props.heading}</h1>
<p>{props.price}</p>
```

The schema block is captured verbatim and never rendered into the body.

## `<style>` — scoped CSS

`<style>` bodies are captured as raw CSS. `{ expression }` interpolation is
supported inside them:

```liqx
<style>
  .hero {
    color: {settings.heading};
    margin: {start}px {end}px;
  }
</style>
<div class="hero">{heading}</div>
```

A `{ ... }` inside the CSS that is *not* a valid expression (for example a
plain CSS block like `{ color: red; }`) is preserved literally:

```liqx
<style>
  .btn {
    color: red;
  }
  .btn:hover {
    color: {settings.heading};
  }
</style>
<button class="btn">{name}</button>
```

The last `<style>` in a document is also collected on the document node, so a
host can extract the scoped CSS. Styles render inline where they appear.

## `<script>` — verbatim JS with interpolation

`<script>` bodies are preserved and their `{ expression }` holes evaluate:

```liqx
<script>
  const initial = { JSON.stringify(props) };
  window.app = { count: {count} };
</script>
<p>{title}</p>
```

`<script>` may carry attributes:

```liqx
<script type="module" defer>
  import { init } from './app.js';
  init({ start: {start} });
</script>
<p>{title}</p>
```

The interpolation in the first example above renders nothing unless a
`JSON.stringify` global is provided by the host — the example is illustrative of
the *syntax*, and nothing fails if the function is missing.

## Verbatim blocks inside expressions

`<style>` and `<script>` are also valid *inside* an expression, where they
evaluate to their rendered markup:

```liqx
<div>
  {<script>const x = {count};</script>}
  {<style>.x { color: red; }</style>}
  <p class="x">{title}</p>
</div>
```

## Empty / optional blocks

A document may omit frontmatter, `<style>`, `<script>`, or `<schema>` entirely
— only the render body is required:

```liqx
<p>Just a body, no frontmatter or metas.</p>
```