<?php

declare(strict_types=1);

namespace Modules\Fleet\Http;

use Gereq\Http\Controller;
use Symfony\Component\HttpFoundation\Response;

/** Legacy URL — ORV je v Nastavení, katalog na /admin/fleet/brands. */
final class SettingsController extends Controller
{
    public function edit(): Response
    {
        return $this->redirect('admin.fleet.brands');
    }

    public function update(): Response
    {
        return $this->redirect('admin.settings');
    }
}
