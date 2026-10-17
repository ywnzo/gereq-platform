<?php

declare(strict_types=1);

namespace Gereq\Debug;

final class Renderer
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function render(array $payload, string $nonce): string
    {
        $css = $this->asset('bar.css');
        $js = $this->asset('bar.js');
        $json = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<div id="gereq-debug" class="gdb" hidden></div>'
            . '<style id="gereq-debug-css"' . $nonceAttr . '>' . $css . '</style>'
            . '<script type="application/json" id="gereq-debug-data"' . $nonceAttr . '>' . $json . '</script>'
            . '<script' . $nonceAttr . '>' . $js . '</script>';
    }

    private function asset(string $name): string
    {
        $path = $this->basePath . '/resources/icons/debug/' . $name;
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false ? '' : $contents;
    }
}
