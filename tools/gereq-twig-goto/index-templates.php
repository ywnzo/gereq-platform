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

/** @var array<string, array<string, array{class: string, array: bool, shape?: array<string, string>}>> $index */
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

    $uses = collectUses($ast, $finder);
    $docTypes = collectVarDocs($code, $ast, $finder);
    $methodReturns = collectMethodReturns($code, $ast, $finder);
    $propTypes = collectPropertyTypes($ast, $finder);
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

        $method = findEnclosingMethod($ast, $call, $finder);
        $locals = $docTypes;
        $qbEntities = [];
        if ($method !== null) {
            [$locals, $qbEntities] = collectLocalTypes(
                $method,
                $docTypes,
                $methodReturns,
                $propTypes,
                $uses,
            );
        }

        $ctx = [
            'locals' => $locals,
            'methodReturns' => $methodReturns,
            'propTypes' => $propTypes,
            'qbEntities' => $qbEntities,
            'uses' => $uses,
        ];

        $vars = $globals;
        if (isset($call->args[1])) {
            foreach (extractArrayKeys($call->args[1]->value, $ctx) as $name => $type) {
                $vars[$name] = $type;
            }
        }
        if ($call->name->toString() === 'page') {
            $vars = mergeTemplateVars($vars, $globals);
        }

        $rel = relativePath($basePath, $template);
        $index[$rel] = mergeTemplateVars($index[$rel] ?? [], $vars);
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
        if (isScalarClass($fqcn) || isset($classes[$fqcn]) || str_starts_with($fqcn, 'array{')) {
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
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $vars
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
        $name = $method->name->toString();

        $doc = $method->getDocComment()?->getText() ?? '';
        if ($doc !== '' && preg_match('/@return\s+(.+?)(?:\n|\*\/)/', $doc, $m2)) {
            $raw = trim(preg_replace('/\s+/', ' ', $m2[1]) ?? $m2[1]);
            $raw = rtrim($raw, ' */');
            $parsed = parsePhpDocType($raw, $uses);
            if ($parsed !== null) {
                $returns[$name] = $parsed;
                continue;
            }
        }

        if ($method->returnType !== null) {
            $parsed = typeNodeToType($method->returnType, $uses);
            if ($parsed !== null) {
                $returns[$name] = $parsed;
            }
        }
    }

    return $returns;
}

/**
 * Promoted ctor props + typed properties → $this->foo typy.
 *
 * @return array<string, array{class: string, array: bool}>
 */
function collectPropertyTypes(array $ast, NodeFinder $finder): array
{
    $uses = collectUses($ast, $finder);
    $props = [];

    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        /** @var Node\Stmt\ClassMethod $method */
        if ($method->name->toString() !== '__construct') {
            continue;
        }
        foreach ($method->params as $param) {
            if ($param->flags === 0 || $param->var->name === null || !is_string($param->var->name)) {
                continue;
            }
            if ($param->type === null) {
                continue;
            }
            $parsed = typeNodeToType($param->type, $uses);
            if ($parsed !== null) {
                $props[$param->var->name] = $parsed;
            }
        }
    }

    foreach ($finder->findInstanceOf($ast, Node\Stmt\Property::class) as $prop) {
        /** @var Node\Stmt\Property $prop */
        if ($prop->type === null) {
            continue;
        }
        $parsed = typeNodeToType($prop->type, $uses);
        if ($parsed === null) {
            continue;
        }
        foreach ($prop->props as $p) {
            $props[$p->name->toString()] = $parsed;
        }
    }

    return $props;
}

/**
 * @param array<string, string> $uses
 * @return array{class: string, array: bool}|null
 */
