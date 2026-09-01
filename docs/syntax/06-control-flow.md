# Control flow

Liqx has two control-flow planes: **frontmatter statements** (program logic
that computes values) and **render-body elements** (markup selection).

## Frontmatter `if` / `else if` / `else`

```liqx
---
let status = 'guest';
if (user) {
  status = 'member';
} else if (count > 0) {
  status = 'buyer';
} else {
  status = 'anonymous';
}
---
<p>{status}</p>
```

Blocks may declare local `const`s:

```liqx
---
let finalPrice = product.price;
if (count > 2) {
  const discount = product.price * 0.2;
  finalPrice = product.price - discount;
}
---
<span>{finalPrice}</span>
```

Guard clauses return early and stop the whole render:

```liqx
---
if (!product || !count) {
  return;
}
const title = product.title;
---
<div class="product">{title}</div>
```

## Frontmatter `switch` / `case` / `default`

```liqx
---
let text = '#000000';
switch (layout) {
  case 'left':
    text = '#333333';
    break;
  case 'accent':
    text = '#b3283f';
    break;
  default:
    text = '#222222';
}
---
<div style={`color:${text}`}>{text}</div>
```

`case` values use loose equality against the discriminant. Execution stops at
the first matching `case`; a `break` is accepted but unnecessary (there is no
fall-through).

## Render-body `<if>` element

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

The condition attribute may be any of `condition`, `cond`, `when`, or `is`, or
a bare first-child expression attribute:

```liqx
<if {count > 0}>
  <p>{count} items</p>
</if>
```

`<else>` and `<elseif>` must be direct children of `<if>`.

## Render-body `<show>` element

Renders its fallback when the condition is falsy. Fallback forms: a
`<fallback>` child, a `<template slot="fallback">` child, or a `fallback={expr}`
attribute.

```liqx
<show when={show}>
  <p>{heading}</p>
  <fallback><p>Behind the scenes</p></fallback>
</show>
```

```liqx
<show when={count} fallback={'none'}>
  <p>You have {count} items</p>
</show>
```

## Render-body `<switch>` / `<match>` element

Dispatch on an optional `value`. With a value, each `match when="..."` is
compared loosely; without a value, each `match`'s condition is truthiness.

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

## Expression-level branching

The ternary `? :` works inside any expression:

```liqx
<p>{show ? <strong>{title}</strong> : <em>{title}</em>}</p>
```

And short-circuiting `&&` / `||` / `??`:

```liqx
<p>{show && 'visible'}</p>
<p>{user.name || 'guest'}</p>
<p>{user.nickname ?? 'anonymous'}</p>
```

## Arithmetic and comparison expressions

```liqx
<p>{count + 1} {count - 1} {count * 2} {count / 2}</p>
<p>{count % 2 === 0 ? 'even' : 'odd'}</p>
<p>{count >= 1 && count <= 10 ? 'in range' : 'out of range'}</p>
```

Truthiness mirrors JavaScript: `null`, `false`, `0`, `''`, and `'0'` are
falsy; everything else is truthy (including empty arrays).

```liqx
<p>{show ? 'on' : 'off'} {count ? 'counted' : 'zero'}</p>
```