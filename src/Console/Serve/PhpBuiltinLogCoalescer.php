<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collapses consecutive identical access lines into one updating "Nx" counter.
 */
final class PhpBuiltinLogCoalescer
{
    private readonly PhpBuiltinLogFormatter $formatter;

    private ?string $signature = null;

    private ?string $baseFormatted = null;

    private int $count = 0;

    private bool $open = false;

    public function __construct(?PhpBuiltinLogFormatter $formatter = null)
    {
        $this->formatter = $formatter ?? new PhpBuiltinLogFormatter();
    }

    public function push(string $rawLine, OutputInterface $output): void
    {
        $entry = $this->formatter->parse($rawLine);
        if ($entry === null) {
            return;
        }
        if ($entry->noise) {
            return;
        }

        $formatted = $this->formatter->formatEntry($entry);
        if ($formatted === null) {
            return;
        }

        $signature = $entry->access
            ? $entry->method . '|' . $entry->status . '|' . $entry->path
            : null;

        if ($signature !== null && $signature === $this->signature) {
            ++$this->count;
            // Refresh base without count suffix for rewrite.
            $this->baseFormatted = $this->formatter->formatEntry($entry) ?? $formatted;
            $this->rewrite($output);

            return;
        }

        $this->finalize($output);
        $this->signature = $signature;
        $this->baseFormatted = $formatted;
        $this->count = 1;
        $this->open = true;

        if ($signature === null) {
            $output->writeln($formatted);
            $this->open = false;
            $this->signature = null;
            $this->baseFormatted = null;
            $this->count = 0;

            return;
        }

        $output->write($this->renderLine());
    }

    public function flush(OutputInterface $output): void
    {
        $this->finalize($output);
    }

    private function rewrite(OutputInterface $output): void
    {
        if (!$this->open || $this->baseFormatted === null) {
            return;
        }
        // Clear current line and rewrite with updated count.
        $output->write("\r\033[2K");
        $output->write($this->renderLine());
    }

    private function finalize(OutputInterface $output): void
    {
        if (!$this->open) {
            return;
        }
        $output->write("\n");
        $this->open = false;
        $this->signature = null;
        $this->baseFormatted = null;
        $this->count = 0;
    }

    private function renderLine(): string
    {
        $line = (string) $this->baseFormatted;
        if ($this->count > 1) {
            $line .= sprintf('  <fg=gray>%d×</>', $this->count);
        }

        return $line;
    }
}
