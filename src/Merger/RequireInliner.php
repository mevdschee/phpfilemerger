<?php

declare(strict_types=1);

namespace PhpFileMerger\Merger;

use PhpFileMerger\Model\Config;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;
use RuntimeException;

/**
 * Inlines static `require`/`include` calls whose path is resolvable at merge
 * time (e.g. `require __DIR__ . '/bootstrap80.php'`).
 *
 * The merged output wraps every file in a bracketed namespace block, so a plain
 * `require __DIR__ . '/x.php'` would resolve relative to the *merged* file at
 * runtime and fail. This class rewrites each such call into a call to a small
 * runtime helper that invokes a registered closure containing the inlined file:
 *
 *   require_once __DIR__ . '/8.1/apache.php';   // becomes
 *   \__pfm_inline_require('pfm0', true);
 *
 * The closure for each target is emitted once, near the top of the merged file,
 * inside a namespace block matching the *target* file's namespace. Because a
 * function/class declared inside a closure is namespaced to where the closure is
 * defined (not where it is called), this places the target's declarations in the
 * correct namespace while still honouring the surrounding conditionals at the
 * call site. Expression-context requires (e.g. `$x ??= require __DIR__ . '/d.php'`)
 * keep working because the helper returns the closure's return value.
 */
class RequireInliner
{
    private \PhpParser\Parser $parser;
    private PrettyPrinter\Standard $printer;
    private Config $config;

    /** @var array<string, string> Map of resolved target path => generated id */
    private array $idByPath = [];

    /** @var array<string, string> Map of id => rendered registry block */
    private array $blocks = [];

    private int $counter = 0;
    private string $projectRootReal;
    private ?string $vendorReal;

    public function __construct(\PhpParser\Parser $parser, PrettyPrinter\Standard $printer, Config $config)
    {
        $this->parser = $parser;
        $this->printer = $printer;
        $this->config = $config;
        $this->projectRootReal = realpath($config->projectRoot) ?: $config->projectRoot;
        $this->vendorReal = realpath($config->getVendorDir()) ?: null;
    }

    /**
     * Rewrite resolvable require/include calls within the given statements.
     * Targets are registered for emission via {@see renderRegistry()}.
     *
     * @param array<Node\Stmt> $statements
     * @param string $sourceFile Absolute path of the file the statements came from
     * @return array<Node\Stmt>
     */
    public function inlineStatements(array $statements, string $sourceFile): array
    {
        $baseDir = dirname($sourceFile);

        $resolve = function (Node\Expr\Include_ $node) use ($baseDir): ?Node\Expr {
            $target = $this->resolveTarget($node->expr, $baseDir);
            if ($target === null) {
                return null;
            }

            $id = $this->registerTarget($target);
            if ($id === null) {
                return null;
            }

            $once = in_array(
                $node->type,
                [Node\Expr\Include_::TYPE_REQUIRE_ONCE, Node\Expr\Include_::TYPE_INCLUDE_ONCE],
                true
            );

            return $this->buildCall($id, $once);
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($resolve) extends NodeVisitorAbstract {
            private int $functionDepth = 0;
            /** @var \Closure(Node\Expr\Include_): ?Node\Expr */
            private \Closure $resolve;

            public function __construct(\Closure $resolve)
            {
                $this->resolve = $resolve;
            }

            public function enterNode(Node $node)
            {
                if ($this->isFunctionLike($node)) {
                    $this->functionDepth++;
                }
                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($this->isFunctionLike($node)) {
                    $this->functionDepth--;
                    return null;
                }

                // Replace a resolvable require/include with the helper call.
                if ($node instanceof Node\Expr\Include_) {
                    return ($this->resolve)($node);
                }

                // A namespace-scope `return require ...` becomes `return <helper>`
                // after the inner replacement above. A bare `return` at namespace
                // scope would halt the whole merged script, so drop the return and
                // keep only the (side-effecting) helper call.
                if (
                    $node instanceof Node\Stmt\Return_
                    && $this->functionDepth === 0
                    && $node->expr !== null
                    && $node->expr->getAttribute('pfm_inlined') === true
                ) {
                    return new Node\Stmt\Expression($node->expr, $node->getAttributes());
                }

                return null;
            }

            private function isFunctionLike(Node $node): bool
            {
                return $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction;
            }
        });

        return $traverser->traverse($statements);
    }

