<?php

declare(strict_types=1);

namespace PhpFileMerger\Model;

/**
 * Configuration for the merge operation
 */
readonly class Config
{
    /**
     * @param string $projectRoot Absolute path to the project root directory
     * @param string $entryPoint Absolute path to the entry point PHP file
     * @param string $outputPath Absolute path where the merged file should be written
     * @param bool $excludeEntryPoint Whether to exclude the entry point code from output (for .include.php)
     * @param int $indentSpaces Number of spaces for indentation (default: 4)
     * @param string|null $vendorDir Path to vendor directory (null for auto-detect)
     * @param array<string> $excludePaths List of file paths to exclude from merging
     * @param bool $removeStrictTypes Whether to remove declare(strict_types=1)
     * @param bool $removeAutoloadRequires Whether to remove vendor/autoload.php requires
     * @param string|null $headerTemplate Custom header template (null for default)
     */
    public function __construct(
        public string $projectRoot,
        public string $entryPoint,
        public string $outputPath,
        public bool $excludeEntryPoint = false,
        public int $indentSpaces = 4,
        public ?string $vendorDir = null,
        public array $excludePaths = [],
        public bool $removeStrictTypes = true,
        public bool $removeAutoloadRequires = true,
        public ?string $headerTemplate = null,
    ) {}

    /**
     * Get the vendor directory path
     */
    public function getVendorDir(): string
    {
        if ($this->vendorDir !== null) {
            return $this->vendorDir;
        }

        return $this->projectRoot . '/vendor';
    }

    /**
     * Get the composer.json path
     */
    public function getComposerJsonPath(): string
    {
        return $this->projectRoot . '/composer.json';
    }

    /**
     * Get indentation string
     */
    public function getIndent(): string
    {
        return str_repeat(' ', $this->indentSpaces);
    }
}
