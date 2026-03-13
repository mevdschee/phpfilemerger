<?php

declare(strict_types=1);

namespace PhpFileMerger\Merger;

use PhpFileMerger\Model\Config;
use PhpFileMerger\Model\PHPFile;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use RuntimeException;

/**
 * Merges PHP files into a single output file
 */
class FileMerger
{
    private \PhpParser\Parser $parser;
    private PrettyPrinter\Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * Merge files into a single output
     * 
     * @param array<string> $sortedFilePaths Array of file paths in dependency order
     * @param array<string, PHPFile> $phpFiles Map of file path => PHPFile
     * @param Config $config
     * @return string The merged PHP code
     * @throws RuntimeException
     */
    public function merge(array $sortedFilePaths, array $phpFiles, Config $config): string
    {
        $output = [];

        // Check if entry point has a shebang line
        $shebang = $this->extractShebang($config->entryPoint);
        if ($shebang !== null) {
            $output[] = $shebang;
        }

        // Add header
        $output[] = $this->generateHeader($config);
        $output[] = '';

        // Include autoload function files (e.g. trigger_deprecation) before any class files
        $autoloadFilesContent = $this->mergeAutoloadFiles($config);
        if ($autoloadFilesContent !== '') {
            $output[] = $autoloadFilesContent;
        }

        // Move entry point to the end so it executes after all classes are defined
        $reorderedPaths = [];
        $entryPointIndex = null;

        foreach ($sortedFilePaths as $index => $filePath) {
            if ($filePath === $config->entryPoint) {
                $entryPointIndex = $index;
            } else {
                $reorderedPaths[] = $filePath;
            }
        }

        // Add entry point at the end if found
        if ($entryPointIndex !== null) {
            $reorderedPaths[] = $sortedFilePaths[$entryPointIndex];
        }

        // Process each file
        foreach ($reorderedPaths as $filePath) {
            // Skip entry point if configured
            if ($config->excludeEntryPoint && $filePath === $config->entryPoint) {
                continue;
            }

            // Skip excluded paths
            if (in_array($filePath, $config->excludePaths, true)) {
                continue;
            }

            $phpFile = $phpFiles[$filePath] ?? null;
            if ($phpFile === null) {
                continue;
            }

            try {
                $mergedContent = $this->processFile($filePath, $phpFile, $config);
                $output[] = $mergedContent;
                $output[] = '';
            } catch (RuntimeException $e) {
                error_log("Warning: Failed to process file $filePath: " . $e->getMessage());
            }
        }

        return implode("\n", $output);
    }

    /**
     * Extract shebang line from file if present
     * 
     * @param string $filePath
     * @return string|null The shebang line or null if not found
     */
    private function extractShebang(string $filePath): ?string
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return null;
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if ($firstLine !== false && str_starts_with(trim($firstLine), '#!')) {
            return rtrim($firstLine);
        }

