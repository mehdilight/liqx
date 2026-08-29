# Compiled rendering

Liqx can lower a parsed template straight to a native PHP closure instead of
walking the AST at render time. The generated `.php` source is saved to a cache
directory so PHP's OPcache compiles it to bytecode once and serves subsequent
renders from shared memory.

## Enabling it

Compilation is **opt-in**. Every host that sets a compiled template directory
switches its renders to the compiled path:

```php
$env = Environment::create();
$env->setCompiledTemplateDir('/var/liqx/compiled'); // creates it if missing
```

From then on, `Template::parse(...)->render(...)` (and section/snippet renders
via `render`/`section` globals) use compiled closures.

## How it works

1. `src/Compiler.php` turns a `Document` AST into a PHP source string that
   returns a `static function (Context $ctx, Evaluator $eval): string`.
   Arrow parameters and block-body constants bind to real closure-local
   variables (with `use (...)`, capture analysis for nested closures), so the
   hot loop never pushes/pops `Context` scopes.
2. `src/CompiledTemplate.php` saves that source as a `*.php` artifact, loads it
   with `require`, and renders through the closure.
3. **Semantics never drift**: every value-level decision (filters, method
   dispatch, loose equality, truthiness, property lookup, `renderValue`) is
   delegated to the shared `Evaluator` helpers the interpreter uses. Only the
   AST-walking *structure* is compiled away. The two paths are kept in parity
   by `tests/CompiledTemplateTest.php`.

### What the emitter lowers

Beyond removing the AST walk, the compiler uses what it knows statically:

- Template literals become native `.` concatenation, with the literal segments
  baked in as PHP string literals.
- A literal-key property read emits the plain-array read inline, keeping
  `Evaluator::getProperty()` as the fallback for objects, Drops, `ArrayAccess`
  and strings. `length` and computed keys always use the helper.
- Filter pipelines become one direct `applyFilter()` call per filter. The
  callable is *not* resolved ahead of the value, because the interpreter
  evaluates the value first and PHP resolves a callee before its arguments —
  hoisting would change which error surfaces.
- Expressions statically known to be strings (template literals, elements,
  style/script nodes) skip `renderValue()` in output position and `attribute()`
  in attribute position.
- Frontmatter consts bind to PHP locals *and* publish to the `Context`: reads
  hit the local, while the name stays visible to schema validation and to
  nested `section()` renders.
- `{items.map(fn)}` in output position projects and concatenates in one pass
  instead of materialising the projected array. Non-array receivers, non-closure
  callbacks and any non-output use fall back to `methodCall()`.
- A static `const x = <literal tree>` is folded to a literal at compile time.
- The optional host wrapper emits its tag conditionally around a body that
  appears once, rather than duplicating the body per branch.

## Cache invalidation

Artifact filenames are content-addressed on the compiler fingerprint:

```
md5('template:' . Compiler::fingerprint() . ':' . $name . ':' . md5($source)) . '.php'
md5('partial:'  . Compiler::fingerprint() . ':' . $name . ':' . md5($source)) . '.php'
```

- Editing the `.liqx` source changes `md5($source)` → new filename → the next
  render parses, recompiles, and writes a fresh artifact. No manual clearing.
- `Compiler::fingerprint()` is a content hash of `src/Compiler.php` itself plus
  the human-bumped `Compiler::VERSION`. Because the artifact key depends on it,
  **editing the emitter automatically invalidates every cached template** — no
  need to remember to bump `VERSION`. `VERSION` remains for explicit wholesale
  recompiles.
- The template name is part of the key too: rendering the same source under a
  different name produces a separate artifact.

Stale artifacts from replaced sources are left on disk; call
`Environment::clearCompiledTemplates()` (or a deploy hook that empties the
directory) to purge them.

## Request lifecycle (compiled mode)

- `Template::parse` in compiled mode **fails fast**: it first checks for a
  cached artifact. On a **hit**, the source is never parsed again — the
  artifact is loaded straight from OPcache. On a **miss**, it parses eagerly so
  a syntax error surfaces at `parse()` time (carrying the template name), and
  the parsed tree feeds the compiler. The expensive codegen still runs only on
  the first render after a miss.

## API

- `Environment::setCompiledTemplateDir(string $dir)` — enable + point at a
  cache directory.
