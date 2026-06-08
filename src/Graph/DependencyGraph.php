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

    /** @var array<string, array<string>> Map of file path => array of dependency file paths (all refs, for discovery) */
    private array $dependencies = [];

    /** @var array<string, array<string>> Map of file path => array of hard dependency file paths (for ordering) */
    private array $hardDependencies = [];

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
        $this->hardDependencies = [];
        $this->visited = [];
        $this->processing = [];

        $this->processFile($entryPoint);
        $this->reportUnresolvedReferences();
    }

    /**
     * Warn about references that should have been bundled but were not.
     *
     * A class whose namespace belongs to a registered autoload prefix is one the
     * merger is responsible for. If such a class is referenced yet is neither
     * resolvable to a file nor declared by any file already in the graph, it will
     * be undefined at runtime in the merged output (e.g. a class dropped because
     * its short name collided with a built-in, or only reachable through a
     * reference the analyzer cannot follow). Surfacing it makes that fail loudly
     * at merge time instead of silently producing a broken file.
     *
     * Checking against the set of actually-declared types (not just name-to-file
     * resolution) avoids false positives for classes that share a file with
     * another class (PSR-4 maps a name to one file, but a file may declare more).
     */
    private function reportUnresolvedReferences(): void
    {
        $declared = [];
        foreach ($this->files as $phpFile) {
            foreach ($phpFile->getAllDeclaredTypes() as $name) {
                $declared['\\' . ltrim($name, '\\')] = true;
            }
        }

        $reported = [];
        foreach ($this->files as $phpFile) {
            foreach ($phpFile->getReferencedClassNames() as $className) {
                $normalized = '\\' . ltrim($className, '\\');
                if (isset($reported[$normalized]) || isset($declared[$normalized])) {
                    continue;
                }
                if (!$this->resolver->matchesKnownPrefix($className)) {
                    continue;
                }
                if ($this->resolver->resolve($className) !== null) {
                    continue;
                }

                $reported[$normalized] = true;
                error_log(
                    "Warning: referenced class $className matches a registered autoload prefix " .
                        "but is not defined by any merged file; it will be undefined in the merged output."
                );
            }
        }
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
            $this->hardDependencies[$filePath] = [];

            // Collect hard dependency class names for ordering
            $hardDepClasses = array_flip($phpFile->getHardDependencyClassNames());

            // Process each referenced class (all references for discovery)
            foreach ($phpFile->getReferencedClassNames() as $className) {
                $depFilePath = $this->resolver->resolve($className);

                if ($depFilePath !== null && $depFilePath !== $filePath) {
                    // Add dependency (all refs)
                    $this->dependencies[$filePath][] = $depFilePath;

                    // Track hard dependency if this class is extends/implements/use
                    if (isset($hardDepClasses[$className])) {
                        $this->hardDependencies[$filePath][] = $depFilePath;
                    }

                    // Recursively process dependency
                    $this->processFile($depFilePath);
                }
            }

            // Remove duplicates from dependencies
            $this->dependencies[$filePath] = array_unique($this->dependencies[$filePath]);
            $this->hardDependencies[$filePath] = array_unique($this->hardDependencies[$filePath]);
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
     * Uses only hard dependencies (extends/implements/use) for ordering.
     * Soft dependencies (type hints, new, etc.) are used only for file discovery.
     * 
     * @return array<string> Array of file paths in dependency order
     */
    public function getTopologicalSort(): array
    {
        $visited = [];
        $sorted = [];

        // DFS post-order using only hard dependencies
        // Back-edges (cycles) are simply ignored
        $visit = function (string $file) use (&$visit, &$visited, &$sorted): void {
            if (isset($visited[$file])) {
                return;
            }
            $visited[$file] = true;

            foreach ($this->hardDependencies[$file] ?? [] as $dep) {
                if (isset($this->files[$dep])) {
                    $visit($dep);
                }
            }

            $sorted[] = $file;
        };

        // Visit all files (sorted for determinism)
        $allFiles = array_keys($this->files);
        sort($allFiles);
        foreach ($allFiles as $file) {
            $visit($file);
        }

        return $sorted;
    }
}