function typeNodeToType(Node $type, array $uses): ?array
{
    if ($type instanceof Node\NullableType) {
        return typeNodeToType($type->type, $uses);
    }
    if ($type instanceof Node\UnionType) {
        foreach ($type->types as $part) {
            if ($part instanceof Node\Identifier && strtolower($part->toString()) === 'null') {
                continue;
            }
            $parsed = typeNodeToType($part, $uses);
            if ($parsed !== null && !isScalarClass($parsed['class'])) {
                return $parsed;
            }
        }
        foreach ($type->types as $part) {
            $parsed = typeNodeToType($part, $uses);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }
    if ($type instanceof Node\Identifier) {
        $name = $type->toString();
        if (isScalarClass($name)) {
            return ['class' => $name, 'array' => false];
        }

        return null;
    }
    if ($type instanceof Node\Name) {
        $raw = $type->toString();
        if (!$type->isFullyQualified() && !str_contains($raw, '\\') && isset($uses[$raw])) {
            $raw = $uses[$raw];
        }
        $raw = ltrim($raw, '\\');
        if ($raw === '' || isScalarClass($raw)) {
            return isScalarClass($raw) ? ['class' => $raw, 'array' => false] : null;
        }

        return ['class' => $raw, 'array' => false];
    }

    return null;
}

/**
 * Najdi ClassMethod obsahující $call podle pozice v AST souboru.
 */
function findEnclosingMethod(array $ast, Node\Expr\MethodCall $call, NodeFinder $finder): ?Node\Stmt\ClassMethod
{
    $callStart = $call->getStartFilePos();
    $callEnd = $call->getEndFilePos();
    if ($callStart < 0) {
        return null;
    }
    foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
        /** @var Node\Stmt\ClassMethod $method */
        $ms = $method->getStartFilePos();
        $me = $method->getEndFilePos();
        if ($ms <= $callStart && $callEnd <= $me) {
            return $method;
        }
    }

    return null;
}

/**
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $docTypes
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $methodReturns
 * @param array<string, array{class: string, array: bool}> $propTypes
 * @param array<string, string> $uses
 * @return array{0: array<string, array{class: string, array: bool, shape?: array<string, string>}>, 1: array<string, string>}
 */
function collectLocalTypes(
    Node\Stmt\ClassMethod $method,
    array $docTypes,
    array $methodReturns,
    array $propTypes,
    array $uses,
): array {
    $locals = $docTypes;
    $qbEntities = [];
    $ctx = [
        'locals' => &$locals,
        'methodReturns' => $methodReturns,
        'propTypes' => $propTypes,
        'qbEntities' => &$qbEntities,
        'uses' => $uses,
    ];

    $stmts = $method->stmts ?? [];
    foreach (flattenStatements($stmts) as $stmt) {
        if (!$stmt instanceof Node\Expr\Assign) {
            continue;
        }
        if (!$stmt->var instanceof Node\Expr\Variable || !is_string($stmt->var->name)) {
            continue;
        }
        $varName = $stmt->var->name;

        $repoEntity = extractRepositoryEntity($stmt->expr, $uses);
        if ($repoEntity !== null && isQueryBuilderLike($stmt->expr)) {
            $qbEntities[$varName] = $repoEntity;
        }

        // $items = $qb->...->getResult()
        $fromQb = entityFromGetResult($stmt->expr, $qbEntities, $uses);
        if ($fromQb !== null) {
            $locals[$varName] = ['class' => $fromQb, 'array' => true];
            continue;
        }

        // $x = $this->em->getRepository(Foo::class)->find* / findAll
        $fromRepo = entityFromRepositoryCall($stmt->expr, $uses);
        if ($fromRepo !== null) {
            $locals[$varName] = $fromRepo;
            continue;
        }

        // $x = $this->em->find(Foo::class, ...)
        $fromFind = entityFromEmFind($stmt->expr, $uses);
        if ($fromFind !== null) {
            $locals[$varName] = $fromFind;
            continue;
        }

        $type = inferExprType($stmt->expr, $ctx);
        if ($type !== null) {
            $locals[$varName] = $type;
        }
    }

    return [$locals, $qbEntities];
}

/**
 * @param list<Node\Stmt> $stmts
 * @return list<Node>
 */
