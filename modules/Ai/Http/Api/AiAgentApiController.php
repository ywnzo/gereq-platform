<?php

declare(strict_types=1);

namespace Modules\Ai\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Api\Entity\ApiToken;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Ai\Application\AgentService;
use Modules\Ai\Application\AiOrchestrator;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Domain\AiAgent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class AiAgentApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly AgentService $agents,
        private readonly AiOrchestrator $orchestrator,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function chat(Request $request, string $slug): JsonResponse
    {
        $agent = $this->agents->findBySlug($slug);
        if (!$agent instanceof AiAgent) {
            return ApiResponse::notFound('agent_not_found', 'Agent not found');
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return ApiResponse::error('validation_error', 'message is required', [], 422);
        }

        $token = $request->attributes->get('_api_token');
        $scopes = $token instanceof ApiToken ? $token->scopes() : ['ai.admin'];
        $surface = in_array('public', $agent->surfaces(), true) ? 'public' : 'api';
        if (isset($payload['surface']) && in_array((string) $payload['surface'], ['api', 'public'], true)) {
            $surface = (string) $payload['surface'];
        }

        $user = $this->auth->user();
        $perms = [];
        if ($user !== null) {
            foreach (['ai.use', 'ai.manage', 'listings.view', 'customers.view', 'crm.view', 'documents.view'] as $p) {
                if ($user->can($p)) {
                    $perms[] = $p;
                }
            }
        }

        $actor = new AiActor(
            $token instanceof ApiToken ? 'api_token' : 'user',
            $token instanceof ApiToken ? $token->id() : $this->auth->id(),
            $surface,
            $perms,
            $scopes,
        );

        try {
            $result = $this->orchestrator->chat(
                $agent,
                $actor,
                $message,
                isset($payload['conversation_id']) ? (string) $payload['conversation_id'] : null,
                isset($payload['entity_type']) ? (string) $payload['entity_type'] : null,
                isset($payload['entity_id']) ? (string) $payload['entity_id'] : null,
                requirePublished: true,
            );
        } catch (\Throwable $e) {
            $code = (int) $e->getCode();
            $status = in_array($code, [403, 404], true) ? $code : 400;

            return ApiResponse::error('ai_error', $e->getMessage(), [], $status);
        }

        return ApiResponse::data($result);
    }
}
