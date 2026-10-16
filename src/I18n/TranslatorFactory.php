<?php

declare(strict_types=1);

namespace Gereq\I18n;

use Gereq\Config\Repository;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TranslatorFactory
{
    public function __construct(
        private readonly string $basePath,
        private readonly LocaleCatalog $catalog,
        private readonly Repository $config,
    ) {
    }

    public function create(): TranslatorInterface
    {
        $configured = (string) $this->config->get('app.locale', $this->catalog->fallback());
        $locale = $this->catalog->isSupported($configured) ? $configured : $this->catalog->fallback();
        $translator = new Translator($locale);
        $translator->setFallbackLocales([$this->catalog->fallback()]);
        $translator->addLoader('php', new PhpFileLoader());

        $this->loadDirectory($translator, $this->basePath . '/resources/translations');

        foreach (\Gereq\Module\ModuleDiscovery::discover($this->basePath) as $manifest) {
            $dir = $manifest->path . '/Resources/translations';
            if (is_dir($dir)) {
                $this->loadDirectory($translator, $dir);
            }
        }

        return $translator;
    }

    private function loadDirectory(Translator $translator, string $directory): void
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            // domain.locale.php → messages.cs.php
            if (!preg_match('/^([a-z0-9_]+)\.([a-z]{2}(?:_[A-Z]{2})?)$/', $basename, $matches)) {
                continue;
            }

            $translator->addResource('php', $file, $matches[2], $matches[1]);
        }
    }
}
