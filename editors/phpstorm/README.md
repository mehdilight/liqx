# Liqx Plugin for PhpStorm & IntelliJ IDEA

Official PhpStorm and IntelliJ IDEA plugin for the [Liqx](https://github.com/mehdilight/liqx) template engine (`.liqx`).

---

## Features

- **Real-Time LSP Diagnostics:** Syntax errors reported as you type using `Template::parse()`.
- **IntelliSense & Autocompletion:**
  - Filters after `|` (`date`, `money`, `image_url`, `default`, etc.).
  - Methods and Drop properties after `.` (`product.title`, `order.total_price`, `.map()`, `.filter()`).
  - Globals and keywords inside `{` (`section`, `settings`, `routes`, `cart`, `props`).
- **Hover Documentation:** Markdown documentation on hover for filters, globals, and drops.
- **Syntax Highlighting:** TextMate grammar supporting JSX, CSS `<style>`, JSON `<schema>`, JS `<script>`, and frontmatter `---`.
- **Live Templates:** Quick snippets (`sfc`, `map`, `render`, `section`, `schema`).

---

## Requirements

- **IDE:** PhpStorm or IntelliJ IDEA 2023.2+ (with LSP API support).
- **Backend:** PHP 8.1+ with the Liqx LSP server CLI (e.g. `php bin/obelisk lsp` or `php bin/console obelisk:lsp`).

---

## Configuration

Go to **Settings | Languages & Frameworks | Liqx**:
- **PHP Executable Path:** Path to `php` binary (default: `php`).
- **LSP Command:** Command to launch the Language Server (default: `bin/obelisk lsp`).
- **Enable Liqx Language Server:** Toggle LSP features on/off.

---

## Building from Source

```bash
# Build the plugin zip distribution
./gradlew buildPlugin

# Run a sandboxed PhpStorm instance with the plugin installed
./gradlew runIde
```

The compiled plugin zip is placed in `build/distributions/liqx-intellij-*.zip` and can be installed via **Settings | Plugins | ⚙️ | Install Plugin from Disk...**.
