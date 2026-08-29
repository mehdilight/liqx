# 3. Elements & attributes

Real HTML/JSX. Elements can appear in the body **or** inside a `{ }`
expression (`{show && <span>hi</span>}`).

```liqx
<section id="main" class="hero">
  <h1>{title}</h1>
  <img src={url} alt="banner" />
</section>
```

## Attribute forms

| Form | Meaning |
|------|---------|
| `name="literal"` | static string |
| `name={expr}` | expression value |
| `name` | boolean attribute → `true` |
| `{...expr}` | spread a map of attributes |
| `{expr}` | inject a raw attribute string |

```liqx
<a href="/x" {...attrs}>Go</a>
<div{section.lithos_attributes}>...</div>   {/* raw string injection */}
<input type="text" disabled />               {/* bare boolean attr */}
```

### Value coercion

| Expression value | Result |
|------------------|--------|
| `true` | bare attribute: ` name` |
| `false` / `null` | attribute omitted |
| array | space-joined (`class={['a', maybe && 'b']}`) |
| anything else | `name="<stringified>"` |

### `key`

`key={...}` is accepted (React-style list hint) and **stripped from
output** — it never appears in the HTML. Also skipped when spreading.

## Self-closing & void elements

```liqx
<img src="/a.jpg" />     →  <img src="/a.jpg" />      (void: stays self-closed)
<div class="x" />        →  <div class="x"></div>     (non-void: expanded)
<span />                 →  <span></span>
<input type="text">      →  <input type="text" />     (void: auto self-closes)
```

Void elements: `area base br col embed hr img input link meta param source
track wbr`.

## `<template>` Wrapper (SFC) & HTML5 Templates

Like Vue SFCs, a **top-level** `<template>...</template>` wraps the document body and is unwrapped during rendering (the outer tag is not emitted into the HTML).

Any **inner** `<template>` tags inside the body (e.g. `<template id="row-tpl">`) are preserved in the HTML output for client-side JavaScript / Web Components.

```liqx
<template>
  <div class="app">
    <!-- Inner HTML5 template: preserved in DOM for JS -->
    <template id="row-tpl">
      <tr><td>Placeholder</td></tr>
    </template>
  </div>
</template>
```
Renders:
```html
<div class="app">
  <template id="row-tpl">
    <tr><td>Placeholder</td></tr>
  </template>
</div>
```

Self-closing top-level `<template />` renders nothing (`""`).

## Elements as expression values

```liqx
{isActive && <span class="badge">Active</span>}

{layout === 'left'
  ? <aside>{menu}</aside>
  : <aside class="right">{menu}</aside>}

<ul>{items.map(i => <li key={i.id}>{i.name}</li>)}</ul>
```

Inside `{ }`, a `<` starts a JSX element only where an operand is expected;
after an operand it is the comparison operator (`{a < b}`).
