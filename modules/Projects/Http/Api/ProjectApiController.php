<?php
declare(strict_types=1);
namespace Modules\Projects\Http\Api;
use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery; use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus; use Gereq\Http\Controller; use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Customers\Domain\Customer;
use Modules\Projects\Domain\Project; use Modules\Projects\Domain\ProjectTask;
use Symfony\Component\HttpFoundation\JsonResponse; use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack; use Twig\Environment;

final class ProjectApiController extends Controller
{
    public function __construct(Environment $twig, Router $router, RequestStack $requestStack, private readonly EntityManagerInterface $em, private readonly EventBus $events) {
        parent::__construct($twig, $router, $requestStack);
    }
    public function index(Request $request): JsonResponse {
        $query = ApiQuery::fromRequest($request, sortable: ['name','status','createdAt','updatedAt'], filterable: ['status','customer_id']);
        $qb = $this->em->getRepository(Project::class)->createQueryBuilder('p');
        if ($query->search !== '') $qb->andWhere('p.name LIKE :q')->setParameter('q', '%'.$query->search.'%');
        if (!empty($query->filters['status'])) $qb->andWhere('p.status = :s')->setParameter('s', $query->filters['status']);
        if (!empty($query->filters['customer_id'])) $qb->andWhere('p.customerId = :c')->setParameter('c', $query->filters['customer_id']);
        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('p.'.$sort, strtoupper($query->direction));
        $total = (int)(clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();
        return ApiResponse::collection(array_map(fn(Project $p)=>$this->ser($p), $items), $query->meta($total));
    }
    public function store(Request $request): JsonResponse {
        $data = gereq()->make(ValidationRegistry::class)->validate('projects.save', $this->body($request));
        $cid = trim((string)$data['customer_id']);
        if (!$this->em->find(Customer::class, $cid) instanceof Customer) return ApiResponse::error('validation_error','Invalid customer',[],422);
        $p = new Project($cid, trim((string)$data['name']));
        $p->setStatus((string)($data['status'] ?? 'planning'));
        $this->em->persist($p); $this->em->flush();
        $payload = $this->ser($p); $this->events->emit('project.created',['type'=>'project','id'=>$p->id()],$payload);
        return ApiResponse::data($payload, 201);
    }
    public function show(string $id): JsonResponse {
        $p = $this->em->find(Project::class, $id); if (!$p instanceof Project) return ApiResponse::notFound();
        $d = $this->ser($p);
        $d['tasks'] = array_map(fn(ProjectTask $t)=>$this->serTask($t), $this->em->getRepository(ProjectTask::class)->findBy(['projectId'=>$id]));
        return ApiResponse::data($d);
    }
    public function update(Request $request, string $id): JsonResponse {
        $p = $this->em->find(Project::class, $id); if (!$p instanceof Project) return ApiResponse::notFound();
        $data = gereq()->make(ValidationRegistry::class)->validate('projects.save', $this->body($request));
        $p->setCustomerId(trim((string)$data['customer_id'])); $p->setName(trim((string)$data['name']));
        if (isset($data['status'])) $p->setStatus((string)$data['status']);
        $this->em->flush(); $payload=$this->ser($p); $this->events->emit('project.updated',['type'=>'project','id'=>$id],$payload);
        return ApiResponse::data($payload);
    }
    public function destroy(string $id): JsonResponse {
        $p = $this->em->find(Project::class, $id); if (!$p instanceof Project) return ApiResponse::notFound();
        foreach ($this->em->getRepository(ProjectTask::class)->findBy(['projectId'=>$id]) as $t) $this->em->remove($t);
        $this->em->remove($p); $this->em->flush();
        return ApiResponse::data(['id'=>$id,'deleted'=>true]);
    }
    public function tasksIndex(string $id): JsonResponse {
        if (!$this->em->find(Project::class, $id) instanceof Project) return ApiResponse::notFound();
        $items = $this->em->getRepository(ProjectTask::class)->findBy(['projectId'=>$id], ['updatedAt'=>'DESC']);
        return ApiResponse::collection(array_map(fn(ProjectTask $t)=>$this->serTask($t), $items), ['total'=>count($items)]);
    }
    public function tasksStore(Request $request, string $id): JsonResponse {
        if (!$this->em->find(Project::class, $id) instanceof Project) return ApiResponse::notFound();
        $data = gereq()->make(ValidationRegistry::class)->validate('projects.task.save', $this->body($request));
        $t = new ProjectTask($id, trim((string)$data['title']));
        $t->setStatus((string)($data['status'] ?? 'todo'));
        if (!empty($data['assignee_user_id'])) $t->setAssigneeUserId((string)$data['assignee_user_id']);
        $this->em->persist($t); $this->em->flush();
        $payload=$this->serTask($t); $this->events->emit('task.created',['type'=>'project_task','id'=>$t->id()],$payload);
        if ($t->assigneeUserId()) $this->events->emit('task.assigned',['type'=>'project_task','id'=>$t->id()],$payload);
        return ApiResponse::data($payload, 201);
    }
    public function tasksUpdate(Request $request, string $id, string $taskId): JsonResponse {
        $t = $this->em->find(ProjectTask::class, $taskId);
        if (!$t instanceof ProjectTask || $t->projectId() !== $id) return ApiResponse::notFound();
        $data = gereq()->make(ValidationRegistry::class)->validate('projects.task.save', $this->body($request));
        $prev=$t->status(); $t->setTitle(trim((string)$data['title'])); $t->setStatus((string)($data['status']??$t->status()));
        if (array_key_exists('assignee_user_id',$data)) $t->setAssigneeUserId(trim((string)$data['assignee_user_id'])?:null);
        $this->em->flush(); $payload=$this->serTask($t);
        $this->events->emit('task.updated',['type'=>'project_task','id'=>$t->id()],$payload);
        if ($t->status()==='done' && $prev!=='done') $this->events->emit('task.completed',['type'=>'project_task','id'=>$t->id()],$payload);
        return ApiResponse::data($payload);
    }
    private function ser(Project $p): array {
        return ['id'=>$p->id(),'customer_id'=>$p->customerId(),'name'=>$p->name(),'status'=>$p->status(),'start_at'=>$p->startAt()?->format('Y-m-d'),'due_at'=>$p->dueAt()?->format('Y-m-d'),'owner_user_id'=>$p->ownerUserId(),'deal_id'=>$p->dealId(),'created_at'=>$p->createdAt()->format(\DateTimeInterface::ATOM),'updated_at'=>$p->updatedAt()->format(\DateTimeInterface::ATOM)];
    }
    private function serTask(ProjectTask $t): array {
        return ['id'=>$t->id(),'project_id'=>$t->projectId(),'title'=>$t->title(),'status'=>$t->status(),'assignee_user_id'=>$t->assigneeUserId(),'due_at'=>$t->dueAt()?->format('Y-m-d'),'created_at'=>$t->createdAt()->format(\DateTimeInterface::ATOM),'updated_at'=>$t->updatedAt()->format(\DateTimeInterface::ATOM)];
    }
    private function body(Request $r): array {
        $d=$r->request->all(); $raw=$r->getContent();
        if (is_string($raw)&&$raw!==''){ $j=json_decode($raw,true); if(is_array($j)) return array_merge($d,$j);} return $d;
    }
}