function flattenStatements(array $stmts): array
{
    $out = [];
    foreach ($stmts as $stmt) {
        $out[] = $stmt;
        if ($stmt instanceof Node\Stmt\Expression) {
            $out[] = $stmt->expr;
        }
        if ($stmt instanceof Node\Stmt\If_) {
            $out = array_merge($out, flattenStatements($stmt->stmts));
            foreach ($stmt->elseifs as $elseif) {
                $out = array_merge($out, flattenStatements($elseif->stmts));
            }
            if ($stmt->else !== null) {
                $out = array_merge($out, flattenStatements($stmt->else->stmts));
            }
        }
        if ($stmt instanceof Node\Stmt\Foreach_) {
            $out = array_merge($out, flattenStatements($stmt->stmts));
        }
        if ($stmt instanceof Node\Stmt\TryCatch) {
            $out = array_merge($out, flattenStatements($stmt->stmts));
            foreach ($stmt->catches as $catch) {
                $out = array_merge($out, flattenStatements($catch->stmts));
            }
        }
    }

    return $out;
}

/**
 * @param array<string, string> $uses
 */
function resolveNameNode(Node\Expr|Node\Name $name, array $uses): ?string
{
    if ($name instanceof Node\Expr\ClassConstFetch) {
        if (!$name->class instanceof Node\Name) {
            return null;
        }
        if (!$name->name instanceof Node\Identifier || strtolower($name->name->toString()) !== 'class') {
            return null;
        }
        $raw = $name->class->toString();
        if (!$name->class->isFullyQualified() && !str_contains($raw, '\\') && isset($uses[$raw])) {
            $raw = $uses[$raw];
        }

        return ltrim($raw, '\\');
    }
    if ($name instanceof Node\Name) {
        $raw = $name->toString();
        if (!$name->isFullyQualified() && !str_contains($raw, '\\') && isset($uses[$raw])) {
            $raw = $uses[$raw];
        }

        return ltrim($raw, '\\');
    }

    return null;
}

/**
 * @param array<string, string> $uses
 */
function extractRepositoryEntity(Node\Expr $expr, array $uses): ?string
{
    $cur = $expr;
    while ($cur instanceof Node\Expr\MethodCall) {
        if ($cur->name instanceof Node\Identifier && $cur->name->toString() === 'getRepository') {
            if (isset($cur->args[0])) {
                return resolveNameNode($cur->args[0]->value, $uses);
            }

            return null;
        }
        $cur = $cur->var;
    }

    return null;
}

function isQueryBuilderLike(Node\Expr $expr): bool
{
    $cur = $expr;
    while ($cur instanceof Node\Expr\MethodCall) {
        if ($cur->name instanceof Node\Identifier) {
            $n = $cur->name->toString();
            if (in_array($n, ['createQueryBuilder', 'createQuery'], true)) {
                return true;
            }
        }
        $cur = $cur->var;
    }

    return false;
}

/**
 * @param array<string, string> $qbEntities
 * @param array<string, string> $uses
 */
function entityFromGetResult(Node\Expr $expr, array $qbEntities, array $uses = []): ?string
{
    if (!$expr instanceof Node\Expr\MethodCall || !$expr->name instanceof Node\Identifier) {
        return null;
    }
    $name = $expr->name->toString();

    // $qb->...->getQuery()->getResult() / getOneOrNullResult()
    if (
        in_array($name, ['getResult', 'getArrayResult', 'getOneOrNullResult', 'getSingleResult', 'execute'], true)
        && $expr->var instanceof Node\Expr\MethodCall
        && $expr->var->name instanceof Node\Identifier
        && $expr->var->name->toString() === 'getQuery'
    ) {
        $expr = $expr->var; // continue unwrap from getQuery()
    } elseif (!in_array($name, ['getResult', 'getArrayResult', 'execute'], true)) {
        return null;
    }

    // unwrap chain to root variable
    $cur = $expr instanceof Node\Expr\MethodCall ? $expr->var : $expr;
    while ($cur instanceof Node\Expr\MethodCall) {
        $cur = $cur->var;
    }
    if ($cur instanceof Node\Expr\Variable && is_string($cur->name) && isset($qbEntities[$cur->name])) {
        return $qbEntities[$cur->name];
    }

    return extractRepositoryEntity($expr, $uses);
}

/**
 * @param array<string, string> $uses
 * @return array{class: string, array: bool}|null
 */
