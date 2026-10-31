<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;
use Gereq\Validation\ValidationException;

/** Private, bounded attachments; never imported into the public media library. */
final class FormUploads implements \Gereq\Cms\FormAttachmentStorage
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator)
    {
    }

    /** @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed> $input
     * @return array<string, array{name: string, mime: string, content: string, hash: string}>
     */
    public function validate(array $fields, array $input): array
    {
        $uploads = [];
        $errors = [];
        $total = 0;
        foreach ($input as $name => $file) {
            if (($fields[$name]['control'] ?? '') !== 'file' || !$file instanceof UploadedFile || !$file->isValid()) {
                $errors[$name] = $this->translator->trans('cms.form.upload_invalid');
                continue;
            }
            $size = $file->getSize();
            $total += $size;
            $mime = $file->getMimeType();
            $extension = strtolower($file->getClientOriginalExtension());
            $allowed = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
            if ($size < 1 || $size > 5 * 1024 * 1024 || $total > 10 * 1024 * 1024 || !in_array($extension, $allowed[$mime] ?? [], true)) {
                $errors[$name] = $this->translator->trans('cms.form.upload_invalid');
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                $errors[$name] = $this->translator->trans('cms.form.upload_invalid');
                continue;
            }
            $uploads[$name] = [
                'name' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $file->getClientOriginalName()) ?? 'attachment', 0, 180),
                'mime' => $mime, 'content' => $content, 'hash' => hash('sha256', $content),
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $uploads;
    }

    /** @param array<string, array{name: string, mime: string, content: string, hash: string}> $uploads */
    public function store(string $attemptId, array $uploads, ?string $actionId = null, ?string $recordId = null): void
    {
        foreach ($uploads as $field => $upload) {
            $this->em->getConnection()->insert('cms_form_uploads', [
                'id' => Uuid::uuid7()->toString(), 'attempt_id' => $attemptId, 'field_name' => $field,
                'action_id' => $actionId, 'receipt_id' => $recordId,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'filename' => $upload['name'], 'mime_type' => $upload['mime'], 'content' => $upload['content'],
            ], ['content' => ParameterType::BINARY]);
        }
    }

    /** @phpstan-impure Reads the current transactional attachment state. */
    public function forRecord(string $action, string $recordId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT id, filename, scan_status FROM cms_form_uploads WHERE action_id = ? AND receipt_id = ? ORDER BY created_at, id', [$action, $recordId]);
        return array_map(static fn (array $row): array => [
            'id' => $row['id'], 'filename' => $row['filename'], 'scan_status' => $row['scan_status'],
            'route' => 'admin.cms.forms.download', 'params' => ['id' => $row['id']],
        ], $rows);
    }

    public function removeForRecord(string $action, string $recordId): void
    {
        $this->em->getConnection()->delete('cms_form_uploads', ['action_id' => $action, 'receipt_id' => $recordId]);
    }
}
