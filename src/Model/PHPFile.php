<?php

declare(strict_types=1);

namespace PhpFileMerger\Model;

/**
 * Represents a parsed PHP file with its dependencies and declarations
 */
readonly class PHPFile
{
    /**
     * @param string $path Absolute path to the PHP file
     * @param string|null $namespace The namespace declared in this file (null for global namespace)
     * @param array<string> $declaredClasses List of fully-qualified class names declared in this file
     * @param array<string> $declaredInterfaces List of fully-qualified interface names declared in this file
     * @param array<string> $declaredTraits List of fully-qualified trait names declared in this file
     * @param array<string> $declaredEnums List of fully-qualified enum names declared in this file (PHP 8.1+)
     * @param array<ClassReference> $references List of class references used in this file
     * @param array<string, string> $useStatements Map of alias => fully-qualified class name from use statements
     */
    public function __construct(
        public string $path,
        public ?string $namespace,
        public array $declaredClasses,
        public array $declaredInterfaces,
        public array $declaredTraits,
        public array $declaredEnums,
        public array $references,
        public array $useStatements,
    ) {}

    /**
     * Get all declared types (classes, interfaces, traits, enums)
     * @return array<string>
     */
    public function getAllDeclaredTypes(): array
    {
        return array_merge(
            $this->declaredClasses,
            $this->declaredInterfaces,
            $this->declaredTraits,
            $this->declaredEnums
        );
    }

    /**
     * Get all unique referenced class names
     * @return array<string>
     */
    public function getReferencedClassNames(): array
    {
        $names = array_map(fn(ClassReference $ref) => $ref->fullyQualifiedName, $this->references);
        return array_unique($names);
    }

    /**
     * Get class names that are "hard" dependencies (must be defined before this file).
     * Only extends, implements, and trait use require ordering.
     * @return array<string>
     */
    public function getHardDependencyClassNames(): array
    {
        $names = [];
        foreach ($this->references as $ref) {
            if (in_array($ref->type, ['extends', 'implements', 'use'], true)) {
                $names[] = $ref->fullyQualifiedName;
            }
        }
        return array_unique($names);
    }
}