function entityFromRepositoryCall(Node\Expr $expr, array $uses): ?array
{
    if (!$expr instanceof Node\Expr\MethodCall || !$expr->name instanceof Node\Identifier) {
        return null;
    }
    $method = $expr->name->toString();
    $single = ['find', 'findOneBy', 'getReference', 'getPartialReference'];
    $many = ['findBy', 'findAll', 'matching'];
    if (!in_array($method, [...$single, ...$many], true)) {
        return null;
    }
    $entity = extractRepositoryEntity($expr, $uses);
    if ($entity === null) {
        return null;
    }

    return ['class' => $entity, 'array' => in_array($method, $many, true)];
}

/**
 * @param array<string, string> $uses
 * @return array{class: string, array: bool}|null
 */
function entityFromEmFind(Node\Expr $expr, array $uses): ?array
{
    if (!$expr instanceof Node\Expr\MethodCall || !$expr->name instanceof Node\Identifier) {
        return null;
    }
    if ($expr->name->toString() !== 'find' || !isset($expr->args[0])) {
        return null;
    }
    // $this->em->find(Foo::class, $id)
    if (!$expr->var instanceof Node\Expr\PropertyFetch) {
        return null;
    }
    $entity = resolveNameNode($expr->args[0]->value, $uses);
    if ($entity === null) {
        return null;
    }

    return ['class' => $entity, 'array' => false];
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
 * @param array{
 *   locals: array<string, array{class: string, array: bool, shape?: array<string, string>}>,
 *   methodReturns: array<string, array{class: string, array: bool, shape?: array<string, string>}>,
 *   propTypes: array<string, array{class: string, array: bool}>,
 *   qbEntities: array<string, string>,
 *   uses: array<string, string>
 * } $ctx
 * @return array<string, array{class: string, array: bool, shape?: array<string, string>}>
 */
function extractArrayKeys(Node\Expr $expr, array $ctx): array
{
    $out = [];

    if ($expr instanceof Node\Expr\Array_) {
        foreach ($expr->items as $item) {
            if ($item === null || !$item->key instanceof Node\Scalar\String_) {
                continue;
            }
            $type = inferExprType($item->value, $ctx);
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
            foreach (extractArrayKeys($arg->value, $ctx) as $k => $v) {
                $out[$k] = $v;
            }
        }
    }

    return $out;
}

/**
 * @param array{
 *   locals: array<string, array{class: string, array: bool, shape?: array<string, string>}>,
 *   methodReturns: array<string, array{class: string, array: bool, shape?: array<string, string>}>,
 *   propTypes: array<string, array{class: string, array: bool}>,
 *   qbEntities: array<string, string>,
 *   uses: array<string, string>
 * } $ctx
 * @return array{class: string, array: bool, shape?: array<string, string>}|null
 */
function inferExprType(Node\Expr $expr, array $ctx): ?array
{
    $locals = $ctx['locals'];
    $methodReturns = $ctx['methodReturns'];
    $propTypes = $ctx['propTypes'];
    $uses = $ctx['uses'];

    if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
        return $locals[$expr->name] ?? (
            in_array($expr->name, ['search', 'q', 'query', 'pageTitle', 'title'], true)
                ? ['class' => 'string', 'array' => false]
                : null
        );
    }
    if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
        $raw = $expr->class->toString();
        if (!$expr->class->isFullyQualified() && !str_contains($raw, '\\') && isset($uses[$raw])) {
            $raw = $uses[$raw];
        }

        return ['class' => ltrim($raw, '\\'), 'array' => false];
    }
    if ($expr instanceof Node\Expr\Ternary) {
        // $x instanceof Foo ? $x : null  — jen když if-větev je stejná proměnná
        if (
            $expr->cond instanceof Node\Expr\Instanceof_
            && $expr->cond->class instanceof Node\Name
            && $expr->cond->expr instanceof Node\Expr\Variable
            && is_string($expr->cond->expr->name)
            && $expr->if instanceof Node\Expr\Variable
            && $expr->if->name === $expr->cond->expr->name
        ) {
            $raw = $expr->cond->class->toString();
            if (!$expr->cond->class->isFullyQualified() && !str_contains($raw, '\\') && isset($uses[$raw])) {
                $raw = $uses[$raw];
            }

            return ['class' => ltrim($raw, '\\'), 'array' => false];
        }
        $ifType = $expr->if !== null ? inferExprType($expr->if, $ctx) : null;
        if ($ifType !== null && !isScalarClass($ifType['class'])) {
            return $ifType;
        }
        $elseType = inferExprType($expr->else, $ctx);
        if ($elseType !== null && !isScalarClass($elseType['class'])) {
            return $elseType;
        }

        return $ifType ?? $elseType;
    }
    if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
        return inferExprType($expr->left, $ctx) ?? inferExprType($expr->right, $ctx);
    }
    if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
        $method = $expr->name->toString();
        if ($method === 'user') {
            return ['class' => 'Gereq\\User\\Entity\\User', 'array' => false];
        }

        $fromRepo = entityFromRepositoryCall($expr, $uses);
        if ($fromRepo !== null) {
            return $fromRepo;
        }
        $fromFind = entityFromEmFind($expr, $uses);
        if ($fromFind !== null) {
            return $fromFind;
        }
        $fromQb = entityFromGetResult($expr, $ctx['qbEntities'], $uses);
        if ($fromQb !== null) {
            return ['class' => $fromQb, 'array' => true];
        }

        // $this->latestCnbRates() / private helpers in same class
        if (
            $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && isset($methodReturns[$method])
        ) {
            return $methodReturns[$method];
        }

        // $this->bikeservice->getBike($id) — property + reflection
        if ($expr->var instanceof Node\Expr\PropertyFetch
            && $expr->var->var instanceof Node\Expr\Variable
            && $expr->var->var->name === 'this'
            && $expr->var->name instanceof Node\Identifier
        ) {
            $prop = $expr->var->name->toString();
            if (isset($propTypes[$prop])) {
                $reflected = reflectMethodReturn($propTypes[$prop]['class'], $method);
                if ($reflected !== null) {
                    return $reflected;
                }
            }
        }

        // $customer->email() — metoda na lokální typované proměnné
        if ($expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
            $obj = $locals[$expr->var->name] ?? null;
            if ($obj !== null && !isScalarClass($obj['class']) && empty($obj['array'])) {
                $reflected = reflectMethodReturn($obj['class'], $method);
                if ($reflected !== null) {
                    return $reflected;
                }
            }
        }

        // $this->orderForBranchMutate($id) — native/@return na metodě ve stejné třídě
        if ($expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this') {
            if (isset($methodReturns[$method])) {
                return $methodReturns[$method];
            }
        }
    }
    if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
        // Foo::STATUSES / KINDS — typicky list stringů
        if ($expr->name instanceof Node\Identifier) {
            $const = $expr->name->toString();
            if (in_array($const, ['STATUSES', 'KINDS', 'TYPES', 'TEAMS', 'PAYMENT_TYPES', 'FUEL_LEVELS'], true)) {
                return ['class' => 'string', 'array' => true];
            }
        }
    }
    if ($expr instanceof Node\Scalar\String_) {
        return ['class' => 'string', 'array' => false];
    }
    if ($expr instanceof Node\Scalar\LNumber || $expr instanceof Node\Scalar\DNumber) {
        return ['class' => $expr instanceof Node\Scalar\LNumber ? 'int' : 'float', 'array' => false];
    }
    if ($expr instanceof Node\Expr\ConstFetch && $expr->name instanceof Node\Name) {
        $n = strtolower($expr->name->toString());
        if ($n === 'true' || $n === 'false') {
            return ['class' => 'bool', 'array' => false];
        }
        if ($n === 'null') {
            return null;
        }
    }

    return null;
}

