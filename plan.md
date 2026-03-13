## Plan: PHP File Merger (PHAR Application)

Create a PHP application that intelligently combines a PHP script and its
dependencies into a single file by analyzing actual dependencies using
nikic/php-parser, parsing PSR-4 autoload mappings from composer.json, and
performing topological sorting to determine correct include order. The tool will
be compiled into a PHAR for easy distribution and execution.

**Why**: The existing [build.php](php-crud-api/build.php) uses a directory-based
approach that includes all files in fixed order. This new PHP tool will parse
PHP code using a proper AST parser to detect actual dependencies
(classes/interfaces used), build a dependency graph, and include only what's
needed in the correct order - making it more generic and intelligent. Using PHP
to parse PHP is more natural and allows leveraging mature parsing libraries.

**How**: Use nikic/php-parser to parse PHP files and extract namespace
declarations and class usage → parse composer.json for PSR-4 mappings → map
class names to file paths → build dependency graph → topological sort → merge
files with namespace wrapping → output single PHP file → compile tool to PHAR
for distribution.

---

### **Steps**

**Phase 1: Project Setup & Core Structure** (_parallel with Phase 2_)

1. Initialize Composer project `phpfilemerger` with dependencies:
   - `nikic/php-parser` (^5.0) - PHP AST parser
   - `symfony/console` (^6.0 or ^7.0) - CLI framework
   - `symfony/finder` - File system utilities
2. Define core classes: `PHPFile`, `DependencyGraph`, `PSR4Resolver`, `Config`,
   `FileMerger`
3. Set up PHPUnit testing framework with example PHP projects in
   `tests/fixtures/`
4. Create build script for compiling to PHAR using `box-project/box` or custom
   script

**Phase 2: PHP AST Parsing with nikic/php-parser** (_parallel with Phase 1_)

5. Implement `PhpFileParser` class using nikic/php-parser to extract:
   - Namespace declarations from AST (`Stmt\Namespace_` nodes)
   - Class/interface/trait/enum definitions (`Stmt\Class_`, `Stmt\Interface_`,
     `Stmt\Trait_`, `Stmt\Enum_` nodes)
   - `use` statements for imports (`Stmt\Use_` nodes)
   - Class references throughout the code:
     - Instantiation: `Expr\New_` nodes
     - Extends/implements: `extends` and `implements` properties
     - Type hints: `Param` nodes, return types, property types
     - Static calls: `Expr\StaticCall` nodes
     - `instanceof` checks: `Expr\Instanceof_` nodes
     - Catch blocks: `Stmt\Catch_` nodes
   - `require`, `require_once`, `include`, `include_once` statements
     (`Expr\Include_` nodes)
6. Create `parseFile(string $path): PHPFile` method that returns structured data
   with:
   - Declared namespace
   - Declared classes/interfaces/traits
   - Imported namespaces (use statements with aliases)
   - Referenced classes (fully resolved names)
7. Implement name resolution using nikic/php-parser's `NameResolver` visitor to
   handle:
   - Fully qualified names (`\Foo\Bar`)
   - Relative names resolved against current namespace
   - Aliases from `use` statements (`use Foo as Bar`)

**Phase 3: Composer.json Integration** (_parallel with Phase 2_)

8. Create `ComposerParser` class to extract from composer.json:
   - PSR-4 autoload mappings (`"Tqdev\\PhpCrudApi\\": "src/Tqdev/PhpCrudApi"`)
   - PSR-0 mappings (if needed for legacy vendor packages)
   - Required vendor packages from `require` section
9. Implement `PSR4Resolver` class with `resolve(string $fqcn): ?string` to map
   fully qualified class name → file path
10. Auto-detect vendor directory location and composer.json path from entry
    point by walking up directory tree

**Phase 4: Dependency Graph & Resolution** (_depends on 5-7, 8-10_)

11. Build `DependencyGraph` starting from entry point file:
    - Parse entry point file using PhpFileParser
    - Extract all class/interface/trait references
    - For each reference, use PSR4Resolver to get file path
    - Recursively parse those files and repeat
    - Track visited files in a Set to avoid cycles
    - Build directed graph: file A depends on file B if A uses classes from B
12. Implement topological sort using Kahn's algorithm or DFS:
    - Dependencies before dependents
    - Interfaces/traits before classes that use them
    - Parent classes before child classes
    - Detect strongly connected components for circular dependency reporting
