<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Queue;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Config\Repository;
use Gereq\Queue\FailedJob;
use Gereq\Queue\FailedJobStore;
use Gereq\Queue\Queue;
use Gereq\Testing\TestCase;

final class FailedJobStoreTest extends TestCase
{
    private EntityManager $em;

    private FailedJobStore $store;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/gereq-failed-job-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/tmp/queue', 0775, true);

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 3) . '/src/Queue'],
            true,
        );
        $config->enableNativeLazyObjects(true);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($conn, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(FailedJob::class)]);
        $this->store = new FailedJobStore($this->em);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->basePath);
        parent::tearDown();
    }

    public function testRecordFailureBumpsAttemptsOnSamePayload(): void
    {
        $payload = serialize(new QueueTestJob('x'));
        $first = $this->store->recordFailure($payload, 'boom 1', QueueTestJob::class);
        $second = $this->store->recordFailure($payload, 'boom 2', QueueTestJob::class);

        self::assertSame($first->id(), $second->id());
        self::assertSame(2, $second->attempts());
        self::assertSame(1, $this->store->count());
        self::assertSame(0, $this->store->countExhausted(3));
        self::assertSame(1, $this->store->countExhausted(2));
    }

    public function testRequeuePushesJobAndForgetRemovesRow(): void
    {
        $job = new QueueTestJob('retry-me');
        $payload = serialize($job);
        $row = $this->store->recordFailure($payload, 'fail', QueueTestJob::class);

        $queue = new Queue(new Repository(['queue' => ['driver' => 'file']]), $this->basePath, $this->store);
        self::assertTrue($this->store->requeue($row, $queue));
        self::assertSame(1, $queue->pending());
        self::assertTrue($this->store->forget($row));
        self::assertSame(0, $this->store->count());
    }

    public function testFindByIdAndPrune(): void
    {
        $row = $this->store->recordFailure(serialize(new QueueTestJob('p')), 'err', QueueTestJob::class);
        self::assertSame($row->id(), $this->store->find($row->id())?->id());
        self::assertSame(0, $this->store->prune(30));
        self::assertSame(1, $this->store->count());
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
