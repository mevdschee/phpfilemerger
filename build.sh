#!/bin/bash

# Build script for PHP File Merger
# Uses phpfilemerger itself to build a single-file distributable

set -e

echo "====================================="
echo "PHP File Merger - Build Script"
echo "====================================="
echo ""

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    echo "Installing dependencies..."
    composer install --no-dev --optimize-autoloader
    echo ""
fi

# Build the single merged file using phpfilemerger itself
echo "Building phpfilemerger.php using phpfilemerger..."
echo ""

php src/index.php merge src/index.php --output phpfilemerger.php

echo ""
echo "✓ Build completed successfully!"
echo ""
echo "Output: phpfilemerger.php"
echo ""
echo "Test with:"
echo "  php phpfilemerger.php --version"
echo "  php phpfilemerger.php --help"
