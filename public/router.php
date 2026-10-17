<?php

declare(strict_types=1);

/**
 * Front controller for the PHP built-in server (`php gereq serve`).
 *
 * PHP 8.5 only emits `[status]: METHOD path` for static files it serves itself.
 * Dynamic requests (this file → index.php) only get Accepted/Closing — so we
 * write a compatible access line to php://stderr for ServeCommand to colourise.
 *
 * Note: the STDERR constant is undefined under `php -S` request workers; use
 * the php://stderr stream instead.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = $uri === '' ? '/' : $uri;
$remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
$remotePort = (string) ($_SERVER['REMOTE_PORT'] ?? '0');

// Capture body for the serve access log. Form posts use $_POST; raw/JSON
// bodies are read once and stashed in $GLOBALS['GEREQ_SERVE_BODY'] so
// Application can reinject them into the Symfony Request.
$payload = '';
if ($_POST !== []) {
    $payload = http_build_query($_POST);
} elseif (
    \PHP_SAPI === 'cli-server'
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && $rawBody !== '') {
        $GLOBALS['GEREQ_SERVE_BODY'] = $rawBody;
        $payload = $rawBody;
    }
}
if ($payload !== '') {
    if (strlen($payload) > 8192) {
        $payload = substr($payload, 0, 8192) . '…';
    }
    $payload = str_replace(["\r\n", "\n", "\r"], '\n', $payload);
    $payload = (string) preg_replace(
        '/((?:password|passwd|pass|token|secret|csrf|_token)=)([^&]*)/i',
        '$1***',
        $payload
    );
}

register_shutdown_function(static function () use ($method, $path, $remoteAddr, $remotePort, $payload): void {
    $code = http_response_code();
    if ($code === false || $code === 0) {
        $code = 200;
    }

    // php -S stamps use libc localtime (not date.timezone=UTC).
    $tzName = 'UTC';
    if (is_link('/etc/localtime')) {
        $target = (string) readlink('/etc/localtime');
        if (preg_match('#zoneinfo/(.+)$#', $target, $m) === 1) {
            $tzName = $m[1];
        }
    }
    try {
        $stamp = (new DateTimeImmutable('now', new DateTimeZone($tzName)))->format('D M d H:i:s Y');
    } catch (Throwable) {
        $stamp = date('D M d H:i:s Y');
    }

    $line = sprintf(
        '[%s] %s:%s [%d]: %s %s',
        $stamp,
        $remoteAddr,
        $remotePort,
        $code,
        $method,
        $path
    );
    if ($payload !== '') {
        $line .= ' | ' . $payload;
    }
    $line .= "\n";

    $stderr = @fopen('php://stderr', 'wb');
    if (is_resource($stderr)) {
        fwrite($stderr, $line);
        fclose($stderr);
    }
});

require __DIR__ . '/index.php';
