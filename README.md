# phpfilemerger

A CLI tool that merges a PHP entry point and all of its class dependencies into
a single self-contained PHP file. It uses a proper AST parser
([nikic/php-parser](https://github.com/nikic/PHP-Parser)) rather than naive file
concatenation, producing a correctly ordered, dependency-aware output file.

Blog:
[tqdev.com/2026-merge-php-projects-single-file](https://www.tqdev.com/2026-merge-php-projects-single-file/)

## Use Case

If you maintain a PHP project that you want to distribute as a single file (e.g.
a single-file API, a standalone script, or a PHAR-like distributable), PHP File
Merger automates the process. It statically analyzes your code, resolves all
class dependencies via PSR-4/PSR-0 autoloading, sorts them in dependency order,
and writes a single merged `.php` file.

## Requirements

- PHP 8.1 or higher
- Composer (for building from source)

## Installation

### Download the PHAR (recommended)

Download the latest `phpfilemerger.phar` from the releases and run it directly:

```bash
php phpfilemerger.phar merge src/index.php
```

### Build from source

```bash
git clone https://github.com/yourname/phpfilemerger
cd phpfilemerger
composer install
./build.sh
```

This produces `phpfilemerger.phar` in the project root.

> **Note:** Building requires `phar.readonly = Off` in your `php.ini`. The build
> script will tell you if this needs to be changed.

### Run without building

```bash
composer install
php src/index.php merge src/index.php
```

## Usage

```
php phpfilemerger.phar merge <entry> [options]
```

`merge` is the default command and can be omitted:

```
php phpfilemerger.phar <entry> [options]
```

### Arguments

| Argument | Required | Description                      |
| -------- | -------- | -------------------------------- |
| `entry`  | Yes      | Path to the PHP entry point file |

### Options

| Option            | Short | Default                 | Description                                                                                              |
| ----------------- | ----- | ----------------------- | -------------------------------------------------------------------------------------------------------- |
| `--output`        | `-o`  | `<entry>.merged.php`    | Output file path                                                                                         |
| `--project-root`  |       | Auto-detected           | Project root (directory containing `composer.json`)                                                      |
| `--vendor-dir`    |       | `<project-root>/vendor` | Vendor directory path                                                                                    |
| `--exclude-entry` |       | `false`                 | Exclude the entry point's procedural code from output (output is named `<entry>.include.php` by default) |
| `--indent`        |       | `4`                     | Number of spaces used for indentation in the output                                                      |

The project root is auto-detected by walking up the directory tree from the
entry point looking for a `composer.json` file.

## Examples

Merge `src/index.php` and all its dependencies into a single file:

```bash
php phpfilemerger.phar merge src/index.php
# Output: src/index.merged.php
```

Specify a custom output path:

```bash
php phpfilemerger.phar merge src/index.php --output dist/app.php
```

Produce an includeable library file (no entry-point code, only class
definitions):

```bash
php phpfilemerger.phar merge src/index.php --exclude-entry --output dist/lib.php
```

## How It Works

1. **Parse**: The entry point is parsed using `nikic/php-parser`. All class
   references (`extends`, `implements`, trait `use`, `new`, type hints,
   attributes, etc.) are extracted.
2. **Resolve**: Each referenced class name is resolved to a file path using
   PSR-4/PSR-0 mappings read from `composer.json` and the Composer-generated
   autoload files in `vendor/composer/`.
3. **Build dependency graph**: The tool recursively processes dependencies,
   building a graph of all required files.
4. **Sort**: Files are sorted in topological order so that hard dependencies
   (`extends`, `implements`, trait `use`) are always placed before the classes
   that depend on them.
5. **Merge**: Each file is processed by the AST and its contents are emitted
   into the output file, wrapped in an explicit `namespace { ... }` block. The
   following transformations are applied automatically:
   - `declare(strict_types=1)` declarations are stripped (they are illegal inside
     the bracketed namespace blocks, so the merged file runs without strict typing)
   - `require vendor/autoload.php` statements are removed
   - Any `files` autoload entries from vendor packages (e.g. global helper
     functions) are inlined
   - Statically resolvable `require`/`include` calls whose path is built from
     `__DIR__` (e.g. `require __DIR__ . '/bootstrap80.php'`) are inlined: the
     target is emitted once as a closure in its own namespace and the call site
     is rewritten to invoke it, preserving conditionals, return values and
     `require_once` semantics
   - Files that early-exit with a namespace-scope `return` (common in polyfills)
     are wrapped in an immediately-invoked closure so the `return` does not abort
     the whole merged file
   - Each included file is annotated with a `// file: relative/path.php` comment
6. **Validate**: `php -l` is run on the output file to verify syntax.

### What the output looks like

```php
#!/usr/bin/env php
<?php

// Generated by PHP File Merger

// file: vendor/example/package/src/Helper.php
namespace Example\Package {
    function helperFunction() { ... }
}

// file: src/Model/Foo.php
namespace MyApp\Model {
    class Foo { ... }
}

// file: src/index.php
namespace {
    $app = new \MyApp\Model\Foo();
    $app->run();
}
```

## Limitations

- **Dynamic class loading**: `new $className()` and similar dynamic patterns
  cannot be statically analyzed and will not be automatically included.
- **Multiple namespace blocks per file**: files using more than one namespace
  block may not be handled correctly. A dynamically-required file that mixes
  namespaces is left as-is (its `require` is skipped with a warning) rather than
  inlined.
- **`include`/`require` statements**: calls whose path resolves statically from
  `__DIR__` are inlined. Anything dynamic (a variable path, a glob, a path that
  cannot be resolved at merge time) is skipped with a warning, since relative
  paths would break in the merged file.
- **Runtime `__DIR__` / `__FILE__` resource access**: only `__DIR__` used inside
  a `require`/`include` is handled. Code that uses `__DIR__`/`__FILE__` at
  runtime to reach **non-PHP resource files** (templates, data directories,
  `.json`/`.dist` assets) cannot be bundled into a single file — those resources
  are not code. Such paths resolve relative to the merged file and will fail at
  runtime. For example, Symfony Console's `completion` command scans its
  `Resources/` directory this way, so merging an application that registers it
  will fail when that command is constructed.
- **Top-level `exit`/`die`**: a dependency file that calls `exit`/`die` at
  namespace scope is skipped with a warning (it would abort the merged file). A
  top-level `return`, by contrast, is now contained by wrapping the file body in
  a closure.

## Development

```bash
# Run tests
vendor/bin/phpunit

# Build the PHAR
./build.sh
```

### What about smartinus44/phpfilemerger?

The project smartinus44/phpfilemerger is no longer published, and its exact
behavior is not documented, but this project aims to replace it. The code is not
a fork of smartinus44/phpfilemerger, but deduced from the code written for
[PHP‑CRUD‑API](https://github.com/mevdschee/php-crud-api), a project that
flattens multiple PHP source files into a single distributable API script.