13. Handle special cases:
    - PHP built-in classes (`DateTime`, `Exception`, etc.) - ignored using
      whitelist
    - Extension classes (PDO, etc.) - ignored
    - Missing vendor dependencies - collect and report with clear error messages
    - Circular dependencies - detect and report with cycle path

**Phase 5: File Merging & Output Generation** (_depends on 11-13_)

14. Create `FileMerger` class with
    `merge(array $sortedFiles, Config $config): string`:
    - Iterate through topologically sorted file list
    - For each file:
      - Parse using nikic/php-parser
      - Strip opening `<?php` tag
      - Remove vendor/autoload require statements
      - Remove `declare(strict_types=1)` if configured
      - Convert namespace declaration to block format if semicolon format
      - Wrap code in namespace block: `namespace Foo\Bar { ... }`
      - Add file path comment: `// file: src/Foo/Bar.php`
      - Indent all code within namespace (4 spaces default, configurable)
      - Pretty-print using nikic/php-parser's PrettyPrinter
    - Concatenate all processed files
15. Implement header generation for output file:
    - configurable template with placeholders
    - Include metadata: project name, license, dependencies, timestamp
16. Handle entry point file specially:
    - Optionally exclude from output (for .include.php variant)
    - Strip entry point code but keep dependencies

**Phase 6: CLI Interface with Symfony Console** (_depends on 14-16_)

17. Create console application using Symfony Console component:
    - `MergeCommand` class extending `Command`
    - Primary command: `phpfilemerger merge <entry-file>`
    - Options:
      - `--output, -o`: Output file path (default: auto-generated from entry
        file)
      - `--project-root`: Project root directory (default: auto-detect)
      - `--exclude-entry`: Exclude entry point code, create .include.php variant
      - `--indent`: Indentation spaces (default: 4)
      - `--vendor-dir`: Vendor directory (default: vendor/)
      - `--verbose, -v`: Show detailed dependency tree
      - `--dry-run`: Show what would be included without writing
18. Auto-detect project root by searching upward for composer.json from entry
    file
19. Add verbose output mode that displays:
    - Dependency graph visualization
    - Files included in merge order
    - Statistics: number of files, classes, lines of code
20. Validate output PHP syntax after generation:
    - Use `php -l` command programmatically
    - Report syntax errors if validation fails

**Phase 7: PHAR Compilation** (_depends on 17-20_)

21. Create PHAR build configuration:
    - Use `box-project/box` with `box.json` config OR
    - Custom build script using PHP's `Phar` class
22. Configure PHAR to:
    - Include all src/ files and vendor/ dependencies
    - Set stub file with shebang for CLI execution: `#!/usr/bin/env php`
    - Compress using gzip
    - Sign with SHA-256
23. Create build script/command:
    - `php build-phar.php` or `box compile`
    - Output: `phpfilemerger.phar`
24. Ensure PHAR can be executed: `./phpfilemerger.phar merge src/index.php`

**Phase 8: Testing & Validation** (_throughout, but comprehensive after step
24_)

25. Unit tests (PHPUnit) for each component:
    - `PhpFileParserTest`: Test AST parsing, name resolution
    - `PSR4ResolverTest`: Test class name → file path mapping
    - `DependencyGraphTest`: Test graph building, topological sort
    - `FileMergerTest`: Test file merging, namespace wrapping, indentation
    - `ComposerParserTest`: Test composer.json parsing
26. Integration test using php-crud-api project:
    - Run merger: `phpfilemerger.phar merge php-crud-api/src/index.php`
    - Verify output includes all necessary files
    - Compare structure with current [api.php](php-crud-api/api.php)
    - Syntax check: `php -l api.merged.php`
    - Run php-crud-api test suite with merged file
27. Edge case tests:
    - Files with no namespace (root namespace)
    - Missing dependencies
    - Circular dependencies
    - Traits, anonymous classes, enums (PHP 8.1+)
    - Files with multiple classes
    - Dynamic class loading (`$class = 'Foo'; new $class;`)
28. Performance benchmark:
    - Merge php-crud-api (200+ files) in < 2 seconds
    - Memory usage under 128MB

---

### **Relevant Files & Architecture**

**New PHP project structure**:

```
phpfilemerger/
├── bin/
│   └── phpfilemerger                # CLI entry script
├── src/
│   ├── Command/
│   │   └── MergeCommand.php         # Symfony Console command
│   ├── Parser/
│   │   ├── PhpFileParser.php        # PHP AST parser using nikic/php-parser
│   │   └── ComposerParser.php       # composer.json parser
│   ├── Resolver/
│   │   └── PSR4Resolver.php         # PSR-4 class name to file resolution
│   ├── Graph/
│   │   ├── DependencyGraph.php      # Dependency graph builder
│   │   └── TopologicalSorter.php    # Topological sort implementation
│   ├── Merger/
│   │   └── FileMerger.php           # File merging & output generation
│   ├── Model/
│   │   ├── PHPFile.php              # Value object for parsed PHP file
│   │   ├── ClassReference.php       # Value object for class reference
│   │   └── Config.php               # Configuration value object
│   └── Application.php              # Symfony Console Application wrapper
├── tests/
│   ├── Unit/
│   │   ├── Parser/
│   │   ├── Resolver/
│   │   ├── Graph/
│   │   └── Merger/
│   ├── Integration/
│   │   └── MergeCommandTest.php
│   └── fixtures/
│       ├── simple/                   # Simple test cases
│       └── php-crud-api/             # Real-world test (symlink or copy)
├── build/
│   └── build-phar.php                # PHAR compilation script
├── box.json                          # Box configuration (if using box-project/box)
├── composer.json                     # Dependencies: nikic/php-parser, symfony/console
├── composer.lock
├── phpunit.xml
└── README.md
```

**Dependencies in composer.json**:

```json
{
    "name": "phpfilemerger/phpfilemerger",
    "description": "Intelligently merge PHP files and dependencies into a single file",
    "type": "project",
    "require": {
        "php": "^8.1",
        "nikic/php-parser": "^5.0",
        "symfony/console": "^6.0 || ^7.0",
        "symfony/finder": "^6.0 || ^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0 || ^11.0",
        "humbug/box": "^4.0"
    },
    "autoload": {
        "psr-4": {
            "PhpFileMerger\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "PhpFileMerger\\Tests\\": "tests/"
        }
    },
    "bin": ["bin/phpfilemerger"]
}
```

**Reference implementation to study**:

- [php-crud-api/build.php](php-crud-api/build.php) - current merge logic,
  namespace wrapping, ignore patterns
- [php-crud-api/api.php](php-crud-api/api.php) - expected output format
- [php-crud-api/composer.json](php-crud-api/composer.json) - PSR-4 structure
  example
- [php-crud-api/src/](php-crud-api/src/) directory structure - namespace
  hierarchy

**Critical patterns to reuse from build.php**:

- Namespace block conversion: `namespace Foo;` → `namespace Foo {`
- File comment headers: `// file: src/Foo/Bar.php`
- 4-space indentation within namespace blocks
- Vendor autoload removal regex pattern
- Header template with license and attribution

**Key nikic/php-parser components to use**:

- `PhpParser\ParserFactory` - Create parser instance
- `PhpParser\NodeTraverser` - Traverse AST
- `PhpParser\NodeVisitor\NameResolver` - Resolve class names
- `PhpParser\PrettyPrinter\Standard` - Pretty-print modified AST
- AST node types:
  - `Stmt\Namespace_` - Namespace declarations
  - `Stmt\Class_`, `Stmt\Interface_`, `Stmt\Trait_`, `Stmt\Enum_` - Type
    definitions
  - `Stmt\Use_` - Import statements
  - `Expr\New_`, `Expr\StaticCall`, `Expr\Instanceof_` - Class usage

---

### **Verification**

1. **Syntax validation**: `php -l <output-file>` must pass without errors
2. **PHPStan/Psalm static analysis**: Run on generated output (level 5+) to
   verify type correctness
3. **Functional test**: Generate merged file from php-crud-api, replace existing
   [api.php](php-crud-api/api.php), run php-crud-api test suite
4. **Dependency completeness**: All classes referenced in entry point are
   resolved and included
5. **Order correctness**: Parse generated file and verify no class is used
   before its definition (interfaces before implementors, parent before child,
   traits before usage)
6. **Performance**: Merge php-crud-api (200+ files) in < 2 seconds
7. **PHAR functionality**: `phpfilemerger.phar merge src/index.php` produces
   identical output to non-PHAR version