/**
 * @return array{class: string, array: bool, shape?: array<string, string>}|null
 */
function reflectMethodReturn(?string $fqcn, string $method): ?array
{
    if ($fqcn === null || $fqcn === '' || isScalarClass($fqcn)) {
        return null;
    }
    $fqcn = ltrim($fqcn, '\\');
    try {
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            return null;
        }
        $ref = new ReflectionClass($fqcn);
        if (!$ref->hasMethod($method)) {
            return null;
        }
        $m = $ref->getMethod($method);
        $nsUses = reflectionFileUses($m->getFileName() ?: null, $ref->getNamespaceName());

        $doc = $m->getDocComment() ?: '';
        if ($doc !== '' && preg_match('/@return\s+(.+?)(?:\n|\*\/)/', $doc, $m2)) {
            $raw = trim(preg_replace('/\s+/', ' ', $m2[1]) ?? $m2[1]);
            $raw = rtrim($raw, ' */');
            $parsed = parsePhpDocType($raw, $nsUses);
            if ($parsed !== null) {
                return qualifyType($parsed, $ref->getNamespaceName());
            }
        }
        if ($m->hasReturnType()) {
            $rt = $m->getReturnType();
            if ($rt instanceof ReflectionNamedType) {
                $name = $rt->getName();
                if ($name === 'void' || $name === 'null') {
                    return null;
                }
                if ($name === 'array') {
                    return null; // bez generics neuhádneme
                }
                if (in_array($name, ['string', 'int', 'float', 'bool', 'mixed', 'iterable', 'object', 'callable'], true)) {
                    return ['class' => $name, 'array' => false];
                }

                return ['class' => ltrim($name, '\\'), 'array' => false];
            }
            if ($rt instanceof ReflectionUnionType) {
                foreach ($rt->getTypes() as $part) {
                    if (!$part instanceof ReflectionNamedType || $part->getName() === 'null') {
                        continue;
                    }
                    $name = $part->getName();
                    if ($name === 'array' || isScalarClass($name)) {
                        continue;
                    }

                    return ['class' => ltrim($name, '\\'), 'array' => false];
                }
            }
        }
    } catch (\Throwable) {
        return null;
    }

    return null;
}

