<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/** Bounded operational metadata only; never stores submitted values or security tokens. */
final class FormSubmissionLog
{
    public const OUTCOMES = ['received', 'validation_error', 'gereq_security', 'rate_limited', 'failed', 'invalid_request', 'not_found', 'unavailable', 'conflict', 'ignored'];
    public const DELIVERIES = ['sent', 'failed', 'not_configured', 'queued', 'unknown', 'not_attempted', 'replayed', 'retrying'];

    /** @param array<string, mixed> $input
     * @return array{page: string, form: string, outcome: string, delivery: string, from: string, to: string}
     */
    public static function filters(array $input): array
    {
        $filters = [];
        foreach (['page', 'form', 'outcome', 'delivery', 'from', 'to'] as $key) {
            $filters[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        }
        $filters['page'] = mb_substr($filters['page'], 0, 180);
        $filters['form'] = substr($filters['form'], 0, 80);
        if (!in_array($filters['outcome'], self::OUTCOMES, true)) {
            $filters['outcome'] = '';
        }
        if (!in_array($filters['delivery'], self::DELIVERIES, true)) {
            $filters['delivery'] = '';
        }
        foreach (['from', 'to'] as $key) {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$key]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]) : false;
            if ($date === false || $date->format('Y-m-d') !== $filters[$key]) {
                $filters[$key] = '';
            }
        }
        return $filters;
    }

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function record(string $slug, string $formId, Response $response, ?string $outcome = null, string $delivery = 'unknown', ?string $actionId = null, ?string $receiptId = null, ?string $attemptId = null, bool $required = false): void
    {
        $data = json_decode((string) $response->getContent(), true);
        $status = $response->getStatusCode();
        try {
            $db = $this->em->getConnection();
            $db->insert('cms_form_attempts', [
                'id' => $attemptId ?? Uuid::uuid7()->toString(), 'page_slug' => mb_substr($slug, 0, 180),
                'form_id' => substr($formId, 0, 80), 'http_status' => $status,
                'outcome' => $outcome ?? ($status < 300 ? 'received' : substr((string) ($data['error']['code'] ?? 'failed'), 0, 80)),
                'delivery_status' => $delivery, 'action_id' => $actionId, 'receipt_id' => $receiptId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$db->isTransactionActive()) {
                $this->prune();
            }
        } catch (\Throwable $exception) {
            if ($required) {
                throw $exception;
            }
            // Diagnostics must not change submission results, including after an action rollback.
            error_log('CMS form diagnostics unavailable: ' . $exception::class);
        }
    }

    /** Optional diagnostics must not cause a successfully sent notification to be retried. */
    public function updateDelivery(string $action, string $recordId, string $status): void
    {
        $db = $this->em->getConnection();
        try {
            $db->transactional(static function () use ($db, $action, $recordId, $status): void {
                $db->update('cms_form_attempts', ['delivery_status' => $status], ['action_id' => $action, 'receipt_id' => $recordId]);
            });
        } catch (\Throwable $exception) {
            error_log('CMS notification diagnostics unavailable: ' . $exception::class);
        }
    }

    /** Retention failures must not roll back accepted intake or remove unexpired rows. */
    public function prune(bool $required = false): void
    {
        try {
            $db = $this->em->getConnection();
            $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            $db->executeStatement('DELETE FROM cms_form_uploads WHERE created_at < ? AND receipt_id IS NULL', [$cutoff]);
            $db->executeStatement('DELETE FROM cms_form_attempts WHERE created_at < ?', [$cutoff]);
        } catch (\Throwable $exception) {
            if ($required) {
                throw $exception;
            }
            error_log('CMS form retention unavailable: ' . $exception::class);
        }
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function recent(array $filters = []): array
    {
        $filters = self::filters($filters);
        $query = $this->em->getConnection()->createQueryBuilder()
            ->select('*')->from('cms_form_attempts')
            ->where('created_at >= :cutoff')->setParameter('cutoff', gmdate('Y-m-d H:i:s', time() - 30 * 86400))
            ->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults(100);
        foreach (['page' => 'page_slug', 'form' => 'form_id', 'outcome' => 'outcome', 'delivery' => 'delivery_status'] as $key => $column) {
            if ($filters[$key] !== '') {
                $query->andWhere($column . ' = :' . $key)->setParameter($key, $filters[$key]);
            }
        }
        if ($filters['from'] !== '') {
            $query->andWhere('created_at >= :date_from')->setParameter('date_from', $filters['from'] . ' 00:00:00');
        }
        if ($filters['to'] !== '') {
            $end = new \DateTimeImmutable($filters['to'], new \DateTimeZone('UTC'));
            $query->andWhere('created_at < :date_to')->setParameter('date_to', $end->modify('+1 day')->format('Y-m-d H:i:s'));
        }
        return $query->executeQuery()->fetchAllAssociative();
    }
}
