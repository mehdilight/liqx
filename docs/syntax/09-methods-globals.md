# Methods, globals & scope

## Array methods

Array values expose a curated set of methods. All may be used in frontmatter
(destruction aside) and in render-body expressions.

`map(fn)` — project each element; the callback receives
`(element, index, array)`:

```liqx
<p>{arr.map((n) => n * 2).join(',')}</p>
```

`filter(fn)` / `find(fn)` — keep or find matching elements:

```liqx
<p>{arr.filter((n) => n > 1).join(',')}</p>
<p>{items.find((i) => i.active).title}</p>
```

`some(fn)` / `every(fn)` — predicate checks:

```liqx
<p>{arr.some((n) => n > 2)}</p>
<p>{arr.every((n) => n > 0)}</p>
```

`join(sep)` / `includes(v)` / `concat(arr)` / `slice(from, to)` / `indexOf(v)`:

```liqx
<p>{tags.join(', ')}</p>
<p>{arr.includes(3)}</p>
<p>{arr.concat([4]).join('-')}</p>
<p>{arr.slice(0, 2).join('+')}</p>
<p>{arr.indexOf(2)}</p>
```

`length` — count:

```liqx
<p>{items.length} items</p>
```

Callbacks run in the evaluation context, so they can reference outer
variables:

```liqx
<p>{products.filter((p) => p.price > price).length}</p>
```

A `null`/absent array receiver is lenient: `map`/`filter`/`find` yield `[]`,
`some` yields `false`, `every` yields `true`, others yield `null`:

```liqx
<p>{missing?.map((x) => x)}</p>
```

## String methods

`toUpperCase` / `toLowerCase` / `trim` / `split(sep)` / `replace(from, to)` /
`replaceAll(from, to)` / `includes(sub)` / `slice(from, to)` /
`startsWith(prefix)` / `endsWith(suffix)` / `length`:

```liqx
<p>{name.toUpperCase()} {name.toLowerCase()}</p>
<p>{'  ' + name + '  ' | strip}</p>
<p>{greeting.split('-').join(' ')}</p>
<p>{name.replace('A', 'X')}</p>
<p>{name.replaceAll('a', 'o')}</p>
<p>{name.includes('d')}</p>
<p>{name.slice(0, 2)}</p>
<p>{name.startsWith('Ad')}</p>
<p>{name.endsWith('da')}</p>
<p>{name.length}</p>
```

`trim` is a method on strings; the `strip` filter is its pipeline equivalent.

## Member access & drops

Dot access on arrays/maps, computed brackets, and null-safe access:

```liqx
<p>{user.name} {user['url']} {user.profile.city}</p>
<p>{maybe?.items?.length ?? 0}</p>
```

`root` always names the whole render payload and cannot be shadowed:

```liqx
<p>{root.shop.name} / {shop.name}</p>
```

## Globals

The standard environment registers three globals (hosts may add more):

- `now` — current unix timestamp:

```liqx
<p>{now}</p>
```

- `render(name, props?)` — render a snippet by name:

```liqx
<p>{render('Chip', { props: { label: 'sale' } })}</p>
```

- `section(name)` — render a named section with the current scope:

```liqx
<p>{section('header')}</p>
```

Globals resolve in `name(...)` calls before filters. A local value (a
frontmatter const holding a function) takes precedence:

```liqx
---
const now = () => 'custom';
---
<p>{now()}</p>
```

## `props`

`props` is available inside a component (the attribute map), and in any
document whose frontmatter ends with `return { ... };` (the exported object):

```liqx
---
return { heading: heading };
---
<template name="Heading">
  <h1>{props.heading}</h1>
</template>
<Heading heading={props.heading} />
```

## Strict mode

Rendering with `strict` enabled makes references to undefined variables throw
instead of evaluating to `null` (`? .` / `??` stay lenient for their object
side). The docs' snippets run lenient, which is why unknown names resolve to
empty output.