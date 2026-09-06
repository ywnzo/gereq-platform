<?php

declare(strict_types=1);

namespace Integrations\Packeta;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\Packeta\Application\PacketaCarrier;
use Integrations\Packeta\Http\PacketaApiController;
use Modules\Shop\Application\ShippingCarrierRegistry;

final class PacketaModule implements ModuleInterface
{
    public function id(): string
    {
        return 'packeta';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $carriers = $context->get(ShippingCarrierRegistry::class);
        $packeta = $context->get(PacketaCarrier::class);
        $carriers->register($packeta);
        $carriers->registerAlias('zasilkovna', $packeta);
        $context->capabilities()->register('shipping.carrier.packeta', $this->id(), $packeta);

        $context->settings()->define(
            'shop.packeta_api_password',
            SettingRecord::TYPE_STRING,
            'packeta.settings.api_password',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'shop.packeta_api_key',
            SettingRecord::TYPE_STRING,
            'packeta.settings.api_key',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'shop.packeta_eshop',
            SettingRecord::TYPE_STRING,
            'packeta.settings.eshop',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'shop.packeta_address_carrier_id',
            SettingRecord::TYPE_STRING,
            'packeta.settings.address_carrier_id',
            '106',
            $this->id(),
        );

        $context->router()->get(
            '/api/v1/shipping/packeta/widget',
            [PacketaApiController::class, 'widget'],
        )->name('api.shipping.packeta.widget');
    }

    public function boot(ModuleContext $context): void
    {
    }
}
