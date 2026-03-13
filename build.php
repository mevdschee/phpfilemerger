<?php

declare(strict_types=1);

/**
 * Build script to create a self-contained single-file phpfilemerger executable
 * 
 * This uses phpfilemerger itself to merge all its dependencies into one file!
 * 
 * Usage: php build/build-standalone.php
 */

$projectRoot = __DIR__;
$srcDir = $projectRoot . '/src';
$entryPoint = $srcDir . '/index.php';
$outputFile = $projectRoot . '/' . ($argv[1] ?? 'phpfilemerger.php');

echo "Building standalone phpfilemerger executable...\n";
echo "Project root: $projectRoot\n";
echo "Entry point: $entryPoint\n";
echo "Output: $outputFile\n\n";

// Make sure we have dependencies installed
if (!file_exists($projectRoot . '/vendor/autoload.php')) {
    echo "Error: Composer dependencies not installed. Run 'composer install' first.\n";
    exit(1);
}

require $projectRoot . '/vendor/autoload.php';

use PhpFileMerger\Graph\DependencyGraph;
use PhpFileMerger\Merger\FileMerger;
use PhpFileMerger\Model\Config;
use PhpFileMerger\Parser\ComposerParser;
use PhpFileMerger\Parser\PhpFileParser;
use PhpFileMerger\Resolver\PSR4Resolver;

try {
    // Build config
    $config = new Config(
        projectRoot: $projectRoot,
        entryPoint: $entryPoint,
        outputPath: $outputFile,
        excludeEntryPoint: false,
        indentSpaces: 4,
        vendorDir: null,
    );

    // Initialize components
    echo "Initializing components...\n";
    $composerParser = new ComposerParser($projectRoot);
    $resolver = new PSR4Resolver($projectRoot, $composerParser);
    $parser = new PhpFileParser();

    // Build dependency graph
    echo "Analyzing dependencies...\n";
    $graph = new DependencyGraph($parser, $resolver);
    $graph->build($entryPoint);

    $files = $graph->getFiles();
    echo "Found " . count($files) . " files\n\n";

    // Get topological sort
    echo "Sorting files by dependencies...\n";
    $sortedFiles = $graph->getTopologicalSort();

    // Merge files
    echo "Merging files into single executable...\n";
    $merger = new FileMerger();
    $mergedCode = $merger->merge($sortedFiles, $files, $config);

    // Write output
    $written = file_put_contents($outputFile, $mergedCode);
    if ($written === false) {
        echo "Error: Failed to write output file: $outputFile\n";
        exit(1);
    }

    // Validate syntax
    echo "Validating syntax...\n";
    exec("php -l " . escapeshellarg($outputFile) . " 2>&1", $output, $returnCode);

    if ($returnCode !== 0) {
        echo "Error: Syntax validation failed:\n";
        echo implode("\n", $output) . "\n";
        exit(1);
    }

    $fileSize = filesize($outputFile);
    $fileSizeFormatted = number_format($fileSize / 1024, 2) . ' KB';

    echo "\n✓ Successfully merged " . count($sortedFiles) . " files into $outputFile ($fileSizeFormatted)\n";
    echo "\nTest with:\n";
    echo "  php $outputFile --version\n";
    echo "  php $outputFile --help\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
