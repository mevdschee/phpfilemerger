<?php

declare(strict_types=1);

namespace PhpFileMerger;

use PhpFileMerger\Command\MergeCommand;
use Symfony\Component\Console\Application as BaseApplication;

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
        $this->setDefaultCommand('merge', true);
    }
}
