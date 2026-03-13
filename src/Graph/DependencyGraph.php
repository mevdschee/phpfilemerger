<?php

declare(strict_types=1);

namespace PhpFileMerger\Graph;

use PhpFileMerger\Model\PHPFile;
use PhpFileMerger\Parser\PhpFileParser;
use PhpFileMerger\Resolver\PSR4Resolver;
use RuntimeException;

/**
 * Builds a dependency graph of PHP files
 */
class DependencyGraph
{
    /** @var array<string, PHPFile> Map of file path => PHPFile */
    private array $files = [];

    /** @var array<string, array<string>> Map of file path => array of dependency file paths */
    private array $dependencies = [];

    /** @var array<string> Set of visited files to detect cycles */
    private array $visited = [];

    /** @var array<string> Set of files currently being processed (to detect cycles) */
    private array $processing = [];

    public function __construct(
        private readonly PhpFileParser $parser,
        private readonly PSR4Resolver $resolver,
    ) {}

    /**
     * Build the dependency graph starting from an entry point file
     * 
     * @param string $entryPoint Absolute path to entry point file
     * @return void
     * @throws RuntimeException
     */
    public function build(string $entryPoint): void
    {
        $this->files = [];
        $this->dependencies = [];
        $this->visited = [];
        $this->processing = [];

        $this->processFile($entryPoint);
    }

    /**
     * Recursively process a file and its dependencies
     * 
     * @param string $filePath Absolute path to file
     * @return void
     */
    private function processFile(string $filePath): void
    {
        // Normalize path
        $filePath = realpath($filePath);
        if ($filePath === false) {
            return;
        }

        // Skip if already visited
        if (isset($this->visited[$filePath])) {
            return;
        }

        // Check for circular dependency
        if (isset($this->processing[$filePath])) {
            // Circular dependency detected - this is actually OK in PHP
            // We'll handle it in topological sort
            return;
        }

        $this->processing[$filePath] = true;

        // Parse the file
        try {
            $phpFile = $this->parser->parseFile($filePath);
            $this->files[$filePath] = $phpFile;
            $this->dependencies[$filePath] = [];

            // Process each referenced class
            foreach ($phpFile->getReferencedClassNames() as $className) {
                $depFilePath = $this->resolver->resolve($className);

                if ($depFilePath !== null && $depFilePath !== $filePath) {
                    // Add dependency
                    $this->dependencies[$filePath][] = $depFilePath;

                    // Recursively process dependency
                    $this->processFile($depFilePath);
                }
            }

            // Remove duplicates from dependencies
            $this->dependencies[$filePath] = array_unique($this->dependencies[$filePath]);
        } catch (RuntimeException $e) {
            // Log warning but continue
            error_log("Warning: Failed to process file $filePath: " . $e->getMessage());
        }

        unset($this->processing[$filePath]);
        $this->visited[$filePath] = true;
    }

    /**
     * Get all files in the graph
     * 
     * @return array<string, PHPFile>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Get dependencies for a file
     * 
     * @param string $filePath
     * @return array<string>
     */
    public function getDependencies(string $filePath): array
    {
        return $this->dependencies[$filePath] ?? [];
    }

    /**
     * Get all dependencies (file path => array of dependency paths)
     * 
     * @return array<string, array<string>>
     */
    public function getAllDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Get topologically sorted list of files
     * 
     * @return array<string> Array of file paths in dependency order
     * @throws RuntimeException
     */
    public function getTopologicalSort(): array
    {
        $sorter = new TopologicalSorter($this->files, $this->dependencies);
        return $sorter->sort();
    }
}
