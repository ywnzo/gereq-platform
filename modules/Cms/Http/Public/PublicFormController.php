<?php

declare(strict_types=1);

namespace Modules\Cms\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\IdempotencyService;
use Gereq\Cms\Cms;
use Gereq\Http\PublicCors;
use Gereq\Publish\ContentReadContext;
use Gereq\Security\RateLimiter;
use Gereq\Validation\ValidationException;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Application\FormSchema;
use Modules\Gereq\Application\GereqFormGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicFormController
{
    private string $deliveryStatus = 'not_attempted';
    private ?string $actionId = null;
    private ?string $receiptId = null;
    private string $attemptId;
    private bool $attemptRecorded = false;
    private bool $ignored = false;

    public function __construct(
        private readonly CmsProvider $provider,
        private readonly Cms $cms,
        private readonly FormSchema $schema,
        private readonly ContentReadContext $readContext,
        private readonly RateLimiter $limiter,
        private readonly IdempotencyService $idempotency,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function submit(Request $request, string $slug, string $formId): Response
    {
        $this->deliveryStatus = 'not_attempted';
        $this->actionId = $this->receiptId = null;
        $this->attemptId = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $this->attemptRecorded = false;
        $this->ignored = false;
        $response = $this->handleSubmission($request, $slug, $formId);
        if (!$this->attemptRecorded && $response->getStatusCode() !== 429) {
            (new \Modules\Cms\Application\FormSubmissionLog($this->em))->record($slug, $formId, $response, $this->ignored ? 'ignored' : null, $this->deliveryStatus, $response->getStatusCode() === 201 ? $this->actionId : null, $response->getStatusCode() === 201 ? $this->receiptId : null, $this->attemptId);
        }
        if ($this->attemptRecorded) {
            (new \Modules\Cms\Application\FormSubmissionLog($this->em))->prune();
        }
        return $response;
    }

    /** @phpstan-impure */
    private function handleSubmission(Request $request, string $slug, string $formId): Response
    {
        if (!(new \Modules\Cms\Application\FormRateLimit($this->limiter))->allow((string) ($request->getClientIp() ?? 'unknown'))) {
            return $this->error($request, 'rate_limited', 429);
        }
        if ((int) $request->headers->get('Content-Length', '0') > 11 * 1024 * 1024 || (!$request->files->count() && strlen($request->getContent()) > 65536)) {
            return $this->error($request, 'invalid_request', 413);
        }
        $body = self::body($request);
        if (!is_array($body) || !is_array($body['values'] ?? null) || count($body['values']) > 50) {
            return $this->error($request, 'invalid_request', 400);
        }
        foreach (FormSchema::RESERVED as $name) {
            if (isset($body[$name]) && !is_string($body[$name])) {
                return $this->error($request, 'invalid_request', 400);
            }
        }
        $page = $this->readContext->withProduction(function () use ($slug): ?array {
            $page = $this->provider->findPublishedBySlug($slug);
            return $page === null ? null : $this->provider->serializePage($page, true, true);
        });
        $form = self::findForm(is_array($page['blocks'] ?? null) ? $page['blocks'] : [], $formId);
        if ($form === null) {
            return $this->error($request, 'not_found', 404);
        }
        if ($this->cms->formAction((string) ($form['action'] ?? '')) === null) {
            return $this->error($request, 'unavailable', 503);
        }
        if (trim((string) ($body['website_url'] ?? '')) !== '') {
            $this->ignored = true;
            return $this->cors(ApiResponse::data(['status' => 'received'], 201), $request);
        }
        if (class_exists(GereqFormGuard::class)) {
            $denied = GereqFormGuard::denyIfInvalid($request);
            if ($denied !== null) {
                return $this->cors($denied, $request);
            }
        }
        try {
            $fields = $this->schema->fields($form);
            $uploadService = new \Modules\Cms\Application\FormUploads($this->em, $this->translator);
            $uploads = $uploadService->validate($fields, is_array($request->files->get('files', [])) ? $request->files->get('files', []) : ['invalid_upload' => null]);
            foreach ($fields as $name => $field) {
                if ($field['control'] === 'file') {
                    // A JSON filename is never proof that a file was uploaded.
                    $body['values'][$name] = $uploads[$name]['name'] ?? '';
                }
            }
            $values = $this->schema->validate($form, $body['values']);
        } catch (ValidationException $exception) {
            return $this->cors(ApiResponse::error('validation_error', $this->translator->trans('cms.form.validation_error'), $exception->errors(), 422), $request);
        }
        $clientKey = (string) ($request->headers->get('Idempotency-Key') ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $clientKey)) {
            return $this->error($request, 'invalid_request', 400);
        }
        ksort($values);
        ksort($uploads);
        $canonical = Request::create($request->getPathInfo(), 'POST', [], [], [], [], json_encode(['values' => $values, 'files' => array_map(static fn (array $upload): string => $upload['hash'], $uploads)], JSON_THROW_ON_ERROR));
        $key = hash('sha256', 'cms-form|' . $page['id'] . '|' . $formId . '|' . $clientKey);
        try {
            $record = $this->idempotency->find($key);
            if ($record !== null) {
                if ($record->requestHash() !== $this->idempotency->requestHash($canonical)) {
                    return $this->error($request, 'conflict', 409);
                }
                $this->deliveryStatus = 'replayed';
                return $this->cors($this->idempotency->replay($record), $request);
            }
            $action = $this->cms->formAction((string) $form['action']);
            $actionResult = [];
            $response = $this->em->wrapInTransaction(function () use ($form, $values, $key, $canonical, $uploadService, $uploads, $slug, $formId, $action, &$actionResult): Response {
                $response = ApiResponse::data(['status' => 'received'], 201);
                $this->idempotency->claim($key, $canonical, $response);
                $result = $actionResult = ($action['handle'])($values);
                $this->actionId = (string) $form['action'];
                if (($action['operations'] ?? null) !== null && is_string($result['id'] ?? null) && strlen($result['id']) <= 80) {
                    $this->actionId = (string) $form['action'];
                    $this->receiptId = $result['id'];
                }
                $delivery = $action['afterCommit'] !== null ? 'not_attempted' : (is_array($result) ? ($result['delivery_status'] ?? 'unknown') : 'unknown');
                $this->deliveryStatus = in_array($delivery, ['sent', 'failed', 'not_configured', 'queued', 'not_attempted'], true) ? $delivery : 'unknown';
                $uploadService->store($this->attemptId, $uploads, $this->actionId, $this->receiptId);
                // Do not expose internal customer ids or action-specific private output.
                if ($uploads !== [] || $action['afterCommit'] !== null) {
                    // The download reference is essential data when an attachment is accepted.
                    (new \Modules\Cms\Application\FormSubmissionLog($this->em))->record($slug, $formId, $response, null, $this->deliveryStatus, $this->actionId, $this->receiptId, $this->attemptId, true);
                }
                return $response;
            });
            $this->attemptRecorded = $uploads !== [] || $action['afterCommit'] !== null;
            if ($action['afterCommit'] !== null) {
                $this->notifyAfterCommit($action['afterCommit'], $actionResult);
            }
            return $this->cors($response, $request);
        } catch (ValidationException $exception) {
            return $this->cors(ApiResponse::error('validation_error', $this->translator->trans('cms.form.validation_error'), $exception->errors(), 422), $request);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            // A concurrent worker claimed this key before its action ran. A later retry can replay it.
            return $this->error($request, 'conflict', 409);
        } catch (\Throwable $exception) {
            error_log('CMS form action failed: ' . $exception::class);
            return $this->error($request, 'failed', 500);
        }
    }

    /** @param \Closure(array<string, mixed>): string $notify
     * @param array<string, mixed> $result
     */
    private function notifyAfterCommit(\Closure $notify, array $result): void
    {
        $db = $this->em->getConnection();
        try {
            // A crash after this claim is deliberately not retried automatically.
            $claimed = $db->executeStatement("UPDATE cms_form_attempts SET delivery_status = 'retrying' WHERE id = ? AND delivery_status = 'not_attempted'", [$this->attemptId]);
            if ($claimed !== 1) {
                return;
            }
            try {
                $delivery = $notify($result);
                $this->deliveryStatus = in_array($delivery, ['sent', 'failed', 'not_configured', 'queued'], true) ? $delivery : 'unknown';
            } catch (\Throwable $exception) {
                error_log('CMS form notification failed: ' . $exception::class);
                $this->deliveryStatus = 'failed';
            }
            $db->update('cms_form_attempts', ['delivery_status' => $this->deliveryStatus], ['id' => $this->attemptId]);
        } catch (\Throwable $exception) {
            // The intake is already committed; never turn it into an apparent submission failure.
            error_log('CMS form notification status unavailable: ' . $exception::class);
        }
    }

    /** @return array<string, mixed>|null */
    private static function body(Request $request): ?array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')) {
            $body = $request->request->all();
            $raw = $body['values'] ?? null;
            $body['values'] = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
            return $body;
        }
        $body = json_decode($request->getContent(), true);
        return is_array($body) ? $body : null;
    }

    /** @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    public static function findForm(array $blocks, string $id, int $depth = 0): ?array
    {
        if ($depth > 12) {
            return null;
        }
        foreach ($blocks as $block) {
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
            if (($block['type'] ?? '') === 'form' && ($block['id'] ?? '') === $id) {
                return $payload;
            }
            if (($block['type'] ?? '') === 'layout' && is_array($payload['children'] ?? null)) {
                $found = self::findForm($payload['children'], $id, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function endpoints(array $blocks, string $slug): array
    {
        foreach ($blocks as &$block) {
            if (($block['type'] ?? '') === 'form') {
                $block['payload']['submit_path'] = '/api/public/pages/' . rawurlencode($slug) . '/forms/' . rawurlencode((string) $block['id']);
            } elseif (($block['type'] ?? '') === 'layout' && is_array($block['payload']['children'] ?? null)) {
                $block['payload']['children'] = self::endpoints($block['payload']['children'], $slug);
            }
        }
        return $blocks;
    }

    private function error(Request $request, string $code, int $status): Response
    {
        return $this->cors(ApiResponse::error($code, $this->translator->trans('cms.form.' . $code), [], $status), $request);
    }

    private function cors(Response $response, Request $request): Response
    {
        $response = PublicCors::apply($response, $request, 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', $response->headers->get('Access-Control-Allow-Headers') . ', Idempotency-Key');
        return $response;
    }
}
