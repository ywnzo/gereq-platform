<?php

declare(strict_types=1);

/**
 * Index Twig typů z controllerů + for-smyček.
 *
 * Výstup (mimo šablony):
 *  - .cache/twig/types.json
 *  - .cache/twig/vars/<cesta-k-sablone>.twig   (@var stuby jen pro IDE/debug)
 *
 * Zároveň odstraní staré BEGIN-GEREQ-TWIG-TYPES bloky ze šablon.
 *
 * Usage:
 *   php tools/gereq-twig-goto/index-templates.php
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

$basePath = dirname(__DIR__, 2);
require $basePath . '/vendor/autoload.php';

$parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 4));
$finder = new NodeFinder();

$globals = [
    'user' => ['class' => 'Gereq\\User\\Entity\\User', 'array' => false],
];

/** @var array<string, array<string, array{class: string, array: bool}>> $index */
$index = [];

$phpFiles = array_merge(
    rglob($basePath . '/src', '*.php'),
    rglob($basePath . '/modules', '*.php'),
);

foreach ($phpFiles as $file) {
    $code = file_get_contents($file);
    if ($code === false || (!str_contains($code, '->page(') && !str_contains($code, '->render('))) {
        continue;
    }

    try {
        $ast = $parser->parse($code);
    } catch (\Throwable) {
        continue;
    }
    if ($ast === null) {
        continue;
    }

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]));
    $ast = $traverser->traverse($ast);

    $docTypes = collectVarDocs($code, $ast, $finder);
    $methodReturns = collectMethodReturns($code, $ast, $finder);
    $calls = $finder->find($ast, static function (Node $node): bool {
        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier) {
            return false;
        }
        $name = $node->name->toString();

        return $name === 'page' || $name === 'render';
    });

    foreach ($calls as $call) {
        /** @var Node\Expr\MethodCall $call */
        if (count($call->args) < 1) {
            continue;
        }
        $viewArg = $call->args[0]->value;
        if (!$viewArg instanceof Node\Scalar\String_) {
            continue;
        }
        $template = resolveTemplatePath($basePath, $viewArg->value);
        if ($template === null) {
            continue;
        }

        $vars = $globals;
        if (isset($call->args[1])) {
            foreach (extractArrayKeys($call->args[1]->value, $docTypes, $methodReturns) as $name => $type) {
                $vars[$name] = $type;
            }
        }
        if ($call->name->toString() === 'page') {
            $vars = array_merge($vars, $globals);
        }

        $rel = relativePath($basePath, $template);
        $index[$rel] = array_merge($index[$rel] ?? [], $vars);
    }
}

// Doplň typy z {% for x in y %} podle y[] / shape
foreach ($index as $rel => $vars) {
    $abs = $basePath . '/' . $rel;
    if (!is_file($abs)) {
        continue;
    }
    $twig = file_get_contents($abs);
    if ($twig === false) {
        continue;
    }
    if (preg_match_all('/\{%\s*for\s+([A-Za-z_][A-Za-z0-9_]*)\s+in\s+([A-Za-z_][A-Za-z0-9_]*)/', $twig, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $loopVar = $m[1];
            $collection = $m[2];
            if (!isset($vars[$collection])) {
                continue;
            }
            $col = $vars[$collection];
            $entry = [
                'class' => $col['class'],
                'array' => false,
            ];
            if (!empty($col['shape']) && is_array($col['shape'])) {
                $entry['shape'] = $col['shape'];
                // prvek list<array{...}> je asociativní pole, ne třída
                if (($col['class'] ?? '') === 'array' || str_starts_with((string) ($col['class'] ?? ''), 'array{')) {
                    $entry['class'] = 'array';
                }
            }
            $index[$rel][$loopVar] = $entry;
        }
    }
}

ksort($index);

/** @var array<string, array{file: ?string, methods: list<array{name: string, return: string, params: list<string>}>}> $classes */
$classes = [];
foreach ($index as $vars) {
    foreach ($vars as $meta) {
        $fqcn = $meta['class'];
        if (isScalarClass($fqcn) || isset($classes[$fqcn])) {
            continue;
        }
        $classes[$fqcn] = reflectClass($fqcn);
    }
}

$cacheTwig = $basePath . '/.cache/twig';
$varsDir = $cacheTwig . '/vars';
if (!is_dir($varsDir)) {
    mkdir($varsDir, 0775, true);
}

