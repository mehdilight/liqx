# 4. Control flow & lists

There are **no** `{% if %}` / `{% for %}` tags. Use JS expressions.

## First-Class Control Flow Components

Liqx provides built-in control flow components (`<If>`, `<Show>`, `<Switch>`) for clean, expressive branching without nested ternaries.

### 1. `<If>`, `<ElseIf>`, `<Else>`

```liqx
<If condition={status === 'delivered'}>
  <span class="badge badge--success">Delivered</span>
  <ElseIf condition={status === 'shipped'}>
    <span class="badge badge--info">In transit</span>
  </ElseIf>
  <ElseIf condition={status === 'confirmed'}>
    <span class="badge badge--primary">Confirmed</span>
  </ElseIf>
  <Else>
    <span class="badge badge--neutral">Pending</span>
  </Else>
</If>
```

* Attributes: `condition={expr}`, `cond={expr}`, or `when={expr}`.
* `<ElseIf>` and `<Else>` are evaluated in order; the first truthy branch renders its children.

### 2. `<Show when={...} fallback={...}>`

Ideal for binary conditional toggles with large blocks or fallback content:

```liqx
<Show when={customer.logged_in} fallback={<a href={routes.login_url}>Log in</a>}>
  <div class="account-badge">
    <span>Welcome, {customer.name}</span>
  </div>
</Show>
```

Fallback content can also be supplied via named slot `<template slot="fallback">`:

```liqx
<Show when={cart.count > 0}>
  <CartItems items={cart.items} />
  <template slot="fallback">
    <p class="empty-cart">Your cart is empty.</p>
  </template>
</Show>
```

### 3. `<Switch>` & `<Match>` (Pattern Matching)

#### Value-Based Matching:
```liqx
<Switch value={block.type}>
  <Match when="heading">
    <h2>{block.settings.text}</h2>
  </Match>
  <Match when="button">
    <a href={block.settings.url} class="btn">{block.settings.label}</a>
  </Match>
  <Default>
    <p>Unknown block</p>
  </Default>
</Switch>
```

#### Boolean Condition Matching:
```liqx
<Switch>
  <Match when={score >= 90}><b>Grade A</b></Match>
  <Match when={score >= 75}><b>Grade B</b></Match>
  <Match when={score >= 50}><b>Grade C</b></Match>
  <Default><b>Fail</b></Default>
</Switch>
```

## Expression-Level Conditionals (`&&`, `||`, `? :`)

For compact inline rendering inside `{ }`:

```liqx
{show && <p>Visible when truthy</p>}
{error || <p>No error</p>}
{count > 0
  ? <span>{count} left</span>
  : <span>Sold out</span>}
{user ? <a href={user.url}>{user.name}</a> : null}
```

* `a && b` → `b` if `a` is truthy, else `a`.
* `a || b` → `a` if truthy, else `b`.

## Truthiness

Falsy: `null`, `false`, `0`, `0.0`, `''`, `'0'`.
**Everything else is truthy — including `[]` (empty array) and `'false'`.**

Beware `{count && …}` when `count` can be `0`: `&&` yields the *left* operand,
so a falsy `0` renders as `0` rather than as nothing. Use an explicit
comparison when the value is numeric:

```liqx
{count > 0 && <span>{count} left</span>}
```

Guard on emptiness with `.length` or the `size` filter:

```liqx
{items.length === 0 && <p>Nothing here.</p>}
{cart.items | size}
```

## Lists — `.map()`

```liqx
<ul>
  {products.map(product => (
    <li key={product.id}>{product.title}</li>
  ))}
</ul>
```

Callback signature: `(element, index, wholeArray) => ...`

```liqx
{rows.map((row, i) => (
  <tr class={i % 2 === 0 ? 'even' : 'odd'}>{row.label}</tr>
))}
```

* Returning an array of elements is fine — they concatenate.
* `key={...}` is a hint and is stripped from output.
* `.map` on `null`/undefined yields an empty list (no error), so
  `{maybe.items.map(...)}` is safe.
* Also works on host collections implementing `Traversable`.
* In the compiled path a `.map` used directly in output position is fused into
  a single projecting-and-concatenating pass. Purely an optimization — the
  output is identical.

See [collection-methods.md](./07-collection-methods.md) for `.filter`,
`.find`, `.some`, `.every`, and block-body arrows.
