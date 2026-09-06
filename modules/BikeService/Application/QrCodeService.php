<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

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

    public function orderUrl(string $orderId): string
    {
        $relative = $this->router->url('admin.bikeservice.orders.show', ['id' => $orderId]);
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        if ($base === '' || str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return $base . (str_starts_with($relative, '/') ? $relative : '/' . $relative);
    }

    public function dataUriForOrder(string $orderId, int $size = 180): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd(),
        );
        $svg = (new Writer($renderer))->writeString($this->orderUrl($orderId));

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