8. **Edge cases validation**:
   - Files with no namespace (wrap in root namespace block)
   - Detect missing dependencies and report with clear, actionable error
     messages
   - Handle traits, anonymous classes, enums (PHP 8.1+), attributes (PHP 8.0+)
   - Files with multiple class definitions
   - Namespace aliases and relative name resolution
9. **Memory efficiency**: Process large projects (1000+ files) without exceeding
   256MB memory limit

---

### **Decisions**

- **Language choice: PHP**: Writing in PHP leverages mature parsing libraries
  (nikic/php-parser) and avoids the complexity of parsing PHP from another
  language. More natural for PHP developers to contribute.
- **nikic/php-parser**: Industry-standard PHP AST parser, battle-tested, handles
  all PHP 7.x-8.x features, provides proper name resolution out of the box
- **Dependency analysis**: Smart AST-based parsing of actual class usage rather
  than directory-based inclusion (more accurate, only includes what's needed)
- **CLI framework: Symfony Console**: Mature, feature-rich CLI framework with
  excellent argument/option parsing, validation, and output formatting
- **PHAR distribution**: Compile to PHAR for single-file distribution, easy
  installation (`wget` and run), no need for global composer install
- **PSR-4 mandatory**: Relies on composer.json for autoload mappings (standard
  modern PHP practice)
- **Topological sort**: Ensures correct load order automatically, no manual
  configuration needed
- **Namespace preservation**: All original namespaces maintained using block
  syntax `{ }` for isolation
- **box-project/box**: Use mature PHAR compiler with proven track record (used
  by PHPUnit, Composer, etc.)
- **PHP 8.1+ requirement**: Leverage modern PHP features (enums, readonly
  properties, named arguments) for cleaner codebase

---

### **Key Technical Challenges**

1. **AST traversal and name resolution**: Correctly using nikic/php-parser's
   visitor pattern to extract all class references, handling edge cases like:
   - Dynamic class instantiation (`new $className()`)
   - Variable method calls (`$obj->$method()`)
   - Anonymous classes with parent classes
   - Attribute references (PHP 8.0+)
2. **Dependency order computation**: Implementing robust topological sort that
   handles:
   - Interfaces must come before classes that implement them
   - Traits must come before classes that use them
   - Parent classes before child classes
   - Multiple inheritance hierarchies
   - Circular dependency detection with clear error reporting
3. **PSR-4 resolution accuracy**: Correctly mapping `Namespace\ClassName` → file
   path, handling:
   - Multiple PSR-4 prefixes (vendor packages + project code)
   - PSR-4 vs PSR-0 autoloading
   - Classmap autoloading as fallback
   - Case-sensitivity differences across filesystems
4. **Namespace block transformation**: Converting `namespace Foo;` declarations
   to `namespace Foo { }` while preserving formatting, comments, and code
   structure within
5. **Vendor dependency inclusion**: Determining transitive dependencies - if
   project uses ClassA which uses ClassB from vendor, both must be included
6. **PHAR compilation**: Ensuring PHAR includes all dependencies, handles
   autoloading correctly internally, and remains executable across different PHP
   installations
7. **Performance at scale**: Efficiently parsing and processing hundreds of
   files without excessive memory consumption (streaming where possible, caching
   parsed ASTs)
8. **Preserving code integrity**: Ensuring merged output is functionally
   identical to original:
   - Maintains all comments (except removed autoload statements)
   - Preserves string literals, heredocs, nowdocs
   - Handles files with side effects (global code execution)

---

### **Approach**

Build incrementally with working prototypes at each stage:

1. **Stage 1 (Days 1-2)**: Basic AST parsing + composer.json reading - prove we
   can extract class references
2. **Stage 2 (Days 3-4)**: PSR-4 resolution + dependency graph - prove we can
   map classes to files
3. **Stage 3 (Days 5-6)**: Topological sort + basic merging - prove we can order
   files correctly
4. **Stage 4 (Days 7-8)**: Namespace transformation + full merging - prove
   output is syntactically valid
5. **Stage 5 (Days 9-10)**: CLI interface + Symfony Console integration
6. **Stage 6 (Days 11-12)**: PHAR compilation + testing on php-crud-api
7. **Stage 7 (Days 13-14)**: Edge case handling + comprehensive testing

Test each component in isolation before integration. Use php-crud-api as
integration test target throughout development.