    /**
     * Render the runtime helper plus every registered inline closure. Returns an
     * empty string when nothing was inlined.
     */
    public function renderRegistry(): string
    {
        if (empty($this->blocks)) {
            return '';
        }

        $output = [];
        $output[] = $this->renderHelper();
        $output[] = '';

        foreach ($this->blocks as $block) {
            $output[] = $block;
            $output[] = '';
        }

        return rtrim(implode("\n", $output));
    }

    /**
     * Resolve a require/include path expression to an absolute, existing PHP file
     * inside the project, or null if it cannot be resolved statically.
     */
    private function resolveTarget(Node\Expr $expr, string $baseDir): ?string
    {
        $path = $this->evaluatePath($expr, $baseDir);
        if ($path === null) {
            return null;
        }

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return null;
        }

        // Only inline files that live inside the project (this includes vendor/).
        if (!str_starts_with($real, $this->projectRootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        // Never inline Composer's autoloader: the merge replaces it entirely (all
        // classes are inlined), so its requires are removed by the autoload filter.
        if ($this->vendorReal !== null) {
            if (
                $real === $this->vendorReal . DIRECTORY_SEPARATOR . 'autoload.php'
                || str_starts_with($real, $this->vendorReal . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR)
            ) {
                return null;
            }
        }

        return $real;
    }

    /**
     * Fold a path expression built from __DIR__, __FILE__, string literals and
     * concatenation into a string. Returns null for anything dynamic.
     */
    private function evaluatePath(Node\Expr $expr, string $baseDir): ?string
    {
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return $baseDir;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->evaluatePath($expr->left, $baseDir);
            $right = $this->evaluatePath($expr->right, $baseDir);
            if ($left === null || $right === null) {
                return null;
            }
            return $left . $right;
        }

        return null;
    }

    /**
     * Ensure a target file is registered and return its id, or null if it cannot
     * be inlined (parse error, unsupported multi-namespace layout, etc.).
     */
    private function registerTarget(string $realPath): ?string
    {
        if (isset($this->idByPath[$realPath])) {
            return $this->idByPath[$realPath];
        }

        // Reserve the id first so recursive/cyclic requires resolve to it.
        $id = 'pfm' . $this->counter++;
        $this->idByPath[$realPath] = $id;

        try {
            $this->blocks[$id] = $this->buildRegistryBlock($realPath, $id);
        } catch (RuntimeException $e) {
            unset($this->idByPath[$realPath]);
            error_log("Warning: could not inline require of $realPath: " . $e->getMessage());
            return null;
        }

        return $id;
    }

