<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Invokes vendor/bin/phpunit for a resolved target.
 */
final class PhpUnitRunner
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * @param list<string> $paths Absolute test directories/files.
     */
    public function run(array $paths, string $targetId, TestRunOptions $options): TestRunResult
    {
        if ($paths === []) {
            throw new \InvalidArgumentException(sprintf(
                'Target "%s" has no test paths to run.',
                $targetId,
            ));
        }

        $command = $this->baseCommand($options);
        foreach ($paths as $path) {
            $command[] = $path;
        }

        return $this->execute($command, $targetId);
    }

    /**
     * @return list<string>
     */
    private function baseCommand(TestRunOptions $options): array
    {
        $command = [
            PHP_BINARY,
            $this->phpUnitBinary(),
            '--configuration',
            $this->basePath . '/phpunit.xml',
            '--colors=always',
        ];

        if ($options->filter !== null && $options->filter !== '') {
            $command[] = '--filter';
            $command[] = $options->filter;
        }
        if ($options->stopOnFailure) {
            $command[] = '--stop-on-failure';
        }
        if ($options->testdox) {
            $command[] = '--testdox';
        }
        foreach ($options->extraArgs as $arg) {
            $command[] = $arg;
        }

        return $command;
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, string $targetId): TestRunResult
    {
        $process = proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->basePath,
            null,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start PHPUnit process.');
        }

        return new TestRunResult(proc_close($process), $command, $targetId);
    }

    private function phpUnitBinary(): string
    {
        $candidates = [
            $this->basePath . '/vendor/bin/phpunit',
            $this->basePath . '/vendor/phpunit/phpunit/phpunit',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new \RuntimeException(
            'PHPUnit binary not found. Run composer install in the project root.',
        );
    }
}
