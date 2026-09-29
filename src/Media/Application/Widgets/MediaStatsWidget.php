<?php

declare(strict_types=1);

namespace Gereq\Media\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Media\Domain\MediaAsset;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** File count in the library and storage used. */
final class MediaStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'media.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('media.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'media.view';
    }

    public function order(): int
    {
        return 56;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $row = $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m')
            ->select('COUNT(m.id) AS cnt, COALESCE(SUM(m.size), 0) AS bytes')
            ->getQuery()
            ->getSingleResult();

        $images = (int) $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.mime LIKE :img')
            ->setParameter('img', 'image/%')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('media.widget.stats.title'),
            'value' => (string) (int) ($row['cnt'] ?? 0),
            'hint' => $this->translator->trans('media.widget.stats.hint', [
                '%size%' => self::formatBytes((int) ($row['bytes'] ?? 0)),
                '%images%' => (string) $images,
            ]),
            'href' => $this->router->url('admin.media.index'),
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['kB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $value >= 100 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
    }
}
