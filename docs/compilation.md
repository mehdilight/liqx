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

## Benchmarks

`php -d opcache.enable_cli=1 bench.php`, 1000 iterations on a Linux box:

| Measure                            | Interpreter       | Compiled          | Ratio |
| ---------------------------------- | ----------------- | ----------------- | ----- |
| Render loop template (50 items)    | ~1.12 s           | ~0.35 s           | ~3.2x |
| Render complex section             | ~0.20 s           | ~0.06 s           | ~3.0x |
| Render verbatim (style/script)     | ~8 ms             | ~5 ms             | ~1.5x |
| Full request (parse + render)      | ~1.6 s            | ~0.47 s (warm)    | ~3.4x |

Verbose leaf-heavy templates gain the least (both paths are dominated by micro
milliseconds at that size). The end-to-end number is where the production win
lives: a warm-cache request skips parsing entirely and serves bytecode from
OPcache shared memory.