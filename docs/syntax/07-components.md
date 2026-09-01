# Components & snippets

A PascalCase tag (first letter uppercase) is a **component**. Liqx resolves a
component from the document's own named `<template>` blocks first, then from
the host's snippet file system.

```liqx
<template name="Chip">
  <span class="chip">{props.label}</span>
</template>

<div>
  <Chip label="Sale" />
  <Chip label="New" />
</div>
```

The component receives its attributes as `props` — every named attribute
becomes a property:

```liqx
<template name="ProductCard">
  <article>
    <h3>{props.title}</h3>
    <p>{props.price | money}</p>
  </article>
</template>

<ProductCard title={product.title} price={product.price} />
```

## Props

Inside a component, `props` is the attribute map. In a frontmatter-returning
document, `props` is also the exported object:

```liqx
---
const meta = { label: name };
return { heading: heading, meta: meta };
---
<template name="Box">
  <section>
    <h2>{props.heading}</h2>
    <p>{props.meta.label}</p>
  </section>
</template>

<Box heading={props.heading} meta={props.meta} />
```

Bare attributes become `true`:

```liqx
<template name="Flag">
  <span data-flag={props.active}>x</span>
</template>
<Flag active />
```

`key` is never forwarded as a prop:

```liqx
<ProductCard key={product.id} title={product.title} />
```

## Children and default slot

A non-self-closing component receives its children as `props.children`.
Named `<template slot="...">` children become `props.slots[name]`.

```liqx
<template name="Card">
  <div class="card">
    <header>{props.slots.title}</header>
    <div>{props.children}</div>
  </div>
</template>

<Card>
  <template slot="title"><h2>{title}</h2></template>
  <p>{product.title}</p>
</Card>
```

## `<slot>` element

Inside a component, `<slot>` renders `props.children` (default) or
`props.slots[name]`, with fallback content:

```liqx
<template name="Card">
  <div class="card">
    <h2><slot name="title">Default title</slot></h2>
    <div><slot>Fallback body</slot></div>
  </div>
</template>

<Card>
  <template slot="title">Custom title</template>
  <p>Body content</p>
</Card>
```

## `render()` global

`render(name, props)` renders a snippet by name through the host file system.
It is the function form of `<Snippet />`.

```liqx
<p>{render("Chip", { props: { label: "sale" } })}</p>
```

```liqx
<div>
  {render("ProductCard", {
    props: { title: product.title, price: product.price }
  })}
</div>
```

Named local templates are also reachable by name:

```liqx
<template name="Badge">
  <b>{props.text}</b>
</template>
<p>{render("Badge", { props: { text: "NEW" } })}</p>
```

## `section()` global

`section(name)` renders a section through the section file system, giving it
the current scope:

```liqx
<p>{section("header")}</p>
```

The section receives a `section` object with at least `name`:

```liqx
---
const header = section.name;
---
<p>{header}</p>
```

## Components in expressions

A component used inside an expression also renders to its markup:

```liqx
<template name="Chip">
  <span>{props.label}</span>
</template>

<p>{show ? <Chip label="on" /> : <Chip label="off" />}</p>
```

## Resolution order

1. A named `<template name="X">` in the same document.
2. The host's snippet file system (the `render()`/PascalCase lookup).

Casing of the lookup is normalized — `ProductCard`, `product-card`, and
`product_card` can all resolve the same source (host filesystem naming
conventions vary; local templates match by case-insensitive name).