    /**
     * Parse a target file and render its registry block: a namespace block (in the
     * target's own namespace) that assigns a closure containing the file's body.
     */
    private function buildRegistryBlock(string $realPath, string $id): string
    {
        $code = file_get_contents($realPath);
        if ($code === false) {
            throw new RuntimeException("Failed to read file");
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\PhpParser\Error $e) {
            throw new RuntimeException("Parse error: " . $e->getMessage(), 0, $e);
        }

        if ($ast === null) {
            throw new RuntimeException("Failed to parse file");
        }

        [$namespace, $uses, $body] = $this->splitFile($ast);

        // Inline any requires the target itself performs (e.g. bootstrap.php
        // requiring bootstrap80.php), resolved relative to the target's location.
        $body = $this->inlineStatements($body, $realPath);

        $indent = $this->config->getIndent();
        $relativePath = str_replace($this->projectRootReal . DIRECTORY_SEPARATOR, '', $realPath);

        $lines = [];
        $lines[] = "// inlined: $relativePath";
        $lines[] = $namespace !== '' ? "namespace $namespace {" : 'namespace {';

        // Import aliases sit at namespace-block scope so the closure body resolves
        // them (a `use` import is illegal inside a closure).
        if (!empty($uses)) {
            foreach (explode("\n", $this->printer->prettyPrint($uses)) as $line) {
                $lines[] = trim($line) !== '' ? $indent . $line : '';
            }
        }

        $lines[] = $indent . "\$GLOBALS['__pfm_inline']['$id'] = static function () {";

        foreach (explode("\n", $this->printer->prettyPrint($body)) as $line) {
            $lines[] = trim($line) !== '' ? $indent . $indent . $line : '';
        }

        $lines[] = $indent . '};';
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * Split a parsed file into [namespace, useImports, body]. Only files with a
     * single effective namespace (or none) are supported; declare(strict_types)
     * and `use` imports are separated out.
     *
     * @param array<Node\Stmt> $ast
     * @return array{0: string, 1: array<Node\Stmt\Use_>, 2: array<Node\Stmt>}
     */
    private function splitFile(array $ast): array
    {
        $namespace = '';
        $statements = [];
        $sawNamespace = false;
        $sawGlobal = false;

        foreach ($ast as $node) {
            // Ignore comments/whitespace and a leading declare(strict_types=1):
            // the declare is stripped anyway and commonly precedes the namespace
            // (e.g. `<?php declare(strict_types=1); namespace Foo;`).
            if ($node instanceof Node\Stmt\Nop || $this->isStrictTypesDeclare($node)) {
                continue;
            }

            if ($node instanceof Node\Stmt\Namespace_) {
                if ($sawNamespace || $sawGlobal) {
                    throw new RuntimeException("multiple namespaces are not supported");
                }
                $sawNamespace = true;
                $namespace = $node->name !== null ? $node->name->toString() : '';
                $statements = $node->stmts ?? [];
            } else {
                if ($sawNamespace) {
                    throw new RuntimeException("mixed namespaced and global code is not supported");
                }
                $sawGlobal = true;
                $statements[] = $node;
            }
        }

        $uses = [];
        $body = [];
        foreach ($statements as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_) {
                $uses[] = $stmt;
                continue;
            }
            // declare(strict_types=1) is illegal inside the namespace block / closure.
            if ($this->isStrictTypesDeclare($stmt)) {
                continue;
            }
            $body[] = $stmt;
        }

        return [$namespace, $uses, $body];
    }

    /**
     * Build the helper-call expression that replaces a require/include node.
     */
    private function buildCall(string $id, bool $once): Node\Expr
    {
        $onceLiteral = $once ? 'true' : 'false';
        // $id is generated internally (pfm<N>), so the snippet is safe to parse.
        $ast = $this->parser->parse("<?php \\__pfm_inline_require('$id', $onceLiteral);");
        if ($ast === null || !isset($ast[0]) || !$ast[0] instanceof Node\Stmt\Expression) {
            throw new RuntimeException("Failed to build inline require call");
        }

        $expr = $ast[0]->expr;
        $expr->setAttribute('pfm_inlined', true);

        return $expr;
    }

    /**
     * Whether a statement is a declare(strict_types=...) directive.
     */
    private function isStrictTypesDeclare(Node\Stmt $stmt): bool
    {
        if (!$stmt instanceof Node\Stmt\Declare_) {
            return false;
        }

        foreach ($stmt->declares as $declare) {
            if ($declare->key->toString() === 'strict_types') {
                return true;
            }
        }

        return false;
    }

    /**
     * The runtime helper emitted once at the top of the merged file.
     */
    private function renderHelper(): string
    {
        $indent = $this->config->getIndent();

        return <<<PHP
            // phpfilemerger: runtime support for inlined require/include calls
            namespace {
            {$indent}\$GLOBALS['__pfm_inline'] ??= [];
            {$indent}if (!function_exists('__pfm_inline_require')) {
            {$indent}{$indent}function __pfm_inline_require(string \$id, bool \$once = false) {
            {$indent}{$indent}{$indent}static \$done = [];
            {$indent}{$indent}{$indent}if (\$once && \array_key_exists(\$id, \$done)) {
            {$indent}{$indent}{$indent}{$indent}return \$done[\$id];
            {$indent}{$indent}{$indent}}
            {$indent}{$indent}{$indent}\$result = (\$GLOBALS['__pfm_inline'][\$id])();
            {$indent}{$indent}{$indent}if (\$once) {
            {$indent}{$indent}{$indent}{$indent}\$done[\$id] = \$result;
            {$indent}{$indent}{$indent}}
            {$indent}{$indent}{$indent}return \$result;
            {$indent}{$indent}}
            {$indent}}
            }
            PHP;
    }
}
