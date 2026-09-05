<?php
declare(strict_types=1);
namespace Modules\Projects\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'project_members')]
#[ORM\UniqueConstraint(name: 'uniq_project_member', columns: ['project_id', 'user_id'])]
class ProjectMember
{
    public const ROLES = ['owner', 'member'];
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(name: 'project_id', length: 36)] private string $projectId;
    #[ORM\Column(name: 'user_id', length: 36)] private string $userId;
    #[ORM\Column(length: 32)] private string $role = 'member';

    public function __construct(string $projectId, string $userId, string $role = 'member') {
        if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('Invalid role');
        $this->id = Uuid::uuid7()->toString();
        $this->projectId = $projectId;
        $this->userId = $userId;
        $this->role = $role;
    }
    public function id(): string { return $this->id; }
    public function projectId(): string { return $this->projectId; }
    public function userId(): string { return $this->userId; }
    public function role(): string { return $this->role; }
    public function setRole(string $role): void {
        if (!in_array($role, self::ROLES, true)) throw new \InvalidArgumentException('Invalid role');
        $this->role = $role;
    }
}