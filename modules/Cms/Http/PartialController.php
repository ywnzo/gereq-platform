<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\HtmlSanitizer;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Domain\CmsPartial;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PartialController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly HtmlSanitizer $html,
        private readonly \Modules\Cms\Application\FormSchema $formSchema,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->listPartials()]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->savePartial($request);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partial = $this->em->find(CmsPartial::class, $id);
        if ($partial === null) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.partial_not_found')]], 404);
        }

        return $this->savePartial($request, $partial);
    }

    private function savePartial(Request $request, ?CmsPartial $partial = null): JsonResponse
    {
        $raw = $request->getContent();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.invalid_payload')]], 422);
        }

        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.partial_name')]], 422);
        }

        $description = trim((string) ($decoded['description'] ?? ''));
        $description = $description === '' ? null : mb_substr($description, 0, 500);

        $incoming = is_array($decoded['blocks'] ?? null) ? $decoded['blocks'] : [];
        $blocks = $this->normalizeBlocks($incoming);
        if ($blocks === []) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.partial_empty')]], 422);
        }

        $this->validateDynamicSources($blocks);
        $created = $partial === null;
        if ($partial === null) {
            $partial = new CmsPartial($name, $blocks, $description);
            $this->em->persist($partial);
        } else {
            $partial->setName($name);
            $partial->setBlocks($blocks);
            $partial->setDescription($description);
        }
        $this->em->flush();

        return new JsonResponse(['data' => $partial->toArray()], $created ? 201 : 200);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function validateDynamicSources(array $blocks): void
    {
        foreach ($blocks as $block) {
            $payload = $block['payload'];
            if ($block['type'] === 'form') {
                $this->formSchema->assertEditable($payload);
            }
            if ($block['type'] === 'dynamic') {
                gereq()->make(\Gereq\Validation\ValidationRegistry::class)->validate('cms.dynamic_template.save', [
                    'source' => $payload['source'] ?? '',
                ]);
            }
            if (is_array($payload['children'] ?? null)) {
                $this->validateDynamicSources($payload['children']);
            }
        }
    }

    public function destroy(string $id): JsonResponse
    {
        $partial = $this->em->find(CmsPartial::class, $id);
        if ($partial === null) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.partial_not_found')]], 404);
        }

        $this->em->remove($partial);
        $this->em->flush();

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPartials(): array
    {
        /** @var list<CmsPartial> $items */
        $items = $this->em->getRepository(CmsPartial::class)->findBy([], ['updatedAt' => 'DESC', 'name' => 'ASC']);

        return array_map(static fn (CmsPartial $p): array => $p->toArray(), $items);
    }

    /**
     * @param list<mixed> $incoming
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    private function normalizeBlocks(array $incoming): array
    {
        $out = [];
        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, PageBlock::TYPES, true)) {
                continue;
            }
            $payloadRaw = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $out[] = [
                'type' => $type,
                'payload' => $this->normalizePayload($type, $payloadRaw),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(string $type, array $payload, int $depth = 0): array
    {
        $core = match ($type) {
            'form' => \Modules\Cms\Application\FormSchema::normalize($payload, fn (mixed $children): array => \Modules\Cms\Application\DynamicElement::blocks($children, fn (string $childType, array $childPayload): array => $this->normalizePayload($childType, $childPayload, $depth + 1), $depth)),
            'form_field' => \Modules\Cms\Application\FormSchema::field($payload),
            'heading' => [
                'text' => $this->html->sanitize((string) ($payload['text'] ?? '')),
                'level' => max(1, min(3, (int) ($payload['level'] ?? 2))),
            ],
            'text' => [
                'body' => $this->html->sanitize((string) ($payload['body'] ?? '')),
            ],
            'html' => [
                'html' => $this->html->sanitize((string) ($payload['html'] ?? '')),
            ],
            'image' => [
                'media_id' => trim((string) ($payload['media_id'] ?? '')),
                'url' => trim((string) ($payload['url'] ?? '')),
                'alt' => trim((string) ($payload['alt'] ?? '')),
                'caption' => trim((string) ($payload['caption'] ?? '')),
            ],
            'cta' => [
                'label' => (new \Gereq\Security\HtmlSanitizer())->sanitize((string) ($payload['label'] ?? '')),
                'url' => \Modules\Cms\Application\ContentSecurity::link((string) ($payload['url'] ?? '')),
                'style' => in_array((string) ($payload['style'] ?? 'primary'), ['primary', 'secondary', 'ghost'], true)
                    ? (string) $payload['style']
                    : 'primary',
            ],
            'divider' => [
                'style' => trim((string) ($payload['style'] ?? 'hero')),
            ],
            'products' => [
                'product_ids' => trim((string) ($payload['product_ids'] ?? '')),
            ],
            'dynamic' => array_merge(\Modules\Cms\Application\DynamicElement::normalize($payload), [
                'item_blocks' => \Modules\Cms\Application\DynamicElement::templateBlocks($payload['item_blocks'] ?? [], fn (string $childType, array $childPayload): array => $this->normalizePayload($childType, $childPayload, $depth + 1), $depth),
            ]),
            'layout' => BlockDesignNormalizer::layout($payload, fn (mixed $children): array => \Modules\Cms\Application\DynamicElement::blocks($children, fn (string $childType, array $childPayload): array => $this->normalizePayload($childType, $childPayload, $depth + 1), $depth)),
            'widget' => \Modules\Cms\Application\IntegrationWidget::normalize($payload),
            'avatar', 'badge' => \Modules\Cms\Application\VisualElement::normalize($type, $payload),
            'rating' => ['value' => substr((string) ($payload['value'] ?? '5'), 0, 100)],
            'gallery' => [
                'media_ids' => trim((string) ($payload['media_ids'] ?? '')),
                'variant' => BlockDesignNormalizer::galleryVariant($payload['variant'] ?? 'grid'),
            ],
            'quote' => [
                'quote' => trim((string) ($payload['quote'] ?? '')),
                'author' => trim((string) ($payload['author'] ?? '')),
                'role' => trim((string) ($payload['role'] ?? '')),
            ],
            'video' => [
                'url' => trim((string) ($payload['url'] ?? '')),
                'caption' => trim((string) ($payload['caption'] ?? '')),
            ],
            'spacer' => [
                'size' => in_array((string) ($payload['size'] ?? 'md'), ['sm', 'md', 'lg', 'xl'], true)
                    ? (string) $payload['size']
                    : 'md',
            ],
            'icon' => [
                'name' => preg_replace('~[^a-z0-9_\\-]+~i', '', trim((string) ($payload['name'] ?? 'circle'))) ?: 'circle',
                'icon_style' => BlockDesignNormalizer::normalizeIconStyle($payload['icon_style'] ?? 'outlined'),
                'size' => BlockDesignNormalizer::normalizeIconSize($payload['size'] ?? '24'),
                'fill' => BlockDesignNormalizer::normalizeIconFill($payload['fill'] ?? '0'),
                'weight' => BlockDesignNormalizer::normalizeIconWeight($payload['weight'] ?? '400'),
                'grade' => BlockDesignNormalizer::normalizeIconGrade($payload['grade'] ?? '0'),
                'opsz' => BlockDesignNormalizer::normalizeIconOpsz($payload['opsz'] ?? ''),
                'title' => trim((string) ($payload['title'] ?? '')),
                'hover_color' => BlockDesignNormalizer::normalizeColor(
                    (string) ($payload['hover_color'] ?? 'inherit'),
                    BlockDesignNormalizer::textTokens(),
                    'inherit',
                ),
            ],
            'table' => [
                'html' => $this->html->sanitize((string) ($payload['html'] ?? '')),
                'caption' => $this->html->sanitize((string) ($payload['caption'] ?? '')),
                'caption_visible' => ((int) ($payload['caption_visible'] ?? 1)) === 0 ? '0' : '1',
                'style_cell_padding' => max(0, min(20, (int) ($payload['style_cell_padding'] ?? 0))),
                'style_border_inner' => ((int) ($payload['style_border_inner'] ?? 1)) === 0 ? '0' : '1',
                'style_border_outer' => ((int) ($payload['style_border_outer'] ?? 1)) === 0 ? '0' : '1',
            ],
            default => [],
        };

        if ($type === 'form') {
            $this->formSchema->assertEditable($core);
        }

        return BlockDesignNormalizer::merge($core, $payload);
    }
}
