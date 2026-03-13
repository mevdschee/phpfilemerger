<?php

declare(strict_types=1);

namespace PhpFileMerger\Merger;

use PhpFileMerger\Model\Config;
use PhpFileMerger\Model\PHPFile;
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

        // Add header
        $output[] = $this->generateHeader($config);
        $output[] = '';

        // Process each file
        foreach ($sortedFilePaths as $filePath) {
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
            $statements = $this->filterStatements($statements, $config);

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
     * @return array<\PhpParser\Node\Stmt>
     */
    private function filterStatements(array $statements, Config $config): array
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

            // Skip autoload includes if configured  
            if ($config->removeAutoloadRequires && $stmt instanceof \PhpParser\Node\Stmt\Expression) {
                if ($stmt->expr instanceof \PhpParser\Node\Expr\Include_) {
                    $includeExpr = $stmt->expr;
                    if ($includeExpr->expr instanceof \PhpParser\Node\Scalar\String_) {
                        $path = $includeExpr->expr->value;
                        if (str_contains($path, 'vendor/autoload.php') || str_contains($path, 'autoload.php')) {
                            continue;
                        }
                    }
                }
            }

            $filtered[] = $stmt;
        }

        return $filtered;
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
