# Frontmatter: improvement checklist

Status key: `[ ]` pending · `[x]` done · `[~]` in progress

## 1. Better data access ergonomics
- [x] Null-safe navigation / missing-property coalescing (a `?.`-style operator or less-noisy `default()`) so frontmatter isn't `block.settings.x ?? 'Default'` on every line.
      → Added `?.` optional property access (`a?.b`, `a?.[i]`, `a?.b.c` short-circuit).
- [x] Encourage/validate dynamic (computed) property access on data in frontmatter.
      → Dynamic access `a[key]`, `a[0]`, `a['name']` works in frontmatter (a declared
      const key, numeric index, or literal string). Covered by tests in
      `RenderTest` + `CompiledTemplateTest` (parity).

## 2. Cross-request / shared (compile-time) values
- [x] Add a static / literal-only destructuring form that resolves once at **compile time** and is baked into the artifact (distinct from per-request derived values).
      → Static frontmatter `const`s (literal trees, non-interpolated template strings,
      numeric `+ - * / %`, `===`/`!==`, unary, and chains of previously-declared static
      consts) are folded once by the compiler and inlined as literals. Data/global/filter
      dependencies and parity-risky ops (loose `==`, `< >`, `&&/||/??`, truthiness `?:`)
      stay runtime.
- [x] Document compile-time constants vs. per-request derived values.
- [x] Verify compile-time constants deliver a real perf win (artifact reuse).
      → `bench.php` "compile-time const folding" section: a folding-heavy template
      renders at **~2.38x** (386k vs 167k ops/s) vs. a value-identical template whose
      consts depend on live data and must be recomputed each render. The folded
      artifact bakes constants in as literals (e.g. `$ctx->set('tax', 1.21)`) so no
      per-render arithmetic runs.

## 3. Sandbox completeness (tighten before widening)
- [x] Decide deliberate surface for collection methods beyond `.map/.filter/.find`
      (e.g. `.join`, `.slice`, `.concat`, `.includes`, `.some`, `.every`, `.reduce`),
      and either add them as first-class or add sanctioned array/string filters instead
      of opening up arbitrary JS methods.
      → Added `.some`/`.every` as first-class (runtime + sandbox `COLLECTION_CALLBACKS`).
      Other collection methods stay runtime-available (`join`, `slice`, `concat`,
      `includes`, `indexOf`, `length`); arbitrary JS method calls remain disallowed.
- [x] Make the nesting-depth error message actionable.
      → The compiler depth-limit error now suggests flattening/breaking into consts.
- [x] Ensure arbitrary property method calls on data are clearly policed in frontmatter.
      → Parse-time whitelist: only the curated collection/string methods (`map`,
      `filter`, `find`, `some`, `every`, `join`, `includes`, `concat`, `slice`,
      `indexOf`, `toUpperCase`, `toLowerCase`, `replace`, `replaceAll`, `trim`,
      `split`, `startsWith`, `endsWith`) are callable; arbitrary methods
      (`user.deleteAll()`) and computed calls (`x[fn]()`) are rejected, so
      frontmatter can never invoke an arbitrary method on host data.

## 4. Helpers / globals intentionally missing
- [x] String helpers as filters (`slugify`, `truncate`, `capitalize`, `replace`, `split`)
      shared by frontmatter and body.
      → All already existed (`truncate`, `truncatewords`, `capitalize`, `replace`,
      `remove`, `split`, `upcase`, `downcase`, `strip`, `append`, `prepend`) except
      `slugify`, which was added. Verified string filters work in frontmatter.
- [x] Date/`now()` / datetime-range helpers for computed flags and defaults.
      → Added a `now()` global returning the current Unix timestamp, usable in
      frontmatter for computed flags (`const isLive = now() >= start && now() <= end;`)
      and paired with the existing `date` filter (`now() | date('%Y')`). Covered by
      interpreter + compiled (parity) tests.
- [x] Unified locale/currency-aware `format()` filter (build on existing `money_*`).
      → `x | format('currency', 'USD', 'en_US')` (Intl `NumberFormatter`), plus
      `'number'`, `'percent'`, `'date'` modes — all locale-aware, with a
      `number_format` fallback when the `intl` extension is absent.

## 5. Frontmatter-specific ergonomics
- [x] `return` / default-export semantics: a final expression becomes a "template props"
      object, so the body reads a clean `props.xxx` instead of a flat namespace of consts.
      → A trailing `return { … };` exposes the value as `props` to the body (interpreter +
      compiled). Must be the last frontmatter statement; JSX/arbitrary arrows are rejected
      by the sandbox on the returned expression.
- [x] Let frontmatter reference/validate the `Schema` node's types for early, typed failures.
      → `SchemaValidator` type-checks the evaluated `props` against the `<schema>`
      block (`{ "props": { "count": "int", "tag": ["string","null"] } }`), throwing a
      typed `LiqxException` early during `render()`/`frontmatter()` instead of letting
      bad shapes mis-render silently. Basic scalar/array/object/union tokens.

## 6. Transparency / debuggability
- [x] Dev-only inspection of evaluated frontmatter consts (e.g. a `--`/comment node or debug endpoint).
      → New `Template::frontmatter($data, $strict)` returns the evaluated const-name →
      value map (plus `props` when there's a return) without rendering the body — a
      debug endpoint for hosts. Implemented via `Renderer::evaluateFrontmatter()`, the
      single source of truth shared with the render path.
- [x] `join`/`concat` string helper to avoid `+` overload surprises.
      → `concat` filter is now type-predictable: arrays merge, anything else
      concatenates as strings (`count | concat(' items')` → `'3 items'`), as an explicit
      alternative to the `+` operator (which switches between addition/concatenation by
      value type).

## 7. `this` / root-data reference
- [x] Decide a way to reference the whole render payload (e.g. `root.block`) to replace
      long dotted paths like `block.settings.` repeated everywhere.
      → Reserved `root` identifier resolves to the whole payload (the outermost scope),
      even inside nested section/partial scopes; works in body + frontmatter, interpreter +
      compiled, strict + lenient. Cannot be shadowed or re-declared (parse-time error).
      `this` was deliberately skipped (lexical-`this` binding complexity in arrow fns).

## Reference notes
- Frontmatter = sandboxed, restricted JS: `const`/`let` only (+ a final `return <expr>;`);
  no loops, `function`, `new`, assignment, classes/imports. Sandbox validator =
  `src/SandboxValidator.php`.
- `const { a, b = d } = c` destructuring is supported (`FrontmatterDestructure`).
- Values are dynamic per render (frontmatter runs at render time against live data); only
  the *structure* is compiled away. Static literal-tree consts are folded to literals at
  compile time in the compiled path.
- In compiled mode, invalid frontmatter fails fast at `parse()` time.
- `.map()/.filter()/.find()/.some()/.every()` arrow callbacks are the arrows allowed in
  frontmatter.
- Navigation is null-safe with `?.` (`a?.b`, `a?.[i]`); string filters usable in frontmatter
  (e.g. `| capitalize`, `| slugify`, `| split`, `| truncate`).
