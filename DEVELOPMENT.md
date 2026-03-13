# Development Status

## ✅ Completed Implementation

The PHP File Merger application has been fully implemented with all core
functionality:

### Core Components

1. **Model Layer** (`src/Model/`)
   - ✅ `PHPFile` - Represents parsed PHP files with dependencies
   - ✅ `ClassReference` - Represents class/interface/trait references
   - ✅ `Config` - Configuration for merge operations

2. **Parser Layer** (`src/Parser/`)
   - ✅ `ComposerParser` - Parses composer.json for PSR-4/PSR-0 mappings
   - ✅ `PhpFileParser` - Uses nikic/php-parser to extract dependencies from PHP
     files
   - ✅ `ClassReferenceVisitor` - AST visitor to collect all class references

3. **Resolver Layer** (`src/Resolver/`)
   - ✅ `PSR4Resolver` - Maps fully-qualified class names to file paths using
     PSR-4/PSR-0 rules
   - Handles vendor dependencies, built-in classes, and multiple autoload
     prefixes

4. **Graph Layer** (`src/Graph/`)
   - ✅ `DependencyGraph` - Builds dependency graph recursively
   - ✅ `TopologicalSorter` - Sorts files in correct load order with cycle
     detection

5. **Merger Layer** (`src/Merger/`)
   - ✅ `FileMerger` - Merges sorted files into single output
   - ✅ `CodeCleanerVisitor` - Removes autoload requires and strict_types
     declarations
   - Wraps code in namespace blocks with proper indentation

6. **CLI Layer** (`src/Command/`)
   - ✅ `MergeCommand` - Symfony Console command with full option support
   - ✅ `Application` - Main console application
   - ✅ `bin/phpfilemerger` - Executable CLI binary

## 📋 Next Steps

### 1. Install Dependencies

```bash
cd /home/maurits/projects/phpfilemerger
composer install
```

### 2. Test with php-crud-api

```bash
# Run the merger on php-crud-api
./bin/phpfilemerger php-crud-api/src/index.php -o test-output.php -v

# Check the output
php -l test-output.php

# Compare with original build.php output
diff test-output.php php-crud-api/api.php
```

### 3. Known Issues to Address

- **Namespace detection**: The current implementation assumes each file has a
  single namespace. Files with multiple namespaces or namespace blocks need
  special handling.
- **Global code**: Files with code outside of namespace blocks (e.g., side
  effects) may need special handling.
- **Dynamic class loading**: References like `new $className()` cannot be
  statically analyzed.
- **Path normalization**: Windows vs Unix path separators need consistent
  handling.

### 4. Potential Improvements

- Add `--ignore` option to exclude specific files/directories
- Add `--no-clean` option to keep autoload requires
- Generate dependency graph visualization (GraphViz DOT format)
- Add PHPUnit test suite
- Add progress bar for large projects
- Cache parsed ASTs for repeated runs
- Support for composer classmap autoloading
- Better error messages with file:line references

### 5. PHAR Compilation (Future)

Once tested and stable, compile to PHAR:

```bash
# Install box
composer require --dev humbug/box

# Create box.json configuration
# Run compilation
box compile
```

## 🧪 Testing Strategy

### Unit Tests

- Test PSR4Resolver with various namespace patterns
- Test PhpFileParser with edge cases (traits, enums, attributes)
- Test TopologicalSorter with circular dependencies
- Test FileMerger output format

### Integration Tests

- Test complete merge on php-crud-api project
- Test with Symfony/Laravel projects
- Test with projects using PSR-0, PSR-4, classmap

### Edge Cases

- Files with no namespace
- Files with multiple classes
- Circular dependencies (should be detected)
- Missing vendor dependencies
- Invalid PHP syntax in source files

## 📝 Usage Examples

```bash
# Basic usage - auto-detect everything
./bin/phpfilemerger src/index.php

# Specify output file
./bin/phpfilemerger src/index.php --output=merged.php

# Create include-only version (exclude entry point code)
./bin/phpfilemerger src/index.php --exclude-entry --output=merged.include.php

# Verbose output showing all files
./bin/phpfilemerger src/index.php -v

# Dry run to see what would be included
./bin/phpfilemerger src/index.php --dry-run

# Specify project root manually
./bin/phpfilemerger src/index.php --project-root=/path/to/project
```

## 🏗️ Architecture Overview

```
Entry Point (bin/phpfilemerger)
    ↓
Application (Symfony Console)
    ↓
MergeCommand
    ↓
    ├─→ ComposerParser → Load PSR-4 mappings
    │       ↓
    ├─→ PSR4Resolver → Map class names to files
    │       ↓
    ├─→ PhpFileParser → Parse PHP files with nikic/php-parser
    │       ↓
    ├─→ DependencyGraph → Build dependency tree
    │       ↓
    ├─→ TopologicalSorter → Sort files by dependencies
    │       ↓
    └─→ FileMerger → Generate merged output
            ↓
        Output File (merged.php)
```

## 🎯 Design Decisions

1. **PHP over Go**: Leverages nikic/php-parser for accurate PHP AST parsing
2. **Smart dependency analysis**: Only includes files that are actually
   referenced
3. **Topological sorting**: Ensures interfaces before classes, traits before
   usage
4. **Namespace preservation**: Uses block syntax to isolate code properly
5. **Symfony Console**: Provides rich CLI with validation and formatting
6. **Readonly properties**: Uses PHP 8.1+ features for immutable value objects
7. **Error handling**: Graceful degradation - warns but continues on parse
   errors

## ✨ Key Features

- ✅ Automatic PSR-4/PSR-0 resolution from composer.json
- ✅ Recursive dependency analysis using AST parsing
- ✅ Topological sorting with cycle detection
- ✅ Namespace block wrapping with indentation
- ✅ Removes vendor/autoload.php requires
- ✅ Removes declare(strict_types=1) declarations
- ✅ Syntax validation of output (php -l)
- ✅ Verbose mode showing dependency tree
- ✅ Auto-detection of project root
- ✅ Support for all PHP 8+ features (enums, attributes, etc.)