$outFile = $cacheTwig . '/types.json';
file_put_contents($outFile, json_encode([
    'generated_at' => date('c'),
    'templates' => $index,
    'classes' => $classes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// zpětná kompatibilita se starší cestou extension
@mkdir($basePath . '/.cache', 0775, true);
@copy($outFile, $basePath . '/.cache/twig-goto-types.json');

$stubs = 0;
foreach ($index as $rel => $vars) {
    if (writeStubFile($varsDir, $rel, $vars)) {
        $stubs++;
    }
}

$stripped = 0;
foreach (array_merge(rglob($basePath . '/resources/views', '*.twig'), rglob($basePath . '/modules', '*.twig')) as $twigFile) {
    if (stripVarBlock($twigFile)) {
        $stripped++;
    }
}

fwrite(STDOUT, 'Indexed ' . count($index) . ' templates, ' . count($classes) . " classes → {$outFile}\n");
fwrite(STDOUT, "Stubs: {$stubs} in .cache/twig/vars/; removed type blocks from {$stripped} templates\n");

/**
 * @param array<string, array{class: string, array: bool}> $vars
 */
function writeStubFile(string $varsDir, string $rel, array $vars): bool
{
    $stubRel = $rel . '.types.twig';
    $path = $varsDir . '/' . $stubRel;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    ksort($vars);
    $lines = [
        '{# Auto-generated — nesahej. Zdroj: php gereq twig:types #}',
        '{# Template: ' . $rel . ' #}',
        '',
    ];
    foreach ($vars as $name => $meta) {
        if (isScalarClass($meta['class'])) {
            $type = $meta['class'] . ($meta['array'] ? '[]' : '');
        } else {
            $type = '\\' . ltrim($meta['class'], '\\') . ($meta['array'] ? '[]' : '');
        }
        $lines[] = '{# @var ' . $name . ' ' . $type . ' #}';
    }
    $lines[] = '';

    return file_put_contents($path, implode("\n", $lines)) !== false;
}

function stripVarBlock(string $path): bool
{
    $src = file_get_contents($path);
    if ($src === false || !str_contains($src, 'BEGIN-GEREQ-TWIG-TYPES')) {
        return false;
    }

    $next = preg_replace(
        '/\n*\{#\s*BEGIN-GEREQ-TWIG-TYPES\s*#\}[\s\S]*?\{#\s*END-GEREQ-TWIG-TYPES\s*#\}\n*/u',
        "\n\n",
        $src,
    );
    if ($next === null || $next === $src) {
        return false;
    }

    // ukliď vícenásobné prázdné řádky hned po extends
    $next = preg_replace('/(\{% extends [^%]+%\})\n{3,}/u', "$1\n\n", $next) ?? $next;

    return file_put_contents($path, $next) !== false;
}

/**
 * @return array{file: ?string, methods: list<array{name: string, return: string, params: list<string>}>}
 */
function reflectClass(string $fqcn): array
{
    $fqcn = ltrim($fqcn, '\\');
    try {
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            return ['file' => null, 'methods' => []];
        }
        $ref = new ReflectionClass($fqcn);
        $methods = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor() || str_starts_with($method->name, '__')) {
                continue;
            }
            $params = [];
            foreach ($method->getParameters() as $p) {
                $params[] = ($p->hasType() ? (string) $p->getType() . ' ' : '') . '$' . $p->getName();
            }
            $methods[] = [
                'name' => $method->getName(),
                'return' => $method->hasReturnType() ? (string) $method->getReturnType() : '',
                'params' => $params,
            ];
        }

        return [
            'file' => $ref->getFileName() ?: null,
            'methods' => $methods,
        ];
    } catch (\Throwable) {
        return ['file' => null, 'methods' => []];
    }
}

function isScalarClass(string $class): bool
{
    $c = strtolower(ltrim($class, '\\'));

    return in_array($c, [
        'string', 'int', 'float', 'bool', 'boolean', 'array', 'mixed', 'null',
        'void', 'iterable', 'callable', 'object', 'number', 'list', 'dict',
    ], true);
}

/** @return list<string> */
function rglob(string $dir, string $pattern): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

function relativePath(string $base, string $abs): string
{
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $abs = str_replace('\\', '/', $abs);
    if (str_starts_with($abs, $base)) {
        return substr($abs, strlen($base));
    }

    return $abs;
}

function resolveTemplatePath(string $basePath, string $view): ?string
{
    if (preg_match('/^@([a-z0-9_]+)\/(.+)$/i', $view, $m)) {
        $ns = $m[1];
        $rest = $m[2];
        $modules = $basePath . '/modules';
        foreach (scandir($modules) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strtolower($entry) !== strtolower($ns)) {
                continue;
            }
            $path = $modules . '/' . $entry . '/Resources/views/' . $rest;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    $path = $basePath . '/resources/views/' . ltrim($view, '/');

    return is_file($path) ? $path : null;
}

/**
 * @return array<string, array{class: string, array: bool, shape?: array<string, string>}>
 */
function collectVarDocs(string $code, array $ast, NodeFinder $finder): array
{
    $uses = collectUses($ast, $finder);

    $docs = [];
    if (preg_match_all('/@var\s+(\S+(?:\{[^}]*\})?(?:\[\])?)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $parsed = parsePhpDocType($m[1], $uses);
            if ($parsed !== null) {
                $docs[$m[2]] = $parsed;
            }
        }
    }

    return $docs;
}

/**
 * @return array<string, array{class: string, array: bool, shape?: array<string, string>}>
 */
function collectMethodReturns(string $code, array $ast, NodeFinder $finder): array
{
    $uses = collectUses($ast, $finder);
    $returns = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        /** @var Node\Stmt\ClassMethod $method */
        $doc = $method->getDocComment()?->getText() ?? '';
        if ($doc === '' || !preg_match('/@return\s+(\S+(?:\{[^}]*\})?)/', $doc, $m)) {
            continue;
        }
        // rozšířit match pro list<array{...}>
        if (preg_match('/@return\s+(.+?)(?:\n|\*\/)/', $doc, $m2)) {
            $raw = trim(preg_replace('/\s+/', ' ', $m2[1]) ?? $m2[1]);
            $raw = rtrim($raw, ' */');
            $parsed = parsePhpDocType($raw, $uses);
            if ($parsed !== null) {
                $returns[$method->name->toString()] = $parsed;
            }
        }
    }

    return $returns;
}

