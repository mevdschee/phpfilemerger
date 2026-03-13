<?php

declare(strict_types=1);

namespace PhpFileMerger\Graph;

use PhpFileMerger\Model\PHPFile;
use RuntimeException;

/**
 * Performs topological sort on dependency graph using DFS
 */
class TopologicalSorter
{
    /** @var array<string> Result: sorted file paths */
    private array $sorted = [];

    /** @var array<string, bool> Visited nodes */
    private array $visited = [];

    /** @var array<string, bool> Nodes currently in recursion stack (for cycle detection) */
    private array $inStack = [];

    /**
     * @param array<string, PHPFile> $files
     * @param array<string, array<string>> $dependencies
     */
    public function __construct(
        private readonly array $files,
        private readonly array $dependencies,
    ) {}

    /**
     * Perform topological sort
     * 
     * @return array<string> Sorted file paths (dependencies before dependents)
     * @throws RuntimeException if circular dependency detected
     */
    public function sort(): array
    {
        $this->sorted = [];
        $this->visited = [];
        $this->inStack = [];

        // Visit each node
        foreach (array_keys($this->files) as $filePath) {
            if (!isset($this->visited[$filePath])) {
                $this->visit($filePath, []);
            }
        }

        return $this->sorted;
    }

    /**
     * Visit a node in DFS
     * 
     * @param string $filePath
     * @param array<string> $path Current path for cycle detection
     * @throws RuntimeException
     */
    private function visit(string $filePath, array $path): void
    {
        // Check for cycles
        if (isset($this->inStack[$filePath])) {
            $cycleStart = array_search($filePath, $path);
            $cycle = array_slice($path, $cycleStart);
            $cycle[] = $filePath;
            throw new RuntimeException(
                "Circular dependency detected: " . implode(" -> ", $cycle)
            );
        }

        // Skip if already visited
        if (isset($this->visited[$filePath])) {
            return;
        }

        // Mark as in stack
        $this->inStack[$filePath] = true;
        $path[] = $filePath;

        // Visit dependencies first (DFS post-order)
        $dependencies = $this->dependencies[$filePath] ?? [];
        foreach ($dependencies as $depPath) {
            if (isset($this->files[$depPath])) {
                $this->visit($depPath, $path);
            }
        }

        // Mark as visited and remove from stack
        $this->visited[$filePath] = true;
        unset($this->inStack[$filePath]);

        // Add to result (dependencies have been added first)
        $this->sorted[] = $filePath;
    }

    /**
     * Alternative sort using Kahn's algorithm (for cycle detection without exception)
     * 
     * @return array{files: array<string>, cycles: array<array<string>>}
     */
    public function sortWithCycleDetection(): array
    {
        $sorted = [];
        $inDegree = [];

        // Build reverse dependency graph (who depends on me?)
        $reverseDeps = [];
        foreach (array_keys($this->files) as $file) {
            $reverseDeps[$file] = [];
        }

        // Calculate in-degree for each node (number of dependencies)
        foreach (array_keys($this->files) as $file) {
            $inDegree[$file] = 0;
        }

        foreach ($this->dependencies as $from => $toList) {
            foreach ($toList as $to) {
                if (isset($this->files[$to])) {
                    // $from depends on $to
                    // So $to is a dependency of $from
                    $inDegree[$from] = ($inDegree[$from] ?? 0) + 1;
                    // And $from is a dependent of $to
                    $reverseDeps[$to][] = $from;
                }
            }
        }

        // Queue all nodes with in-degree 0 (no dependencies)
        $queue = [];
        foreach ($inDegree as $file => $degree) {
            if ($degree === 0) {
                $queue[] = $file;
            }
        }

        // Process queue
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;

            // Reduce in-degree of dependents (those who depend on current)
            foreach ($reverseDeps[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Check for cycles
        $cycles = [];
        if (count($sorted) < count($this->files)) {
            // There are cycles - find them
            $remaining = array_diff(array_keys($this->files), $sorted);
            $cycles[] = array_values($remaining);
        }

        return [
            'files' => $sorted,
            'cycles' => $cycles,
        ];
    }
}