        return null;
    }

    /**
     * Process a single file
     * 
     * @param string $filePath
     * @param PHPFile $phpFile
     * @param Config $config
     * @return string
     */
    private function processFile(string $filePath, PHPFile $phpFile, Config $config): string
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                throw new RuntimeException("Failed to parse file: $filePath");
            }

            // Extract statements from namespace (or use top-level statements)
            $statements = $this->extractStatements($ast);

            // Filter out unwanted statements
            $statements = $this->filterStatements($statements, $config, $filePath);

            // Remove execution-stopping statements at namespace scope
            $statements = $this->removeExecutionStoppers($statements, $filePath);

            // Get relative path for comment
            $relativePath = str_replace($config->projectRoot . '/', '', $filePath);

            // Build output
            $output = [];
            $output[] = "// file: $relativePath";

            // Wrap in namespace block
            $namespace = $phpFile->namespace ?? '';
            if ($namespace !== '') {
                $output[] = "namespace $namespace {";
            } else {
                $output[] = "namespace {";
            }

            // Pretty print the statements
            if (!empty($statements)) {
                $code = $this->printer->prettyPrint($statements);

                // Indent the code
                $indent = $config->getIndent();
                $lines = explode("\n", $code);
                foreach ($lines as $line) {
                    if (trim($line) !== '') {
                        $output[] = $indent . $line;
                    } else {
                        $output[] = '';
                    }
                }
            }

            $output[] = "}";

            return implode("\n", $output);
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Parse error in file $filePath: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract statements from AST (from inside namespace or top-level)
     * 
     * @param array<\PhpParser\Node\Stmt> $ast
     * @return array<\PhpParser\Node\Stmt>
     */
    private function extractStatements(array $ast): array
    {
        $statements = [];

        foreach ($ast as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
                // Extract statements from inside namespace
                $statements = array_merge($statements, $node->stmts ?? []);
            } else {
                // Top-level statement (no namespace)
                $statements[] = $node;
            }
        }

        return $statements;
    }

    /**
     * Filter out unwanted statements
     * 
     * @param array<\PhpParser\Node\Stmt> $statements
     * @param Config $config
     * @param string $filePath
     * @return array<\PhpParser\Node\Stmt>
     */
    private function filterStatements(array $statements, Config $config, string $filePath): array
    {
        $filtered = [];

        foreach ($statements as $stmt) {
            // Skip declare statements if configured
            if ($config->removeStrictTypes && $stmt instanceof \PhpParser\Node\Stmt\Declare_) {
                $isDeclareStrictTypes = false;
                foreach ($stmt->declares as $declare) {
                    if ($declare->key->toString() === 'strict_types') {
                        $isDeclareStrictTypes = true;
                        break;
                    }
                }
                if ($isDeclareStrictTypes) {
                    continue;
                }
            }

            // Handle include/require statements
            if (
                $stmt instanceof \PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof \PhpParser\Node\Expr\Include_
            ) {
                $include = $stmt->expr;
                if ($config->removeAutoloadRequires && $this->isAutoloadRequire($include)) {
                    continue;
                }
                // Any other require/include at namespace scope is skipped with a warning.
                // Path expressions like __DIR__ would resolve relative to the merged file,
                // not the original source, so including them would break at runtime.
                $pathStr = $this->printer->prettyPrint([$include->expr]);
                $kind = match ($include->type) {
                    \PhpParser\Node\Expr\Include_::TYPE_REQUIRE       => 'require',
                    \PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE  => 'require_once',
                    \PhpParser\Node\Expr\Include_::TYPE_INCLUDE       => 'include',
                    \PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE  => 'include_once',
                    default                                            => 'include/require',
                };
                error_log(
                    "Warning: Skipped top-level $kind ($pathStr) in $filePath at line " .
                        $stmt->getLine() . ". Path would resolve relative to the merged file."
                );
                continue;
            }

            $filtered[] = $stmt;
        }

        return $filtered;
    }

    /**
     * Walk statements at namespace scope (not inside functions) and throw if any
     * execution-stopping construct is found: return, exit/die, __halt_compiler.
     * These would silently stop the merged file mid-execution.
     *
     * @param array<\PhpParser\Node\Stmt> $statements
     * @param string $filePath
     * @return array<\PhpParser\Node\Stmt>
     * @throws RuntimeException
     */
    private function removeExecutionStoppers(array $statements, string $filePath): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($filePath) extends NodeVisitorAbstract {
            private int $functionDepth = 0;
            private string $filePath;

            public function __construct(string $filePath)
            {
                $this->filePath = $filePath;
            }

            public function enterNode(\PhpParser\Node $node)
            {
                if (
                    $node instanceof \PhpParser\Node\Stmt\Function_
                    || $node instanceof \PhpParser\Node\Stmt\ClassMethod
                    || $node instanceof \PhpParser\Node\Expr\Closure
                    || $node instanceof \PhpParser\Node\Expr\ArrowFunction
                ) {
                    $this->functionDepth++;
                }
                return null;
            }

            public function leaveNode(\PhpParser\Node $node)
            {
                if (
                    $node instanceof \PhpParser\Node\Stmt\Function_
                    || $node instanceof \PhpParser\Node\Stmt\ClassMethod
                    || $node instanceof \PhpParser\Node\Expr\Closure
                    || $node instanceof \PhpParser\Node\Expr\ArrowFunction
                ) {
                    $this->functionDepth--;
                    return null;
                }

                if ($this->functionDepth > 0) {
                    return null;
                }

                if ($node instanceof \PhpParser\Node\Stmt\Return_) {
                    throw new RuntimeException(
                        "File {$this->filePath} contains a top-level 'return' at line {$node->getLine()} " .
                            "which would stop execution of the merged file."
                    );
                }

                if ($node instanceof \PhpParser\Node\Stmt\HaltCompiler) {
                    throw new RuntimeException(
                        "File {$this->filePath} contains '__halt_compiler()' at line {$node->getLine()} " .
                            "which would stop execution of the merged file."
                    );
                }

                if (
                    $node instanceof \PhpParser\Node\Stmt\Expression
                    && $node->expr instanceof \PhpParser\Node\Expr\Exit_
                ) {
                    throw new RuntimeException(
                        "File {$this->filePath} contains a top-level 'exit'/'die' at line {$node->getLine()} " .
                            "which would stop execution of the merged file."
                    );
                }

                return null;
            }
        });

        $traverser->traverse($statements);

        return $statements;
    }

    /**
     * Include global function files from vendor/composer/autoload_files.php.
     * These define functions like trigger_deprecation() that must exist before
     * any class files are loaded.
     */
    private function mergeAutoloadFiles(Config $config): string
    {
        $autoloadFilesPath = $config->getVendorDir() . '/composer/autoload_files.php';
        if (!file_exists($autoloadFilesPath)) {
            return '';
        }

        $files = (static function () use ($autoloadFilesPath): mixed {
            return require $autoloadFilesPath;
        })();

        if (!is_array($files)) {
            return '';
        }

        $output = [];

        foreach ($files as $file) {
            // Skip dev/test-only that would break when inlined
            if (
                str_contains($file, '/phpunit/') ||
                str_contains($file, '/phpspec/')
            ) {
                continue;
            }

            if (!file_exists($file)) {
                continue;
            }

            $relativePath = str_replace($config->projectRoot . '/', '', $file);

            try {
                $content = $this->processAutoloadFile($file, $relativePath, $config);
                $output[] = $content;
                $output[] = '';
            } catch (RuntimeException $e) {
                error_log("Warning: Failed to process autoload file $file: " . $e->getMessage());
            }
        }

        return implode("\n", $output);
    }

    /**
     * Process a single autoload function file using the PHP parser so that
     * any namespace declarations are converted to bracketed form.
     */
    private function processAutoloadFile(string $filePath, string $relativePath, Config $config): string
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Parse error in $filePath: " . $e->getMessage(), 0, $e);
        }

        if ($ast === null) {
            throw new RuntimeException("Failed to parse file: $filePath");
        }

        // Group statements by namespace (handles both bracketed and unbracketed namespace files)
        /** @var array<string, list<\PhpParser\Node\Stmt>> $groups */
        $groups = [];

        foreach ($ast as $node) {
            if ($node instanceof \PhpParser\Node\Stmt\Namespace_) {
                $ns = $node->name !== null ? $node->name->toString() : '';
                $groups[$ns] = array_merge($groups[$ns] ?? [], $node->stmts ?? []);
            } else {
                $groups[''] = array_merge($groups[''] ?? [], [$node]);
            }
        }

        $indent = $config->getIndent();
        $output = [];
        $output[] = "// file: $relativePath";

        foreach ($groups as $namespace => $stmts) {
            if (empty($stmts)) {
                continue;
            }

            // Filter out include/require and return statements that use __DIR__
            $printer = $this->printer;
            $stmts = array_filter($stmts, static function (\PhpParser\Node\Stmt $stmt) use ($filePath, $printer): bool {
                if (
                    $stmt instanceof \PhpParser\Node\Stmt\Expression
                    && $stmt->expr instanceof \PhpParser\Node\Expr\Include_
                ) {
                    $include = $stmt->expr;
                    $pathStr = $printer->prettyPrint([$include->expr]);
                    $kind = match ($include->type) {
                        \PhpParser\Node\Expr\Include_::TYPE_REQUIRE      => 'require',
                        \PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
                        \PhpParser\Node\Expr\Include_::TYPE_INCLUDE      => 'include',
                        \PhpParser\Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
                        default                                           => 'include/require',
                    };
                    error_log(
                        "Warning: Skipped top-level $kind ($pathStr) in $filePath at line " .
                            $stmt->getLine() . ". Path would resolve relative to the merged file."
                    );
                    return false;
                }
                if ($stmt instanceof \PhpParser\Node\Stmt\Return_) {
                    error_log(
                        "Warning: Skipped top-level return in $filePath at line " .
                            $stmt->getLine() . ". Would stop execution of the merged file."
                    );
                    return false;
                }
                return true;
            });
            $stmts = array_values($stmts);

            if (empty($stmts)) {
                continue;
            }

            $output[] = $namespace !== '' ? "namespace $namespace {" : 'namespace {';

            foreach (explode("\n", $this->printer->prettyPrint($stmts)) as $line) {
                $output[] = trim($line) !== '' ? $indent . $line : '';
            }

            $output[] = '}';
        }

        return implode("\n", $output);
    }

    /**
     * Check if an include expression is an autoload require
     * 
     * @param \PhpParser\Node\Expr\Include_ $include
     * @return bool
     */
    private function isAutoloadRequire(\PhpParser\Node\Expr\Include_ $include): bool
    {
        // Get the string representation of the include path
        $pathStr = $this->printer->prettyPrint([$include->expr]);

        // Check if it contains references to autoload
        return str_contains($pathStr, 'autoload.php') ||
            str_contains($pathStr, 'vendor/autoload') ||
            preg_match('/[\'"].*autoload\.php[\'"]/', $pathStr) === 1;
    }

    /**
     * Generate file header
     * 
     * @param Config $config
     * @return string
     */
    private function generateHeader(Config $config): string
    {
        if ($config->headerTemplate !== null) {
            return $config->headerTemplate;
        }

        $date = date('Y-m-d H:i:s');

        return <<<HEADER
<?php
/**
 * Merged PHP File
 * 
 * Generated by phpfilemerger
 * Date: $date
 * Entry point: {$this->getRelativePath($config->entryPoint,$config->projectRoot)}
 * 
 * This file was automatically generated. Do not edit manually.
 */
HEADER;
    }

    /**
     * Get relative path
     * 
     * @param string $path
     * @param string $base
     * @return string
     */
    private function getRelativePath(string $path, string $base): string
    {
        return str_replace($base . '/', '', $path);
    }
}
