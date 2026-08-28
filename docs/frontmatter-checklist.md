# Frontmatter: improvement checklist

Status key: `[ ]` pending · `[x]` done · `[~]` in progress

## 1. Better data access ergonomics
- [x] Null-safe navigation / missing-property coalescing (a `?.`-style operator or less-noisy `default()`) so frontmatter isn't `block.settings.x ?? 'Default'` on every line.
      → Added `?.` optional property access (`a?.b`, `a?.[i]`, `a?.b.c` short-circuit).
- [ ] Encourage/validate dynamic (computed) property access on data in frontmatter.

## 2. Cross-request / shared (compile-time) values
- [x] Add a static / literal-only destructuring form that resolves once at **compile time** and is baked into the artifact (distinct from per-request derived values).
      → Static frontmatter `const`s (literal trees, non-interpolated template strings,
      numeric `+ - * / %`, `===`/`!==`, unary, and chains of previously-declared static
      consts) are folded once by the compiler and inlined as literals. Data/global/filter
      dependencies and parity-risky ops (loose `==`, `< >`, `&&/||/??`, truthiness `?:`)
      stay runtime.
- [x] Document compile-time constants vs. per-request derived values.
- [ ] Verify compile-time constants deliver a real perf win (artifact reuse).

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
- [ ] Ensure arbitrary property method calls on data are clearly policed in frontmatter.

## 4. Helpers / globals intentionally missing
- [x] String helpers as filters (`slugify`, `truncate`, `capitalize`, `replace`, `split`)
      shared by frontmatter and body.
      → All already existed (`truncate`, `truncatewords`, `capitalize`, `replace`,
      `remove`, `split`, `upcase`, `downcase`, `strip`, `append`, `prepend`) except
      `slugify`, which was added. Verified string filters work in frontmatter.
- [ ] Unified locale/currency-aware `format()` filter (build on existing `money_*`).
- [ ] Date/`now()` / datetime-range helpers for computed flags and defaults.

## 5. Frontmatter-specific ergonomics
- [x] `return` / default-export semantics: a final expression becomes a "template props"
      object, so the body reads a clean `props.xxx` instead of a flat namespace of consts.
      → A trailing `return { … };` exposes the value as `props` to the body (interpreter +
      compiled). Must be the last frontmatter statement; JSX/arbitrary arrows are rejected
      by the sandbox on the returned expression.
- [ ] Let frontmatter reference/validate the `Schema` node's types for early, typed failures.

## 6. Transparency / debuggability
- [ ] Dev-only inspection of evaluated frontmatter consts (e.g. a `--`/comment node or debug endpoint).
- [ ] `join`/`concat` string helper to avoid `+` overload surprises.

## 7. `this` / root-data reference
- [ ] Decide a way to reference the whole render payload (e.g. `root.block`) to replace
      long dotted paths like `block.settings.` repeated everywhere.

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
