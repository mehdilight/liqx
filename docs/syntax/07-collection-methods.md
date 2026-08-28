# 7. Collection & string methods

JS-style methods on values, dispatched through a whitelist (not arbitrary
method calls). Available in the body and — for the sanctioned subset — in
frontmatter.

## Array methods

| Method | Notes |
|--------|-------|
| `.map(fn)` | `fn(element, index, array)` |
| `.filter(fn)` | keep where `fn(...)` is truthy |
| `.find(fn)` | first match, or `null` |
| `.some(fn)` | any match → bool (empty → `false`) |
| `.every(fn)` | all match → bool (empty → `true`) |
| `.join(sep='')` | → string |
| `.includes(x)` | strict membership → bool |
| `.indexOf(x)` | index or `-1` |
| `.concat(arr)` | merge arrays |
| `.slice(start, len?)` | sub-array |
| `.length` | count |

```liqx
{items.filter(i => i.active).map(i => <li>{i.name}</li>)}
{items.slice(0, 3).map(i => i.title)}
{tags.includes('sale') && <span>On sale</span>}
{items.some(i => i.inStock) ? 'available' : 'out'}
```

* On `null`/undefined: `.map/.filter/.find` → `[]`, `.some` → `false`,
  `.every` → `true`, others → `null`. So chains on maybe-missing data are safe.
* Works on host objects implementing `Traversable` / `Countable`
  (`.length` on a `Countable` avoids hydrating items).

## String methods

`.toUpperCase()` `.toLowerCase()` `.includes(s)` `.slice(start, len?)`
`.replace(a, b)` `.replaceAll(a, b)` `.trim()` `.split(sep)`
`.startsWith(s)` `.endsWith(s)` `.length`

```liqx
{product.title.toLowerCase().includes('watch') && <span>⌚</span>}
```

## Arrow callbacks

Short form:

```liqx
{items.map(item => <li>{item.name}</li>)}
{items.map((item, i) => <li class={i % 2 ? 'odd' : 'even'}>{item.name}</li>)}
```

### Block-body arrows

`{ … }` body with local `const`s and a trailing `return`:

```liqx
{items.map(p => {
  const title = p.title | upcase;
  const href  = p.url ?? '#';
  return <a href={href}>{title}</a>;
})}
```

* Declarations run in the arrow's own scope; outer scope is visible.
* No `return` → the arrow yields `null`.
* Destructuring works: `p => { const { title } = p; return <li>{title}</li>; }`
* Allowed in frontmatter as `.map/.filter/.find/.some/.every` callbacks:

```liqx
---
const featured = items.filter(p => {
  const t = p.title | downcase;
  return t.includes('watch');
});
---
```
