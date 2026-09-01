# Elements

The render body is written with JSX-flavored HTML. Tags may be lowercase
(HTML), PascalCase (components), or one of the control-flow elements (`if`,
`show`, `switch`, `slot`, `template`).

## Tags and children

Plain elements with children, text, and interpolation:

```liqx
<div class="card">
  <h2>{product.title}</h2>
  <p>{product.price | money}</p>
</div>
```

Self-closing elements write `/>`:

```liqx
<img src={product.image} alt={product.title} />
<input type="text" value={name} />
<br />
```

HTML void elements (`img`, `input`, `br`, `hr`, `meta`, `link`, …) never emit
a closing tag, whether written self-closing or not:

```liqx
<img src={product.image} />
```

A self-closing non-void element emits `<tag></tag>` (JSX behavior):

```liqx
<my-widget />
```

## Attributes

### Literal strings

```liqx
<a href="/products" class="btn">Shop</a>
```

### Expression values

```liqx
<a href={product.url}>{product.title}</a>
```

### Bare attributes

A bare attribute is equivalent to `true` and renders as a standalone keyword:

```liqx
<button disabled>Go</button>
<details open>{title}</details>
```

### Boolean expression attributes

`true` → bare keyword, `false`/`null` → attribute dropped:

```liqx
<button disabled={!ready}>Go</button>
<option selected={layout === 'left'}>Left</option>
```

### Kept `key`

The `key` attribute is stripped from output — a hint for hosts, never rendered:

```liqx
<ul>{items.map((item) => <li key={item.id}>{item.name}</li>)}</ul>
```

### Dashed and colon-namespaced attributes

Attribute names may contain dashes and colons (`data-*`, `aria-*`, `xml:lang`,
`xlink:href`):

```liqx
<div data-id={product.id} aria-label={product.title} xml:lang="en">
  {product.title}
</div>
```

A `class:` prefix is reserved for class modifiers (see below):

### Spread attributes

`{...expr}` spreads a map of attributes (the key `key` is skipped):

```liqx
<div {...attrs}>content</div>
```

### Raw attribute-string injection

A non-spread `{expr}` attribute injects a raw string — Liquid's
`{{ section.lithos_attributes }}` pattern:

```liqx
<div {section.lithos_attributes}>{section.name}</div>
```

## `class:` modifiers

Svelte-style boolean class toggles. `class:name={cond}` adds `name` to the
class list when the condition is truthy; combined with a base `class`:

```liqx
<button class="btn" class:active={isActive}>Press</button>
```

Modifiers can be stacked and combined with an expression base class:

```liqx
<div class={`card ${layout}`} class:open={show} class:muted={!show}>
  {title}
</div>
```

## Control-flow elements

`if` / `elseif` / `else` render children conditionally:

```liqx
<if condition={show}>
  <p>{heading}</p>
  <elseif condition={count > 0}>
    <p>Count: {count}</p>
  </elseif>
  <else>
    <p>Nothing to show</p>
  </else>
</if>
```

The first child attribute (a bare expression) is also a valid condition:

```liqx
<if {show}>
  <p>{heading}</p>
  <else><p>fallback</p></else>
</if>
```

`show` renders a fallback when its condition is falsy — via a `fallback`
element, a `slot="fallback"` template, or a `fallback={expr}` attribute:

```liqx
<show when={show}>
  <p>{heading}</p>
  <fallback><p>Nothing here</p></fallback>
</show>
```

```liqx
<show when={items.length}>
  <ul>{items.map((i) => <li>{i.title}</li>)}</ul>
  <fallback><p>No items</p></fallback>
</show>
```

`switch` / `match` dispatch on a value; `match default` is the fallback. Without
a `value`, each `match`'s own condition is taken as truthy:

```liqx
<switch value={layout}>
  <match when="left"><div class="col-left">{title}</div></match>
  <match when="right"><div class="col-right">{title}</div></match>
  <match default><div class="col-full">{title}</div></match>
</switch>
```

```liqx
<switch>
  <match when={count === 0}>none</match>
  <match when={count < 3}>a few</match>
  <match default>many</match>
</switch>
```

## Text and escaping

Text is emitted as written. Use the `escape` filter to HTML-escape interpolated
values:

```liqx
<p>{user.name | escape}</p>
```

The `escape_once` filter escapes only what is not already escaped:

```liqx
<p>{name | escape_once}</p>
```

## Named templates

`<template name="...">` does not render in place; it registers a local snippet
invoked by a PascalCase element or `render()` (see
[07-components.md](./07-components.md)).

```liqx
<template name="Price">
  <span class="price">{props.value | money}</span>
</template>
<Price value={product.price} />
```

When used as a child of a component with a `slot` attribute, `<template>` names
a slot instead:

```liqx
<Card>
  <template slot="title"><h2>{title}</h2></template>
  <p>{product.title}</p>
</Card>
```