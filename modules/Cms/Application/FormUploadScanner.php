<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Security\Malware\MalwareScanner;
use Gereq\Security\Malware\ScanResult;
use Ramsey\Uuid\Uuid;

/** Durable, leased scan work; submission never waits for scanner availability. */
final class FormUploadScanner
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly EntityManagerInterface $em, private readonly MalwareScanner $scanner)
    {
    }

    public function process(): void
    {
        $db = $this->em->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $db->executeStatement("UPDATE cms_form_uploads SET scan_status = 'failed', scan_reason = 'interrupted', scan_lease_token = NULL, scan_lease_until = NULL WHERE scan_status = 'scanning' AND scan_attempts >= ? AND scan_lease_until <= ?", [self::MAX_ATTEMPTS, $now]);
        $ids = $db->fetchFirstColumn("SELECT id FROM cms_form_uploads WHERE scan_attempts < ? AND ((scan_status = 'pending' AND (scan_next_at IS NULL OR scan_next_at <= ?)) OR (scan_status = 'scanning' AND scan_lease_until <= ?)) ORDER BY created_at, id LIMIT 10", [self::MAX_ATTEMPTS, $now, $now]);
        $deadline = microtime(true) + 45;
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->scan((string) $id);
        }
    }

    public function retry(string $id): bool
    {
        return $this->em->getConnection()->executeStatement("UPDATE cms_form_uploads SET scan_status = 'pending', scan_attempts = 0, scan_next_at = NULL, scan_reason = '' WHERE id = ? AND scan_status = 'failed'", [$id]) === 1;
    }

    private function scan(string $id): void
    {
        $db = $this->em->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $token = Uuid::uuid7()->toString();
        $claimed = $db->executeStatement("UPDATE cms_form_uploads SET scan_status = 'scanning', scan_attempts = scan_attempts + 1, scan_lease_token = ?, scan_lease_until = ? WHERE id = ? AND scan_attempts < ? AND ((scan_status = 'pending' AND (scan_next_at IS NULL OR scan_next_at <= ?)) OR (scan_status = 'scanning' AND scan_lease_until <= ?))", [$token, gmdate('Y-m-d H:i:s', time() + 120), $id, self::MAX_ATTEMPTS, $now, $now]);
        if ($claimed !== 1) {
            return;
        }
        $row = $db->fetchAssociative('SELECT content, scan_attempts FROM cms_form_uploads WHERE id = ? AND scan_lease_token = ?', [$id, $token]);
        if ($row === false) {
            return;
        }
        $bytes = is_resource($row['content']) ? stream_get_contents($row['content']) : $row['content'];
        try {
            $result = is_string($bytes) ? $this->scanner->scan($bytes) : ScanResult::failed('read_error', false);
        } catch (\Throwable $exception) {
            error_log('Form upload scanner failed: ' . $exception::class);
            $result = ScanResult::failed('scanner_error');
        }
        $attempts = (int) $row['scan_attempts'];
        $status = $result->status === 'failed' && $result->retryable && $attempts < self::MAX_ATTEMPTS ? 'pending' : $result->status;
        $next = $status === 'pending' ? gmdate('Y-m-d H:i:s', time() + [60, 300, 900, 3600][$attempts - 1]) : null;
        // A superseded worker cannot overwrite a newer verdict after its lease expires.
        $db->executeStatement('UPDATE cms_form_uploads SET scan_status = ?, scan_reason = ?, scan_engine = ?, scan_definitions = ?, scan_hash = ?, scanned_at = ?, scan_next_at = ?, scan_lease_token = NULL, scan_lease_until = NULL WHERE id = ? AND scan_lease_token = ?', [$status, $result->reason, $result->engine, $result->definitions, is_string($bytes) ? hash('sha256', $bytes) : null, gmdate('Y-m-d H:i:s'), $next, $id, $token]);
    }
}
