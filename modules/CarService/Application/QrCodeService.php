<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCodeService
{
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
