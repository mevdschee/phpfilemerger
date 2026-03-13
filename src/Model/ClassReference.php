<?php

declare(strict_types=1);

namespace PhpFileMerger\Model;

/**
 * Represents a reference to a class/interface/trait in PHP code
 */
readonly class ClassReference
{
    /**
     * @param string $fullyQualifiedName The fully-qualified class name (e.g., \Foo\Bar\Baz)
     * @param string $type The type of reference: 'new', 'extends', 'implements', 'use', 'instanceof', 'catch', 'static', 'typehint', 'attribute'
     * @param int $line Line number where this reference occurs
     */
    public function __construct(
        public string $fullyQualifiedName,
        public string $type,
        public int $line,
    ) {}

    /**
     * Check if this reference is a dependency (needs to be loaded before the current file)
     */
    public function isDependency(): bool
    {
        // All reference types are dependencies except for forward references in some cases
        // For simplicity, treat all as dependencies initially
        return true;
    }
}
