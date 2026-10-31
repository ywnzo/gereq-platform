<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application;

use Doctrine\ORM\Event\PostRemoveEventArgs;
use Gereq\Cms\Cms;
use Modules\SiteInquiries\Domain\Inquiry;

/** Post-remove runs inside Doctrine's delete transaction, including bulk removal. */
final class InquiryAttachmentCleanup
{
    public function __construct(private readonly Cms $cms)
    {
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $inquiry = $event->getObject();
        if ($inquiry instanceof Inquiry) {
            $this->cms->removeRecordAttachments('site_inquiries.create', $inquiry->id());
        }
    }
}
