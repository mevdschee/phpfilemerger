<?php

declare(strict_types=1);

namespace PhpFileMerger;

use PhpFileMerger\Command\MergeCommand;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Main application class
 */
class Application extends BaseApplication
{
    private const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct('PHP File Merger', self::VERSION);

        $this->add(new MergeCommand());
        // Single-command application: "merge" is the only command and its name
        // can be omitted (php phpfilemerger.phar <entry> ...).
        $this->setDefaultCommand('merge', true);
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        // This is a single-command application, so Symfony does not parse a
        // command name and would treat an explicit leading "merge" as a second
        // positional argument ("Too many arguments"). Strip it so the
        // documented form (php phpfilemerger.phar merge <entry> ...) also works.
        if ($input === null) {
            $argv = $_SERVER['argv'] ?? [];
            if (isset($argv[1]) && $argv[1] === 'merge') {
                array_splice($argv, 1, 1);
            }
            $input = new ArgvInput($argv);
        }

        return parent::run($input, $output);
    }
}
