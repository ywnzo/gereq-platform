<?php

declare(strict_types=1);

namespace Modules\Newsletter\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'newsletter_campaigns')]
#[ORM\Index(name: 'idx_newsletter_campaigns_status', columns: ['status'])]
#[FormEntity('newsletter.campaign.save')]
#[FormGenerator(
    exclude: ['id', 'status', 'sentAt', 'createdAt'],
    form: 'newsletter.campaign.save',
    cols: 1,
)]
class Campaign
{
    public const STATUSES = ['draft', 'sent'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'newsletter.field.subject', order: 10)]
    private string $subject;

    #[ORM\Column(name: 'body_html', type: 'text')]
    #[FormField(
        required: true,
        label: 'newsletter.field.body',
        type: 'editor',
        extra: 'field--full',
        order: 20,
    )]
    private string $bodyHtml;

    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    #[ORM\Column(name: 'sent_at', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $subject, string $bodyHtml)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->subject = $subject;
        $this->bodyHtml = $bodyHtml;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function bodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function setBodyHtml(string $bodyHtml): void
    {
        $this->bodyHtml = $bodyHtml;
    }

    public function markSent(): void
    {
        $this->status = 'sent';
        $this->sentAt = new \DateTimeImmutable();
    }

    public function setDraft(): void
    {
        $this->status = 'draft';
        $this->sentAt = null;
    }

    public function statusLabel(): string
    {
        return 'newsletter.campaign.status.' . $this->status;
    }
}
