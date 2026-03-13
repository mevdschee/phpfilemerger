#!/bin/bash

# Build script for PHP File Merger
# Uses box to create a PHAR archive

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

# Check if box is available
if [ ! -f "vendor/bin/box" ]; then
    echo "Error: box is not installed."
    echo "Install it with: composer require --dev humbug/box"
    exit 1
fi

# Make sure phar.readonly is disabled
PHP_INI_READONLY=$(php -r "echo ini_get('phar.readonly');")
if [ "$PHP_INI_READONLY" = "1" ]; then
    echo "Error: phar.readonly must be disabled."
    echo "Run with: php -d phar.readonly=0 vendor/bin/box compile"
    exit 1
fi

# Build the PHAR
echo "Building phpfilemerger.phar using box..."
echo ""

box compile

echo ""
echo "✓ Build completed successfully!"
echo ""
echo "Output: phpfilemerger.phar"
echo ""
echo "Test with:"
echo "  php phpfilemerger.phar --version"
echo "  php phpfilemerger.phar --help"
