# Liqx for VS Code

Syntax highlighting, snippets, and basic completion for `.liqx` templates.

## Features

- **Highlighting** (TextMate grammar, `text.html.liqx`):
  - `---` frontmatter fence → embedded JavaScript
  - `{ expression }` interpolation → embedded JavaScript (incl. JSX, nested `{ }`)
  - `{/* comments */}`
  - `<style>` → embedded CSS, `<script>` → embedded JS, `<schema>` → embedded JSON,
    each still highlighting `{ }` interpolation
  - `{ }` expression attributes inside HTML tags (`src={url}`, `{...attrs}`)
  - HTML via the built-in `text.html.basic`
- **Snippets** — `---`, `map`, `if`, `ternary`, `pipe`, `render`, `section`,
  `style`, `schema`, `schemaprops`, `arrowblock`, …
- **Completion**:
  - filter names after `|`
  - array / string methods after `.`
  - `const` / `let` / `return`, and `render` / `section` / `now` / `root` / `props`
    inside `{ }` or frontmatter

## Install (from source)

```sh
# symlink into your user extensions folder
ln -s "$(pwd)/editors/vscode" ~/.vscode/extensions/phpmystic.liqx-0.1.0
# then reload VS Code
```

Or run it live: open `editors/vscode/` in VS Code and press <kbd>F5</kbd>
("Run Extension").

## Package a `.vsix`

```sh
npm i -g @vscode/vsce
cd editors/vscode
vsce package
code --install-extension liqx-0.1.0.vsix
```

## Notes

- Highlighting needs no build step — it is pure JSON grammar + config.
- The completion registries in `extension.js` mirror `src/StandardFilters.php`
  and `src/Evaluator.php`; update them when those change.
- Leading blank lines before the opening `---` are not treated as frontmatter
  by the grammar (the fence must be the first line).
