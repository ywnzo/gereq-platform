<?php

declare(strict_types=1);

namespace Gereq\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony console app with GEREQ brand colors and list/version banner.
 */
final class GereqApplication extends Application
{
    public function getHelp(): string
    {
        return $this->getLongVersion();
    }

    public function getLongVersion(): string
    {
        return ConsoleBrand::banner($this->getVersion());
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        parent::configureIO($input, $output);

        $formatter = $output->getFormatter();
        $formatter->setStyle('info', new OutputFormatterStyle(ConsoleBrand::SIGNAL, null, ['bold']));
        $formatter->setStyle('comment', new OutputFormatterStyle(ConsoleBrand::MUTED));
        $formatter->setStyle('question', new OutputFormatterStyle(ConsoleBrand::SIGNAL, null, ['bold']));
        $formatter->setStyle('error', new OutputFormatterStyle('white', '#B91C1C', ['bold']));
    }
}
