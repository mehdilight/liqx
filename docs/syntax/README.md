# Liqx syntax

Liqx is a JSX-flavored template language for PHP. It keeps Liquid's
section / snippet / schema model but replaces `{{ }}` / `{% %}` with **real
HTML** and **real `{ }` JS-style expressions**.

A `.liqx` document is:

```
--- frontmatter (restricted JS, optional) ---
render body: HTML + JSX + { expressions }
<style> / <script> / <schema>  (optional verbatim blocks)
```

## Syntax reference

| # | File | Covers |
|---|------|--------|
| 1 | [document-structure.md](./01-document-structure.md) | Frontmatter fences, body, block order |
| 2 | [expressions.md](./02-expressions.md) | `{ }`, literals, operators, ternary, template strings, member access, comments |
| 3 | [elements-and-attributes.md](./03-elements-and-attributes.md) | JSX elements, attributes, spread, raw injection, void/self-closing |
| 4 | [control-flow-and-lists.md](./04-control-flow-and-lists.md) | `&&` / ternary rendering, `.map` lists, keys, truthiness |
| 5 | [filters.md](./05-filters.md) | `|` pipeline + full standard filter list |
| 6 | [frontmatter.md](./06-frontmatter.md) | `const`/`let`, destructuring, `return`/`props`, sandbox rules, `root`, `now()` |
| 7 | [collection-methods.md](./07-collection-methods.md) | `.map/.filter/.find/.some/.every`, array/string methods, block-body arrows |
| 8 | [verbatim-blocks.md](./08-verbatim-blocks.md) | `<style>`, `<script>`, `<schema>` and interpolation rules |
| 9 | [composition.md](./09-composition.md) | `render()`, `section()`, props, scoping, strict mode, output wrapper |

Rendering is also available in a compiled mode that emits a native PHP closure
per template. It is a drop-in switch with identical output — see
[compilation.md](../compilation.md) for how to enable it and what the host must
configure.

## Full example

```liqx
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
    block.type === 'text'
      ? <p {...block.attrs}>{block.settings.text}</p>
      : block.type === 'button'
        ? <a href={block.settings.url} {...block.attrs}>{block.settings.label}</a>
        : null
  ))}
</section>

<style>
  .hero--{section.id} { background: {settings.bg_color}; }
</style>

<schema>
{ "name": "Hero", "props": { "heading": "string" } }
</schema>
```
