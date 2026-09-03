<?php

declare(strict_types=1);

use Gereq\Foundation\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zlib.output_compression', '0');

try {
    Application::create(dirname(__DIR__))->run();
} catch (\Throwable $exception) {
    $debug = filter_var($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    \Gereq\Http\ExceptionHandler::fallback($exception, $debug);
}
