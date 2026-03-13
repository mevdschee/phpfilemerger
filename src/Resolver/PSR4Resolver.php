<?php

declare(strict_types=1);

namespace PhpFileMerger\Resolver;

use PhpFileMerger\Parser\ComposerParser;
use RuntimeException;

/**
 * Resolves fully-qualified class names to file paths using PSR-4 and PSR-0 autoloading rules
 */
class PSR4Resolver
{
    /** @var array<string, array<string>> PSR-4 namespace prefix => directory paths */
    private array $psr4Prefixes = [];

    /** @var array<string, array<string>> PSR-0 namespace prefix => directory paths */
    private array $psr0Prefixes = [];

    /** @var array<string, string> Classmap: fully-qualified class name => file path */
    private array $classmap = [];

    /** @var array<string> List of PHP built-in classes to ignore */
    private const BUILTIN_CLASSES = [
        'stdClass',
        'Exception',
        'ErrorException',
        'Error',
        'ParseError',
        'TypeError',
        'ArgumentCountError',
        'ArithmeticError',
        'AssertionError',
        'DivisionByZeroError',
        'CompileError',
        'Throwable',
        'DateTime',
        'DateTimeImmutable',
        'DateTimeZone',
        'DateInterval',
        'DatePeriod',
        'Iterator',
        'IteratorAggregate',
        'Traversable',
        'ArrayAccess',
        'Serializable',
        'Closure',
        'Generator',
        'WeakReference',
        'WeakMap',
        'Fiber',
        'Attribute',
        'PDO',
        'PDOStatement',
        'PDOException',
        'mysqli',
        'mysqli_result',
        'mysqli_stmt',
        'Redis',
        'Memcached',
        'SimpleXMLElement',
        'DOMDocument',
        'DOMElement',
        'DOMNode',
        'DOMNodeList',
        'DOMXPath',
        'ZipArchive',
        'PharData',
        'SplFileInfo',
        'SplFileObject',
        'DirectoryIterator',
        'RecursiveDirectoryIterator',
        'RecursiveIteratorIterator',
        'ArrayIterator',
        'ArrayObject',
        'SplDoublyLinkedList',
        'SplStack',
        'SplQueue',
        'SplHeap',
        'SplMinHeap',
        'SplMaxHeap',
        'SplPriorityQueue',
        'SplFixedArray',
        'SplObjectStorage',
        'ReflectionClass',
        'ReflectionMethod',
        'ReflectionProperty',
        'ReflectionFunction',
        'ReflectionParameter',
        'ReflectionType',
        'ReflectionNamedType',
    ];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ComposerParser $composerParser,
    ) {
        $this->loadAutoloadMappings();
    }

    /**
     * Load autoload mappings from composer.json and vendor autoload files
     */
    private function loadAutoloadMappings(): void
    {
        // Load project's composer.json
        $projectAutoload = $this->composerParser->parse();

        foreach ($projectAutoload['psr4'] as $prefix => $paths) {
            $paths = is_array($paths) ? $paths : [$paths];
            $this->psr4Prefixes[$prefix] = array_map(
                fn($path) => $this->normalizePath($this->projectRoot . DIRECTORY_SEPARATOR . $path),
                $paths
            );
        }

        foreach ($projectAutoload['psr0'] as $prefix => $paths) {
            $paths = is_array($paths) ? $paths : [$paths];
            $this->psr0Prefixes[$prefix] = array_map(
                fn($path) => $this->normalizePath($this->projectRoot . DIRECTORY_SEPARATOR . $path),
                $paths
            );
        }

        // Load vendor autoload mappings
        $vendorDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'vendor';
        if (is_dir($vendorDir)) {
            $vendorAutoload = $this->composerParser->parseVendorAutoload($vendorDir);

            foreach ($vendorAutoload['psr4'] as $prefix => $paths) {
                if (!isset($this->psr4Prefixes[$prefix])) {
                    $this->psr4Prefixes[$prefix] = [];
                }
                $this->psr4Prefixes[$prefix] = array_merge(
                    $this->psr4Prefixes[$prefix],
                    array_map(fn($path) => $this->normalizePath($path), $paths)
                );
            }

            foreach ($vendorAutoload['psr0'] as $prefix => $paths) {
                if (!isset($this->psr0Prefixes[$prefix])) {
                    $this->psr0Prefixes[$prefix] = [];
                }
                $this->psr0Prefixes[$prefix] = array_merge(
                    $this->psr0Prefixes[$prefix],
                    array_map(fn($path) => $this->normalizePath($path), $paths)
                );
            }

            // Build classmap
            foreach ($vendorAutoload['classmap'] as $filePath) {
                // Note: We'd need to parse each file to build a complete classmap
                // For now, we'll rely on PSR-4/PSR-0 resolution
            }
        }

        // Sort prefixes by length (longest first) for accurate matching
        uksort($this->psr4Prefixes, fn($a, $b) => strlen($b) - strlen($a));
        uksort($this->psr0Prefixes, fn($a, $b) => strlen($b) - strlen($a));
    }

    /**
     * Resolve a fully-qualified class name to a file path
     * 
     * @param string $className Fully-qualified class name (e.g., "Foo\Bar\Baz" or "\Foo\Bar\Baz")
     * @return string|null File path or null if not found
     */
    public function resolve(string $className): ?string
    {
        // Remove leading backslash
        $className = ltrim($className, '\\');

        // Check if it's a built-in class
        if ($this->isBuiltinClass($className)) {
            return null;
        }

        // Check classmap first (most specific)
        if (isset($this->classmap[$className])) {
            return $this->classmap[$className];
        }

        // Try PSR-4 resolution
        $filePath = $this->resolvePsr4($className);
        if ($filePath !== null) {
            return $filePath;
        }

        // Try PSR-0 resolution
        $filePath = $this->resolvePsr0($className);
        if ($filePath !== null) {
            return $filePath;
        }

        return null;
    }

    /**
     * Resolve using PSR-4 rules
     */
    private function resolvePsr4(string $className): ?string
    {
        foreach ($this->psr4Prefixes as $prefix => $baseDirs) {
            // Check if class uses this namespace prefix
            if (strpos($className, $prefix) === 0) {
                // Remove the prefix from class name
                $relativeClass = substr($className, strlen($prefix));

                // Replace namespace separators with directory separators
                $relativeFile = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

                // Check each base directory
                foreach ($baseDirs as $baseDir) {
                    $filePath = $baseDir . DIRECTORY_SEPARATOR . $relativeFile;
                    if (file_exists($filePath)) {
                        return $this->normalizePath($filePath);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve using PSR-0 rules
     */
    private function resolvePsr0(string $className): ?string
    {
        foreach ($this->psr0Prefixes as $prefix => $baseDirs) {
            // Check if class uses this namespace prefix
            if (strpos($className, $prefix) === 0) {
                // PSR-0: namespace separators become directory separators
                // underscore in class name also becomes directory separator
                $relativeFile = str_replace('\\', DIRECTORY_SEPARATOR, $className);
                $relativeFile = str_replace('_', DIRECTORY_SEPARATOR, $relativeFile) . '.php';

                foreach ($baseDirs as $baseDir) {
                    $filePath = $baseDir . DIRECTORY_SEPARATOR . $relativeFile;
                    if (file_exists($filePath)) {
                        return $this->normalizePath($filePath);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a class is a built-in PHP class
     */
    private function isBuiltinClass(string $className): bool
    {
        // Remove namespace for checking
        $shortName = substr($className, strrpos($className, '\\') + 1);

        if (in_array($shortName, self::BUILTIN_CLASSES, true)) {
            return true;
        }

        // Check if it's a class from a PHP extension
        if (class_exists($className, false) || interface_exists($className, false) || trait_exists($className, false)) {
            try {
                $reflection = new \ReflectionClass($className);
                return $reflection->isInternal();
            } catch (\ReflectionException $e) {
                // Class doesn't exist yet, which is fine
            }
        }

        return false;
    }

    /**
     * Normalize file path (resolve . and .., convert to absolute)
     */
    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), fn($part) => $part !== '.');
        $absolutes = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }

    /**
     * Get all registered PSR-4 prefixes
     * 
     * @return array<string, array<string>>
     */
    public function getPsr4Prefixes(): array
    {
        return $this->psr4Prefixes;
    }
}
