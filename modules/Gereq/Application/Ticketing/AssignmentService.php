<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Ticketing;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Gereq\Domain\Ticket;
use Modules\Gereq\Domain\TicketSite;

final class AssignmentService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function assign(Ticket $ticket, TicketSite $site): void
    {
        $strategy = $site->assignmentStrategy();
        if ($strategy === 'specific' && $site->defaultAssigneeId()) {
            $ticket->setAssignedUserId($site->defaultAssigneeId());

            return;
        }
        if ($strategy === 'least_busy' && $site->defaultAssigneeId()) {
            // Without agent presence metrics, fall back to default assignee.
            $ticket->setAssignedUserId($site->defaultAssigneeId());

            return;
        }
        // unassigned — leave null
    }
}
