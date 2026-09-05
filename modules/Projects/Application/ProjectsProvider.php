<?php
declare(strict_types=1);
namespace Modules\Projects\Application;
use Doctrine\ORM\EntityManagerInterface;
use Modules\Projects\Domain\Project;

final class ProjectsProvider
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    /** @return list<Project> */
    public function forCustomer(string $customerId, int $limit = 10): array {
        return $this->em->getRepository(Project::class)->findBy(['customerId' => $customerId], ['updatedAt' => 'DESC'], $limit);
    }
}