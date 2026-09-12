# Liqx

A JSX-style template engine for PHP with compiled rendering, reusable components, schema validation, and restricted JavaScript-style expressions.

Liqx combines HTML markup and `{expression}` interpolation with Liquid-inspired sections, snippets, filters, and schemas. It supports both interpreted rendering and compilation to cached PHP closures.

## Requirements

PHP 8.2 or newer with `mbstring`, and Composer 2.

## Installation

Until the package is submitted to Packagist, configure its Git repository in your project:

```bash
composer config repositories.liqx vcs https://github.com/mehdilight/liqx
composer require phpmystic/liqx:dev-main
```

After a tagged release is available on Packagist, install with `composer require phpmystic/liqx`.

## Quick start

```php
<?php
require 'vendor/autoload.php';

use Phpmystic\Liqx\Template;

$template = Template::parse('<h1>Hello, {name | escape}!</h1>');
echo $template->render(['name' => 'World']);
// <h1>Hello, World!</h1>
```

Parse once and render with different data. Use the `escape` filter for untrusted values inserted into HTML text; interpolation does not automatically HTML-escape output.

## Features

- HTML-style templates with expressions, filter pipes, conditional markup, and array mapping.
- Frontmatter declarations, destructuring, functions, and conditional statements.
- Reusable snippets, named templates, sections, and slots.
- Schema validation for template props.
- Custom filters and global functions through `Environment`.
- Optional compilation to native PHP with a disk cache.

```php
use Phpmystic\Liqx\Environment;
use Phpmystic\Liqx\Template;

$environment = Environment::create();
$environment->setCompiledTemplateDir(__DIR__ . '/var/cache/liqx');
$template = Template::parse('<p>{message | escape}</p>', $environment);
echo $template->render(['message' => 'Compiled with Liqx']);
```

The compiled cache directory must be writable by the application. Registered PHP filters and globals are host-provided capabilities; the expression language is a restricted subset, not a general JavaScript runtime.

## Documentation

Read the [syntax reference](docs/syntax/README.md) for document structure, expressions, frontmatter, filters, components, and schemas. Its examples are exercised by the test suite.

Editor integrations are available in the repository for [VS Code](https://github.com/mehdilight/liqx/tree/main/editors/vscode) and [PhpStorm](https://github.com/mehdilight/liqx/tree/main/editors/phpstorm).

## Development

```bash
git clone https://github.com/mehdilight/liqx.git
cd liqx
composer install
composer validate --strict
composer lint
composer test
composer analyse
```

CI runs syntax checks, tests, and static analysis on PHP 8.2–8.5. Include a minimal reproduction with bug reports and regression tests with fixes. Changes to the renderer should preserve interpreted and compiled behavior.

## Releases

Versions are derived from Git tags; `composer.json` deliberately omits a version field, following [Packagist's versioning guidance](https://packagist.org/about#managing-package-versions).

To publish the first release, confirm CI passes on `main`, create and push a semantic version tag, then submit `https://github.com/mehdilight/liqx` at [Packagist](https://packagist.org/packages/submit). Configure the GitHub integration to keep subsequent releases synchronized. Making the repository public does not automatically register the package on Packagist.

## License

Liqx is licensed under the [MIT License](LICENSE).
