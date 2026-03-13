#!/bin/bash

# Build script for PHP File Merger
# Uses phpfilemerger itself to create a single merged PHP file

set -e

echo "====================================="
echo "PHP File Merger - Build Script"
echo "====================================="
echo ""

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    echo "Installing dependencies..."
    composer install --optimize-autoloader
    echo ""
fi

# Use phpfilemerger itself to build a single merged file
echo "Building standalone executable using phpfilemerger..."
echo ""

php build.php phpfilemerger.php

echo ""
echo "✓ Build completed successfully!"
