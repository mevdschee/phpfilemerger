<?php

declare(strict_types=1);

namespace PhpFileMerger\Parser;

use PhpFileMerger\Model\ClassReference;
use PhpFileMerger\Model\PHPFile;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Parses PHP files using nikic/php-parser to extract dependencies
 */
class PhpFileParser
{
    private \PhpParser\Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse a PHP file and extract its structure and dependencies
     * 
     * @param string $filePath Absolute path to the PHP file
     * @return PHPFile
     * @throws RuntimeException
     */
    public function parseFile(string $filePath): PHPFile
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new RuntimeException("Failed to read file: $filePath");
        }

        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                throw new RuntimeException("Failed to parse file: $filePath");
            }

            // Traverse AST with NameResolver to resolve all names
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());

            $visitor = new ClassReferenceVisitor();
            $traverser->addVisitor($visitor);

            $traverser->traverse($ast);

            return new PHPFile(
                path: $filePath,
                namespace: $visitor->getNamespace(),
                declaredClasses: $visitor->getDeclaredClasses(),
                declaredInterfaces: $visitor->getDeclaredInterfaces(),
                declaredTraits: $visitor->getDeclaredTraits(),
                declaredEnums: $visitor->getDeclaredEnums(),
                references: $visitor->getReferences(),
                useStatements: $visitor->getUseStatements(),
            );
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Parse error in file $filePath: " . $e->getMessage(), 0, $e);
        }
    }
}

/**
 * AST visitor that collects class references and declarations
 */
class ClassReferenceVisitor extends NodeVisitorAbstract
{
    private ?string $currentNamespace = null;

    /** @var array<string> */
    private array $declaredClasses = [];

    /** @var array<string> */
    private array $declaredInterfaces = [];

    /** @var array<string> */
    private array $declaredTraits = [];

    /** @var array<string> */
    private array $declaredEnums = [];

    /** @var array<ClassReference> */
    private array $references = [];

    /** @var array<string, string> */
    private array $useStatements = [];

    public function enterNode(Node $node)
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : null;
        }

        // Track use statements
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $alias = $use->alias ? $use->alias->toString() : $use->name->getLast();
                $this->useStatements[$alias] = $use->name->toString();
            }
        }

        // Track class declarations
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name !== null) {
                $this->declaredClasses[] = $this->getFullyQualifiedName($node->name->toString());
            }

            // Track parent class
            if ($node->extends !== null) {
                $this->addReference($node->extends, 'extends', $node->getLine());
            }

            // Track implemented interfaces
            foreach ($node->implements as $interface) {
                $this->addReference($interface, 'implements', $node->getLine());
            }
        }

        // Track interface declarations
        if ($node instanceof Node\Stmt\Interface_) {
            $this->declaredInterfaces[] = $this->getFullyQualifiedName($node->name->toString());

            // Track extended interfaces
            foreach ($node->extends as $parent) {
                $this->addReference($parent, 'extends', $node->getLine());
            }
        }

        // Track trait declarations
        if ($node instanceof Node\Stmt\Trait_) {
            $this->declaredTraits[] = $this->getFullyQualifiedName($node->name->toString());
        }

        // Track enum declarations (PHP 8.1+)
        if ($node instanceof Node\Stmt\Enum_) {
            $this->declaredEnums[] = $this->getFullyQualifiedName($node->name->toString());

            // Track implemented interfaces
            foreach ($node->implements as $interface) {
                $this->addReference($interface, 'implements', $node->getLine());
            }
        }

        // Track trait uses
        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addReference($trait, 'use', $node->getLine());
            }
        }

        // Track new instances
        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->addReference($node->class, 'new', $node->getLine());
            }
        }

        // Track static calls
        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $this->addReference($node->class, 'static', $node->getLine());
            }
        }

        // Track instanceof
        if ($node instanceof Node\Expr\Instanceof_) {
            if ($node->class instanceof Node\Name) {
                $this->addReference($node->class, 'instanceof', $node->getLine());
            }
        }

        // Track catch blocks
        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addReference($type, 'catch', $node->getLine());
            }
        }

        // Track type hints in parameters
        if ($node instanceof Node\Param) {
            if ($node->type instanceof Node\Name) {
                $this->addReference($node->type, 'typehint', $node->getLine());
            } elseif ($node->type instanceof Node\UnionType || $node->type instanceof Node\IntersectionType) {
                foreach ($node->type->types as $type) {
                    if ($type instanceof Node\Name) {
                        $this->addReference($type, 'typehint', $node->getLine());
                    }
                }
            }
        }

        // Track return type hints
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $returnType = $node->getReturnType();
            if ($returnType instanceof Node\Name) {
                $this->addReference($returnType, 'typehint', $node->getLine());
            } elseif ($returnType instanceof Node\UnionType || $returnType instanceof Node\IntersectionType) {
                foreach ($returnType->types as $type) {
                    if ($type instanceof Node\Name) {
                        $this->addReference($type, 'typehint', $node->getLine());
                    }
                }
            }
        }

        // Track property type hints
        if ($node instanceof Node\Stmt\Property) {
            if ($node->type instanceof Node\Name) {
                $this->addReference($node->type, 'typehint', $node->getLine());
            } elseif ($node->type instanceof Node\UnionType || $node->type instanceof Node\IntersectionType) {
                foreach ($node->type->types as $type) {
                    if ($type instanceof Node\Name) {
                        $this->addReference($type, 'typehint', $node->getLine());
                    }
                }
            }
        }

        // Track attributes (PHP 8.0+)
        if (
            $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\ClassMethod ||
            $node instanceof Node\Stmt\Property || $node instanceof Node\Param
        ) {
            foreach ($node->attrGroups as $attrGroup) {
                foreach ($attrGroup->attrs as $attr) {
                    $this->addReference($attr->name, 'attribute', $node->getLine());
                }
            }
        }

        return null;
    }

    /**
     * Add a class reference
     */
    private function addReference(Node\Name $name, string $type, int $line): void
    {
        // NameResolver already resolved the name, so it's fully qualified
        $fqn = $name->toString();

        // Skip special names
        if (in_array($fqn, ['self', 'parent', 'static'], true)) {
            return;
        }

        // Ensure leading backslash
        if ($fqn[0] !== '\\') {
            $fqn = '\\' . $fqn;
        }

        $this->references[] = new ClassReference($fqn, $type, $line);
    }

    /**
     * Get fully qualified name for a class declared in current namespace
     */
    private function getFullyQualifiedName(string $name): string
    {
        if ($this->currentNamespace === null) {
            return '\\' . $name;
        }
        return '\\' . $this->currentNamespace . '\\' . $name;
    }

    public function getNamespace(): ?string
    {
        return $this->currentNamespace;
    }

    /**
     * @return array<string>
     */
    public function getDeclaredClasses(): array
    {
        return $this->declaredClasses;
    }

    /**
     * @return array<string>
     */
    public function getDeclaredInterfaces(): array
    {
        return $this->declaredInterfaces;
    }

    /**
     * @return array<string>
     */
    public function getDeclaredTraits(): array
    {
        return $this->declaredTraits;
    }

    /**
     * @return array<string>
     */
    public function getDeclaredEnums(): array
    {
        return $this->declaredEnums;
    }

    /**
     * @return array<ClassReference>
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * @return array<string, string>
     */
    public function getUseStatements(): array
    {
        return $this->useStatements;
    }
}
