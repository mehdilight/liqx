# 9. Composition — `render()`, `section()`

Liqx has no `{% include %}` / `{% section %}` tags. Named partials are
pulled in with two built-in **globals** called from `{ }` expressions.

## `render("name", props)` — snippets

```liqx
<main>
  {render("card", { product: featured, size: "large" })}
</main>
```

* Resolves `name` through the host's snippet `FileSystem`.
* The passed object is the snippet's `props`. **Isolated** — parent
  variables do not leak in.

The snippet reads `props`:

```liqx
{/* snippets/card.liqx */}
---
const { product, size = 'medium' } = props;
---
<div class={size}>
  <h3>{product.title}</h3>
  <span>{product.price | money}</span>
</div>
```

`props` is also directly accessible without destructuring:
`<span>{props.label}</span>`.

## `section("name")` — sections

```liqx
{section("header")}
```

* Resolves through the host's section `FileSystem`.
* Renders with the **parent scope visible** (page-level `shop`, etc.), plus
  `section.name`:

```liqx
{/* sections/header.liqx */}
<h1>{shop.name}</h1><span>{section.name}</span>
```

* Hosts usually override the `section` global to inject the merchant's saved
  settings: `env.registerGlobal('section', fn ($name) => ...)`.

## Host extension points

| Point | Registered via | Called as |
|-------|----------------|-----------|
| Filter | `Environment::registerFilter($name, $fn)` | `x \| name(args)` — `$fn($x, ...$args)` |
| Global ("tag") | `Environment::registerGlobal($name, $fn)` | `{name(args)}` |
| Snippet FS | `Environment::setSnippetFileSystem(...)` | `render(...)` |
| Section FS | `Environment::setSectionFileSystem(...)` | `section(...)` |

A global whose first parameter is type-hinted `Context` receives the live
evaluation context automatically:

```php
$env->registerGlobal('site', fn (Context $ctx, string $k) => $ctx->lookup($k)['value']);
```

## Strict vs lenient rendering

```php
$template->render($data);                 // lenient: missing var → null → ''
$template->render($data, strict: true);   // missing var → UndefinedVariableException
```

* `?.` and `??` stay null-safe even in strict mode.
* Runtime errors carry `templateName` and `lineNumber` for dev overlays.

## Drops

Objects extending `Phpmystic\Liqx\Drop` (or just exposing
`beforeMethod(string): mixed`) answer arbitrary property lookups:

```liqx
<h3>{product.title}</h3>      {/* → $product->beforeMethod('title') */}
```

Resolution: `beforeMethod` → public property → `ArrayAccess` → `null`.
