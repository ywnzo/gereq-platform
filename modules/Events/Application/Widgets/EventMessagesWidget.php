<?php

declare(strict_types=1);

namespace Modules\Events\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Events\Domain\EventContact;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Latest messages from the public contact form. */
final class EventMessagesWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

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
        return 'events.messages';
    }

    public function title(): string
    {
        return $this->translator->trans('events.widget.messages.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'events.view';
    }

    public function order(): int
    {
        return 20;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        /** @var list<EventContact> $messages */
        $messages = $this->em->getRepository(EventContact::class)->findBy(
            [],
            ['createdAt' => 'DESC'],
            self::LIMIT,
        );

        $items = [];
        foreach ($messages as $message) {
            $excerpt = trim($message->message());
            if (mb_strlen($excerpt) > 80) {
                $excerpt = rtrim(mb_substr($excerpt, 0, 77)) . '…';
            }
            $items[] = [
                'title' => $message->name(),
                'meta' => $message->email() . ($excerpt !== '' ? ' · ' . $excerpt : ''),
                'icon' => 'mail',
                'href' => $this->router->url('admin.events.messages'),
                'aside' => $message->createdAt()->format('j. n.'),
                'aside_hint' => $message->createdAt()->format('H:i'),
                'badge' => [
                    'label' => $this->translator->trans('events.contacts.topic.' . $message->topic()),
                    'tone' => $message->topic() === 'data' ? 'warn' : 'info',
                ],
            ];
        }

        return [
            'title' => $this->translator->trans('events.widget.messages.title'),
            'subtitle' => $this->translator->trans('events.widget.messages.subtitle'),
            'href' => $this->router->url('admin.events.messages'),
            'link_label' => $this->translator->trans('events.contacts.title'),
            'items' => $items,
            'empty_message' => $this->translator->trans('events.widget.messages.empty'),
        ];
    }
}
