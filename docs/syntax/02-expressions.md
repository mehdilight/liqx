# 2. Expressions

Anything inside `{ }` in the body (or an attribute value) is a real
JS-style expression.

```liqx
<p>{name}</p>
<p>{user.profile.city}</p>
<p>{price * quantity | money}</p>
<p>{count > 0 ? 'in stock' : 'sold out'}</p>
```

## Literals

| Kind | Examples |
|------|----------|
| String | `'hi'`, `"hi"` — escapes: `\n \t \r \\ \' \" \`` |
| Number | `42`, `3.14` |
| Boolean | `true`, `false` |
| Nullish | `null`, `undefined` (both evaluate to null) |
| Array | `[1, 2, 3]`, `[a, b]` |
| Object | `{ key: value, "quoted-key": x, class: 'y' }` |
| Template string | `` `Hi ${name}, ${count} items` `` |

Object keys may be identifiers, strings, or reserved words (`{ class: 'x' }`).
No shorthand (`{ x }` → syntax error) and no computed keys (`{ [k]: v }` →
syntax error).

## Operators

| Group | Operators |
|-------|-----------|
| Arithmetic | `+` `-` `*` `/` `%` |
| Comparison | `===` `!==` `==` `!=` `<` `>` `<=` `>=` |
| Logical | `&&` `\|\|` `??` |
| Unary | `!` `-` `+` |
| Ternary | `test ? a : b` |

* `+` concatenates if **either** side is a string, otherwise adds numbers.
* `-` `*` `/` `%` always coerce to number.
* `==` is loose (`1 == '1'`), `===` is strict.
* `&&` / `||` return the operand (not a bool): `show && <p>x</p>`.
* `??` returns the right side only when the left is `null`/undefined — and it
  suppresses "undefined variable" errors on the left in strict mode.

### Precedence (loosest → tightest)

```
pipe  |
ternary  ? :
||
??
&&
equality  == != === !==
relational  < > <= >=
+ -
* / %
unary  ! - +
member / call / index   a.b   a(b)   a[b]
```

Parenthesize to be explicit: `{(a || b) && c}`.

## Member access

```liqx
{obj.prop}            {/* dot access */}
{obj['prop']}         {/* computed / dynamic key */}
{arr[0]}              {/* index */}
{obj?.prop}           {/* null-safe: null if obj is null/undefined */}
{obj?.['prop']}       {/* null-safe computed */}
{a?.b.c}              {/* short-circuits whole chain if a is nullish */}
{arr.length}          {/* length on arrays and strings */}
{list.map(x => x.id)} {/* method call — see collection-methods.md */}
```

Property resolution order on objects: `beforeMethod()` (Drops) → public
property → `ArrayAccess` → `get_object_vars`. A missing property is always
`null` — including in strict mode. Only an **undefined variable** throws; see
[composition.md](./09-composition.md#strict-vs-lenient-rendering).

## Template strings

```liqx
{`${greeting}, ${user.name}! You have ${cart.items.length} items.`}
```

Backtick-delimited, `${ }` holds a full expression.

## Comments

```liqx
<p>a{/* this vanishes */}b</p>   →  <p>ab</p>
```

`//` line and `/* */` block comments also work **inside** an expression.

## Output rules

When an expression value is rendered to markup:

| Value | Output |
|-------|--------|
| `null`, `false` | `` (empty) |
| `true` | `true` |
| array | each item rendered and concatenated (recursively) |
| object with `__toString` | the string |
| object without `__toString` | `` (empty) — including a bare `Traversable`; call `.map(…)` to render its items |
| JSX element | its rendered HTML |
