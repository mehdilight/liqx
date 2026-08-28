# 4. Control flow & lists

There are **no** `{% if %}` / `{% for %}` tags. Use JS expressions.

## Conditional rendering

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
* Chain ternaries for multi-branch:

```liqx
{block.type === 'text'
  ? <p>{block.text}</p>
  : block.type === 'image'
    ? <img src={block.src} />
    : null}
```

## Truthiness

Falsy: `null`, `false`, `0`, `0.0`, `''`, `'0'`.
**Everything else is truthy — including `[]` (empty array) and `'false'`.**

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

See [collection-methods.md](./07-collection-methods.md) for `.filter`,
`.find`, `.some`, `.every`, and block-body arrows.
