<?php

declare(strict_types=1);

namespace Modules\Vikendo\Application;

use Gereq\Queue\Job;
use Modules\Events\Application\Import\EventImportService;

/** Async import událostí z Vikendo (zpracuje queue worker). */
final class VikendoImportJob implements Job
{
    public function __construct(
        private readonly string $sourceKey = 'vikendo',
        private readonly bool $archiveMissing = true,
    ) {
    }

    public function handle(): void
    {
        $rid = VikendoLog::beginRun();
        $started = microtime(true);

        VikendoLog::info('job.start', [
            'source' => $this->sourceKey,
            'archive_missing' => $this->archiveMissing,
            'pid' => getmypid(),
        ]);

        if (!class_exists(EventImportService::class)) {
            VikendoLog::error('job.abort', ['reason' => 'events_module_missing']);
            VikendoLog::endRun();
            throw new \RuntimeException('Modul Events není k dispozici pro Vikendo import.');
        }

        $config = gereq()->make(VikendoImportConfig::class);
        if (!$config instanceof VikendoImportConfig || !$config->importEnabled()) {
            VikendoLog::warn('job.skip', ['reason' => 'import_disabled']);
            VikendoLog::endRun();

            return;
        }

        VikendoLog::info('job.config', [
            'districts' => $config->districtEids(),
            'district_labels' => $config->districtLabels(),
            'per_page' => $config->perPage(),
            'max_pages' => $config->maxPages(),
            'start_from' => $config->startFrom() ?? 'today',
        ]);

        try {
            /** @var EventImportService $import */
            $import = gereq()->make(EventImportService::class);
            VikendoLog::info('job.import.begin', ['source' => $this->sourceKey]);
            $result = $import->import($this->sourceKey, $this->archiveMissing, dryRun: false);
        } catch (\Throwable $e) {
            VikendoLog::error('job.import.exception', [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'elapsed_s' => round(microtime(true) - $started, 2),
            ]);
            VikendoLog::endRun();
            throw $e;
        }

        $errors = $result['errors'] ?? [];
        VikendoLog::info('job.done', [
            'source' => $result['source'] ?? $this->sourceKey,
            'fetched' => $result['fetched'] ?? 0,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'archived' => $result['archived'] ?? 0,
            'errors' => count($errors),
            'elapsed_s' => round(microtime(true) - $started, 2),
            'rid' => $rid,
        ]);

        if ($errors !== []) {
            foreach (array_slice($errors, 0, 20) as $i => $err) {
                VikendoLog::warn('job.error', [
                    'n' => $i + 1,
                    'message' => (string) $err,
                ]);
            }
            if (count($errors) > 20) {
                VikendoLog::warn('job.error.truncated', ['more' => count($errors) - 20]);
            }
        }
        VikendoLog::endRun();
    }
}
