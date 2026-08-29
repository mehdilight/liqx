# 8. Verbatim blocks — `<style>`, `<script>`, `<schema>`

Their bodies are captured **raw** — CSS / JS / JSON, never parsed as JSX.
`<style>` and `<script>` still allow selective `{ }` interpolation.

## `<style>`

```liqx
<style>
  .hero--{section.id} {
    background: {settings.bg_color};
    padding: 10px;
  }
</style>
```

* Renders inline where it appears. Multiple `<style>` blocks are all kept
  and do not collapse.
* `{ expr }` interpolates. A `{ … }` that looks like a CSS/JS block (contains
  a top-level `:` or `;`) is left **literal**, so plain CSS rules survive:

```liqx
<style>.a { color: red; }</style>   {/* the { color: red; } is untouched */}
```

* Template literals inside interpolation work:

```liqx
<style>.a { --x: {cond && `--bg: url(${url});`}; }</style>
```

* `Template::document()->style?->body` exposes the last block's raw CSS for
  hosts that collect scoped styles.

## `<script>`

```liqx
<script src={asset_url} defer>
  window.routes = { cart: '{routes.cart_url}' };
  if (ready) { init(); }
</script>
```

* Attributes are evaluated (`src={...}`, `defer`).
* `{ expr }` interpolates; JS object literals and blocks
  (`{ cart: 'x' }`, `if (a) { b(); }`) stay literal via the same
  `:` / `;` heuristic.
* A `<script>` **inside a `{ }` expression** is treated as an ordinary
  element, not a verbatim block:

```liqx
{show && <div><script src={url} defer></script></div>}
```

## `<schema>`

```liqx
<schema>
{
  "name": "Hero",
  "max_blocks": 8,
  "props": { "heading": "string", "count": "int", "tag": ["string", "null"] }
}
</schema>
```

* Pure JSON metadata. **Never rendered.**
* `Template::schema()` returns the decoded array (host section config).
  Multiple `<schema>` blocks: the last one wins.
* If a `"props"` map is present, the frontmatter `return` value (`props`) is
  **type-checked** on every render — a mismatch throws a typed
  `LiqxException` early instead of mis-rendering.

### Schema prop types

Scalar token or a list-of-tokens union:

| Token | Matches |
|-------|---------|
| `string` | string |
| `int` | integer |
| `number` / `float` | int or float / float |
| `bool` | boolean |
| `array` / `object` | array |
| `null` | null |

```json
{ "props": { "count": "int", "tag": ["string", "null"] } }
```

Only props actually present are checked; missing/optional props pass.
