<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Security;

use Gereq\Config\Repository;
use Gereq\Security\Malware\ClamAvScanner;
use Gereq\Security\Malware\ScanResult;
use Gereq\Testing\TestCase;

final class ClamAvScannerTest extends TestCase
{
    public function testDeploymentPolicyAlertsOnUninspectableContainers(): void
    {
        $config = file_get_contents(dirname(__DIR__, 3) . '/docker/clamav/clamd.conf');
        self::assertNotFalse($config);
        foreach (['ScanPDF', 'ScanArchive', 'AlertEncryptedDoc', 'AlertEncryptedArchive', 'AlertExceedsMax'] as $setting) {
            self::assertMatchesRegularExpression('/^' . $setting . '\s+yes\s*$/m', $config, $setting . ' is required to avoid incomplete scans appearing clean.');
        }
    }

    public function testMissingOrInvalidConfigurationNeverReturnsClean(): void
    {
        self::assertSame('failed', (new ClamAvScanner(new Repository()))->scan('bytes')->status);
        self::assertSame('unavailable', (new ClamAvScanner(new Repository(['malware' => ['endpoint' => 'unix:///tmp/nonexistent-clamav-' . bin2hex(random_bytes(8))]])))->scan('bytes')->reason);
        self::assertSame('configuration', (new ClamAvScanner(new Repository(['malware' => ['endpoint' => 'https://example.test']])))->scan('bytes')->reason);
    }

    public function testStreamsExactBytesAndRecognizesOnlyExplicitSuccess(): void
    {
        foreach (['stream: OK' => 'clean', 'stream: Test.Signature FOUND' => 'infected', 'stream: OK extra' => 'failed', 'stream: error ERROR' => 'failed'] as $reply => $status) {
            $result = $this->scanReply($reply);
            self::assertSame($status, $result->status);
            if ($status === 'clean') {
                self::assertSame('ClamAV 1.4.3', $result->engine);
                self::assertSame('12345', $result->definitions);
            }
        }
    }

    public function testIncompleteScansAndStaleDefinitionsAreNotCleanOrConfirmedThreats(): void
    {
        foreach (['Heuristics.Encrypted.PDF' => 'encrypted', 'Heuristics.Encrypted.Zip' => 'encrypted', 'Heuristics.Limits.Exceeded.MaxFileSize' => 'scan_limit'] as $signature => $reason) {
            $result = $this->scanReply('stream: ' . $signature . ' FOUND');
            self::assertSame('failed', $result->status);
            self::assertSame($reason, $result->reason);
            self::assertFalse($result->retryable);
        }
        self::assertSame('stale_definitions', $this->scanReply('stream: OK', 'ClamAV 1.4.3/12345/Mon Jan 1 00:00:00 2024', false)->reason);
    }

    public function testInvalidOrFutureSignatureMetadataCannotReturnClean(): void
    {
        foreach (['ClamAV 1.4.3/not-a-number/' . gmdate('D M j H:i:s Y'), 'ClamAV 1.4.3/12345/not-a-date', 'ClamAV 1.4.3/12345/' . gmdate('D M j H:i:s Y', time() + 86400)] as $version) {
            $result = $this->scanReply('stream: OK', $version, false);
            self::assertSame('failed', $result->status);
            self::assertSame('protocol', $result->reason);
        }
    }

    public function testUnresponsiveScannerFailsWithinItsDeadline(): void
    {
        $start = microtime(true);
        self::assertSame('failed', $this->scanReply('__STALL__')->status);
        self::assertLessThan(3, microtime(true) - $start);
    }

    private function scanReply(string $reply, ?string $version = null, bool $expectStream = true): ScanResult
    {
        $path = sys_get_temp_dir() . '/clam-test-' . bin2hex(random_bytes(8));
        $version ??= 'ClamAV 1.4.3/12345/' . gmdate('D M j H:i:s Y');
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/clamd-server.php', $path, base64_encode($version), base64_encode($reply)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("READY\n", fgets($pipes[1]));
            $content = str_repeat("file\0bytes", 10000);
            $result = (new ClamAvScanner(new Repository(['malware' => ['endpoint' => 'unix://' . $path, 'timeout' => $reply === '__STALL__' ? 1 : 20]])))->scan($content);
            if ($expectStream) {
                self::assertSame(hash('sha256', $content), file_get_contents($path . '.hash'));
            }
            return $result;
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            foreach ([$path, $path . '.hash'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
