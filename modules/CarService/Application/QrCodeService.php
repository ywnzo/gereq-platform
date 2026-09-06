<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Gereq\Config\Repository as Config;
use Gereq\Http\Router;

final class QrCodeService
{
    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
    ) {}

    public function trackerUrl(string $trackerToken): string
    {
        $path = $this->router->url('admin.carservice.tracker');
        $sep = str_contains($path, '?') ? '&' : '?';
        $relative = $path . $sep . 'token=' . rawurlencode($trackerToken);
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($base === '') {
            return $relative;
        }
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return $base . (str_starts_with($relative, '/') ? $relative : '/' . $relative);
    }

    public function svgForOrder(string $trackerToken, int $size = 220): string
    {
        return $this->svg($this->trackerUrl($trackerToken), $size);
    }

    public function dataUriForOrder(string $trackerToken, int $size = 220): string
    {
        return $this->dataUri($this->trackerUrl($trackerToken), $size);
    }

    public function svg(string $payload, int $size = 220): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);

        return $writer->writeString($payload);
    }

    public function dataUri(string $payload, int $size = 220): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->svg($payload, $size));
    }
}
