<?php

declare(strict_types=1);

$base = getenv('GEREQ_INSTANCE_ROOT') ?: '/app';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/_provision/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}
// Serve only resolved files under the two public roots; never application secrets.
foreach ([$base . '/public', '/frontend'] as $public) {
    $file = realpath($public . $path);
    if ($file !== false && str_starts_with($file, $public . '/') && is_file($file)
        && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['js', 'css', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff', 'woff2', 'avif', 'gif'], true)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];
        header('Content-Type: ' . ($mime[$ext] ?? mime_content_type($file)));
        readfile($file);
        return;
    }
}
if (preg_match('~^/(admin(?:/|$)|api(?:/|$)|uploads(?:/|$)|media(?:/|$)|\.well-known/)~', $path)) {
    require $base . '/public/index.php';
    return;
}
header('Content-Type: text/html; charset=utf-8');
require_once $base . '/vendor/autoload.php';
$app = Gereq\Foundation\Application::create($base);
$request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
echo $app->container()->get(Gereq\Hook\HookManager::class)->filter('storefront.spa_html', file_get_contents('/frontend/index.html'), $request);
