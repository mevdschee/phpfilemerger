<?php

declare(strict_types=1);

namespace PhpFileMerger\Parser;

use RuntimeException;

/**
 * Parses composer.json to extract autoload configuration
 */
class ComposerParser
{
    /**
     * @param string $projectRoot
     */
    public function __construct(
        private readonly string $projectRoot
    ) {}

    /**
     * Parse composer.json and return autoload configuration
     * 
     * @return array{psr4: array<string, string|array<string>>, psr0: array<string, string|array<string>>, classmap: array<string>, files: array<string>}
     */
    public function parse(): array
    {
        $composerPath = $this->projectRoot . '/composer.json';

        if (!file_exists($composerPath)) {
            throw new RuntimeException("composer.json not found at: $composerPath");
        }

        $content = file_get_contents($composerPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read composer.json at: $composerPath");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON in composer.json at: $composerPath");
        }

        return [
            'psr4' => $this->extractPsr4($data),
            'psr0' => $this->extractPsr0($data),
            'classmap' => $this->extractClassmap($data),
            'files' => $this->extractFiles($data),
        ];
    }

    /**
     * Extract PSR-4 autoload mappings from composer data
     * 
     * @param array<mixed> $data
     * @return array<string, string|array<string>>
     */
    private function extractPsr4(array $data): array
    {
        $psr4 = [];

        // Get from autoload section
        if (isset($data['autoload']['psr-4']) && is_array($data['autoload']['psr-4'])) {
            $psr4 = array_merge($psr4, $data['autoload']['psr-4']);
        }

        // Get from autoload-dev section
        if (isset($data['autoload-dev']['psr-4']) && is_array($data['autoload-dev']['psr-4'])) {
            $psr4 = array_merge($psr4, $data['autoload-dev']['psr-4']);
        }

        return $psr4;
    }

    /**
     * Extract PSR-0 autoload mappings from composer data
     * 
     * @param array<mixed> $data
     * @return array<string, string|array<string>>
     */
    private function extractPsr0(array $data): array
    {
        $psr0 = [];

        // Get from autoload section
        if (isset($data['autoload']['psr-0']) && is_array($data['autoload']['psr-0'])) {
            $psr0 = array_merge($psr0, $data['autoload']['psr-0']);
        }

        // Get from autoload-dev section
        if (isset($data['autoload-dev']['psr-0']) && is_array($data['autoload-dev']['psr-0'])) {
            $psr0 = array_merge($psr0, $data['autoload-dev']['psr-0']);
        }

        return $psr0;
    }

    /**
     * Extract classmap entries from composer data
     * 
     * @param array<mixed> $data
     * @return array<string>
     */
    private function extractClassmap(array $data): array
    {
        $classmap = [];

        // Get from autoload section
        if (isset($data['autoload']['classmap']) && is_array($data['autoload']['classmap'])) {
            $classmap = array_merge($classmap, $data['autoload']['classmap']);
        }

        // Get from autoload-dev section
        if (isset($data['autoload-dev']['classmap']) && is_array($data['autoload-dev']['classmap'])) {
            $classmap = array_merge($classmap, $data['autoload-dev']['classmap']);
        }

        return $classmap;
    }

    /**
     * Extract files entries from composer data
     * 
     * @param array<mixed> $data
     * @return array<string>
     */
    private function extractFiles(array $data): array
    {
        $files = [];

        // Get from autoload section
        if (isset($data['autoload']['files']) && is_array($data['autoload']['files'])) {
            $files = array_merge($files, $data['autoload']['files']);
        }

        // Get from autoload-dev section
        if (isset($data['autoload-dev']['files']) && is_array($data['autoload-dev']['files'])) {
            $files = array_merge($files, $data['autoload-dev']['files']);
        }

        return $files;
    }

    /**
     * Parse vendor composer autoload files to get all PSR-4 mappings including from dependencies
     * 
     * @param string $vendorDir
     * @return array{psr4: array<string, array<string>>, psr0: array<string, array<string>>, classmap: array<string>, files: array<string>}
     */
    public function parseVendorAutoload(string $vendorDir): array
    {
        $psr4 = [];
        $psr0 = [];
        $classmap = [];
        $files = [];

        // Parse composer autoload files
        $autoloadPath = $vendorDir . '/composer/autoload_psr4.php';
        if (file_exists($autoloadPath)) {
            $loaded = require $autoloadPath;
            if (is_array($loaded)) {
                foreach ($loaded as $namespace => $paths) {
                    $psr4[$namespace] = is_array($paths) ? $paths : [$paths];
                }
            }
        }

        $autoloadPsr0Path = $vendorDir . '/composer/autoload_psr0.php';
        if (file_exists($autoloadPsr0Path)) {
            $loaded = require $autoloadPsr0Path;
            if (is_array($loaded)) {
                foreach ($loaded as $namespace => $paths) {
                    $psr0[$namespace] = is_array($paths) ? $paths : [$paths];
                }
            }
        }

        $autoloadClassmapPath = $vendorDir . '/composer/autoload_classmap.php';
        if (file_exists($autoloadClassmapPath)) {
            $loaded = require $autoloadClassmapPath;
            if (is_array($loaded)) {
                $classmap = array_values($loaded);
            }
        }

        $autoloadFilesPath = $vendorDir . '/composer/autoload_files.php';
        if (file_exists($autoloadFilesPath)) {
            $loaded = require $autoloadFilesPath;
            if (is_array($loaded)) {
                $files = array_values($loaded);
            }
        }

        return [
            'psr4' => $psr4,
            'psr0' => $psr0,
            'classmap' => $classmap,
            'files' => $files,
        ];
    }
}
