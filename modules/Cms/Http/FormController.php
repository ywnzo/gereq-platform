<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FormController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly \Modules\Cms\Application\FormSchema $formSchema,
        private readonly \Gereq\Cms\Cms $formActions,
        private readonly \Modules\Cms\Application\FormUploadScanner $uploadScanner,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function formAttempts(Request $request): Response
    {
        $filters = \Modules\Cms\Application\FormSubmissionLog::filters($request->query->all());
        $attempts = (new \Modules\Cms\Application\FormSubmissionLog($this->em))->recent($filters);
        $recordIds = [];
        foreach ($attempts as $attempt) {
            if (is_string($attempt['action_id']) && is_string($attempt['receipt_id'])) {
                $recordIds[$attempt['action_id']][] = $attempt['receipt_id'];
            }
        }
        $links = [];
        foreach ($recordIds as $actionId => $ids) {
            $operations = $this->formActions->formAction($actionId)['operations'] ?? null;
            if ($operations !== null && $this->auth->user()?->can($operations->viewPermission())) {
                $links[$actionId] = $operations->recordLinks($ids);
            }
        }
        $uploads = [];
        if ($attempts !== []) {
            foreach ($this->em->getConnection()->executeQuery('SELECT id, attempt_id, field_name, filename, mime_type, created_at, scan_status, scanned_at, scan_engine FROM cms_form_uploads WHERE attempt_id IN (?) ORDER BY created_at, id', [array_column($attempts, 'id')], [\Doctrine\DBAL\ArrayParameterType::STRING])->fetchAllAssociative() as $upload) {
                $uploads[$upload['attempt_id']][] = $upload;
            }
        }
        foreach ($attempts as &$attempt) {
            $operations = $this->formActions->formAction((string) ($attempt['action_id'] ?? ''))['operations'] ?? null;
            $attempt['uploads'] = [];
            $attempt['record_url'] = null;
            $attempt['retry'] = false;
            if ($operations !== null && is_string($attempt['receipt_id']) && $this->auth->user()?->can($operations->viewPermission())) {
                $link = $links[$attempt['action_id']][$attempt['receipt_id']] ?? null;
                if ($link !== null) {
                    $attempt['record_url'] = $this->router->url($link['route'], $link['params']);
                    $attempt['uploads'] = $uploads[$attempt['id']] ?? [];
                    $attempt['retry'] = $this->auth->user()?->can($operations->retryPermission())
                        && in_array($attempt['delivery_status'], ['failed', 'not_configured', 'not_attempted'], true) && (int) $attempt['retry_count'] < 5;
                }
            }
            if ($operations === null && $this->formActions->formAction((string) ($attempt['action_id'] ?? '')) !== null) {
                $attempt['uploads'] = $uploads[$attempt['id']] ?? [];
            }
        }
        unset($attempt);
        return $this->page('@cms/pages/form_attempts.html.twig', [
            'pageTitle' => $this->translator->trans('cms.form.attempts'),
            'attempts' => $attempts,
            'filters' => $filters,
            'outcomes' => \Modules\Cms\Application\FormSubmissionLog::OUTCOMES,
            'deliveries' => \Modules\Cms\Application\FormSubmissionLog::DELIVERIES,
        ]);
    }

    /** @return array<string, mixed> */
    private function authorizedUpload(string $id): array
    {
        $upload = $this->em->getConnection()->fetchAssociative('SELECT id, filename, action_id, receipt_id FROM cms_form_uploads WHERE id = ? AND (receipt_id IS NOT NULL OR created_at >= ?)', [$id, gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        if ($upload === false) {
            $this->abort(404);
        }
        $action = $this->formActions->formAction((string) ($upload['action_id'] ?? ''));
        $operations = $action['operations'] ?? null;
        if ($action === null || ($operations === null && !$this->auth->user()?->can('cms.manage'))
            || ($operations !== null && (!$this->auth->user()?->can($operations->viewPermission())
                || !is_string($upload['receipt_id']) || $operations->recordLink($upload['receipt_id']) === null))) {
            $this->abort(403);
        }
        return $upload;
    }

    public function downloadFormUpload(string $id): Response
    {
        $upload = $this->authorizedUpload($id);
        // Read the current verdict and its exact bytes together after authorization.
        $current = $this->em->getConnection()->fetchAssociative('SELECT content, scan_status, scan_hash FROM cms_form_uploads WHERE id = ?', [$id]);
        if ($current === false) {
            $this->abort(404);
        }
        if ($current['scan_status'] === 'infected') {
            $this->abort(403, $this->translator->trans('cms.form.scan.blocked'));
        }
        $content = is_resource($current['content']) ? stream_get_contents($current['content']) : $current['content'];
        if (!is_string($content)) {
            $this->abort(503);
        }
        $clean = $current['scan_status'] === 'clean' && is_string($current['scan_hash']) && hash_equals($current['scan_hash'], hash('sha256', $content));
        if ($current['scan_status'] === 'clean' && !$clean) {
            $this->abort(409, $this->translator->trans('cms.form.scan.changed'));
        }
        $request = $this->request();
        if (!$clean && (!$request->isMethod('POST') || $request->request->get('acknowledge') !== '1')) {
            $response = $this->page('@cms/pages/form_upload_warning.html.twig', [
                'pageTitle' => $this->translator->trans('cms.form.scan.warning_title'),
                'upload' => $upload, 'scan_status' => $current['scan_status'],
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }
        if (!$clean) {
            $this->auditor->log('cms.form.unscanned_download_acknowledged', 'cms_form_upload', $id, null, ['scan_status' => $current['scan_status']]);
        }
        $response = new Response($content, 200, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        $response->headers->set('Content-Disposition', \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('attachment', $upload['filename'], 'attachment'));
        return $response;
    }

    public function retryUploadScan(string $id): Response
    {
        if (!$this->auth->user()?->can('cms.manage')) {
            $this->abort(403);
        }
        $this->authorizedUpload($id);
        if (!$this->uploadScanner->retry($id)) {
            $this->abort(409);
        }
        $this->auditor->log('cms.form.scan_retry_requested', 'cms_form_upload', $id);
        $this->flash('success', $this->translator->trans('cms.form.scan.retry_queued'));
        return $this->redirect('admin.cms.forms.download', ['id' => $id]);
    }

    public function retryFormNotification(string $id): Response
    {
        $db = $this->em->getConnection();
        $attempt = $db->fetchAssociative('SELECT * FROM cms_form_attempts WHERE id = ? AND created_at >= ?', [$id, gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        if ($attempt === false) {
            $this->abort(404);
        }
        $operations = $this->formActions->formAction((string) ($attempt['action_id'] ?? ''))['operations'] ?? null;
        if ($operations === null || !$this->auth->user()?->can($operations->retryPermission()) || !$this->auth->user()?->can($operations->viewPermission())) {
            $this->abort(403);
        }
        if (!is_string($attempt['receipt_id']) || $operations->recordLink($attempt['receipt_id']) === null) {
            $this->abort(404);
        }
        $db->transactional(function () use ($db, $id): void {
            $claimed = $db->executeStatement("UPDATE cms_form_attempts SET delivery_status = 'retrying', retry_count = retry_count + 1, last_retry_at = ? WHERE id = ? AND delivery_status IN ('failed', 'not_configured', 'not_attempted') AND retry_count < 5", [gmdate('Y-m-d H:i:s'), $id]);
            if ($claimed !== 1) {
                $this->abort(409);
            }
            $this->auditor->log('cms.form.notification_retry_started', 'cms_form_attempt', $id);
        });
        try {
            $delivery = $operations->retryNotification($attempt['receipt_id']);
            if (!in_array($delivery, ['sent', 'queued', 'failed', 'not_configured'], true)) {
                $delivery = 'failed';
            }
        } catch (\Throwable $exception) {
            error_log('CMS notification retry failed: ' . $exception::class);
            $delivery = 'failed';
        }
        $db->update('cms_form_attempts', ['delivery_status' => $delivery], ['id' => $id]);
        $this->auditor->log('cms.form.notification_retried', 'cms_form_attempt', $id, null, ['delivery_status' => $delivery]);
        $this->flash(in_array($delivery, ['sent', 'queued'], true) ? 'success' : 'error', $this->translator->trans('cms.form.delivery.' . $delivery));
        return $this->redirect('admin.cms.forms.attempts', ['page' => $attempt['page_slug'], 'form' => $attempt['form_id']]);
    }

    /** Validates the current draft and guard, deliberately never invokes an action handler. */
    public function testForm(Request $request): Response
    {
        if ((int) $request->headers->get('Content-Length', '0') > 11 * 1024 * 1024 || (!$request->files->count() && strlen($request->getContent()) > 65536)) {
            return \Gereq\Api\ApiResponse::error('invalid_request', $this->translator->trans('cms.form.invalid_request'), [], 413);
        }
        $body = json_decode($request->getContent(), true);
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')) {
            $body = $request->request->all();
            foreach (['form', 'values'] as $key) {
                $raw = $body[$key] ?? null;
                $body[$key] = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
            }
        }
        if (!is_array($body) || !is_array($body['form'] ?? null) || !is_array($body['values'] ?? null) || count($body['values']) > 50) {
            return \Gereq\Api\ApiResponse::error('invalid_request', $this->translator->trans('cms.form.invalid_request'), [], 400);
        }
        foreach (\Modules\Cms\Application\FormSchema::RESERVED as $name) {
            if (isset($body[$name]) && !is_string($body[$name])) {
                return \Gereq\Api\ApiResponse::error('invalid_request', $this->translator->trans('cms.form.invalid_request'), [], 400);
            }
        }
        try {
            $this->formSchema->assertEditable($body['form']);
            $fields = $this->formSchema->fields($body['form']);
            $uploads = (new \Modules\Cms\Application\FormUploads($this->em, $this->translator))->validate($fields, is_array($request->files->get('files', [])) ? $request->files->get('files', []) : ['invalid_upload' => null]);
            foreach ($fields as $name => $field) {
                if ($field['control'] === 'file') {
                    $body['values'][$name] = $uploads[$name]['name'] ?? '';
                }
            }
            $this->formSchema->validate($body['form'], $body['values']);
        } catch (\Gereq\Validation\ValidationException $exception) {
            return \Gereq\Api\ApiResponse::error('validation_error', $this->translator->trans('cms.form.validation_error'), $exception->errors(), 422);
        }
        if (class_exists(\Modules\Gereq\Application\GereqFormGuard::class)) {
            $denied = \Modules\Gereq\Application\GereqFormGuard::denyIfInvalid($request);
            if ($denied !== null) {
                return $denied;
            }
        }
        return \Gereq\Api\ApiResponse::data(['status' => 'validated', 'message' => $this->translator->trans('cms.form.test_success')]);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
