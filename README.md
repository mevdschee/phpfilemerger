# PHP File Merger

Intelligently merge PHP files and their dependencies into a single file by
analyzing actual dependencies using nikic/php-parser.

## Features

- **Smart dependency analysis**: Parses PHP AST to detect actual class usage
- **PSR-4 autoloading**: Automatically resolves class names to file paths via
  composer.json
- **Topological sorting**: Ensures correct load order (interfaces before
  classes, parents before children)
- **Namespace preservation**: Maintains all original namespaces using block
  syntax

## Installation

### Via PHAR (Recommended)

```bash
wget https://github.com/phpfilemerger/phpfilemerger/releases/latest/download/phpfilemerger.phar
chmod +x phpfilemerger.phar
sudo mv phpfilemerger.phar /usr/local/bin/phpfilemerger
```

### Via Composer

```bash
composer global require phpfilemerger/phpfilemerger
```

### From Source

```bash
git clone https://github.com/phpfilemerger/phpfilemerger.git
cd phpfilemerger
composer install
```

## Usage

```bash
# Merge a PHP entry point and its dependencies
phpfilemerger merge src/index.php

# Specify output file
phpfilemerger merge src/index.php --output=api.php

# Exclude entry point code (create .include.php)
phpfilemerger merge src/index.php --exclude-entry

# Show verbose output with dependency tree
phpfilemerger merge src/index.php -v
```

## How It Works

1. Parses the entry point file using nikic/php-parser
2. Extracts all class/interface/trait references from the AST
3. Resolves each reference to a file path using PSR-4 mappings from
   composer.json
4. Recursively processes all dependencies
5. Builds a dependency graph
6. Performs topological sort to determine correct include order
7. Merges all files into a single output file with proper namespace blocks

## Requirements

- PHP 8.1 or higher
- composer.json with PSR-4 autoload configuration

## License

MIT License
