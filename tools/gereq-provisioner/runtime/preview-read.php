<?php
// Read-only CLI helper embedded by the central service; never installed as an HTTP route.
declare(strict_types=1);
$base = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
ob_start();
try {
    require $base . '/vendor/autoload.php';
    $id = $argv[1] ?? '';
    if (!preg_match('/^[a-f0-9-]{36}$/D', $id)) { throw new RuntimeException('invalid page'); }
    $container = Gereq\Foundation\Application::create($base)->container();
    $em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
    $page = $em->getRepository(Modules\Cms\Domain\Page::class)->find($id);
    if (!$page instanceof Modules\Cms\Domain\Page || $page->isRaw()) { throw new RuntimeException('native page required'); }
    $freeze = $container->get(Gereq\Publish\ProductionFreeze::class);
    if ($freeze->isPendingDelete('cms.pages', $id)) { throw new RuntimeException('page removed'); }
    $context = $container->get(Gereq\Publish\ContentReadContext::class);
    $provider = $container->get(Modules\Cms\Application\CmsProvider::class);
    $published = $context->withProduction(fn () => $provider->findPublishedBySlug($page->slug()));
    $context->useStaging();
    $data = $provider->serializePage($page, true, true);
    $palette = Modules\Cms\Application\BrandColorVariables::palette($container->get(Gereq\Setting\Settings::class));
    $result = ['page_id'=>$id, 'title'=>$page->title(), 'slug'=>$page->slug(),
        'publication_status'=>$published instanceof Modules\Cms\Domain\Page ? ($freeze->hasFreeze('cms.pages', $id) ? 'published_with_changes' : 'published') : 'draft',
        'blocks'=>$data['blocks'] ?? [], 'palette'=>$palette];
    ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Throwable) {
    ob_end_clean();
    fwrite(STDERR, "Draft preview unavailable\n");
    exit(1);
}