/**
 * @return array<string, string>
 */
function collectUses(array $ast, NodeFinder $finder): array
{
    $uses = [];
    foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
        /** @var Node\Stmt\Use_ $use */
        foreach ($use->uses as $useUse) {
            $uses[$useUse->getAlias()->toString()] = $useUse->name->toString();
        }
    }

    return $uses;
}

/**
 * @param array<string, string> $uses
 * @return array{class: string, array: bool, shape?: array<string, string>}|null
 */
function parsePhpDocType(string $raw, array $uses): ?array
{
    $raw = trim($raw);
    $isArray = false;
    /** @var array<string, string>|null $shape */
    $shape = null;

    // list<array{base: string, ...}> / array<int, array{...}>
    if (preg_match('/^(?:list|array)<(?:[^,<]+,\s*)?(array\{[^}]+\})>$/', $raw, $m)) {
        $shape = parseArrayShape($m[1]);
        return [
            'class' => 'array',
            'array' => true,
            'shape' => $shape,
        ];
    }

    if (preg_match('/^(?:list|array)<([^>]+)>$/', $raw, $m)) {
        $inner = $m[1];
        if (str_contains($inner, ',')) {
            $parts = array_map('trim', explode(',', $inner));
            $raw = (string) end($parts);
        } else {
            $raw = trim($inner);
        }
        $isArray = true;
    } elseif (str_ends_with($raw, '[]')) {
        $raw = substr($raw, 0, -2);
        $isArray = true;
    }

    if (str_starts_with($raw, 'array{')) {
        $shape = parseArrayShape($raw);

        return [
            'class' => 'array',
            'array' => $isArray,
            'shape' => $shape,
        ];
    }

    if (isScalarClass($raw)) {
        return ['class' => $raw, 'array' => $isArray];
    }

    $raw = ltrim($raw, '\\');
    if (!str_contains($raw, '\\') && isset($uses[$raw])) {
        $raw = $uses[$raw];
    }

    if ($raw === '' || !preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $raw)) {
        return null;
    }

    return ['class' => $raw, 'array' => $isArray];
}

/**
 * @return array<string, string>
 */
function parseArrayShape(string $raw): array
{
    $raw = trim($raw);
    if (str_starts_with($raw, 'array{')) {
        $raw = substr($raw, 6, -1);
    }
    $shape = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([A-Za-z_][A-Za-z0-9_\\\\|]*)/', $part, $m)) {
            $shape[$m[1]] = $m[2];
        }
    }

    return $shape;
}

/**
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $docTypes
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $methodReturns
 * @return array<string, array{class: string, array: bool, shape?: array<string, string>}>
 */
function extractArrayKeys(Node\Expr $expr, array $docTypes, array $methodReturns = []): array
{
    $out = [];

    if ($expr instanceof Node\Expr\Array_) {
        foreach ($expr->items as $item) {
            if ($item === null || !$item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $type = inferExprType($item->value, $docTypes, $methodReturns);
            if ($type !== null) {
                $out[$item->key->value] = $type;
            }
        }

        return $out;
    }

    if ($expr instanceof Node\Expr\FuncCall
        && $expr->name instanceof Node\Name
        && $expr->name->toString() === 'array_merge'
    ) {
        foreach ($expr->args as $arg) {
            foreach (extractArrayKeys($arg->value, $docTypes, $methodReturns) as $k => $v) {
                $out[$k] = $v;
            }
        }
    }

    return $out;
}

/**
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $docTypes
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $methodReturns
 * @return array{class: string, array: bool, shape?: array<string, string>}|null
 */
function inferExprType(Node\Expr $expr, array $docTypes, array $methodReturns = []): ?array
{
    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
        return $docTypes[$expr->name] ?? (
            // heuristika: $search, $q → string
            in_array($expr->name, ['search', 'q', 'query', 'pageTitle', 'title'], true)
                ? ['class' => 'string', 'array' => false]
                : null
        );
    }
    if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
        return ['class' => $expr->class->toString(), 'array' => false];
    }
    if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
        $method = $expr->name->toString();
        if ($method === 'user') {
            return ['class' => 'Gereq\\User\\Entity\\User', 'array' => false];
        }
        // $this->latestCnbRates()
        if (
            $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && isset($methodReturns[$method])
        ) {
            return $methodReturns[$method];
        }
    }
    if ($expr instanceof Node\Scalar\String_) {
        return ['class' => 'string', 'array' => false];
    }

    return null;
}
