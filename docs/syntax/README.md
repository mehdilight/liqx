# Liqx syntax reference

This is the complete syntax reference for the Liqx template engine — a
compiler-grounded, JSX-flavored template language that keeps Liquid's
section/schema/block model but swaps `{{ }}` / `{% %}` for real HTML plus
real JS-style `{ }` expressions, with a restricted, sandboxed frontmatter
subset.

## Quick orientation

| You want to…                     | Read |
| --------------------------------- | ---- |
| See how a `.liqx` document is laid out | [01-structure.md](./01-structure.md) |
| Compute values before rendering (the frontmatter sandbox) | [02-frontmatter.md](./02-frontmatter.md) |
| Write `{ }` expressions — operators, literals, methods, pipes | [03-expressions.md](./03-expressions.md) |
| Write HTML/JSX elements and attributes | [04-elements.md](./04-elements.md) |
| Transform values with filters | [05-filters.md](./05-filters.md) |
| Branch and switch markup | [06-control-flow.md](./06-control-flow.md) |
| Build components, snippets, and slots | [07-components.md](./07-components.md) |
| Typed props, `<style>`, and `<script>` | [08-schema-style-script.md](./08-schema-style-script.md) |
| Array/string methods, globals, and scope | [09-methods-globals.md](./09-methods-globals.md) |

## The shape of a document

```liqx
---
const title = "Hello";
const count = 3;
return { greeting: title };
---
<template name="Chip">
  <span class="chip">{props.label}</span>
</template>

<section class="hero">
  <h1>{props.greeting}</h1>
  <p>{count} items in the cart</p>
  {products.map((p) => <Chip label={p.title} />)}
</section>

<style>
  .hero { color: {settings.heading}; }
</style>
<schema>
  { "props": { "greeting": "string" } }
</schema>
```

The rendered output interpolates the expressions, mounts the components, and
draws the scoped CSS.

## Two planes of logic

- **Frontmatter** (between `---` fences at the top) is restricted JavaScript:
  declarations, destructuring, functions, `if`/`switch`, and a final `return`
  that becomes `props`. No loops, `new`, `class`, or arbitrary method calls.
- **The render body** is markup with `{ }` interpolation, filter pipes, and
  JSX elements — plus value-level branching embedded in the expressions.

## Syntax at a glance

| Snippet | Meaning |
| --- | --- |
| `{expression}` | interpolate a value |
| `{value \| filter(args)}` | pipe through filters |
| `{/* comment */}` | render nothing |
| `{cond ? a : b}` | ternary |
| `{a && b}` `{a \|\| b}` `{a ?? b}` | logical short-circuit |
| `{arr.map(fn)}` `{str.toUpperCase()}` | array / string methods |
| `` `...${expr}...` `` | template strings |
| `{...attrs}` | spread attributes |
| `class:name={cond}` | class modifier |
| `<if>/<elseif>/<else>` | conditional markup |
| `<show when={x}><fallback/></show>` | conditional with fallback |
| `<switch value={x}><match/></switch>` | dispatch markup |
| `<Component />` | render a snippet / named template |
| `<slot name="x" />` | compose children |
| `<style>/<script>/<schema>` | verbatim blocks with interpolation |

Every `.md` file in this folder doubles as a test fixture: `DocsSyntaxTest`
parses and renders every ```` ```liqx ```` block, so the reference cannot drift
from the engine.