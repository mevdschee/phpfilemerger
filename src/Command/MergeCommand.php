<?php

declare(strict_types=1);

namespace PhpFileMerger\Command;

use PhpFileMerger\Graph\DependencyGraph;
use PhpFileMerger\Merger\FileMerger;
use PhpFileMerger\Model\Config;
use PhpFileMerger\Parser\ComposerParser;
use PhpFileMerger\Parser\PhpFileParser;
use PhpFileMerger\Resolver\PSR4Resolver;
use \RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command to merge PHP files
 */
class MergeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('merge')
            ->setDescription('Merge a PHP entry point and its dependencies into a single file')
            ->addArgument(
                'entry',
                InputArgument::REQUIRED,
                'Path to the entry point PHP file'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path (default: auto-generated)'
            )
            ->addOption(
                'project-root',
                null,
                InputOption::VALUE_REQUIRED,
                'Project root directory (default: auto-detect from composer.json)'
            )
            ->addOption(
                'exclude-entry',
                null,
                InputOption::VALUE_NONE,
                'Exclude entry point code from output (create .include.php)'
            )
            ->addOption(
                'indent',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of spaces for indentation',
                '4'
            )
            ->addOption(
                'vendor-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Vendor directory path (default: PROJECT_ROOT/vendor)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be included without writing output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            // Get entry point
            $entryPoint = $input->getArgument('entry');
            $entryPoint = $this->resolveAbsolutePath($entryPoint);

            if (!file_exists($entryPoint)) {
                $io->error("Entry point file not found: $entryPoint");
                return Command::FAILURE;
            }

            $io->title('PHP File Merger');
            $io->text("Entry point: $entryPoint");

            // Auto-detect project root
            $projectRoot = $input->getOption('project-root');
            if ($projectRoot === null) {
                $projectRoot = $this->findProjectRoot($entryPoint);
                if ($projectRoot === null) {
                    $io->error('Could not auto-detect project root. Please specify --project-root');
                    return Command::FAILURE;
                }
            }
            $projectRoot = $this->resolveAbsolutePath($projectRoot);
            $io->text("Project root: $projectRoot");

            // Determine output path
            $outputPath = $input->getOption('output');
            if ($outputPath === null) {
                $suffix = $input->getOption('exclude-entry') ? '.include.php' : '.merged.php';
                $outputPath = pathinfo($entryPoint, PATHINFO_FILENAME) . $suffix;
            }
            $outputPath = $this->resolveAbsolutePath($outputPath);
            $io->text("Output: $outputPath");
            $io->newLine();

            // Build config
            $config = new Config(
                projectRoot: $projectRoot,
                entryPoint: $entryPoint,
                outputPath: $outputPath,
                excludeEntryPoint: (bool) $input->getOption('exclude-entry'),
                indentSpaces: (int) $input->getOption('indent'),
                vendorDir: $input->getOption('vendor-dir'),
            );

            // Initialize components
            $io->section('Initializing...');
            $composerParser = new ComposerParser($projectRoot);
            $resolver = new PSR4Resolver($projectRoot, $composerParser);
            $parser = new PhpFileParser();

            // Build dependency graph
            $io->section('Analyzing dependencies...');
            $graph = new DependencyGraph($parser, $resolver);
            $graph->build($entryPoint);

            $files = $graph->getFiles();
            $io->success(sprintf('Found %d files', count($files)));

            // Get topological sort
            $io->section('Sorting files...');
            $sortedFiles = $graph->getTopologicalSort();

            if ($output->isVerbose()) {
                $io->text('File order:');
                foreach ($sortedFiles as $i => $file) {
                    $relativePath = str_replace($projectRoot . '/', '', $file);
                    $io->text(sprintf('  %d. %s', $i + 1, $relativePath));
                }
                $io->newLine();
            }

            // Dry run check
            if ($input->getOption('dry-run')) {
                $io->warning('Dry run mode - no files written');
                return Command::SUCCESS;
            }

            // Merge files
            $io->section('Merging files...');
            $merger = new FileMerger();
            $mergedCode = $merger->merge($sortedFiles, $files, $config);

            // Write output
            $written = file_put_contents($outputPath, $mergedCode);
            if ($written === false) {
                $io->error("Failed to write output file: $outputPath");
                return Command::FAILURE;
            }

            // Validate syntax
            $io->section('Validating output...');
            $validationResult = $this->validatePhpSyntax($outputPath);

            if ($validationResult === true) {
                $io->success('Output file is syntactically valid');
            } else {
                $io->error('Syntax validation failed:');
                $io->text($validationResult);
                return Command::FAILURE;
            }

            // Success
            $fileSize = filesize($outputPath);
            $io->success(sprintf(
                'Successfully merged %d files into %s (%s)',
                count($sortedFiles),
                $outputPath,
                $this->formatBytes($fileSize)
            ));

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * Resolve to absolute path
     */
    private function resolveAbsolutePath(string $path): string
    {
        if ($path[0] === '/') {
            return $path;
        }
        return getcwd() . '/' . $path;
    }

    /**
     * Find project root by searching for composer.json
     */
    private function findProjectRoot(string $startPath): ?string
    {
        $dir = dirname($startPath);
        $maxDepth = 10;
        $depth = 0;

        while ($depth < $maxDepth) {
            if (file_exists($dir . '/composer.json')) {
                return $dir;
            }

            $parentDir = dirname($dir);
            if ($parentDir === $dir) {
                // Reached filesystem root
                break;
            }

            $dir = $parentDir;
            $depth++;
        }

        return null;
    }

    /**
     * Validate PHP syntax using php -l
     */
    private function validatePhpSyntax(string $filePath): bool|string
    {
        $command = sprintf('php -l %s 2>&1', escapeshellarg($filePath));
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            return true;
        }

        return implode("\n", $output);
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