/**
 * @return array<string, string>
 */
function reflectionFileUses(?string $file, string $namespace): array
{
    $uses = [];
    if ($file === null || !is_file($file)) {
        return $uses;
    }
    $code = file_get_contents($file);
    if ($code === false) {
        return $uses;
    }
    if (preg_match_all('/^use\s+([^;]+);/m', $code, $matches)) {
        foreach ($matches[1] as $use) {
            $use = trim($use);
            if (str_contains($use, '{') || str_starts_with($use, 'function ') || str_starts_with($use, 'const ')) {
                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/', $use, $m)) {
                $uses[$m[2]] = ltrim($m[1], '\\');
            } else {
                $parts = explode('\\', $use);
                $uses[end($parts)] = ltrim($use, '\\');
            }
        }
    }
    // same-namespace short names resolve later via qualifyType
    $uses['__NAMESPACE__'] = $namespace;

    return $uses;
}

/**
 * @param array{class: string, array: bool, shape?: array<string, string>} $type
 * @return array{class: string, array: bool, shape?: array<string, string>}
 */
function qualifyType(array $type, string $namespace): array
{
    $class = $type['class'];
    if (isScalarClass($class) || str_contains($class, '\\') || str_starts_with($class, 'array{')) {
        return $type;
    }
    $candidate = $namespace !== '' ? $namespace . '\\' . $class : $class;
    if (class_exists($candidate) || interface_exists($candidate)) {
        $type['class'] = $candidate;
    }

    return $type;
}

/**
 * Preferuj konkrétní class typ před chybějícím / skalárem při merge více page() volání.
 *
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $base
 * @param array<string, array{class: string, array: bool, shape?: array<string, string>}> $overlay
 * @return array<string, array{class: string, array: bool, shape?: array<string, string>}>
 */
function mergeTemplateVars(array $base, array $overlay): array
{
    foreach ($overlay as $name => $type) {
        if (!isset($base[$name])) {
            $base[$name] = $type;
            continue;
        }
        $base[$name] = preferType($base[$name], $type);
    }

    return $base;
}

/**
 * @param array{class: string, array: bool, shape?: array<string, string>} $a
 * @param array{class: string, array: bool, shape?: array<string, string>} $b
 * @return array{class: string, array: bool, shape?: array<string, string>}
 */
function preferType(array $a, array $b): array
{
    $score = static function (array $t): int {
        $s = 0;
        if (!isScalarClass($t['class']) && !str_starts_with($t['class'], 'array{')) {
            $s += 10;
        }
        if (!empty($t['shape'])) {
            $s += 5;
        }
        if (!empty($t['array'])) {
            $s += 1;
        }

        return $s;
    };

    return $score($b) >= $score($a) ? $b : $a;
}
