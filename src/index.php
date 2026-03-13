<?php

/**
 * PHP File Merger - Entry Point
 * 
 * Intelligently merge PHP files and dependencies into a single file
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpFileMerger\Application;

$application = new Application();
$application->run();
