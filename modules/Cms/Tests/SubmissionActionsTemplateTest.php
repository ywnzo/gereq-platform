<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class SubmissionActionsTemplateTest extends TestCase
{
    public function testRowLinksToOneModalForAllAttachments(): void
    {
        $attempt = $this->attempt([$this->upload('clean', 'clean'), $this->upload('pending', 'pending')]);
        $html = $this->twig()->render('@cms/pages/_submission_actions.html.twig', ['attempt' => $attempt]);
        self::assertStringContainsString('href="/record"', $html);
        self::assertStringContainsString('href="#cms-form-attachments-attempt-1"', $html);
        self::assertStringContainsString('cms.form.attachments.title', $html);
        self::assertStringNotContainsString('/download/', $html);
        self::assertStringNotContainsString('test-icon', $html);
        self::assertStringNotContainsString('cms.form.scan.infected', $html);
        self::assertStringNotContainsString('btn--danger-outline', $html);
    }

    public function testRowUsesNormalAttachmentsButtonForInfectedFiles(): void
    {
        $attempt = $this->attempt([$this->upload('clean', 'clean'), $this->upload('infected', 'infected')]);
        $html = $this->twig()->render('@cms/pages/_submission_actions.html.twig', ['attempt' => $attempt]);
        self::assertStringContainsString('href="#cms-form-attachments-attempt-1"', $html);
        self::assertStringContainsString('cms.form.attachments.title', $html);
        self::assertStringNotContainsString('cms.form.scan.infected', $html);
        self::assertStringNotContainsString('test-icon', $html);
    }

    public function testModalShowsFileAndScanDetailsWithSafeDownloadActions(): void
    {
        $attempt = $this->attempt([$this->upload('clean', 'clean'), $this->upload('pending', 'pending'), $this->upload('infected', 'infected')]);
        $html = $this->twig()->render('@cms/pages/_submission_attachments_modal.html.twig', ['attempt' => $attempt]);
        self::assertStringContainsString('data-modal-hash="cms-form-attachments-attempt-1"', $html);
        self::assertStringContainsString('application/pdf', $html);
        self::assertStringContainsString('scan-engine', $html);
        self::assertStringContainsString('cms.form.scan.warning', $html);
        self::assertStringContainsString('cms.form.scan.blocked', $html);
        self::assertStringContainsString('href="/download/clean"', $html);
        self::assertStringContainsString('href="/download/pending"', $html);
        self::assertStringNotContainsString('href="/download/infected"', $html);
        self::assertStringContainsString('disabled', $html);
        self::assertStringContainsString('&lt;infected&gt;.pdf', $html);
    }

    /** @return array<string, mixed> */
    private function upload(string $id, string $status): array
    {
        return [
            'id' => $id, 'filename' => '<' . $id . '>.pdf', 'scan_status' => $status,
            'mime_type' => 'application/pdf', 'field_name' => 'attachment',
            'created_at' => '2026-10-27 12:00:00', 'scanned_at' => '2026-10-27 12:01:00',
            'scan_engine' => 'scan-engine',
        ];
    }

    /** @param list<array<string, mixed>> $uploads
     * @return array<string, mixed>
     */
    private function attempt(array $uploads): array
    {
        return ['id' => 'attempt-1', 'record_url' => '/record', 'uploads' => $uploads, 'retry' => false];
    }

    private function twig(): Environment
    {
        $root = dirname(__DIR__, 3);
        $loader = new FilesystemLoader($root . '/resources/views');
        $loader->addPath(dirname(__DIR__) . '/Resources/views', 'cms');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('url', static fn (string $name, array $args): string => '/download/' . $args['id']));
        $twig->addFunction(new TwigFunction('icon', static fn (): string => 'test-icon'));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => ''));
        return $twig;
    }
}