- `Environment::compiledTemplateDir(): ?string` — current directory or null.
- `Environment::clearCompiledTemplates(): void` — delete all artifacts.
- `Template::parse(...)` / `render(...)` / `renderIn(...)` / `renderContext(...)`
  — transparently compiled when a directory is set.
- `CompiledTemplate::fromSource(string $phpSource)` /
  `CompiledTemplate::cached(string $dir, string $key, string $name, \Closure $compile)`
  — lower-level entry points for hosts that manage artifacts themselves.

## Host configuration

Compiled artifacts are ordinary `.php` files, so the win depends on the host's
OPcache configuration. The compiler cannot set these; they belong in `php.ini`
or the pool config.

### OPcache is required, not optional

```ini
opcache.enable=1
opcache.validate_timestamps=1   ; 0 only if a deploy always restarts PHP
opcache.revalidate_freq=2
opcache.memory_consumption=128  ; artifacts are small; see the sizing note
opcache.max_accelerated_files=10000
```

Without OPcache, every render re-parses and re-compiles the artifact from
source. Measured on the complex-section artifact (3.9 KB), the cost of the
`require` that loads it:

| `opcache.enable` | per `require` |
| ---------------- | ------------- |
| on               | 0.11 us       |
| off              | 60.1 us       |

That is a ~530x difference on artifact loading alone, and it is paid on every
request, so an OPcache-less host can be slower in compiled mode than in
interpreter mode.

`validate_timestamps=1` is safe here: artifact filenames are content-addressed
(see *Cache invalidation*), so a changed template produces a new filename rather
than a modified file. The stat is the only cost, and with `revalidate_freq` set
it is amortised.

### JIT is worth enabling

The compiled path is straight-line PHP over concatenation and array reads, which
is what the tracing JIT handles best:

```ini
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

| Measure (1000 iterations)       | JIT off  | `jit=tracing` | Ratio |
| ------------------------------- | -------- | ------------- | ----- |
| Compiled render loop (50 items) | 88.7 ms  | 58.3 ms       | 1.52x |
| Compiled render complex section | 14.3 ms  | 10.9 ms       | 1.31x |
| Compiled warm-cache full request| 134.0 ms | 105.5 ms      | 1.27x |
| Interpreter render loop         | 337.8 ms | 192.5 ms      | 1.75x |

This is a configuration change only — no code change, and no effect on output.
The interpreter benefits too, so JIT is not a reason to choose one path over the
other.

### Sizing the OPcache

Artifacts are small: 1.7-4.8 KB of generated PHP for the templates in
`bench.php`. A theme with a few hundred templates therefore needs single-digit
megabytes of the OPcache budget, and the default `memory_consumption=128` is
ample. Watch `opcache_get_status()['memory_usage']['free_memory']` if a host
compiles thousands of distinct templates (e.g. per-tenant sources), because a
full OPcache silently stops caching new files.

### Not worth changing

- `pcre.jit` — measured no difference (within noise) at every bench size. Only
  the lexer uses a regex, and it is not on the compiled render path at all.
- `opcache.file_cache` — a second-level cache for artifacts that are already
  content-addressed on disk; it adds a stat without removing the compile.

The numbers above were measured on macOS with PHP 8.4 CLI and
`-d opcache.enable_cli=1`. Behaviour under PHP-FPM with a shared OPcache across
workers has not been measured; expect the OPcache and JIT effects to be at least
as large there, since the compile is amortised across many more requests.

## Benchmarks

`php -d opcache.enable_cli=1 bench.php`, 1000 iterations (macOS, PHP 8.4, JIT
off):

| Measure                            | Interpreter | Compiled      | Ratio |
| ---------------------------------- | ----------- | ------------- | ----- |
| Render loop template (50 items)    | 335 ms      | 89 ms         | 3.7x  |
| Render complex section             | 60 ms       | 14 ms         | 4.2x  |
| Render verbatim (style/script)     | 3.4 ms      | 1.6 ms        | 2.1x  |
| Full request (parse + render)      | 468 ms      | 132 ms (warm) | 3.6x  |

Verbose leaf-heavy templates gain the least (both paths are dominated by micro
milliseconds at that size). The end-to-end number is where the production win
lives: a warm-cache request skips parsing entirely and serves bytecode from
OPcache shared memory. Enabling `opcache.jit=tracing` multiplies the compiled
numbers by a further ~1.3-1.5x (see *Host configuration*).