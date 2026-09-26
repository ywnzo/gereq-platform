<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Facade for discovering and running GEREQ test targets.
 *
 * Targets:
 *   all | core | file | modules | integrations | module:<id> | <module-id>
 */
final class TestEngine
{
    private readonly string $basePath;
    private readonly TestCatalog $catalog;
    private readonly PhpUnitRunner $runner;

    public function __construct(
        string $basePath,
        ?TestCatalog $catalog = null,
        ?PhpUnitRunner $runner = null,
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->catalog = $catalog ?? new TestCatalog($this->basePath);
        $this->runner = $runner ?? new PhpUnitRunner($this->basePath);
    }

    public function catalog(): TestCatalog
    {
        return $this->catalog;
    }

    public function resolve(string $query): TestTarget
    {
        return $this->catalog->resolve($query);
    }

    /**
     * @return list<TestTarget>
     */
    public function listTargets(bool $withTestsOnly = false): array
    {
        $targets = $this->catalog->listable();
        if (!$withTestsOnly) {
            return $targets;
        }

        return array_values(array_filter(
            $targets,
            static fn (TestTarget $t): bool => $t->hasTests(),
        ));
    }

    public function run(string $query, ?TestRunOptions $options = null): TestRunResult
    {
        $options ??= new TestRunOptions();
        $target = $this->catalog->resolve($query);
        $paths = $this->catalog->phpUnitPaths($target);

        if ($paths === []) {
            throw new \InvalidArgumentException(sprintf(
                'Target "%s" (%s) has no test directories. Add tests under the expected paths first.',
                $target->id,
                $target->label,
            ));
        }

        return $this->runner->run($paths, $target->id, $options);
    }
}
